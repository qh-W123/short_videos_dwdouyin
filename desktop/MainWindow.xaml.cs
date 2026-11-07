using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using DwDouyin.Core;

namespace DwDouyin
{
    public partial class MainWindow : Window
    {
        // 一个作品一张结果卡
        class WorkCard
        {
            public WorkInfo Work;
            public List<(RadioButton rb, MediaEntry entry)> Quality = new();
            public TextBlock Status;
        }

        readonly List<WorkCard> _cards = new();
        CancellationTokenSource _downloadCts;
        bool _downloading;
        bool _parsing;
        int _cardSeq;

        public MainWindow()
        {
            InitializeComponent();
            Config.Load();
            History.Load();

            ApiBaseBox.Text = Config.ApiBase;
            ApiPathBox.Text = Config.ApiPath;
            DownloadDirBox.Text = Config.DownloadDir;
            VersionText.Text = $"v{Config.Version} · C#/WPF";
            UpdateApiPreview();

            Loaded += async (_, _) =>
            {
                RefreshHistory();
                await CheckServerAsync(quiet: true);
            };
        }

        // ================= 导航 =================
        void Nav_Checked(object sender, RoutedEventArgs e)
        {
            if (PageParse == null) return;
            var rb = sender as RadioButton;
            if (rb == null) return;
            PageParse.Visibility = rb == NavParse ? Visibility.Visible : Visibility.Collapsed;
            PageHistory.Visibility = rb == NavHistory ? Visibility.Visible : Visibility.Collapsed;
            PageSettings.Visibility = rb == NavSettings ? Visibility.Visible : Visibility.Collapsed;
        }

        // ================= 解析 =================
        void PasteBtn_Click(object sender, RoutedEventArgs e)
        {
            try
            {
                var text = Clipboard.GetText();
                if (!string.IsNullOrWhiteSpace(text))
                {
                    InputBox.Text = text;
                    InputBox.CaretIndex = InputBox.Text.Length;
                }
            }
            catch { }
        }

        void ClearBtn_Click(object sender, RoutedEventArgs e)
        {
            InputBox.Clear();
            ResultList.Children.Clear();
            _cards.Clear();
            ResultHeader.Visibility = Visibility.Collapsed;
            ParseStatus.Text = "等待输入链接";
        }

        async void ParseBtn_Click(object sender, RoutedEventArgs e)
        {
            if (_parsing) return;
            var text = InputBox.Text?.Trim();
            if (string.IsNullOrEmpty(text))
            {
                ParseStatus.Text = "先粘贴链接再解析";
                return;
            }

            var links = Parser.ExtractLinks(text);
            if (links.Count == 0)
            {
                ParseStatus.Text = "没识别到抖音链接，确认一下是不是 v.douyin.com 短链或 douyin.com 作品页";
                return;
            }

            _parsing = true;
            ParseBtn.IsEnabled = false;
            ParseStatus.Text = $"正在解析 0/{links.Count}";

            int ok = 0, fail = 0;
            var sw = System.Diagnostics.Stopwatch.StartNew();

            for (int i = 0; i < links.Count; i++)
            {
                var link = links[i];
                ParseStatus.Text = $"正在解析 {i + 1}/{links.Count}";
                try
                {
                    var work = await Task.Run(() => Parser.ParseLinkAsync(link));
                    AddResultCard(work);
                    History.Add(work);
                    ok++;
                }
                catch (Exception ex)
                {
                    AddFailCard(link, ex.Message);
                    fail++;
                }
            }

            sw.Stop();
            _parsing = false;
            ParseBtn.IsEnabled = true;

            if (links.Count == 1)
                ParseStatus.Text = ok > 0
                    ? $"解析完成，用时 {sw.Elapsed.TotalSeconds:F1} 秒"
                    : "解析失败";
            else
                ParseStatus.Text = $"共 {links.Count} 条：成功 {ok} 条" +
                                   (fail > 0 ? $"，失败 {fail} 条" : "") +
                                   $"，用时 {sw.Elapsed.TotalSeconds:F1} 秒";

            RefreshHistory();
        }

