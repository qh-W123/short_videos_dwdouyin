<?php
/**
 *@Author: JH-Ahua
 *@CreateTime: 2026/4/30 23:27
 *@email: admin@bugpk.com
 *@blog: www.jiuhunwl.cn
 *@Api: api.bugpk.com
 *@tip: 微博视频去水印解析（非文章视频图文解析）
 */
@set_time_limit(90);
@ini_set('max_execution_time', '90');
header("Access-Control-Allow-Origin: *");

define('MAX_REDIRECTS', 5);
define('TIMEOUT', 30);

function main()
{
    $params = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $url = $params['url'] ?? '';

    if (empty($url)) {
        outputError('参数url不能为空', 400);
    }

    $videoId = extractVideoId($url);
    if (empty($videoId)) {
        outputError("无法从URL中提取视频ID: {$url}", 404);
    }

    $headers = getRequestHeaders();
    $result = fetchVideoInfo($videoId, $headers);
    outputSuccess($result);
}

function extractVideoId($url)
{
    $id = '';

    if (strpos($url, 'video.weibo.com/show') !== false) {
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            $id = $queryParams['fid'] ?? '';
        }
    } else if (strpos($url, 'weibo.com/tv/') !== false) {
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            $id = $queryParams['fid'] ?? '';
        }
        if (empty($id)) {
            $pattern = '/weibo\.com\/tv\/(show|v)\/([^?&]+)/';
            preg_match($pattern, $url, $matches);
            $id = $matches[2] ?? '';
        }
    } else if (strpos($url, 't.cn/') !== false) {
        $redirectUrl = getRedirectUrl($url);
        $id = !empty($redirectUrl) ? extractVideoId($redirectUrl) : '';
    }

    return $id;
}

function getRedirectUrl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECTION_TIMEOUT);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 256);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    curl_exec($ch);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return $redirectUrl;
}

function getRequestHeaders()
{
    //微博web端cookie
    return [
        'cookie: ',
        'referer: https://weibo.com/',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
    ];
}

function fetchVideoInfo($videoId, $headers)
{
    $apiUrl = 'https://weibo.com/tv/api/component';
    $pagePath = "/tv/show/{$videoId}";
    $requestUrl = "{$apiUrl}?page=" . urlencode($pagePath);

    $requestData = [
        'Component_Play_Playinfo' => ['oid' => $videoId]
    ];
    $postData = 'data=' . urlencode(json_encode($requestData));
    $response = sendCurlRequest($requestUrl, $postData, $headers, true);

    if (is_array($response)) {
        return $response;
    }

    if (empty($response)) {
        return ['code' => 500, 'msg' => 'API请求失败，无响应数据'];
    }

    $responseData = json_decode($response, true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        return ['code' => 500, 'msg' => 'API响应解析失败', 'raw' => $response];
    }

    if (isset($responseData['code']) && $responseData['code'] == 100000 && !empty($responseData['data']['Component_Play_Playinfo'])) {
        $proxyBase = "https://svproxy.168299.xyz/?type=weibo&proxyurl=";
        $videoInfo = $responseData['data']['Component_Play_Playinfo'];

        $backupUrls = [];
        $bestQuality = ['priority' => -1, 'url' => '', 'label' => '', 'quality' => ''];

        if (isset($videoInfo['urls']) && is_array($videoInfo['urls'])) {
            foreach ($videoInfo['urls'] as $quality => $url) {
                $fullUrl = $proxyBase . base64_encode('https:' . $url);
                $qualityKey = 'unknown';
                $priority = 0;

                if (strpos($quality, '2K') !== false) {
                    $qualityKey = 'origin';
                    $priority = 4;
                } elseif (strpos($quality, '1080P') !== false) {
                    $qualityKey = 'origin';
                    $priority = 3;
                } elseif (strpos($quality, '720P') !== false) {
                    $qualityKey = 'origin';
                    $priority = 2;
                } elseif (strpos($quality, '480P') !== false) {
                    $qualityKey = 'hd';
                    $priority = 1;
                } elseif (strpos($quality, '360P') !== false) {
                    $qualityKey = 'sd';
                    $priority = 0;
                }

                $backupUrls[] = ['label' => $quality, 'quality' => $qualityKey, 'url' => $fullUrl];

                if ($priority > $bestQuality['priority']) {
                    $bestQuality = ['priority' => $priority, 'url' => $fullUrl, 'label' => $quality, 'quality' => $qualityKey];
                }
            }
        }

        $mainUrl = $bestQuality['url'];
        $mainQuality = $bestQuality['quality'];

        $duration = 0;
        if (isset($videoInfo['duration_time'])) {
            $duration = is_numeric($videoInfo['duration_time']) ? (int)$videoInfo['duration_time'] : 0;
        }

        $avatar = '';
        if (!empty($videoInfo['avatar'])) {
            $avatar = $proxyBase . base64_encode('https:' . $videoInfo['avatar']);
        }

        $cover = '';
        if (!empty($videoInfo['cover_image'])) {
            $cover = $proxyBase . base64_encode('https:' . $videoInfo['cover_image']);
        }

        return [
            'code' => 200,
            'msg' => '解析成功',
            'data' => [
                'type' => 'video',
                'title' => $videoInfo['title'] ?? '',
                'desc' => $videoInfo['title'] ?? '',
                'author' => [
                    'name' => $videoInfo['author'] ?? '',
                    'id' => (string)($videoInfo['author_id'] ?? ''),
                    'avatar' => $avatar,
                ],
                'cover' => $cover,
                'url' => $mainUrl,
                'quality' => $mainQuality,
                'duration' => $duration,
                'video_backup' => $backupUrls,
                'extra' => [
                    'play_count' => $videoInfo['play_count'] ?? '',
                    'reposts_count' => $videoInfo['reposts_count'] ?? '',
                    'comments_count' => $videoInfo['comments_count'] ?? '',
                    'attitudes_count' => $videoInfo['attitudes_count'] ?? '',
                    'ip_info' => $videoInfo['ip_info_str'] ?? '',
                    'date' => $videoInfo['date'] ?? '',
                ],
            ]
        ];
    } else {
        $errorMsg = '解析失败,当前链接中的视频不存在。';
        if (!isset($responseData['code'])) {
            $errorMsg = '当前官方接口已失效！';
        } elseif ($responseData['code'] != 100000) {
            $errorMsg = '官方接口响应错误！';
        }
        return ['code' => 404, 'msg' => $errorMsg];
    }
}

function sendCurlRequest($url, $postData = '', $headers = [], $isJsonResponse = false, $isDownload = false)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, MAX_REDIRECTS);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECTION_TIMEOUT);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 256);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }

    if ($isDownload) {
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        if ($isJsonResponse) {
            return ['code' => 500, 'msg' => "cURL错误: {$error}"];
        } else {
            return false;
        }
    }

    if ($httpCode >= 400) {
        if ($isJsonResponse) {
            return ['code' => $httpCode, 'msg' => "API请求失败，HTTP状态码: {$httpCode}"];
        } else {
            return false;
        }
    }

    return $response;
}

function outputSuccess($data)
{
    header('Content-Type: application/json');
    echo json_encode($data, 480);
}

function outputError($message, $code = 500)
{
    header("Content-Type: application/json");
    echo json_encode(['code' => $code, 'msg' => $message], 480);
    exit;
}

main();