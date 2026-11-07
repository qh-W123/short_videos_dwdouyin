<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/24 下午9:28
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 快手链接解析核心类
 */
class KuaishouSpider
{
    private $cookie;
    private $userAgent;
    private $timeout;

    public function __construct($cookie, $userAgent, $timeout = 5000)
    {
        $this->cookie = $cookie;
        $this->userAgent = $userAgent;
        $this->timeout = $timeout;
    }

    /**
     * 主入口：解析URL
     */
    public function analyze(string $url): array
    {
        // 1. 获取重定向后的URL
        $redirectUrl = $this->getRedirectedUrl($url);
        if (empty($redirectUrl)) {
            return ['code' => 400, 'msg' => '无法获取有效链接'];
        }

        // 2. 获取页面内容
        $pageContent = $this->curlRequest($redirectUrl);
        if ($pageContent === false) {
            return ['code' => 500, 'msg' => '页面内容获取失败'];
        }

        // 3. 识别类型和ID
        [$contentType, $contentId] = $this->extractContentIdAndType($redirectUrl);
        if (empty($contentId)) {
            return ['code' => 400, 'msg' => '无法识别的链接类型'];
        }
        // 4. 尝试解析 (INIT_STATE 优先，其次 APOLLO_STATE)
        $result = $this->extractFromInitState($pageContent)
            ?? $this->extractFromApolloState($pageContent, $contentId, $contentType);

        return $result ?? ['code' => 404, 'msg' => '未找到有效媒体信息'];
    }

    /**
     * 解析 INIT_STATE
     */
    private function extractFromInitState(string $pageContent): ?array
    {
        $pattern = '/window\.INIT_STATE\s*=\s*(.*?)\<\/script>/s';
        if (!preg_match($pattern, $pageContent, $matches)) {
            return null;
        }

        $jsonString = rtrim(trim($matches[1]), ';');
        $data = json_decode($jsonString, true);

        // 解析失败时的容错处理
        if (json_last_error() !== JSON_ERROR_NONE) {
            $cleanedJsonString = stripslashes($jsonString);
            $cleanedJsonString = str_replace([
                '"{"err_msg":"launchApplication:fail"}"',
                '"{"err_msg":"system:access_denied"}"'
            ], [
                '"err_msg","launchApplication:fail"',
                '"err_msg","system:access_denied"'
            ], $cleanedJsonString);

            $data = json_decode($cleanedJsonString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['code' => 500, 'msg' => 'JSON解析错误: ' . json_last_error_msg()];
            }
        }
        // 过滤有效数据
        $filteredData = $this->filterMediaData($data);
        if (empty($filteredData)) {
            return null;
        }

        $firstItem = reset($filteredData);
        $photo = $firstItem['photo'] ?? [];
        // 提取公共音乐信息
        $musicInfo = [];
        $musicSource = $photo['music'] ?? ($photo['soundTrack'] ?? []);
        if (!empty($musicSource)) {
            $musicInfo = [
                'name' => $musicSource['name'] ?? '',
                'artist' => $musicSource['artist'] ?? '',
                'cover' => $musicSource['imageUrls'][0]['url'] ?? ($musicSource['avatarUrls'][0]['url'] ?? ''),
                'url' => $musicSource['audioUrls'][0]['url'] ?? ''
            ];
        }

        // 1. 尝试提取图片 (图集)
        $imageList = $photo['ext_params']['atlas']['list'] ?? [];
        if (!empty($imageList)) {
            return [
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'type' => 'image',
                    'title' => $photo['caption'] ?? '',
                    'author' => $photo['userName'] ?? '',
                    'avatar' => $photo['headUrl'] ?? '',
                    'count' => count($imageList),
                    'like' => $photo['likeCount'] ?? 0,
                    'time' => $photo['timestamp'] ?? 0,
                    'music' => 'http://txmov2.a.kwimgs.com' . ($photo['ext_params']['atlas']['music'] ?? ''),
                    'images' => array_map(function ($path) {
                        return 'http://tx2.a.yximgs.com/' . $path;
                    }, $imageList),
                    'api' => 1
                ]
            ];
        }

        // 2. 尝试提取单张图片
        if ((isset($photo['photoType']) && $photo['photoType'] === 'SINGLE_PICTURE') || (isset($photo['singlePicture']) && $photo['singlePicture'] === true)) {
            $coverUrls = $photo['coverUrls'] ?? [];
            $imageUrl = '';
            if (!empty($coverUrls)) {
                $imageUrl = $coverUrls[0]['url'];
            }

            if (!empty($imageUrl)) {
                return [
                    'code' => 200,
                    'msg' => '解析成功',
                    'data' => [
                        'type' => 'image',
                        'author' => $photo['userName'] ?? '',
                        'avatar' => $photo['headUrl'] ?? '',
                        'like' => $photo['likeCount'] ?? 0,
                        'time' => $photo['timestamp'] ?? 0,
                        'title' => $photo['caption'] ?? '',
                        'cover' => $imageUrl,
                        'url' => $imageUrl,
                        'images' => [$imageUrl],
                        'music' => $musicInfo
                    ]
                ];
            }
        }

