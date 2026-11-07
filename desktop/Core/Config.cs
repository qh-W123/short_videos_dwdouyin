using System;
using System.IO;
using System.Text.Json;

namespace DwDouyin.Core
{
    /// <summary>应用配置：数据目录取程序所在目录（config.json 与 exe 同级）。</summary>
    public static class Config
    {
        public const string Version = "1.0.1";
        public static readonly string DataDir = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        static readonly string ConfigPath = Path.Combine(DataDir, "config.json");

        public static string DownloadDir { get; private set; } =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Downloads", "dwdouyin");
        public static string ApiBase { get; private set; } = Parser.DefaultApiBase;
        public static string ApiPath { get; private set; } = Parser.DefaultApiPath;

        static readonly object Lock = new();
        static bool _loaded;

        public static void Load()
        {
            lock (Lock)
            {
                if (_loaded) return;
                _loaded = true;
                try
                {
                    Directory.CreateDirectory(DataDir);
                    if (File.Exists(ConfigPath))
                    {
                        using var doc = JsonDocument.Parse(File.ReadAllText(ConfigPath));
                        var root = doc.RootElement;
                        if (root.TryGetProperty("download_dir", out var dd) && dd.ValueKind == JsonValueKind.String)
                            DownloadDir = dd.GetString();
                        if (root.TryGetProperty("api_base", out var ab) && ab.ValueKind == JsonValueKind.String)
                            ApiBase = ab.GetString();
                        if (root.TryGetProperty("api_path", out var ap) && ap.ValueKind == JsonValueKind.String)
                            ApiPath = ap.GetString();
                    }
                }
                catch { }
                Parser.ApiBase = ApiBase;
                Parser.ApiPath = ApiPath;
            }
        }

        public static void Save(string downloadDir, string apiBase, string apiPath)
        {
            lock (Lock)
            {
                DownloadDir = downloadDir;
                ApiBase = apiBase;
                ApiPath = apiPath;
                Parser.ApiBase = apiBase;
                Parser.ApiPath = apiPath;
                Directory.CreateDirectory(DataDir);
                var json = JsonSerializer.Serialize(new
                {
                    version = 2,
                    download_dir = DownloadDir,
                    api_base = ApiBase,
                    api_path = ApiPath,
                }, new JsonSerializerOptions { WriteIndented = true });
                File.WriteAllText(ConfigPath, json);
            }
        }
    }
}