        void AddFailCard(string link, string message)
        {
            ResultHeader.Visibility = Visibility.Visible;
            var panel = new Border
            {
                Style = (Style)FindResource("Panel"),
                Margin = new Thickness(0, 0, 0, 12),
            };
            var sp = new StackPanel();
            sp.Children.Add(new TextBlock
            {
                Text = "这一条没解析出来",
                FontSize = 14,
                FontWeight = FontWeights.SemiBold,
                Foreground = (Brush)FindResource("InkBrush"),
            });
            sp.Children.Add(new TextBlock
            {
                Text = message,
                Style = (Style)FindResource("SDim"),
                Margin = new Thickness(0, 8, 0, 0),
            });
            sp.Children.Add(new TextBlock
            {
                Text = link,
                Style = (Style)FindResource("SFaint"),
                Margin = new Thickness(0, 8, 0, 0),
                TextTrimming = TextTrimming.CharacterEllipsis,
            });
            panel.Child = sp;
            ResultList.Children.Add(panel);
        }

        void AddResultCard(WorkInfo work)
        {
            ResultHeader.Visibility = Visibility.Visible;
            _cardSeq++;

            var card = new WorkCard { Work = work };
            var outer = new Border
            {
                Style = (Style)FindResource("Panel"),
                Margin = new Thickness(0, 0, 0, 12),
            };

            var grid = new Grid();
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(92) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            // 封面
            var cover = new Border
            {
                Width = 92,
                Height = 122,
                CornerRadius = new CornerRadius(8),
                Background = (Brush)FindResource("FieldBrush"),
                VerticalAlignment = VerticalAlignment.Top,
                ClipToBounds = true,
            };
            var img = new Image { Stretch = Stretch.UniformToFill };
            cover.Child = img;
            Grid.SetColumn(cover, 0);
            grid.Children.Add(cover);
            if (!string.IsNullOrEmpty(work.CoverUrl))
                _ = LoadCoverAsync(work.CoverUrl, img);

            // 右侧信息
            var right = new StackPanel { Margin = new Thickness(18, 0, 0, 0) };
            Grid.SetColumn(right, 1);
            grid.Children.Add(right);

            right.Children.Add(new TextBlock
            {
                Text = string.IsNullOrWhiteSpace(work.Title) ? "无标题" : work.Title,
                Style = (Style)FindResource("STitle"),
                TextWrapping = TextWrapping.Wrap,
                MaxHeight = 46,
                TextTrimming = TextTrimming.CharacterEllipsis,
            });

            var metaBits = new List<string> { $"@{work.Author}" };
            if (work.WorkType == "video" && work.DurationMs > 0)
                metaBits.Add(TimeSpan.FromMilliseconds(work.DurationMs).ToString(@"mm\:ss"));
            if (!string.IsNullOrEmpty(work.SizeLabel)) metaBits.Add(work.SizeLabel);
            metaBits.Add(work.WorkType == "images" ? $"图文 {work.MediaList.Count} 张" : "视频");
            right.Children.Add(new TextBlock
            {
                Text = string.Join(" · ", metaBits),
                Style = (Style)FindResource("SDim"),
                Margin = new Thickness(0, 8, 0, 0),
            });

            // 画质档
            var qPanel = new WrapPanel { Margin = new Thickness(0, 16, 0, 0) };
            for (int i = 0; i < work.MediaList.Count; i++)
            {
                var entry = work.MediaList[i];
                var rb = new RadioButton
                {
                    Style = (Style)FindResource("SegmentRadio"),
                    Content = QualityCaption(entry),
                    GroupName = $"q{_cardSeq}",
                    IsChecked = i == 0,
                };
                card.Quality.Add((rb, entry));
                qPanel.Children.Add(rb);
            }
            right.Children.Add(qPanel);

            // 操作行
            var actions = new StackPanel
            {
                Orientation = Orientation.Horizontal,
                Margin = new Thickness(0, 14, 0, 0),
            };

            var st = new TextBlock
            {
                Style = (Style)FindResource("SFaint"),
                Margin = new Thickness(12, 0, 0, 0),
                VerticalAlignment = VerticalAlignment.Center,
                TextTrimming = TextTrimming.CharacterEllipsis,
            };
            card.Status = st;

            var dlBtn = new Button
            {
                Content = work.WorkType == "images" ? "下载图片" : "下载视频",
                Style = (Style)FindResource("BtnPrimary"),
            };
            dlBtn.Click += async (_, _) =>
            {
                var sel = card.Quality.Where(x => x.rb.IsChecked == true).Select(x => x.entry).ToList();
                if (sel.Count == 0) sel = work.MediaList.Take(1).ToList();
                await DownloadWorkAsync(work, sel, st, dlBtn);
            };
            actions.Children.Add(dlBtn);

            var copyBtn = new Button
            {
                Content = "复制直链",
                Style = (Style)FindResource("BtnQuiet"),
                Margin = new Thickness(4, 0, 0, 0),
            };
            copyBtn.Click += (_, _) =>
            {
                var hit = card.Quality.FirstOrDefault(x => x.rb.IsChecked == true).entry;
                if (hit == null) hit = work.MediaList.FirstOrDefault();
                if (hit != null)
                {
                    try { Clipboard.SetText(hit.Url); } catch { }
                    st.Text = "直链已复制";
                }
            };
            actions.Children.Add(copyBtn);
            actions.Children.Add(st);
            right.Children.Add(actions);

            outer.Child = grid;
            ResultList.Children.Add(outer);
            _cards.Add(card);
        }

