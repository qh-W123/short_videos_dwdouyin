using System;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Windows;

namespace DwDouyin
{
    public partial class App : Application
    {
        protected override void OnStartup(StartupEventArgs e)
        {
            // 自测模式：DwDouyin.exe --selftest [链接] [--download]
            // 不开窗口，直接在控制台跑一遍解析链路，验证交付的 exe 是否能正常解析。
            if (e.Args.Length > 0 && e.Args[0] == "--selftest")
            {
                Environment.ExitCode = RunSelfTestAsync(e.Args.Skip(1).ToArray()).GetAwaiter().GetResult();
                Shutdown(Environment.ExitCode);
                return;
            }
            base.OnStartup(e);
        }

        static async Task<int> RunSelfTestAsync(string[] args)
        {
            var sb = new StringBuilder();
            void Log(string s) { sb.AppendLine(s); Console.WriteLine(s); }

            try
            {
                bool doDownload = args.Contains("--download");
                var linkArg = args.FirstOrDefault(a => !a.StartsWith("--"));
                string link = linkArg ?? "https://v.douyin.com/vJr89tfmP3g/";

                Core.Config.Load();   // 自测不走窗口，需手动载入 config.json（否则用默认 API 地址）
                Log("==== DwDouyin 自测 ====");
                Log($"版本: {Core.Config.Version}");
                Log($"API : {Core.Config.ApiBase}");
                Log($"输入: {link}");

                var sw = System.Diagnostics.Stopwatch.StartNew();
                var work = await Core.Parser.ParseWorkAsync(link).ConfigureAwait(false);
                sw.Stop();

                Log($"解析成功（{sw.Elapsed.TotalSeconds:F2} 秒）");
                Log($"  类型: {work.WorkType}");
                Log($"  ID  : {work.AwemeId}");
                Log($"  标题: {work.Title}");
                Log($"  作者: {work.Author}");
                Log($"  封面: {(string.IsNullOrEmpty(work.CoverUrl) ? "(无)" : work.CoverUrl[..Math.Min(60, work.CoverUrl.Length)] + "…")}");
                Log($"  时长: {(work.DurationMs > 0 ? TimeSpan.FromMilliseconds(work.DurationMs).ToString(@"mm\:ss") : "-")}");
                Log($"  大小: {work.SizeLabel}");
                Log($"  媒体档位: {work.MediaList.Count}");
                for (int i = 0; i < work.MediaList.Count; i++)
                {
                    var m = work.MediaList[i];
                    Log($"    [{i}] {m.Label} | {m.Grade} | {(m.SizeBytes > 0 ? m.SizeBytes.ToString() : "-")} | {m.Url[..Math.Min(50, m.Url.Length)]}…");
                }
                Log("==== 自测通过 ====");

                if (doDownload && work.MediaList.Count > 0)
                {
                    Log("");
                    Log("==== 下载测试 ====");
                    var dir = System.IO.Path.Combine(Core.Config.DownloadDir, "selftest");
                    System.IO.Directory.CreateDirectory(dir);
                    var entry = work.MediaList[0];
                    var name = Core.Downloader.SafeFileName($"{work.Author}-{work.Title}");
                    if (name.Length > 60) name = name[..60];
                    var path = Core.Downloader.UniquePath(System.IO.Path.Combine(dir, name + ".mp4"));
                    Log($"目标: {path}");
                    var dlSw = System.Diagnostics.Stopwatch.StartNew();
                    long lastReported = 0;
                    await Core.Downloader.DownloadFileAsync(entry.Url, path, p =>
                    {
                        if (p.Received - lastReported > 2 * 1024 * 1024)
                        {
                            lastReported = p.Received;
                            Console.WriteLine($"  {p.Received / 1024.0 / 1024.0:F1} MB / {(p.Total > 0 ? (p.Total / 1024.0 / 1024.0).ToString("F1") + " MB" : "?")}  {p.SpeedBps / 1024.0 / 1024.0:F2} MB/s");
                        }
                    }).ConfigureAwait(false);
                    dlSw.Stop();
                    var fi = new System.IO.FileInfo(path);
                    Log($"下载完成: {fi.Length / 1024.0 / 1024.0:F2} MB, 耗时 {dlSw.Elapsed.TotalSeconds:F1} 秒");
                    Log("==== 下载测试通过 ====");
                }

                return 0;
            }
            catch (Exception ex)
            {
                Log($"==== 自测失败 ==== {ex.GetType().Name}: {ex.Message}");
                return 1;
            }
            finally
            {
                try { System.IO.File.WriteAllText(System.IO.Path.Combine(Core.Config.DataDir, "selftest.log"), sb.ToString()); } catch { }
            }
        }
    }
}
