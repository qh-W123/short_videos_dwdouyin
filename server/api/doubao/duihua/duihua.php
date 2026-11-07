<?php
/**
*@Author: JH-Ahua
*@CreateTime: 2026/4/24 17:15
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 豆包提取对话无水印图片
*/
header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $extra = [])
{
    echo json_encode(array_merge([
        'code' => $code,
        'msg' => $msg
    ], $extra), 480);
    exit;
}

function decode_json_array($json)
{
    if (!is_string($json) || $json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function sanitize_video_url($url)
{
    if (!is_string($url) || $url === '') {
        return '';
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    // 常见水印相关参数，移除后可拿到更干净的直链
    unset($query['lr'], $query['logo_type'], $query['download']);

    $sanitized = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    if (!empty($query)) {
        $sanitized .= '?' . http_build_query($query);
    }
    return $sanitized;
}

function is_watermarked_video_url($url)
{
    if (!is_string($url) || $url === '') {
        return false;
    }

    $lower = strtolower($url);
    $watermarkMarkers = [
        'video_gen_watermark',
        'watermark_dyn',
        'logo_type=video_gen_watermark'
    ];

    foreach ($watermarkMarkers as $marker) {
        if (strpos($lower, $marker) !== false) {
            return true;
        }
    }
    return false;
}

function extract_video_urls($video)
{
    $urls = [];

    // 1) 先收集外层 download_url（作为兜底）
    $downloadUrl = arr_get($video, ['download_url'], '');
    if ($downloadUrl !== '') {
        $sanitized = sanitize_video_url($downloadUrl);
        if (!is_watermarked_video_url($sanitized)) {
            $urls[] = $sanitized;
        }
    }

    // 2) video_model 里可能有更完整的直链（main_url/backup_url_1 是 base64）
    $videoModelRaw = arr_get($video, ['video_model'], '');
    $videoModel = decode_json_array($videoModelRaw);
    if (is_array($videoModel)) {
        $videoList = arr_get($videoModel, ['video_list'], []);
        if (is_array($videoList)) {
            foreach ($videoList as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                foreach (['main_url', 'backup_url_1'] as $k) {
                    $encoded = $variant[$k] ?? '';
                    if (!is_string($encoded) || $encoded === '') {
                        continue;
                    }
                    $decoded = base64_decode($encoded, true);
                    if (!is_string($decoded) || $decoded === '') {
                        continue;
                    }
                    $sanitized = sanitize_video_url($decoded);
                    if (!is_watermarked_video_url($sanitized)) {
                        $urls[] = $sanitized;
                    }
                }
            }
        }
    }

    // 去重
    $unique = [];
    $seen = [];
    foreach ($urls as $u) {
        if ($u === '' || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $unique[] = $u;
    }
    return $unique;
}

$url = $_REQUEST['url'] ?? '';
if (empty($url)) {
    respond(400, '缺少url参数');
}

$headers = [
    'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'
];

// 你的Cookie（保持不变）
$cookies = '';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_COOKIE, $cookies);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$html = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($html === false || $html === '') {
    respond(500, $curlErr !== '' ? ('抓取失败: ' . $curlErr) : '网页内容为空，抓取失败');
}

// 使用 DOM 解析 script 标签，避免正则在超长/跨行属性里截断。
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();

$scripts = $dom->getElementsByTagName('script');
$rawFnArgs = '';
foreach ($scripts as $script) {
    if (!($script instanceof DOMElement)) {
        continue;
    }
    if (!$script->hasAttribute('data-fn-args')) {
        continue;
    }
    $candidate = $script->getAttribute('data-fn-args');
    if ($candidate === '') {
        continue;
    }
    // 优先匹配你给的这个路由脚本
    if (
        $script->getAttribute('data-fn-name') === 'r'
        && $script->getAttribute('data-script-src') === 'modern-run-router-data-fn'
    ) {
        $rawFnArgs = $candidate;
        break;
    }
    // 兜底：先记住第一个 data-fn-args
    if ($rawFnArgs === '') {
        $rawFnArgs = $candidate;
    }
}

if ($rawFnArgs === '') {
    respond(404, '未找到 script[data-fn-args] 属性');
}

// data-fn-args 在 HTML 中通常是 &quot; 实体编码，这里先还原。
$decodedFnArgs = html_entity_decode($rawFnArgs, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// data-fn-args 本身是一个 JSON 字符串（数组），例如：
// ["thread_(token)/page","shareInfo",{...}]
$parsedFnArgs = decode_json_array($decodedFnArgs);

if ($parsedFnArgs === null) {
    respond(422, 'data-fn-args 不是合法 JSON', [
        'data_fn_args_raw' => $rawFnArgs,
        'data_fn_args_decoded' => $decodedFnArgs
    ]);
}

/**
 * 安全获取数组路径值
 */
function arr_get($arr, $path, $default = null)
{
    $cur = $arr;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return $cur;
}

/**
 * 从任意层级递归提取 creations
 */
function collect_creations($node, &$out)
{
    if (!is_array($node)) {
        return;
    }

    if (isset($node['creation_block']['creations']) && is_array($node['creation_block']['creations'])) {
        foreach ($node['creation_block']['creations'] as $c) {
            if (is_array($c)) {
                $out[] = $c;
            }
        }
    }

    if (isset($node['creations']) && is_array($node['creations'])) {
        foreach ($node['creations'] as $c) {
            if (is_array($c)) {
                $out[] = $c;
            }
        }
    }

    foreach ($node as $v) {
        if (is_array($v)) {
            collect_creations($v, $out);
        }
    }
}

/**
 * 解析 message 的 content / content_v2 字符串 JSON，并提取 creation
 */
function collect_creations_from_message($message, &$out)
{
    if (!is_array($message)) {
        return;
    }

    // 直接在 message 本体找
    collect_creations($message, $out);

    // content: 通常是一个 JSON 字符串（数组）
    if (!empty($message['content']) && is_string($message['content'])) {
        $decoded = decode_json_array($message['content']);
        if ($decoded !== null) {
            collect_creations($decoded, $out);
        }
    }

    // content_block 下每个 block 的 content / content_v2 也可能是字符串 JSON
    if (!empty($message['content_block']) && is_array($message['content_block'])) {
        foreach ($message['content_block'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            collect_creations($block, $out);

            if (!empty($block['content']) && is_string($block['content'])) {
                $decoded = decode_json_array($block['content']);
                if ($decoded !== null) {
                    collect_creations($decoded, $out);
                }
            }
            if (!empty($block['content_v2']) && is_string($block['content_v2'])) {
                $decoded = decode_json_array($block['content_v2']);
                if ($decoded !== null) {
                    collect_creations($decoded, $out);
                }
            }
        }
    }
}

$payload = isset($parsedFnArgs[2]) && is_array($parsedFnArgs[2]) ? $parsedFnArgs[2] : [];
$data = arr_get($payload, ['data'], []);
$messageList = arr_get($data, ['message_snapshot', 'message_list'], []);

$allCreations = [];
if (is_array($messageList)) {
    foreach ($messageList as $msg) {
        collect_creations_from_message($msg, $allCreations);
    }
}
// 提取无水印图片（image_ori_raw.url）和视频（video.download_url）
$images = [];
$videos = [];
$seenImage = [];
$seenVideo = [];
foreach ($allCreations as $c) {
    // 图片
    if (isset($c['image']) && is_array($c['image'])) {
        $img = $c['image'];
        $rawUrl = arr_get($img, ['image_ori_raw', 'url'], '');
        if ($rawUrl !== '' && !isset($seenImage[$rawUrl])) {
            $seenImage[$rawUrl] = true;
            $images[] = $rawUrl;
        }
    }

    // 视频
    if (isset($c['video']) && is_array($c['video'])) {
        $videoUrls = extract_video_urls($c['video']);
        foreach ($videoUrls as $videoUrl) {
            if (!isset($seenVideo[$videoUrl])) {
                $seenVideo[$videoUrl] = true;
                $videos[] = $videoUrl;
            }
        }
    }
}

respond(
    200,
    (count($images) > 0 || count($videos) > 0) ? 'ok' : '当前分享数据仅包含水印资源，未发现可用无水印直链',
    [
        'images' => $images,
        'videos' => $videos
    ]
);
?>