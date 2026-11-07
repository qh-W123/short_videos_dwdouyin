<?php

/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/24 下午5:05
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 抖音主页解析（原 dyzy，需登录 Cookie 调用 feed 接口）
 */
class DouyinHomeParser
{
    private $cookie;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct($cookie)
    {
        $this->cookie = $cookie;
    }

    /**
     * 获取数据主入口
     * @param string $url 分享链接
     * @param string $id 用户ID (sec_uid)
     * @param int $count 获取数量
     * @return array
     */
    public function getData($url = '', $id = '', $count = 18)
    {
        $sec_uid = '';

        if (!empty($url)) {
            $sec_uid = $this->extractSecUid($url);
        } elseif (!empty($id)) {
            $sec_uid = $id;
        }

        if (empty($sec_uid)) {
            return ['code' => 400, 'msg' => '请提供有效的链接(url)或ID(id)'];
        }

        return $this->fetchUserVideos($sec_uid, $count);
    }

    /**
     * 从分享链接提取sec_uid
     */
    private function extractSecUid($share_link)
    {
        // 处理抖音短链接
        if (preg_match('/https?:\/\/v\.douyin\.com\/[a-zA-Z0-9]+\/?/', $share_link, $matches)) {
            $redirect_url = $this->getRedirectUrl($matches[0]);
            if (preg_match('/user\/([^?\/]+)/', $redirect_url, $uid_matches)) {
                return $uid_matches[1];
            }
        }

        // 直接解析用户URL
        if (preg_match('/user\/([^?\/]+)/', $share_link, $uid_matches)) {
            return $uid_matches[1];
        }

        return null;
    }

