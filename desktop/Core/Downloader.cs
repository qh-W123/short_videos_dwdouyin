using System;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Threading;
using System.Threading.Tasks;

namespace DwDouyin.Core
{
    public class DownloadException : Exception
    {
        public string Kind { get; }
        public DownloadException(string message, string kind = "download_failed") : base(message) { Kind = kind; }
    }

    /// <summary>
    /// 下载引擎：流式下载 + 进度回调 + 断点续传 + 3 次重试。
    /// 抖音直链需 Referer 防盗链；按域名自适应（非抖音 CDN 不带 Referer）。
    ///
    /// 关键点：HttpClient.Timeout 只覆盖到响应头，之后 ReadAsync 不受它约束，
    /// 网络断流会永久挂起。所以每次读取都套一个独立的空闲超时 CTS。
    /// </summary>
    public static class Downloader
    {
        const int MaxRetry = 3;
        const int ChunkSize = 256 * 1024;
        const int IdleTimeoutSeconds = 30;   // 连续无数据超过此值判定断流，可续传重试
        const string DownloadUa =
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 " +
            "(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36";

        static readonly HttpClientHandler Handler = new()
        {
            AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
            AllowAutoRedirect = true,
        };

        public class Progress
        {
            public long Received;
            public long Total;
            public double SpeedBps;
        }

        /// <summary>下载单个文件。progressCb 约 4 次/秒回调；cancelCheck 返回 true 时中断。</summary>
        public static async Task<string> DownloadFileAsync(
            string url, string filepath,
            Action<Progress> progressCb = null,
            Func<bool> cancelCheck = null,
            CancellationToken ct = default)
        {
            Exception lastError = null;
            for (int attempt = 1; attempt <= MaxRetry; attempt++)
            {
                try
                {
                    return await DownloadOnceAsync(url, filepath, progressCb, cancelCheck, ct).ConfigureAwait(false);
                }
                catch (DownloadException ex) when (ex.Kind is "cancelled" or "gone" or "forbidden")
                {
                    throw;   // 这几种重试无意义
                }
                catch (DownloadException ex)
                {
                    lastError = ex;
                }
                catch (OperationCanceledException)
                {
                    if (cancelCheck?.Invoke() == true)
                        throw new DownloadException("已取消", "cancelled");
                    lastError = new DownloadException("下载超时或断流", "timeout");
                }
                catch (HttpRequestException ex)
                {
                    lastError = new DownloadException($"网络连接失败：{ex.Message}", "network");
                }

                if (cancelCheck?.Invoke() == true)
                    throw new DownloadException("已取消", "cancelled");
                if (attempt < MaxRetry)
                    await Task.Delay(1200 * attempt + Random.Shared.Next(0, 800), ct).ConfigureAwait(false);
            }
            throw lastError ?? new DownloadException("下载失败", "network");
        }

        static async Task<string> DownloadOnceAsync(
            string url, string filepath, Action<Progress> progressCb, Func<bool> cancelCheck, CancellationToken ct)
        {
            string tmpPath = filepath + ".part";
            bool keepPart = false;

            long existing = 0;
            try { if (File.Exists(tmpPath)) existing = new FileInfo(tmpPath).Length; } catch { existing = 0; }

            using var http = new HttpClient(Handler, disposeHandler: false) { Timeout = TimeSpan.FromSeconds(60) };
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.TryAddWithoutValidation("User-Agent", DownloadUa);
            req.Headers.TryAddWithoutValidation("Accept", "*/*");
            req.Headers.TryAddWithoutValidation("Accept-Language", "zh-CN,zh;q=0.9");
            if (url.Contains("douyin") || url.Contains("amemv") || url.Contains("bytecdn") || url.Contains("douyinvod"))
                req.Headers.TryAddWithoutValidation("Referer", "https://www.douyin.com/");
            // 断点续传：已有 .part 就从断点请求
            if (existing > 0)
                req.Headers.Range = new RangeHeaderValue(existing, null);

            // 只等响应头，之后自己控制数据流超时
            using var resp = await http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);