        static string QualityCaption(MediaEntry e)
        {
            var parts = new List<string> { e.Label };
            if (!string.IsNullOrEmpty(e.Grade) && e.Grade != e.Label) parts.Add(e.Grade);
            if (e.SizeBytes > 0) parts.Add(FmtBytes(e.SizeBytes));
            if (!string.IsNullOrEmpty(e.Wh)) parts.Add(e.Wh);
            return string.Join(" · ", parts);
        }

        static string FmtBytes(long size) => size switch
        {
            >= 1L << 30 => $"{size / (double)(1L << 30):F2} GB",
            >= 1L << 20 => $"{size / (double)(1L << 20):F1} MB",
            >= 1L << 10 => $"{size / (double)(1L << 10):F0} KB",
            _ => $"{size} B",
        };

        async Task LoadCoverAsync(string url, Image target)
        {
            try
            {
                using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
                var bytes = await http.GetByteArrayAsync(url);
                var bmp = new BitmapImage();
                bmp.BeginInit();
                bmp.StreamSource = new MemoryStream(bytes);
                bmp.CacheOption = BitmapCacheOption.OnLoad;
                bmp.EndInit();
                bmp.Freeze();
                target.Source = bmp;
            }
            catch { }
        }

        // ================= 下载 =================
        async Task DownloadWorkAsync(WorkInfo work, List<MediaEntry> selected, TextBlock status, Button trigger)
        {
            if (_downloading)
            {
                if (status != null) status.Text = "已有下载任务在跑，等它结束";
                return;
            }
            if (selected == null || selected.Count == 0) return;

            var dir = Config.DownloadDir;
            try { Directory.CreateDirectory(dir); } catch { }

            _downloadCts = new CancellationTokenSource();
            _downloading = true;
            if (trigger != null) trigger.IsEnabled = false;
            ProgressCard.Visibility = Visibility.Visible;
            CancelBtn.Visibility = Visibility.Visible;
            ProgressBarCtl.Value = 0;

            int ok = 0, fail = 0;
            for (int i = 0; i < selected.Count; i++)
            {
                if (_downloadCts.IsCancellationRequested) break;
                var entry = selected[i];

                string suffix = selected.Count > 1 ? $"_{entry.Label}" : "";
                string baseName = Downloader.SafeFileName($"{work.Author}-{work.Title}");
                if (baseName.Length > 70) baseName = baseName.Substring(0, 70);
                string ext = work.WorkType == "video" ? ".mp4" : ".jpg";
                string filepath = Downloader.UniquePath(Path.Combine(dir, baseName + suffix + ext));

                ProgressTitle.Text = selected.Count > 1
                    ? $"下载中 {i + 1}/{selected.Count}"
                    : "下载中";
                ProgressDetail.Text = Path.GetFileName(filepath);

                try
                {
                    await Downloader.DownloadFileAsync(
                        entry.Url, filepath,
                        p => Dispatcher.Invoke(() =>
                        {
                            double pct = p.Total > 0 ? p.Received * 100.0 / p.Total : 0;
                            ProgressBarCtl.Value = pct;
                            ProgressSpeed.Text = FmtBytes((long)p.SpeedBps) + "/s";
                            ProgressDetail.Text =
                                $"{Path.GetFileName(filepath)} · {FmtBytes(p.Received)}" +
                                (p.Total > 0 ? $" / {FmtBytes(p.Total)}" : "");
                        }),
                        () => _downloadCts.IsCancellationRequested,
                        _downloadCts.Token);
                    ok++;
                    if (status != null) status.Text = "已保存到 " + Path.GetFileName(filepath);
                }
                catch (DownloadException ex)
                {
                    if (ex.Kind == "cancelled")
                    {
                        if (status != null) status.Text = "已取消";
                        break;
                    }
                    fail++;
                    if (status != null) status.Text = ex.Message;
                }
                catch (Exception ex)
                {
                    fail++;
                    if (status != null) status.Text = ex.Message;
                }
            }

            bool cancelled = _downloadCts.IsCancellationRequested;
            _downloading = false;
            if (trigger != null) trigger.IsEnabled = true;
            CancelBtn.Visibility = Visibility.Collapsed;

            ProgressTitle.Text = cancelled
                ? $"已取消（完成 {ok}，失败 {fail}）"
                : ok > 0
                    ? (fail > 0 ? $"完成 {ok} 个，{fail} 个失败" : $"完成 {ok} 个")
                    : "下载结束";
            ProgressSpeed.Text = "";
            ProgressBarCtl.Value = 100;

            if (ok > 0 && status != null && !cancelled)
                status.Text = "已保存到 " + dir;
        }

