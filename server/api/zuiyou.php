<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/3/31 上午11:25
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 最右解析
 */
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

/**
 * 构建统一格式的响应数组
 * @param int $code 状态码
 * @param string $msg 消息描述
 * @param array $data 附加数据
 * @return array 响应数组
 */
function outputJson(int $code, string $msg, array $data = []): array
{
    return [
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ];
}

/**
 * 从URL中提取指定参数
 * @param string $url 原始URL
 * @param string $param 要提取的参数名
 * @return string|null 参数值或null
 */
function getParamFromUrl(string $url, string $param): ?string
{
    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['query'])) {
        return null;
    }

    parse_str($parsedUrl['query'], $queryParams);
    return $queryParams[$param] ?? null;
}

/**
 * 获取URL重定向后的最终地址
 * @param string $url 原始URL
 * @return string|null 重定向后的URL或null
 */
function getRedirectUrl(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_LOW_SPEED_LIMIT => 256,
        CURLOPT_LOW_SPEED_TIME => 6,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 处理3xx重定向状态码
    if ($httpCode >= 300 && $httpCode < 400) {
        if (preg_match('/Location: (.*?)\r\n/', $response, $matches)) {
            $redirectUrl = trim($matches[1]);
            // 处理相对路径
            if (strpos($redirectUrl, 'http') !== 0) {
                $parsedOriginal = parse_url($url);
                $redirectUrl = $parsedOriginal['scheme'] . '://' . $parsedOriginal['host'] . $redirectUrl;
            }
            return $redirectUrl;
        }
    }

    // 无重定向或获取失败，返回原URL
    return $url;
}

/**
 * 从URL或其重定向地址中提取指定参数
 * @param string $url 原始URL
 * @param string $param 要提取的参数名
 * @return string|null 提取到的参数值或null
 */
function getParamFromUrlWithRedirect(string $url, string $param): ?string
{
    // 先尝试从原始URL提取参数
    $value = getParamFromUrl($url, $param);
    if (!empty($value)) {
        return $value;
    }

    // 原始URL无参数，获取重定向后的URL再尝试提取
    $redirectUrl = getRedirectUrl($url);
    if ($redirectUrl && $redirectUrl !== $url) {
        $value = getParamFromUrl($redirectUrl, $param);
        if (!empty($value)) {
            return $value;
        }
    }

    return null;
}

/**
 * curl请求处理函数
 * @param string $url 请求URL
 * @param array|null $headers 请求头
 * @param mixed|null $data POST数据
 * @return string|false 响应结果或false
 */
function curlRequest(string $url, ?array $headers = null, $data = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_LOW_SPEED_LIMIT => 256,
        CURLOPT_LOW_SPEED_TIME => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    if (isset($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $result = curl_exec($ch);

    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        trigger_error("cURL error: $error", E_USER_WARNING);
        return false;
    }

    curl_close($ch);
    return $result;
}

// 初始化响应数据
$responseData = null;

// 获取并验证输入URL（修复编码和参数截断问题）
$url = '';
if (isset($_SERVER['QUERY_STRING'])) {
    // 找到"url="在查询字符串中的位置
    $urlStart = strpos($_SERVER['QUERY_STRING'], 'url=');
    if ($urlStart !== false) {
        // 从"url="之后截取剩余字符串（包含所有参数）
        $urlPart = substr($_SERVER['QUERY_STRING'], $urlStart + 4);
        // 解码URL（处理%26等编码字符）
        $url = rawurldecode($urlPart);
    }
}
$url = trim($url);

if (empty($url)) {
    $responseData = outputJson(201, '请输入url');
}

// 验证URL是否为空
if (empty($url)) {
    $responseData = outputJson(201, '请输入url');
}

// 验证URL格式
if ($responseData === null && !filter_var($url, FILTER_VALIDATE_URL)) {
    $responseData = outputJson(201, '请输入有效的URL');
}

// 提取pid和vid参数
$pid = null;
$vid = null;

if ($responseData === null) {
    $pid = getParamFromUrlWithRedirect($url, 'pid');
    if (empty($pid)) {
        $responseData = outputJson(201, '找不到有效的pid参数（包括重定向后）');
    }
}
if ($responseData === null) {
    $vid = getParamFromUrlWithRedirect($url, 'vid');
}

// 调用API获取数据
if ($responseData === null) {
    $apiUrl = 'https://share.xiaochuankeji.cn/planck/share/post/detail_h5';
    $requestData = json_encode([
        'pid' => (int)$pid,
        'h_av' => '5.2.13.011'
    ]);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $apiResponse = curlRequest($apiUrl, $headers, $requestData);
    if ($apiResponse === false) {
        $responseData = outputJson(500, 'API请求失败');
    } else {
        $data = json_decode($apiResponse, true);

        // 检查JSON解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            $responseData = outputJson(500, 'API响应解析失败: ' . json_last_error_msg());
        } else {
            // 安全提取数据，避免未定义索引错误
            $postData = $data['data']['post'] ?? [];
            $memberData = $postData['member'] ?? [];
            $videosData = $postData['videos'] ?? [];
            $imgsData = $postData['imgs'][0] ?? [];
            $vid = $imgsData['id'];
            if (empty($vid)) {
                $responseData = outputJson(201, '找不到有效的vid参数（包括重定向后）');
            }
            $json = [
                'author' => $memberData['name'] ?? null,
                'avatar' => $memberData['avatar_urls']['origin']['urls'][0] ?? null,
                'title' => $postData['content'] ?? null,
                'cover' => $imgsData['urls']['540_webp']['urls'][0] ?? null,
                'url' => $videosData[$vid]['url'] ?? null,
            ];

            $responseData = outputJson(200, '请求成功', $json);
        }
    }
}

// 输出最终JSON结果
echo json_encode($responseData, 480);
?>