        // 3. 尝试提取视频
        if (isset($photo['mainMvUrls']) || (isset($photo['photoType']) && $photo['photoType'] === 'VIDEO')) {
            $videoUrl = $photo['mainMvUrls'][0]['url'] ?? '';
            if (empty($videoUrl) && isset($photo['manifest']['adaptationSet'][0]['representation'][0]['url'])) {
                $videoUrl = $photo['manifest']['adaptationSet'][0]['representation'][0]['url'];
            }

            if (!empty($videoUrl)) {
                return [
                    'code' => 200,
                    'msg' => '解析成功',
                    'data' => [
                        'type' => 'video',
                        'author' => $photo['userName'] ?? '',
                        'avatar' => $photo['headUrl'] ?? '',
                        'like' => $photo['likeCount'] ?? 0,
                        'time' => $photo['timestamp'] ?? 0,
                        'title' => $photo['caption'] ?? '',
                        'cover' => $photo['coverUrls'][0]['url'] ?? '',
                        'url' => $videoUrl,
                        'music' => $musicInfo
                    ]
                ];
            }
        }

        return null;
    }

    /**
     * 解析 APOLLO_STATE
     */
    private function extractFromApolloState(string $pageContent, string $contentId, string $contentType): ?array
    {
        $pattern = '/window\.__APOLLO_STATE__\s*=\s*(.*?)\<\/script>/s';
        if (!preg_match($pattern, $pageContent, $matches)) {
            return null;
        }

        $cleanedData = preg_replace('/function\s*\([^)]*\)\s*{[^}]*}/', ':', $matches[1]);
        $cleanedData = preg_replace('/,\s*(?=}|])/', '', $cleanedData);
        $cleanedData = str_replace(';(:());', '', $cleanedData);

        $apolloState = json_decode($cleanedData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $videoInfo = $apolloState['defaultClient'] ?? null;
        if (empty($videoInfo)) {
            return null;
        }

        $key = "VisionVideoDetailPhoto:{$contentId}";
        $videoData = $videoInfo[$key] ?? null;
        if (empty($videoData)) {
            return null;
        }

        $authorData = null;
        foreach ($videoInfo as $k => $v) {
            if (strpos($k, 'VisionVideoDetailAuthor:') === 0) {
                $authorData = $v;
                break;
            }
        }

        $videoUrl = '';
        if ($contentType === 'long-video') {
            $videoUrl = $videoData['manifestH265']['json']['adaptationSet'][0]['representation'][0]['backupUrl'][0] ?? '';
        } else {
            $videoUrl = $videoData['photoUrl'] ?? '';
        }

        if (empty($videoUrl)) {
            return null;
        }

        // 根据 contentType 确定资源类型
        $type = 'video';
        if ($contentType === 'photo') {
            $type = 'image';
        }

        return [
            'code' => 200,
            'msg' => '解析成功',
            'data' => [
                'type' => $type,
                'author' => $authorData['name'] ?? '',
                'avatar' => $authorData['headerUrl'] ?? '', // APOLLO STATE 可能字段不同，这里做个假设，或者暂时留空
                'title' => $videoData['caption'] ?? '',
                'cover' => $videoData['coverUrl'] ?? '',
                'url' => $videoUrl,
                // APOLLO STATE 下其他字段可能需要进一步抓包确认，暂时保持基础信息
            ]
        ];
    }

    private function filterMediaData(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (strpos($key, 'tusjoh') === 0 && (isset($value['fid']) || isset($value['photo']))) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function getRedirectedUrl(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => $this->timeout
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (curl_errno($ch)) {
            $finalUrl = null;
        }
        curl_close($ch);
        return $finalUrl;
    }

    private function curlRequest(string $url)
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'sec-ch-ua: "Google Chrome";v="143", "Chromium";v="143", "Not A(Brand";v="24"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'Cookie: ' . $this->cookie
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function extractContentIdAndType(string $url): array
    {
        $patterns = [
            'short-video' => '/short-video\/([^?]+)/',
            'long-video' => '/long-video\/([^?]+)/',
            'photo' => '/photo\/([^?]+)/'
        ];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return [$type, $matches[1]];
            }
        }
        return ['', ''];
    }
}