        void CancelBtn_Click(object sender, RoutedEventArgs e)
        {
            _downloadCts?.Cancel();
            CancelBtn.Visibility = Visibility.Collapsed;
        }

        // ================= 历史记录 =================
        void RefreshHistory()
        {
            HistoryList.Children.Clear();
            var items = History.Items;
            HistoryEmpty.Visibility = items.Count == 0 ? Visibility.Visible : Visibility.Collapsed;

            foreach (var h in items)
            {
                HistoryList.Children.Add(BuildHistoryRow(h));
            }
        }

        UIElement BuildHistoryRow(HistoryItem h)
        {
            var panel = new Border
            {
                Style = (Style)FindResource("Panel"),
                Margin = new Thickness(0, 0, 0, 10),
            };
            var grid = new Grid();
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(64) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });

            var cover = new Border
            {
                Width = 64,
                Height = 84,
                CornerRadius = new CornerRadius(6),
                Background = (Brush)FindResource("FieldBrush"),
                ClipToBounds = true,
                VerticalAlignment = VerticalAlignment.Top,
            };
            var img = new Image { Stretch = Stretch.UniformToFill };
            cover.Child = img;
            Grid.SetColumn(cover, 0);
            grid.Children.Add(cover);
            if (!string.IsNullOrEmpty(h.CoverUrl)) _ = LoadCoverAsync(h.CoverUrl, img);

            var mid = new StackPanel { Margin = new Thickness(16, 0, 0, 0), VerticalAlignment = VerticalAlignment.Center };
            Grid.SetColumn(mid, 1);
            grid.Children.Add(mid);

