using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace DwDouyin.Core
{
    /// <summary>解析结果中的单条媒体（一个清晰度档位或一张图）。</summary>
    public class MediaEntry
    {
        public string Label { get; set; } = "";
        public string Url { get; set; } = "";
        public long SizeBytes { get; set; }
        public string Wh { get; set; } = "";
        public string Grade { get; set; } = "";
    }

    /// <summary>解析出的作品信息。</summary>
    public class WorkInfo
    {
        public string AwemeId { get; set; } = "";
        public string ShareUrl { get; set; } = "";
        public string WorkType { get; set; } = "video";   // video / images
        public string Title { get; set; } = "";
        public string Author { get; set; } = "";
        public string SecUid { get; set; } = "";
        public string CoverUrl { get; set; } = "";
        public string MusicTitle { get; set; } = "";
        public string MusicUrl { get; set; } = "";
        public List<MediaEntry> MediaList { get; set; } = new();
        public long DurationMs { get; set; }
        public string SizeLabel { get; set; } = "";
    }

    public class ParseException : Exception
    {
        public string Kind { get; }
        public ParseException(string message, string kind = "parse_failed") : base(message) { Kind = kind; }
    }

    /// <summary>
    /// 解析引擎：链接提取 → 短链跳转 → 解析服务器 API（地址可在设置页改，默认见 DefaultApiBase）。
    /// 返回统一 JSON：{code:200, msg, data:{url, title, author{name}, cover, video_backup[], images[]}}
    /// </summary>
    public static class Parser
    {
        // 自建解析服务器（Ubuntu 虚拟机 Docker 部署的 short_videos）
        public const string DefaultApiBase = "http://127.0.0.1";
        public const string DefaultApiPath = "/api/douyin/douyin.php";
        public static string ApiBase { get; set; } = DefaultApiBase;
        public static string ApiPath { get; set; } = DefaultApiPath;

        /// <summary>拼出完整请求地址（路径留空时用默认路径）。</summary>
        public static string BuildRequestUrl(string link)
        {
            var b = (ApiBase ?? "").Trim().TrimEnd('/');
            var p = (ApiPath ?? "").Trim();
            if (string.IsNullOrEmpty(p)) p = DefaultApiPath;
            if (!p.StartsWith("/")) p = "/" + p;
            return $"{b}{p}?url={link}";
        }

        const string MobileUa =
            "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) " +
            "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1";

        static readonly HttpClient Http = CreateHttp();

        static HttpClient CreateHttp()
        {
            var handler = new HttpClientHandler
            {
                AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
                AllowAutoRedirect = false,
            };
            return new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(20) };
        }

        static readonly Regex ShortLinkPattern =
            new(@"https?://v\.(?:douyin|iesdouyin)\.com/[A-Za-z0-9_\-]+/?", RegexOptions.Compiled);
        static readonly Regex WebLinkPattern =
            new(@"https?://(?:www\.)?douyin\.com/(?:video|note|slides|gallery)/(\d+)", RegexOptions.Compiled);
        static readonly Regex ModalIdPattern =
            new(@"[?&]modal_id=(\d+)", RegexOptions.Compiled);
        static readonly Regex AwemeInPathPattern =
            new(@"/(?:share/)?(?:video|note|slides|gallery)/(\d+)", RegexOptions.Compiled);

        /// <summary>从混杂文本提取第一条作品链接。</summary>
        public static string ExtractLink(string text)
        {
            if (string.IsNullOrWhiteSpace(text)) return null;
            var m = ShortLinkPattern.Match(text);
            if (m.Success) return m.Value;
            m = ModalIdPattern.Match(text);
            if (m.Success) return $"https://www.douyin.com/video/{m.Groups[1].Value}";
            m = WebLinkPattern.Match(text);
            if (m.Success) return $"https://www.douyin.com/video/{m.Groups[1].Value}";
            return null;
        }

        /// <summary>从多行文本里按行提取全部链接（批量解析用），去重保序。</summary>
        public static List<string> ExtractLinks(string text)
        {
            var result = new List<string>();
            if (string.IsNullOrWhiteSpace(text)) return result;
            var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

            // 先按行切；单行内可能塞了多段（分享文案连写），再逐行扫全部匹配
            foreach (var rawLine in text.Replace("\r\n", "\n").Replace('\r', '\n').Split('\n'))
            {
                var line = rawLine.Trim();
                if (line.Length == 0) continue;

                bool any = false;
                foreach (Match sm in ShortLinkPattern.Matches(line))
                {
                    // 短链保留结尾斜杠：服务器端 extractShareUrl 依赖它做重定向解析，
                    // 去掉后被当成网页链接处理会返回 500。
                    var u = sm.Value;
                    if (seen.Add(u)) result.Add(u);
                    any = true;
                }
                foreach (Match wm in WebLinkPattern.Matches(line))
                {
                    var u = $"https://www.douyin.com/video/{wm.Groups[1].Value}";
                    if (seen.Add(u)) result.Add(u);
                    any = true;
                }
                if (any) continue;

                foreach (Match mm in ModalIdPattern.Matches(line))
                {
                    var u = $"https://www.douyin.com/video/{mm.Groups[1].Value}";
                    if (seen.Add(u)) result.Add(u);
                }
            }
            return result;
        }

        /// <summary>短链/网页链接 → aweme_id。短链读 302 Location（无风控）。</summary>
        public static async Task<string> ResolveAwemeIdAsync(string link)
        {
            var m = WebLinkPattern.Match(link);
            if (m.Success) return m.Groups[1].Value;

            using var req = new HttpRequestMessage(HttpMethod.Get, link);
            req.Headers.TryAddWithoutValidation("User-Agent", MobileUa);
            var resp = await Http.SendAsync(req).ConfigureAwait(false);
            string location = link;
            if ((int)resp.StatusCode is 301 or 302 or 303 or 307 or 308)
            {
                var loc = resp.Headers.Location;
                if (loc != null) location = loc.IsAbsoluteUri ? loc.AbsoluteUri : loc.ToString();
            }
            m = AwemeInPathPattern.Match(location);
            if (!m.Success) throw new ParseException("作品失效或短链接无效", "gone");
            return m.Groups[1].Value;
        }

        /// <summary>解析主入口：文本 → WorkInfo（取第一条链接）。</summary>
        public static async Task<WorkInfo> ParseWorkAsync(string text)
        {
            var link = ExtractLink(text);
            if (link == null)
                throw new ParseException("未从粘贴文本中提取到抖音链接", "no_link");
            return await ParseLinkAsync(link).ConfigureAwait(false);
        }

        /// <summary>解析单条链接 → WorkInfo。</summary>
        public static async Task<WorkInfo> ParseLinkAsync(string link)
        {
            var awemeId = await ResolveAwemeIdAsync(link).ConfigureAwait(false);

            // 自建服务器：/api/douyin/douyin.php?url={作品链接}
            // douyin.php 从 REQUEST_URI 原样截取 url= 之后内容，无需 urlencode
            var apiUrl = BuildRequestUrl(link);
            string json;
            int status;
            try
            {
                using var req = new HttpRequestMessage(HttpMethod.Get, apiUrl);
                req.Headers.TryAddWithoutValidation("User-Agent",
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0 Safari/537.36");
                using var resp = await Http.SendAsync(req).ConfigureAwait(false);
                status = (int)resp.StatusCode;
                json = await resp.Content.ReadAsStringAsync().ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                throw new ParseException($"解析服务器连接失败：{ex.Message}", "network");
            }

            if (string.IsNullOrWhiteSpace(json))
            {
                throw new ParseException(
                    status == 500
                        ? "服务器内部错误，换个链接再试一次"
                        : $"解析服务器没有返回内容（HTTP {status}）",
                    "parse_failed");
            }

            JsonDocument doc;
            try
            {
                doc = JsonDocument.Parse(json);
            }
            catch (JsonException)
            {
                throw new ParseException($"解析服务器返回了非 JSON 内容（HTTP {status}）", "parse_failed");
            }

            using (doc)
            {
                var root = doc.RootElement;
                if (root.ValueKind != JsonValueKind.Object)
                    throw new ParseException("解析服务器返回数据无效", "parse_failed");
                if (!root.TryGetProperty("code", out var codeEl) || codeEl.ValueKind != JsonValueKind.Number)
                    throw new ParseException("解析服务器返回数据无效", "parse_failed");
                if (codeEl.GetInt32() != 200)
                {
                    var msg = root.TryGetProperty("msg", out var msgEl) && msgEl.ValueKind == JsonValueKind.String
                        ? msgEl.GetString()
                        : "解析失败";
                    throw new ParseException($"服务器返回：{msg}", "parse_failed");
                }
                if (!root.TryGetProperty("data", out var dataEl) || dataEl.ValueKind != JsonValueKind.Object)
                    throw new ParseException("服务器返回数据无效", "parse_failed");

                return BuildWork(awemeId, link, dataEl);
            }
        }

        static WorkInfo BuildWork(string awemeId, string link, JsonElement d)
        {
            var work = new WorkInfo
            {
                AwemeId = awemeId,
                ShareUrl = link,
                Title = GetStr(d, "title") ?? GetStr(d, "desc") ?? "无标题",
                Author = GetNestedStr(d, "author", "name") ?? "未知作者",
                CoverUrl = GetStr(d, "cover") ?? "",
            };

            // 音乐
            if (d.TryGetProperty("music", out var music) && music.ValueKind == JsonValueKind.Object)
            {
                work.MusicTitle = GetStr(music, "title") ?? "";
                work.MusicUrl = GetStr(music, "url") ?? "";
            }

            // 时长 / 大小
            if (d.TryGetProperty("duration", out var dur) && dur.ValueKind == JsonValueKind.Number)
                work.DurationMs = (long)(dur.GetDouble() * 1000);
            work.SizeLabel = GetStr(d, "size_label") ?? "";

            // 图文
            if (d.TryGetProperty("images", out var images) &&
                images.ValueKind == JsonValueKind.Array && images.GetArrayLength() > 0)
            {
                work.WorkType = "images";
                int i = 1;
                foreach (var img in images.EnumerateArray())
                {
                    string u = img.ValueKind == JsonValueKind.String ? img.GetString() : null;
                    if (!string.IsNullOrEmpty(u))
                        work.MediaList.Add(new MediaEntry { Label = $"图片{i++}", Url = u });
                }
                if (work.MediaList.Count == 0)
                    throw new ParseException("作品失效或已被删除", "gone");
                return work;
            }

            // 视频：主直链 + video_backup 多档
            var mainUrl = GetStr(d, "url") ?? "";
            if (string.IsNullOrEmpty(mainUrl))
                throw new ParseException("作品失效或已被删除", "gone");

            var mainEntry = new MediaEntry
            {
                Label = "无水印 原画",
                Url = mainUrl,
                SizeBytes = GetLong(d, "size"),
                Grade = GetStr(d, "quality") ?? "",
            };
            work.MediaList.Add(mainEntry);

            if (d.TryGetProperty("video_backup", out var backup) && backup.ValueKind == JsonValueKind.Array)
            {
                int bi = 0;
                foreach (var vb in backup.EnumerateArray())
                {
                    bi++;
                    string u = null;
                    string label = null, grade = null, wh = "";
                    long size = 0;
                    if (vb.ValueKind == JsonValueKind.String)
                    {
                        u = vb.GetString();
                    }
                    else if (vb.ValueKind == JsonValueKind.Object)
                    {
                        u = GetStr(vb, "url");
                        label = GetStr(vb, "label") ?? GetStr(vb, "quality");
                        grade = GetStr(vb, "quality") ?? "";
                        size = GetLong(vb, "size");
                        wh = GetWh(vb);
                    }
                    if (string.IsNullOrEmpty(u) || u == mainUrl) continue;
                    work.MediaList.Add(new MediaEntry
                    {
                        Label = label ?? $"备用线路{bi}",
                        Url = u,
                        SizeBytes = size,
                        Wh = wh,
                        Grade = grade,
                    });
                }
            }
            return work;
        }

        static string GetStr(JsonElement el, string name) =>
            el.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;

        static long GetLong(JsonElement el, string name)
        {
            if (el.TryGetProperty(name, out var v))
            {
                if (v.ValueKind == JsonValueKind.Number && v.TryGetInt64(out var l)) return l;
                if (v.ValueKind == JsonValueKind.String && long.TryParse(v.GetString(), out var s)) return s;
            }
            return 0;
        }

        static string GetNestedStr(JsonElement el, string obj, string name) =>
            el.TryGetProperty(obj, out var o) && o.ValueKind == JsonValueKind.Object ? GetStr(o, name) : null;

        static string GetWh(JsonElement el)
        {
            int w = 0, h = 0;
            if (el.TryGetProperty("width", out var we) && we.ValueKind == JsonValueKind.Number) w = we.GetInt32();
            if (el.TryGetProperty("height", out var he) && he.ValueKind == JsonValueKind.Number) h = he.GetInt32();
            return (w > 0 || h > 0) ? $"{w}x{h}" : "";
        }
    }
}
