<?php
/**
 *@Author: JH-Ahua
 *@CreateTime: 2026/4/30 17:27
 *@email: admin@bugpk.com
 *@blog: www.jiuhunwl.cn
 *@Api: api.bugpk.com
 *@tip: 即梦ai视频去水印解析
 */
declare(strict_types=1);

final class JimengParser
{
    private const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

    private const API_URL = 'https://jimeng.jianying.com/mweb/v1/get_item_info';

    private const PLATFORM_HOSTS = [
        'jimeng.jianying.com' => true,
        'v.jimeng.aiseet.atry.com' => true,
    ];

    private $ua;

    private $lastFailureHint = '';

    public function __construct($userAgent = null)
    {
        $this->ua = $userAgent !== null && $userAgent !== '' ? $userAgent : self::DEFAULT_UA;
    }

    private function output(int $code, string $msg, array $data = []): string
    {
        return json_encode([
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ], 480);
    }

    private function fail(int $code, string $msg, array $data = []): string
    {
        return $this->output($code, $msg, $data);
    }

    public function parseShareText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $this->fail(400, '请输入即梦链接');
        }

        $itemId = self::extractItemIdFromUrl($text);
        if ($itemId === null || $itemId === '') {
            $shareUrl = self::extractFirstUrl($text);
            if ($shareUrl !== null) {
                $resolved = self::getLocation($shareUrl);
                if ($resolved !== null) {
                    $itemId = self::getItemId($resolved);
                }
            }
        }
        if ($itemId === null || $itemId === '') {
            return $this->fail(400, '链接格式错误，无法提取ID');
        }

        return $this->parseByItemId($itemId);
    }

    public function parseByItemId(string $itemId): string
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return $this->fail(400, '请提供有效的ID');
        }

        $this->lastFailureHint = '';
        $detail = $this->fetchItemInfo($itemId);
        if ($detail === null) {
            $msg = '请求失败';
            if ($this->lastFailureHint !== '') {
                $msg .= '（' . $this->lastFailureHint . '）';
            }
            return $this->fail(500, $msg, $this->lastFailureHint !== '' ? ['reason' => $this->lastFailureHint] : []);
        }

        $payload = $this->buildFormatData($detail);

        return $this->output(200, '解析成功', $payload);
    }

    public static function extractItemIdFromUrl(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $url = self::extractFirstUrl($text);
        if ($url === null) {
            if (preg_match('/^\d{16,}$/', $text)) {
                return $text;
            }
            return null;
        }

        $itemId = self::getItemId($url);
        if ($itemId === null || $itemId === '') {
            if (strpos($url, '/s/') !== false) {
                $resolved = self::getLocation($url);
                if ($resolved !== null) {
                    $itemId = self::getItemId($resolved);
                }
            }
        }

        return $itemId;
    }

    private function fetchItemInfo(string $itemId): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['published_item_id' => $itemId]),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $this->ua,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        $cerr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($cerr !== '') {
            $this->lastFailureHint = 'curl: ' . $cerr;
            return null;
        }

        if ($httpCode >= 400) {
            $this->lastFailureHint = 'HTTP ' . $httpCode;
            return null;
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            $this->lastFailureHint = 'JSON解析失败';
            return null;
        }

        if (isset($json['ret']) && $json['ret'] !== '0') {
            $this->lastFailureHint = $json['errmsg'] ?? 'API错误';
            return null;
        }

        return $json;
    }

    private function buildFormatData(array $detail): array
    {
        $common = $detail['data']['common_attr'] ?? [];
        $author = $detail['data']['author'] ?? [];
        $video = $detail['data']['video'] ?? [];
        $statistic = $detail['data']['statistic'] ?? [];

        $title = (string)($common['description'] ?? '');
        $itemId = (string)($common['published_item_id'] ?? '');

        $authorOut = [
            'name' => (string)($author['name'] ?? ''),
            'uid' => (string)($author['uid'] ?? ''),
            'sec_uid' => (string)($author['sec_uid'] ?? ''),
            'avatar' => (string)($author['avatar_url'] ?? ''),
            'description' => (string)($author['description'] ?? ''),
        ];

        $coverUrl = '';
        $coverMap = $common['cover_url_map'] ?? [];
        if (is_array($coverMap)) {
            if (isset($coverMap['1080'])) {
                $coverUrl = (string)$coverMap['1080'];
            } elseif (isset($coverMap['720'])) {
                $coverUrl = (string)$coverMap['720'];
            } elseif (isset($coverMap['original'])) {
                $coverUrl = (string)$coverMap['original'];
            } else {
                $first = reset($coverMap);
                if ($first !== false) {
                    $coverUrl = (string)$first;
                }
            }
        }
        if ($coverUrl === '' && isset($common['cover_url'])) {
            $coverUrl = (string)$common['cover_url'];
        }

        $videoUrl = '';
        $videoQuality = '';
        $videoBackup = [];
        $duration = 0;

        $originVideo = $video['origin_video'] ?? [];
        $transcoded = $video['transcoded_video'] ?? [];

        if (isset($transcoded['origin']) && is_array($transcoded['origin']) && isset($transcoded['origin']['video_url']) && $transcoded['origin']['video_url'] !== '') {
            $videoUrl = (string)$transcoded['origin']['video_url'];
            $videoQuality = 'origin';
        } elseif (is_array($originVideo) && isset($originVideo['video_url']) && $originVideo['video_url'] !== '') {
            $videoUrl = (string)$originVideo['video_url'];
            $videoQuality = 'origin';
        }

        if (is_array($originVideo) && isset($originVideo['video_url']) && $originVideo['video_url'] !== '') {
            $originUrl = (string)$originVideo['video_url'];
            if ($originUrl !== $videoUrl) {
                $videoBackup[] = [
                    'label' => 'origin_video',
                    'quality' => 'origin',
                    'url' => $originUrl,
                    'width' => (int)($originVideo['width'] ?? 0),
                    'height' => (int)($originVideo['height'] ?? 0),
                    'bit_rate' => (int)($originVideo['br'] ?? 0),
                    'size' => (int)($originVideo['size'] ?? 0),
                    'fps' => (int)($originVideo['fps'] ?? 0),
                    'definition' => (string)($originVideo['definition'] ?? 'origin'),
                ];
            }
        }

        if (is_array($transcoded)) {
            foreach ($transcoded as $quality => $item) {
                if (!is_array($item) || !isset($item['video_url']) || $item['video_url'] === '') {
                    continue;
                }
                $url = (string)$item['video_url'];
                if ($url === $videoUrl) {
                    continue;
                }
                $width = (int)($item['width'] ?? 0);
                $height = (int)($item['height'] ?? 0);
                $bitrate = (int)($item['br'] ?? $item['bitrate'] ?? 0);
                $size = (int)($item['size'] ?? 0);
                $fps = (int)($item['fps'] ?? 0);
                $definition = (string)($item['definition'] ?? '');

                $videoBackup[] = [
                    'label' => $quality,
                    'quality' => $quality,
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'bit_rate' => $bitrate,
                    'size' => $size,
                    'fps' => $fps,
                    'definition' => $definition,
                ];
            }
        }

        if (isset($video['duration_ms']) && $video['duration_ms'] > 0) {
            $duration = (int)($video['duration_ms'] / 1000);
        } elseif (isset($video['duration']) && $video['duration'] > 0) {
            $duration = (int)$video['duration'];
        }

        $result = [
            'type' => 'video',
            'title' => $title,
            'desc' => $title,
            'author' => $authorOut,
            'cover' => $coverUrl,
            'url' => $videoUrl,
            'quality' => $videoQuality,
            'duration' => $duration,
            'video_id' => $itemId,
            'video_backup' => $videoBackup,
            'images' => [],
            'extra' => [
                'item_id' => $itemId,
                'create_time' => (int)($common['create_time'] ?? 0),
                'update_time' => (int)($common['update_time'] ?? 0),
                'status' => (int)($common['status'] ?? 0),
                'aspect_ratio' => (float)($common['aspect_ratio'] ?? 0),
                'publish_source' => (string)($common['publish_source'] ?? ''),
                'effect_type' => (int)($common['effect_type'] ?? 0),
                'video' => [
                    'video_id' => (string)($video['video_id'] ?? ''),
                    'duration_ms' => (int)($video['duration_ms'] ?? 0),
                    'transcode_status' => (int)($video['transcode_status'] ?? 0),
                    'has_audio' => (bool)($video['has_audio'] ?? true),
                    'is_mute' => (bool)($video['is_mute'] ?? false),
                    'watermark_type' => (int)($video['watermark_type'] ?? 0),
                    'width' => (int)($originVideo['width'] ?? 0),
                    'height' => (int)($originVideo['height'] ?? 0),
                    'fps' => (int)($originVideo['fps'] ?? 0),
                    'format' => (string)($originVideo['format'] ?? ''),
                    'definition' => (string)($originVideo['definition'] ?? ''),
                    'md5' => (string)($originVideo['md5'] ?? ''),
                    'size' => (int)($originVideo['size'] ?? 0),
                    'bitrate' => (int)($originVideo['br'] ?? 0),
                ],
                'statistics' => [
                    'usage_num' => (int)($statistic['usage_num'] ?? 0),
                    'favorite_num' => (int)($statistic['favorite_num'] ?? 0),
                    'play_num' => (int)($statistic['play_num'] ?? 0),
                ],
                'share_url' => (string)($common['share_url'] ?? ''),
            ],
        ];

        return $result;
    }

    private static function extractFirstUrl(string $text): ?string
    {
        if (preg_match('/\bhttps?:\/\/[^\s\'"<>]+/i', $text, $m)) {
            return $m[0];
        }
        return null;
    }

    private static function getLocation(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9',
            ],
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($httpCode === 301 || $httpCode === 302) {
            if ($location !== null && $location !== '') {
                return trim($location) !== '' ? $location : null;
            }
            if ($effectiveUrl !== null && $effectiveUrl !== '') {
                return $effectiveUrl;
            }
        }
        return null;
    }

    private static function host(string $url): ?string
    {
        if (preg_match('/^https?:\/\/([^\/]+)/i', $url, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private static function getItemId(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }

        parse_str($parsed['query'] ?? '', $query);
        if (isset($query['item_id']) && $query['item_id'] !== '') {
            return (string)$query['item_id'];
        }

        if (isset($query['id']) && $query['id'] !== '') {
            return (string)$query['id'];
        }

        $path = $parsed['path'] ?? '';
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (!empty($parts)) {
            $last = end($parts);
            if ($last !== '' && preg_match('/^\d+$/', $last)) {
                return $last;
            }
        }

        return null;
    }

    public static function toHttps(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        return strpos($url, 'http://') === 0 ? 'https://' . substr($url, 7) : $url;
    }
}