            mid.Children.Add(new TextBlock
            {
                Text = string.IsNullOrWhiteSpace(h.Title) ? "无标题" : h.Title,
                Style = (Style)FindResource("SBody"),
                FontWeight = FontWeights.SemiBold,
                TextWrapping = TextWrapping.Wrap,
                MaxHeight = 40,
                TextTrimming = TextTrimming.CharacterEllipsis,
            });

            var bits = new List<string> { $"@{h.Author}" };
            if (!string.IsNullOrEmpty(h.SizeLabel)) bits.Add(h.SizeLabel);
            bits.Add(h.WorkType == "images" ? $"图文 {h.MediaList.Count} 张" : "视频");
            try
            {
                var local = DateTimeOffset.FromUnixTimeSeconds(h.SavedAt).ToLocalTime();
                bits.Add(local.ToString("MM-dd HH:mm"));
            }
            catch { }
            mid.Children.Add(new TextBlock
            {
                Text = string.Join(" · ", bits),
                Style = (Style)FindResource("SDim"),
                Margin = new Thickness(0, 6, 0, 0),
            });

            if (h.LikelyExpired)
            {
                mid.Children.Add(new TextBlock
                {
                    Text = "直链可能已过期，下载会自动重新解析",
                    Style = (Style)FindResource("SFaint"),
                    Margin = new Thickness(0, 6, 0, 0),
                });
            }

            var right = new StackPanel
            {
                Orientation = Orientation.Horizontal,
                VerticalAlignment = VerticalAlignment.Center,
            };
            Grid.SetColumn(right, 2);
            grid.Children.Add(right);

            var st = new TextBlock
            {
                Style = (Style)FindResource("SFaint"),
                VerticalAlignment = VerticalAlignment.Center,
                Margin = new Thickness(0, 0, 10, 0),
                MaxWidth = 180,
                TextTrimming = TextTrimming.CharacterEllipsis,
            };

            var dl = new Button
            {
                Content = "下载",
                Style = (Style)FindResource("BtnPrimary"),
                Padding = new Thickness(16, 8, 16, 8),
            };
            dl.Click += async (_, _) =>
            {
                var work = History.ToWork(h);
                if (h.LikelyExpired)
                {
                    st.Text = "正在刷新直链…";
                    try
                    {
                        var fresh = await Task.Run(() => Parser.ParseLinkAsync(h.ShareUrl));
                        History.Add(fresh);
                        RefreshHistory();
                        work = fresh;
                        st.Text = "";
                    }
                    catch (Exception ex)
                    {
                        st.Text = "刷新失败：" + ex.Message;
                        return;
                    }
                }
                await DownloadWorkAsync(work, work.MediaList.Take(1).ToList(), st, dl);
            };
            right.Children.Add(st);
            right.Children.Add(dl);

            var del = new Button
            {
                Content = "删除",
                Style = (Style)FindResource("BtnQuiet"),
                Margin = new Thickness(2, 0, 0, 0),
            };
            del.Click += (_, _) =>
            {
                var list = History.Items.ToList();
                list.RemoveAll(x => x.AwemeId == h.AwemeId);
                History.Replace(list);
                RefreshHistory();
            };
            right.Children.Add(del);

