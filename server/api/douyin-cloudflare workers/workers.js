/**
 *@Author: JH-Ahua
 *@CreateTime: 2026/4/14 10:41
 *@email: admin@bugpk.com
 *@blog: www.jiuhunwl.cn
 *@Api: api.bugpk.com
 *@tip: 抖音解析cloudflare workers版
 */
const DEFAULT_UA =
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36";

// 详情接口专用移动端 UA：配合 api.amemv.com 免 Cookie 详情接口使用（视频/图集/实况通用）
const MOBILE_UA =
    "Mozilla/5.0 (Linux; Android 13; 2210132C Build/TKQ1.220829.002) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Mobile Safari/537.36";

const REDIRECT_DONE_HOSTS = {
    "www.douyin.com": true,
};

const PLATFORM_HOSTS = {
    "www.douyin.com": true,
    "www.iesdouyin.com": true,
};

const CORS_HEADERS = {
    "access-control-allow-origin": "*",
    "access-control-allow-methods": "GET,POST,OPTIONS",
    "access-control-allow-headers": "Content-Type",
    "cache-control": "no-cache, no-store, must-revalidate",
    "content-type": "application/json; charset=utf-8",
};

// 原画播放地址模板：传入 vid 拼接后 302 即为原画直链
const ORIGINAL_PLAY_ENDPOINT = "https://aweme.snssdk.com/aweme/v1/play/";
// 批量转换 vid 时的并发数与单次上限（防止超出 Workers 子请求配额，超出的回退拼接地址）
const VID_RESOLVE_CONCURRENCY = 8;
const VID_RESOLVE_MAX_COUNT = 30;

export default {
    async fetch(request) {
        if (request.method === "OPTIONS") {
            return new Response(null, {status: 204, headers: CORS_HEADERS});
        }

        try {
            const input = await getInputUrl(request);
            if (!input) {
                return jsonResponse(400, "请输入抖音链接");
            }

            const result = await parseShareText(input);
            return new Response(JSON.stringify(result), {
                status: result.code >= 500 ? 500 : 200,
                headers: CORS_HEADERS,
            });
        } catch (error) {
            return new Response(
                JSON.stringify(output(500, `服务异常：${safeErrorMessage(error)}`)),
                {
                    status: 500,
                    headers: CORS_HEADERS,
                }
            );
        }
    },
};

async function getInputUrl(request) {
    const url = new URL(request.url);
    const fromQuery = url.searchParams.get("url");
    if (fromQuery && fromQuery.trim()) {
        return fromQuery.trim();
    }

    if (request.method === "POST") {
        const contentType = request.headers.get("content-type") || "";

        if (contentType.includes("application/json")) {
            const body = await request.json().catch(() => null);
            if (body && typeof body.url === "string" && body.url.trim()) {
                return body.url.trim();
            }
        }

        if (
            contentType.includes("application/x-www-form-urlencoded") ||
            contentType.includes("multipart/form-data")
        ) {
            const form = await request.formData().catch(() => null);
            const value = form?.get("url");
            if (typeof value === "string" && value.trim()) {
                return value.trim();
            }
        }

        if (contentType.startsWith("text/plain")) {
            const text = (await request.text().catch(() => "")).trim();
            if (text) {
                return text;
            }
        }
    }

    return "";
}

async function parseShareText(text) {
    const shareUrl = extractFirstUrl(text);
    if (!shareUrl) {
        return output(400, "未在文本中识别到有效链接");
    }

    const resolved = await followRedirectsToPlatform(shareUrl);
    if (!resolved) {
        return output(400, "无法解析重定向或链接不属于抖音");
    }

    const resolvedHost = host(resolved);
    if (!resolvedHost || !PLATFORM_HOSTS[resolvedHost]) {
        return output(400, "该链接不是抖音网页链接");
    }

    const realUrl = extractVideoAddress(resolved);
    const awemeId = getVideoId(realUrl);
    if (!awemeId) {
        return output(400, `链接格式错误，无法提取ID。处理后的链接: ${realUrl}`);
    }

    const detailResult = await fetchAwemeDetail(awemeId);
    if (!detailResult.ok) {
        const data = detailResult.reason ? {reason: detailResult.reason} : {};
        return output(500, detailResult.reason ? `请求失败（${detailResult.reason}）` : "请求失败", data);
    }

    const payload = await buildLegacyFormatData(detailResult.detail.aweme_detail, awemeId);
    const ok =
        (payload.type === "video" && payload.url) ||
        ((payload.type === "image" || payload.type === "live") &&
            Array.isArray(payload.images) &&
            payload.images.length > 0);

    if (!ok) {
        return output(404, "解析失败，未找到有效内容", []);
    }

    return output(200, "解析成功", payload);
}