    /**
     * 获取重定向URL
     */
    private function getRedirectUrl($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_USERAGENT => $this->userAgent
        ]);
        curl_exec($ch);
        $redirect_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $redirect_url;
    }

    /**
     * 获取用户视频列表
     */
    private function fetchUserVideos($sec_uid, $count = 18)
    {
        $headers = $this->buildHeaders($sec_uid);

        $all_items = [];
        $max_cursor = 0;
        $has_more = true;
        $page = 0;
        $max_pages = ceil($count / 18);

        // 限制最大页数以防止超时，可根据需要调整
        if ($max_pages > 50) $max_pages = 50;

        while ($has_more && count($all_items) < $count && $page < $max_pages) {
            $page++;
            $page_count = min(18, $count - count($all_items));
            $url = $this->buildApiUrl($sec_uid, $max_cursor, $page_count);

            $result = $this->makeRequest($url, $headers);

            if (!$result['success'] || $result['http_code'] !== 200) {
                return ['code' => 500, 'msg' => '请求失败: ' . ($result['error'] ?? "HTTP {$result['http_code']}")];
            }

            $data = json_decode($result['data'], true);
            if (!$data || !isset($data['aweme_list']) || !is_array($data['aweme_list'])) {
                // 如果第一次就失败，直接返回错误
                if (empty($all_items)) {
                    return ['code' => 500, 'msg' => '数据解析失败'];
                }
                break; // 如果已经获取了一部分，就返回已获取的
            }

            if (isset($data['status_code']) && $data['status_code'] !== 0) {
                if (empty($all_items)) {
                    return ['code' => 500, 'msg' => 'API错误: ' . ($data['status_msg'] ?? 'Unknown')];
                }
                break;
            }

            $items = $data['aweme_list'];
            $all_items = array_merge($all_items, $items);

            $has_more = isset($data['has_more']) && $data['has_more'] == 1;
            if ($has_more && isset($data['max_cursor'])) {
                $max_cursor = $data['max_cursor'];
                usleep(500000); // 避免请求过快
            } else {
                break;
            }
        }

        $parsed_items = [];
        // 只返回请求的数量
        foreach (array_slice($all_items, 0, $count) as $index => $aweme) {
            $parsed_items[] = $this->parseAwemeItem($aweme, $index);
        }

        return [
            'code' => 200,
            'msg' => 'success',
            'data' => $parsed_items,
            'pagination' => [
                'total' => count($all_items),
                'has_more' => $has_more
            ]
        ];
    }

    /**
     * 构建请求头
     */
    private function buildHeaders($sec_uid)
    {
        return [
            'authority: www.douyin.com',
            'accept: application/json, text/plain, */*',
            'accept-encoding: gzip, deflate',
            'accept-language: zh-CN,zh;q=0.9,en;q=0.8',
            'cache-control: no-cache',
            'pragma: no-cache',
            'referer: https://www.douyin.com/user/' . $sec_uid,
            'sec-ch-ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: ' . $this->userAgent
        ];
    }

    /**
     * 构建API请求URL
     */
    private function buildApiUrl($sec_uid, $max_cursor = 0, $count = 18)
    {
        $params = [
            'device_platform' => 'webapp',
            'aid' => '6383',
            'channel' => 'channel_pc_web',
            'sec_user_id' => $sec_uid,
            'max_cursor' => $max_cursor,
            'count' => $count,
            'publish_video_strategy_type' => '2',
            'pc_client_type' => '1',
            'version_code' => '170400',
            'version_name' => '17.4.0',
            'cookie_enabled' => 'true',
            'screen_width' => '1920',
            'screen_height' => '1080',
            'browser_language' => 'zh-CN',
            'browser_platform' => 'Win32',
            'browser_name' => 'Chrome',
            'browser_version' => '120.0.0.0',
            'browser_online' => 'true',
            'engine_name' => 'Blink',
            'engine_version' => '120.0.0.0',
            'os_name' => 'Windows',
            'os_version' => '10',
            'cpu_core_num' => '16',
            'device_memory' => '8',
            'platform' => 'PC',
            'downlink' => '10',
            'effective_type' => '4g',
            'round_trip_time' => '50',
            'webid' => $this->generateWebId()
        ];

        return 'https://www.douyin.com/aweme/v1/web/aweme/post/?' . http_build_query($params);
    }

    /**
     * 生成Web ID
     */
    private function generateWebId()
    {
        return 'verify_' . substr(md5(uniqid(mt_rand(), true)), 0, 16);
    }

    /**
     * 发送HTTP请求
     */
    private function makeRequest($url, $headers)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIE => $this->cookie,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => $this->userAgent
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'data' => $response
        ];
    }

    /**
     * 解析作品数据
     */
    private function parseAwemeItem($aweme, $index)
    {
        $item = [
            'index' => $index + 1,
            'aweme_id' => $aweme['aweme_id'] ?? '',
            'desc' => $aweme['desc'] ?? '',
            'create_time' => isset($aweme['create_time']) ? date('Y-m-d H:i:s', $aweme['create_time']) : '',
            'share_url' => "https://www.douyin.com/video/" . ($aweme['aweme_id'] ?? '')
        ];

        // 作者信息
        if (isset($aweme['author'])) {
            $item['author'] = $aweme['author']['nickname'] ?? '';
            $item['author_uid'] = $aweme['author']['uid'] ?? '';
            $item['author_sec_uid'] = $aweme['author']['sec_uid'] ?? '';
        }

        // 类型判断
        $is_image = isset($aweme['images']) && is_array($aweme['images']) && !empty($aweme['images']);
        $item['type'] = $is_image ? 'image' : 'video';

        if ($is_image) {
            // 图片处理
            $item['images'] = [];
            foreach ($aweme['images'] as $image) {
                if (isset($image['url_list'][0])) {
                    $item['images'][] = $image['url_list'][0];
                }
            }
            $item['cover'] = $item['images'][0] ?? '';
        } else {
            // 视频处理
            if (isset($aweme['video']['cover']['url_list'][0])) {
                $item['cover'] = $aweme['video']['cover']['url_list'][0];
            }
            $item['url'] = $this->ensureVideoMimeType($this->findVideoUrl($aweme));
            if (isset($aweme['video']['duration'])) {
                $item['duration'] = $aweme['video']['duration'] / 1000;
            }
        }

        // 音乐信息
        if (isset($aweme['music'])) {
            $item['music_title'] = $aweme['music']['title'] ?? '';
            $item['music_author'] = $aweme['music']['author'] ?? '';
            if (isset($aweme['music']['play_url']['url_list'][0])) {
                $item['music_url'] = $aweme['music']['play_url']['url_list'][0];
            }
        }

        // 统计数据
        if (isset($aweme['statistics'])) {
            $stats = $aweme['statistics'];
            $item['statistics'] = [
                'digg_count' => $stats['digg_count'] ?? 0,
                'comment_count' => $stats['comment_count'] ?? 0,
                'share_count' => $stats['share_count'] ?? 0,
                'collect_count' => $stats['collect_count'] ?? 0,
                'play_count' => $stats['play_count'] ?? 0
            ];
        }

        // 标签信息
        if (isset($aweme['text_extra']) && is_array($aweme['text_extra'])) {
            $item['hashtags'] = [];
            foreach ($aweme['text_extra'] as $tag) {
                if (isset($tag['hashtag_name'])) {
                    $item['hashtags'][] = $tag['hashtag_name'];
                }
            }
        }

        return $item;
    }

    /**
     * 查找视频URL
     */
    private function findVideoUrl($aweme)
    {
        if (isset($aweme['video']['play_addr']['url_list'][0])) {
            return $aweme['video']['play_addr']['url_list'][0];
        }

        if (isset($aweme['video']['bit_rate']) && is_array($aweme['video']['bit_rate'])) {
            foreach ($aweme['video']['bit_rate'] as $bit_rate) {
                if (isset($bit_rate['play_addr']['url_list'][0])) {
                    return $bit_rate['play_addr']['url_list'][0];
                }
            }
        }

        return '';
    }

    /**
     * 补齐视频直链的 mime_type=video_mp4（抖音 CDN 约定参数）
     *
     * 仅对 http(s) 地址生效；已存在 mime_type 参数则原样返回；
     * 无查询串用 ? 拼接，已有查询串用 &；带 fragment 时追加到 fragment 之前。
     */
    private function ensureVideoMimeType($url)
    {
        // 空安全字符串转换，与 DouyinParser::strv / workers 版 stringValue 语义保持一致
        $url = is_string($url) ? $url : ($url === null ? '' : (string)$url);
        if ($url === '' || stripos($url, 'http') !== 0) {
            return $url;
        }

        // fragment 不参与查询串判断，追加时要放在 fragment 之前
        $hashPos = strpos($url, '#');
        $base = $hashPos === false ? $url : substr($url, 0, $hashPos);
        $fragment = $hashPos === false ? '' : substr($url, $hashPos);

        $query = parse_url($base, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            if (array_key_exists('mime_type', $params)) {
                return $url;
            }
        }

        return $base . (strpos($base, '?') !== false ? '&' : '?') . 'mime_type=video_mp4' . $fragment;
    }
}
