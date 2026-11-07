using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace DwDouyin.Core
{
    /// <summary>一条历史记录（解析成功时写入，含可直接复用的直链）。</summary>
    public class HistoryItem
    {
        public string AwemeId { get; set; } = "";
        public string ShareUrl { get; set; } = "";
        public string WorkType { get; set; } = "video";
        public string Title { get; set; } = "";
        public string Author { get; set; } = "";
        public string CoverUrl { get; set; } = "";
        public long DurationMs { get; set; }
        public string SizeLabel { get; set; } = "";
        public long SavedAt { get; set; }          // unix 秒
        public List<MediaEntry> MediaList { get; set; } = new();

        /// <summary>抖音直链带签名过期时间，超过约 2 小时基本失效。</summary>
        [JsonIgnore]
        public bool LikelyExpired =>
            DateTimeOffset.UtcNow.ToUnixTimeSeconds() - SavedAt > 2 * 3600;
    }

    /// <summary>历史记录存储：程序目录下的 history.json，最多保留 200 条。</summary>
    public static class History
    {
        const int MaxItems = 200;
        static readonly string Path_ = Path.Combine(Config.DataDir, "history.json");
        static readonly List<HistoryItem> Items_ = new();
        static bool _loaded;

        public static IReadOnlyList<HistoryItem> Items => Items_;

        static readonly JsonSerializerOptions JsonOpts = new()
        {
            WriteIndented = true,
            Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        };

        public static void Load()
        {
            if (_loaded) return;
            _loaded = true;
            try
            {
                if (!File.Exists(Path_)) return;
                var list = JsonSerializer.Deserialize<List<HistoryItem>>(File.ReadAllText(Path_), JsonOpts);
                if (list != null) Items_.AddRange(list);
            }
            catch { }
        }

        public static void Save()
        {
            try
            {
                Directory.CreateDirectory(Config.DataDir);
                File.WriteAllText(Path_, JsonSerializer.Serialize(Items_, JsonOpts));
            }
            catch { }
        }

        /// <summary>写入一条记录；同一作品已存在时用新的直链覆盖（时间刷新）。</summary>
        public static void Add(WorkInfo work)
        {
            if (work == null || string.IsNullOrEmpty(work.AwemeId)) return;
            Items_.RemoveAll(x => x.AwemeId == work.AwemeId);
            Items_.Insert(0, new HistoryItem
            {
                AwemeId = work.AwemeId,
                ShareUrl = work.ShareUrl,
                WorkType = work.WorkType,
                Title = work.Title,
                Author = work.Author,
                CoverUrl = work.CoverUrl,
                DurationMs = work.DurationMs,
                SizeLabel = work.SizeLabel,
                SavedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
                MediaList = work.MediaList.Select(m => new MediaEntry
                {
                    Label = m.Label, Url = m.Url, SizeBytes = m.SizeBytes, Wh = m.Wh, Grade = m.Grade,
                }).ToList(),
            });
            while (Items_.Count > MaxItems) Items_.RemoveAt(Items_.Count - 1);
            Save();
        }

        public static void Clear()
        {
            Items_.Clear();
            Save();
        }

        public static void Replace(IEnumerable<HistoryItem> items)
        {
            Items_.Clear();
            Items_.AddRange(items);
            Save();
        }

        /// <summary>历史项 → WorkInfo（复用同一套下载逻辑）。</summary>
        public static WorkInfo ToWork(HistoryItem h) => new()
        {
            AwemeId = h.AwemeId,
            ShareUrl = h.ShareUrl,
            WorkType = h.WorkType,
            Title = h.Title,
            Author = h.Author,
            CoverUrl = h.CoverUrl,
            DurationMs = h.DurationMs,
            SizeLabel = h.SizeLabel,
            MediaList = h.MediaList.Select(m => new MediaEntry
            {
                Label = m.Label, Url = m.Url, SizeBytes = m.SizeBytes, Wh = m.Wh, Grade = m.Grade,
            }).ToList(),
        };
    }
}