function output(code, msg, data = []) {
    return {code, msg, data};
}

function jsonResponse(code, msg, data = []) {
    return new Response(JSON.stringify(output(code, msg, data)), {
        status: code >= 500 ? 500 : 200,
        headers: CORS_HEADERS,
    });
}

function safeErrorMessage(error) {
    if (error instanceof Error && error.message) {
        return error.message;
    }
    return String(error || "unknown");
}

function extractFirstUrl(text) {
    const match = text.match(
        /\bhttps?:\/\/(?:www\.|[-a-zA-Z0-9.@:%_+~#=]{1,256}\.[a-zA-Z0-9()]{1,6})\b(?:[-a-zA-Z0-9()@:%_+.~#?&/=]*)?/i
    );
    return match ? match[0] : null;
}

async function followRedirectsToPlatform(startUrl, max = 8) {
    let current = startUrl;
    for (let i = 0; i < max; i += 1) {
        const currentHost = host(current);
        if (currentHost && REDIRECT_DONE_HOSTS[currentHost]) {
            return current;
        }

        const next = await requestLocation(current);
        if (!next) {
            break;
        }
        current = next;
    }

    const finalHost = host(current);
    return finalHost && PLATFORM_HOSTS[finalHost] ? current : null;
}

async function requestLocation(url) {
    const response = await fetch(url, {
        method: "GET",
        redirect: "manual",
        headers: {
            "user-agent": DEFAULT_UA,
            accept: "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
            "accept-language": "zh-CN,zh;q=0.9",
        },
    }).catch(() => null);

    if (!response || response.status < 300 || response.status >= 400) {
        return null;
    }

    const location = response.headers.get("location");
    if (!location) {
        return null;
    }

    try {
        return new URL(location, url).toString();
    } catch {
        return null;
    }
}

function buildOriginalPlayUrl(vid, ratio = "default") {
    if (!vid) {
        return null;
    }
    return `${ORIGINAL_PLAY_ENDPOINT}?video_id=${encodeURIComponent(String(vid))}&ratio=${ratio}&line=0`;
}

// 原画核心逻辑：传入视频 vid，请求原画播放端点，302 直出原画 CDN 直链
// 这个破原画接口竟然还有人卖四位数，真是穷疯了
async function resolveOriginalVideoUrl(vid, ratio = "default") {
    const fallbackUrl = buildOriginalPlayUrl(vid, ratio);
    if (!fallbackUrl) {
        return null;
    }

    let current = fallbackUrl;
    try {
        for (let i = 0; i < 3; i += 1) {
            const next = await requestLocation(current);
            if (!next) {
                break;
            }
            current = next;
        }
    } catch {
        return fallbackUrl;
    }

    return current === fallbackUrl ? fallbackUrl : toHttps(current) || fallbackUrl;
}

// 从 video 信息中提取 vid（优先 play_addr.uri，兼容 playAddr[0].uri 与 video.uri）
function extractVidFromVideoInfo(videoInfo) {
    if (!isObject(videoInfo)) {
        return null;
    }
    if (isObject(videoInfo.play_addr) && videoInfo.play_addr.uri) {
        return String(videoInfo.play_addr.uri);
    }
    if (Array.isArray(videoInfo.playAddr) && isObject(videoInfo.playAddr[0]) && videoInfo.playAddr[0].uri) {
        return String(videoInfo.playAddr[0].uri);
    }
    if (typeof videoInfo.uri === "string" && videoInfo.uri) {
        return videoInfo.uri;
    }
    return null;
}

// 批量转换 vid：去重 + 缓存 + 并发控制 + 数量上限，超出上限的 vid 不在结果中（由调用方回退拼接地址）
async function resolveVidBatch(vidList) {
    const uniqueVids = [...new Set(vidList.filter(Boolean))];
    const limitedVids = uniqueVids.slice(0, VID_RESOLVE_MAX_COUNT);
    const cache = new Map();
    if (!limitedVids.length) {
        return cache;
    }

    let index = 0;
    const workers = Array.from(
        {length: Math.min(VID_RESOLVE_CONCURRENCY, limitedVids.length)},
        async () => {
            while (index < limitedVids.length) {
                const vid = limitedVids[index];
                index += 1;
                cache.set(vid, await resolveOriginalVideoUrl(vid));
            }
        }
    );
    await Promise.all(workers);
    return cache;
}

function extractVideoAddress(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return url;
    }

    const cleanPath = parsed.pathname.replace(/\/+$/, "");
    const normalized = `${parsed.protocol}//${parsed.host}${cleanPath}`;

    if (
        parsed.host === "www.douyin.com" ||
        parsed.host === "www.iesdouyin.com"
    ) {
        const modalId = parsed.searchParams.get("modal_id");
        if (modalId) {
            return `${normalized}?modal_id=${encodeURIComponent(modalId)}`;
        }
    }

    return normalized;
}

function getVideoId(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }

    for (const key of ["vid", "id", "modal_id", "v", "s", "pid"]) {
        const value = parsed.searchParams.get(key);
        if (value) {
            return value;
        }
    }

    const parts = parsed.pathname.split("/").filter(Boolean);
    let last = parts[parts.length - 1];
    if (!last) {
        return null;
    }
    if (last.endsWith(".html")) {
        last = last.slice(0, -5);
    }
    return last || null;
}