            if (resp.StatusCode == HttpStatusCode.Forbidden)
                throw new DownloadException("403 防盗链：直链可能已过期，请重新解析一次再下载", "forbidden");
            if (resp.StatusCode == HttpStatusCode.NotFound)
                throw new DownloadException("文件地址失效(404)", "gone");

            bool resumed = existing > 0 && resp.StatusCode == HttpStatusCode.PartialContent;
            if (!resumed) existing = 0;   // 服务器不支持 Range → 从头来
            if (!resp.IsSuccessStatusCode && resp.StatusCode != HttpStatusCode.PartialContent)
                throw new DownloadException($"下载失败：HTTP {(int)resp.StatusCode}", "network");

            long contentLen = resp.Content.Headers.ContentLength ?? 0;
            long total = resumed ? existing + contentLen : contentLen;

            long received = existing;
            var lastTime = DateTime.UtcNow;
            long lastBytes = received;
            double speed = 0;

            try
            {
                await using var src = await resp.Content.ReadAsStreamAsync(ct).ConfigureAwait(false);
                await using var dst = new FileStream(tmpPath, resumed ? FileMode.Append : FileMode.Create,
                    FileAccess.Write, FileShare.None, ChunkSize, useAsync: true);

                var buffer = new byte[ChunkSize];
                while (true)
                {
                    if (cancelCheck?.Invoke() == true)
                    {
                        keepPart = resumed || received > 0;   // 取消保留断点供下次续传
                        throw new DownloadException("已取消", "cancelled");
                    }

                    int read;
                    using (var idleCts = CancellationTokenSource.CreateLinkedTokenSource(ct))
                    {
                        idleCts.CancelAfter(TimeSpan.FromSeconds(IdleTimeoutSeconds));
                        try
                        {
                            read = await src.ReadAsync(buffer, idleCts.Token).ConfigureAwait(false);
                        }
                        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
                        {
                            keepPart = true;   // 断流：保留 .part，下次 Range 续传
                            throw new DownloadException("下载断流（30 秒无数据）", "timeout");
                        }
                    }
                    if (read <= 0) break;

                    await dst.WriteAsync(buffer.AsMemory(0, read), ct).ConfigureAwait(false);
                    received += read;

                    var now = DateTime.UtcNow;
                    if (progressCb != null && (now - lastTime).TotalSeconds >= 0.25)
                    {
                        speed = (received - lastBytes) / Math.Max((now - lastTime).TotalSeconds, 0.001);
                        lastTime = now;
                        lastBytes = received;
                        progressCb(new Progress { Received = received, Total = total, SpeedBps = speed });
                    }
                }
            }
            catch
            {
                if (!keepPart)
                {
                    try { if (File.Exists(tmpPath)) File.Delete(tmpPath); } catch { }
                }
                throw;
            }

            // 大小校验（服务端给了 Content-Length 时）
            if (total > 0 && received < total)
                throw new DownloadException($"下载不完整（{received}/{total}）", "network");

            File.Move(tmpPath, filepath, overwrite: true);
            progressCb?.Invoke(new Progress { Received = received, Total = total, SpeedBps = speed });
            return filepath;
        }

        /// <summary>生成不冲突的文件名（同名加 (1) (2)…）。</summary>
        public static string UniquePath(string filepath)
        {
            if (!File.Exists(filepath) && !File.Exists(filepath + ".part")) return filepath;
            string root = Path.Combine(Path.GetDirectoryName(filepath) ?? ".", Path.GetFileNameWithoutExtension(filepath));
            string ext = Path.GetExtension(filepath);
            for (int i = 1; i < 999; i++)
            {
                var candidate = $"{root}({i}){ext}";
                if (!File.Exists(candidate) && !File.Exists(candidate + ".part")) return candidate;
            }
            return $"{root}-{Environment.ProcessId}{ext}";
        }

        public static string SafeFileName(string name)
        {
            foreach (var c in Path.GetInvalidFileNameChars()) name = name.Replace(c, '_');
            return name.Trim().TrimEnd('.', ' ');
        }
    }
}