            panel.Child = grid;
            return panel;
        }

        void ClearHistoryBtn_Click(object sender, RoutedEventArgs e)
        {
            if (History.Items.Count == 0) return;
            History.Clear();
            RefreshHistory();
        }

        // ================= 设置 / 接口 =================
        void ApiField_Changed(object sender, TextChangedEventArgs e) => UpdateApiPreview();

        void UpdateApiPreview()
        {
            if (ApiPreviewText == null) return;
            var b = (ApiBaseBox?.Text ?? "").Trim().TrimEnd('/');
            var p = (ApiPathBox?.Text ?? "").Trim();
            if (string.IsNullOrEmpty(p)) p = Parser.DefaultApiPath;
            if (!p.StartsWith("/")) p = "/" + p;
            ApiPreviewText.Text = $"GET  {b}{p}?url=<作品链接>";
        }

        async Task CheckServerAsync(bool quiet)
        {
            var baseUrl = (ApiBaseBox.Text ?? "").Trim().TrimEnd('/');
            var path = (ApiPathBox.Text ?? "").Trim();
            if (string.IsNullOrEmpty(path)) path = Parser.DefaultApiPath;
            if (!path.StartsWith("/")) path = "/" + path;

            ServerStatusText.Text = "正在检测服务器…";
            try
            {
                using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(8) };
                var resp = await http.GetAsync(baseUrl + path);
                var body = await resp.Content.ReadAsStringAsync();
                bool alive = body.Contains("请输入") || body.Contains("\"code\"");
                ServerStatusText.Text = alive ? "服务器已连接" : $"服务器响应异常（{(int)resp.StatusCode}）";
                if (!quiet && alive)
                    ApiTestResult.Text = $"连接正常，返回：{Trim(body, 120)}";
                else if (!quiet)
                    ApiTestResult.Text = $"响应异常（HTTP {(int)resp.StatusCode}）：{Trim(body, 120)}";
            }
            catch (Exception ex)
            {
                ServerStatusText.Text = "服务器未连接";
                if (!quiet) ApiTestResult.Text = "连接失败：" + ex.Message;
            }
        }

        async void TestApiBtn_Click(object sender, RoutedEventArgs e)
        {
            TestApiBtn.IsEnabled = false;
            ApiTestResult.Text = "正在测试…";
            try { await CheckServerAsync(quiet: false); }
            finally { TestApiBtn.IsEnabled = true; }
        }

        void SaveApiBtn_Click(object sender, RoutedEventArgs e)
        {
            var baseUrl = (ApiBaseBox.Text ?? "").Trim().TrimEnd('/');
            var path = (ApiPathBox.Text ?? "").Trim();
            if (string.IsNullOrEmpty(baseUrl))
            {
                ApiTestResult.Text = "服务器地址不能为空";
                return;
            }
            if (!baseUrl.StartsWith("http://") && !baseUrl.StartsWith("https://"))
                baseUrl = "http://" + baseUrl;

            Config.Save(Config.DownloadDir, baseUrl, path);
            ApiBaseBox.Text = baseUrl;
            UpdateApiPreview();
            ApiTestResult.Text = "接口设置已保存";
            _ = CheckServerAsync(quiet: false);
        }

        void BrowseDir_Click(object sender, RoutedEventArgs e)
        {
            using var dialog = new System.Windows.Forms.FolderBrowserDialog();
            if (!string.IsNullOrEmpty(DownloadDirBox.Text))
                dialog.SelectedPath = DownloadDirBox.Text;
            if (dialog.ShowDialog() == System.Windows.Forms.DialogResult.OK)
                DownloadDirBox.Text = dialog.SelectedPath;
        }

        void OpenDir_Click(object sender, RoutedEventArgs e)
        {
            var dir = DownloadDirBox.Text?.Trim();
            if (string.IsNullOrEmpty(dir)) dir = Config.DownloadDir;
            try { Directory.CreateDirectory(dir); } catch { }
            try { System.Diagnostics.Process.Start("explorer.exe", dir); } catch { }
        }

        void SaveDirBtn_Click(object sender, RoutedEventArgs e)
        {
            var dir = (DownloadDirBox.Text ?? "").Trim();
            if (string.IsNullOrEmpty(dir)) return;
            try { Directory.CreateDirectory(dir); } catch { }
            Config.Save(dir, Config.ApiBase, Config.ApiPath);
            ApiTestResult.Text = "下载目录已保存";
        }

        static string Trim(string s, int n) =>
            string.IsNullOrEmpty(s) ? "" : (s.Length <= n ? s : s.Substring(0, n) + "…");
    }
}