async function fetchAwemeDetail(awemeId) {
    // 免 Cookie 详情接口：api.amemv.com（无签名、无游客 Cookie 依赖）。
    // 视频返回 play_addr.uri + bit_rate，图集返回 images[].url_list（原图），实况另含 images[].video，三种类型通用。
    if (typeof awemeId !== "string" || !/^[1-9][0-9]{15,19}$/.test(awemeId)) {
        return {ok: false, reason: "请输入作品 aweme_id，而不是合集 mix_id"};
    }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
        const params = new URLSearchParams({
            aweme_id: awemeId,
            aid: "6383",
            device_platform: "web",
            version_code: "190500",
            version_name: "19.5.0",
        });
        const response = await fetch(
            `https://api.amemv.com/aweme/v1/aweme/detail/?${params.toString()}`,
            {
                method: "GET",
                redirect: "follow",
                headers: {
                    "user-agent": MOBILE_UA,
                    accept: "application/json, text/plain, */*",
                },
                signal: controller.signal,
            }
        );
        if (!response.ok) {
            await response.body?.cancel().catch(() => {});
            return {ok: false, reason: `详情接口 HTTP ${response.status}`};
        }
        const json = await response.json().catch(() => null);
        if (!json || typeof json !== "object" || Array.isArray(json) || typeof json.status_code !== "number") {
            return {ok: false, reason: "详情接口缺少有效 status_code"};
        }
        if (json.status_code !== 0) {
            return {ok: false, reason: `详情接口 status_code=${json.status_code}`};
        }
        const detail = json.aweme_detail;
        if (!detail || typeof detail !== "object" || Array.isArray(detail)) {
            return {ok: false, reason: "详情接口未返回 aweme_detail（不能据此断言作品已删除）"};
        }
        if (String(detail.aweme_id || "") !== awemeId) {
            return {ok: false, reason: "详情接口返回的作品 ID 不匹配"};
        }
        // 保留完整上游对象，继续交给原有媒体选取/格式化流程；此处不裁剪图文和 Live Photo。
        return {ok: true, detail: json};
    } catch (error) {
        return {
            ok: false,
            reason: controller.signal.aborted ? "详情请求超时" : `详情请求失败：${safeErrorMessage(error)}`,
        };
    } finally {
        clearTimeout(timer);
    }
}

async function buildLegacyFormatData(detail, fallbackVideoId) {
    const title = stringValue(detail?.desc);

    const authorArr = isObject(detail?.author) ? detail.author : {};
    const avatarThumb = isObject(authorArr.avatar_thumb) ? authorArr.avatar_thumb : {};
    const avatars = Array.isArray(avatarThumb.url_list) ? avatarThumb.url_list : [];
    const author = {
        name: stringValue(authorArr.nickname),
        id: stringValue(authorArr.uid || authorArr.unique_id || authorArr.short_id),
        avatar: stringValue(avatars[0]),
    };

    const music = isObject(detail?.music) ? detail.music : {};
    const musicPlay = isObject(music.play_url) ? music.play_url : {};
    const musicCoverSource = isObject(music.cover_thumb)
        ? music.cover_thumb
        : isObject(music.cover_thumb_medium)
            ? music.cover_thumb_medium
            : {};
    const musicOut = {
        title: stringValue(music.title || music.music_name),
        author: stringValue(music.author || music.owner_nickname),
        url: toHttps(stringValue(Array.isArray(musicPlay.url_list) ? musicPlay.url_list[0] : "")) || "",
        cover:
            toHttps(
                stringValue(
                    Array.isArray(musicCoverSource.url_list) ? musicCoverSource.url_list[0] : ""
                )
            ) || "",
    };

    const video = isObject(detail?.video) ? detail.video : null;
    // 契约要求秒：抖音原始 duration 为毫秒，>=1000 视为毫秒归一为秒
    let duration = video && Number.isFinite(Number(video.duration))
        ? Number(video.duration)
        : null;
    if (duration !== null && duration >= 1000) {
        duration = Math.round(duration / 1000 * 100) / 100;
    }

    const createTime = normalizeCreateTime(detail?.create_time ?? detail?.createTime);

    const result = {
        type: "unknown",
        title,
        desc: title,
        author,
        cover: "",
        url: null,
        quality: "",
        duration,
        size: 0,
        size_label: "",
        create_time: createTime,
        publish_time: formatPublishTime(createTime),
        video_backup: [],
        images: [],
        live_photo: [],
        music: musicOut,
        extra: {},
    };

    let images = Array.isArray(detail?.images) ? detail.images : [];
    if (!images.length && Array.isArray(detail?.image_list)) {
        images = detail.image_list;
    }

    if (images.length) {
        result.type = "image";
        const liveCandidates = [];
        for (const img of images) {
            if (!isObject(img)) {
                continue;
            }

            const imgUrl = pickImageListItemUrl(img);
            if (imgUrl) {
                result.images.push(imgUrl);
            }

            const videoInfo = isObject(img.video) ? img.video : {};
            let liveVideoUrl = extractLivePhotoVideoUrl(videoInfo);
            if (liveVideoUrl) {
                liveVideoUrl = toHttps(liveVideoUrl.replace(/playwm/g, "play")) || "";
            }
            if (imgUrl && liveVideoUrl) {
                liveCandidates.push({
                    image: imgUrl,
                    fallbackUrl: liveVideoUrl,
                    vid: extractVidFromVideoInfo(videoInfo),
                });
            }
        }

        // 多视频 vid 全部转 302 后的原画直链，拿不到的回退原地址
        const vidMap = await resolveVidBatch(liveCandidates.map((item) => item.vid));
        for (const item of liveCandidates) {
            const resolved = item.vid ? vidMap.get(item.vid) : null;
            result.live_photo.push({image: item.image, video: ensureVideoMimeType(resolved || item.fallbackUrl)});
        }

        if (result.live_photo.length) {
            result.type = "live";
        }
    } else {
        result.type = "video";
        const videoInfo = extractHighestQualityVideo(detail);

        let playUri = extractVidFromVideoInfo(video);
        let main = videoInfo.url;
        if (main) {
            main = toHttps(main.replace(/playwm/g, "play"));
        }

        // 原画优先：拿 vid 302 后的原画直链作为主地址；失败则回退原来的 main
        const resolvedOriginal = await resolveOriginalVideoUrl(playUri);
        if (resolvedOriginal) {
            if (main && main !== resolvedOriginal) {
                videoInfo.backup.unshift(main);
            }
            main = resolvedOriginal;
            // 只要主链接是原画 302 出来的，画质统一标 original
            result.quality = "original";
        } else if (videoInfo.gearName) {
            result.quality = videoInfo.gearName;
        }

        // 补齐 mime_type=video_mp4；主地址先归一，保证下面去重比较口径一致
        main = ensureVideoMimeType(main);

        if (main) {
            result.url = main;
            result.size = await probeVideoSize(main);
            result.size_label = formatSizeLabel(result.size);
        }

        const backups = [];
        for (const candidate of videoInfo.backup) {
            const converted = ensureVideoMimeType(toHttps(candidate.replace(/playwm/g, "play")));
            if (converted && converted !== result.url && !backups.includes(converted)) {
                backups.push(converted);
            }
        }
        result.video_backup = backups;

        result.video_id = playUri || fallbackVideoId;
    }

    const cover = pickCover(detail);
    result.cover = cover ? toHttps(cover) || "" : "";

    return result;
}

function pickImageListItemUrl(img) {
    let raw = "";
    if (Array.isArray(img.urlList) && typeof img.urlList[0] === "string") {
        raw = img.urlList[0];
    } else if (Array.isArray(img.url_list) && typeof img.url_list[0] === "string") {
        raw = img.url_list[0];
    } else if (Array.isArray(img.url_list) && img.url_list.length) {
        const last = img.url_list[img.url_list.length - 1];
        raw = typeof last === "string" ? last : "";
    }

    return raw ? toHttps(raw) : null;
}

function extractLivePhotoVideoUrl(videoInfo) {
    let liveVideoUrl = null;
    let v26Candidate = null;

    if (Array.isArray(videoInfo.playAddr)) {
        for (const addr of videoInfo.playAddr) {
            if (!isObject(addr) || typeof addr.src !== "string") {
                continue;
            }
            if (addr.src.includes("v3-web")) {
                liveVideoUrl = addr.src;
                break;
            }
            if (!v26Candidate && addr.src.includes("v26-web")) {
                v26Candidate = addr.src;
            }
        }

        if (!liveVideoUrl && v26Candidate) {
            liveVideoUrl = v26Candidate.replace(/:\/\/([^/]+)/, "://v26-luna.douyinvod.com");
        }

        if (!liveVideoUrl) {
            if (isObject(videoInfo.playAddr[1]) && typeof videoInfo.playAddr[1].src === "string") {
                liveVideoUrl = videoInfo.playAddr[1].src;
            } else if (isObject(videoInfo.playAddr[0]) && typeof videoInfo.playAddr[0].src === "string") {
                liveVideoUrl = videoInfo.playAddr[0].src;
            }
        }
    }

    if (!liveVideoUrl && isObject(videoInfo.play_addr) && Array.isArray(videoInfo.play_addr.url_list)) {
        let v26 = null;
        for (const candidate of videoInfo.play_addr.url_list) {
            if (typeof candidate !== "string") {
                continue;
            }
            if (candidate.includes("v3-web")) {
                liveVideoUrl = candidate;
                break;
            }
            if (!v26 && candidate.includes("v26-web")) {
                v26 = candidate;
            }
        }

        if (!liveVideoUrl && v26) {
            liveVideoUrl = v26.replace(/:\/\/([^/]+)/, "://v26-luna.douyinvod.com");
        }

        if (!liveVideoUrl) {
            if (typeof videoInfo.play_addr.url_list[1] === "string") {
                liveVideoUrl = videoInfo.play_addr.url_list[1];
            } else if (typeof videoInfo.play_addr.url_list[0] === "string") {
                liveVideoUrl = videoInfo.play_addr.url_list[0];
            }
        }
    }

    if (!liveVideoUrl && typeof videoInfo.playApi === "string" && videoInfo.playApi) {
        liveVideoUrl = videoInfo.playApi;
    }

    return liveVideoUrl || null;
}

function collectPlayUrlCandidatesFromBitRateItem(rateItem) {
    const candidates = [];

    if (Array.isArray(rateItem.playAddr)) {
        for (const item of rateItem.playAddr) {
            if (isObject(item) && typeof item.src === "string" && item.src) {
                candidates.push(item.src);
            }
        }
    }

    if (!candidates.length && isObject(rateItem.play_addr) && Array.isArray(rateItem.play_addr.url_list)) {
        for (const item of rateItem.play_addr.url_list) {
            if (typeof item === "string" && item) {
                candidates.push(item);
            }
        }
    }

    return candidates;
}

function extractHighestQualityVideo(detail) {
    const video = isObject(detail?.video) ? detail.video : {};
    let bitRateList = null;

    if (Array.isArray(video.bitRateList) && video.bitRateList.length) {
        bitRateList = [...video.bitRateList];
    } else if (Array.isArray(video.bit_rate) && video.bit_rate.length) {
        bitRateList = [...video.bit_rate];
    }

    let url = null;
    let gearName = "";
    const backup = [];

    if (bitRateList) {
        bitRateList.sort((a, b) => {
            const ba = isObject(a) ? Number(a.bitRate || a.bit_rate || 0) : 0;
            const bb = isObject(b) ? Number(b.bitRate || b.bit_rate || 0) : 0;
            return bb - ba;
        });

        for (const rateItem of bitRateList) {
            if (!isObject(rateItem)) {
                continue;
            }

            const candidates = collectPlayUrlCandidatesFromBitRateItem(rateItem);
            if (!candidates.length) {
                continue;
            }

            let v3Link = null;
            let v26Link = null;
            for (const candidate of candidates) {
                if (candidate.includes("v3-web")) {
                    v3Link = candidate;
                    break;
                }
                if (!v26Link && candidate.includes("v26-web")) {
                    v26Link = candidate;
                }
            }

            const currentBestUrl = v3Link
                ? v3Link
                : v26Link
                    ? v26Link.replace(/:\/\/([^/]+)/, "://v26-luna.douyinvod.com")
                    : candidates[0];

            if (!url) {
                url = currentBestUrl;
                gearName = stringValue(rateItem.gearName || rateItem.gear_name || "");
            }

            for (let candidate of candidates) {
                if (candidate.includes("v26-web")) {
                    candidate = candidate.replace(/:\/\/([^/]+)/, "://v26-luna.douyinvod.com");
                }
                if (candidate !== url && !backup.includes(candidate)) {
                    backup.push(candidate);
                }
            }

            if (url && backup.length) {
                break;
            }
        }
    }

    if (!url) {
        let uri = stringValue(video.uri);
        if (!uri && isObject(video.play_addr) && video.play_addr.uri) {
            uri = String(video.play_addr.uri);
        }

        let playApi =
            typeof video.playApi === "string" && video.playApi
                ? video.playApi
                : isObject(video.play_addr) && Array.isArray(video.play_addr.url_list) && typeof video.play_addr.url_list[0] === "string"
                    ? video.play_addr.url_list[0]
                    : "";

        if (playApi) {
            url = playApi.replace(/playwm/g, "play");
        } else if (uri) {
            // 原画兜底地址：buildLegacyFormatData 会用 vid 走 resolveOriginalVideoUrl 做 302 解析，失败回退此地址
            url = buildOriginalPlayUrl(uri);
        }

        const urlList = isObject(video.play_addr) && Array.isArray(video.play_addr.url_list)
            ? video.play_addr.url_list
            : [];
        if (urlList.length > 1) {
            urlList.forEach((link, index) => {
                if (index === 0 || typeof link !== "string") {
                    return;
                }
                const converted = link.replace(/playwm/g, "play");
                if (converted && converted !== url && !backup.includes(converted)) {
                    backup.push(converted);
                }
            });
        }
    }

    return {url, backup, gearName};
}

// 统一响应契约辅助：1024 进制 B/KB/MB/GB/TB，最多两位小数且不保留尾部 0
function formatSizeLabel(bytes) {
    const size = Number(bytes);
    if (!Number.isFinite(size) || size <= 0) {
        return "";
    }
    const units = ["B", "KB", "MB", "GB", "TB"];
    let value = size;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    if (unit === 0) {
        return `${Math.round(value)}B`;
    }
    const label = value.toFixed(2).replace(/\.?0+$/, "");
    return `${label}${units[unit]}`;
}

// 发布时间归一化：秒级直用，毫秒级（>=1e12）除一次 1000，非法/超范围归 0
function normalizeCreateTime(value) {
    let ts = Number(value);
    if (!Number.isFinite(ts) || ts <= 0) {
        return 0;
    }
    if (ts >= 1000000000000) {
        ts = Math.floor(ts / 1000);
    } else {
        ts = Math.floor(ts);
    }
    if (ts < 1000000000 || ts > 4102444800) {
        return 0;
    }
    return ts;
}

// 固定东八区格式化为 YYYY-MM-DD HH:mm:ss，未知返回空串
function formatPublishTime(createTime) {
    if (!createTime) {
        return "";
    }
    try {
        const formatter = new Intl.DateTimeFormat("zh-CN", {
            timeZone: "Asia/Shanghai",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false,
        });
        const parts = formatter.formatToParts(new Date(createTime * 1000));
        const get = (type) => (parts.find((p) => p.type === type) || {}).value || "";
        return `${get("year")}-${get("month")}-${get("day")} ${get("hour")}:${get("minute")}:${get("second")}`;
    } catch {
        return "";
    }
}

// 有界探测主视频真实字节大小：单次 HEAD、不下载正文，拿不到一律 0（契约允许未知）
async function probeVideoSize(url) {
    if (!url) {
        return 0;
    }
    try {
        const response = await fetch(url, {
            method: "HEAD",
            headers: {"user-agent": DEFAULT_UA},
        });
        if (!response.ok) {
            return 0;
        }
        const contentType = (response.headers.get("content-type") || "").toLowerCase();
        if (!contentType.includes("video") && !contentType.includes("octet-stream")) {
            return 0;
        }
        const length = Number(response.headers.get("content-length"));
        return Number.isFinite(length) && length > 0 ? Math.floor(length) : 0;
    } catch {
        return 0;
    }
}

function pickCover(detail) {
    const video = isObject(detail?.video) ? detail.video : null;

    if (video) {
        if (Array.isArray(video.originCover?.urlList) && video.originCover.urlList[0]) {
            return String(video.originCover.urlList[0]);
        }
        if (Array.isArray(video.origin_cover?.url_list) && video.origin_cover.url_list[0]) {
            return String(video.origin_cover.url_list[0]);
        }
        if (typeof video.originCover === "string") {
            return video.originCover;
        }
        if (Array.isArray(video.originCoverUrlList) && video.originCoverUrlList[0]) {
            return String(video.originCoverUrlList[0]);
        }

        if (isObject(video.cover)) {
            const line = video.cover.urlList?.[0] || video.cover.url_list?.[0] || "";
            if (typeof line === "string" && line) {
                return line;
            }
        }

        if (typeof video.cover === "string") {
            return video.cover;
        }
    }

    if (Array.isArray(detail?.cover?.url_list) && detail.cover.url_list[0]) {
        return String(detail.cover.url_list[0]);
    }

    if (video) {
        if (Array.isArray(video.dynamicCover?.urlList) && video.dynamicCover.urlList[0]) {
            return String(video.dynamicCover.urlList[0]);
        }
        if (Array.isArray(video.dynamic_cover?.url_list) && video.dynamic_cover.url_list[0]) {
            return String(video.dynamic_cover.url_list[0]);
        }
    }

    if (detail?.videoInfoRes?.item_list?.[0]?.video?.cover?.url_list?.[0]) {
        return String(detail.videoInfoRes.item_list[0].video.cover.url_list[0]);
    }

    let images = Array.isArray(detail?.images) ? detail.images : [];
    if (!images.length && Array.isArray(detail?.image_list)) {
        images = detail.image_list;
    }
    if (images.length && isObject(images[0]) && Array.isArray(images[0].url_list) && images[0].url_list[0]) {
        return String(images[0].url_list[0]);
    }

    return null;
}

function toHttps(url) {
    if (!url) {
        return null;
    }
    return url.startsWith("http://") ? `https://${url.slice(7)}` : url;
}

function host(url) {
    try {
        return new URL(url).host.toLowerCase();
    } catch {
        return null;
    }
}

function isObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function stringValue(value) {
    return typeof value === "string" ? value : value == null ? "" : String(value);
}

// 补齐视频直链的 mime_type=video_mp4（抖音 CDN 约定参数）：已存在该参数则原样返回。
// 只对 http(s) 地址生效；只做字符串追加，不用 URL 对象重写查询串——
// 抖音 CDN 直链带签名参数，重写会改变参数顺序与编码，可能破坏签名。
function ensureVideoMimeType(url) {
    const value = stringValue(url);
    if (!value || !/^https?:\/\//i.test(value)) {
        return value;
    }

    // fragment 不参与查询串判断，追加时要放在 fragment 之前
    const hashIndex = value.indexOf("#");
    const base = hashIndex === -1 ? value : value.slice(0, hashIndex);
    const fragment = hashIndex === -1 ? "" : value.slice(hashIndex);

    const questionIndex = base.indexOf("?");
    if (questionIndex !== -1) {
        for (const pair of base.slice(questionIndex + 1).split("&")) {
            if (safeDecode(pair.split("=")[0]) === "mime_type") {
                return value;
            }
        }
        return `${base}&mime_type=video_mp4${fragment}`;
    }

    return `${base}?mime_type=video_mp4${fragment}`;
}

function safeDecode(value) {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}
