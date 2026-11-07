<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2025/8/6 上午12:56
 * @UpdateTime: 2026/10/19
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 短视频聚合解析：一个入口自动识别平台，转发到对应的单平台解析接口
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// ==================== 配置区域 ====================
// 平台关键词与对应单平台接口的路径（相对本文件所在目录）。
// 如需转发到其他站点，将 path 改为完整 URL 即可，
// 例如 'https://api.bugpk.com/api/douyin/douyin.php'。
$platforms = [
    'douyin' => [
        'keywords' => ['douyin', 'iesdouyin'],
        'path' => 'douyin/douyin.php',
    ],
    'kuaishou' => [
        'keywords' => ['kuaishou'],
        'path' => 'kuaishou/ksjx.php',
    ],
    'xiaohongshu' => [
        'keywords' => ['xhs', 'xiaohongshu'],
        'path' => 'xiaohongshu/xhsjx.php',
    ],
    'bilibili' => [
        'keywords' => ['bilibili', 'b23'],
        'path' => 'bilibili/bilibili.php',
    ],
    'weibo' => [
        'keywords' => ['weibo'],
        'path' => 'weibo.php',
    ],
    'toutiao' => [
        'keywords' => ['toutiao'],
        'path' => 'toutiao.php',
    ],
    'pipixia' => [
        'keywords' => ['pipix'],
        'path' => 'ppxia.php',
    ],
    'pipigx' => [
        'keywords' => ['ippzone', 'pipigx'],
        'path' => 'pipigx.php',
    ],
    'zuiyou' => [
        'keywords' => ['izuiyou'],
        'path' => 'zuiyou.php',
    ],
];

// 转发请求超时（秒）
$requestTimeout = 30;

// 允许参与上游接口地址构造的域名白名单（精确匹配，支持带端口）。
// 留空 = 仅信任 Web 服务器配置的 SERVER_NAME（无法被请求头伪造），
// 防止伪造 Host 头把聚合转发指到任意主机（SSRF）。
// 反向代理/多域名部署时在此列出本站域名，例如 ['api.example.com', 'www.example.com']。
$allowedHosts = [];

// 是否允许私网/保留地址作为本站域名（127.0.0.1、内网 IP 等）。
// 仅本地开发（php -S、localhost 调试）临时置为 true；生产环境必须保持 false，
// 防止伪造 Host 头探测服务器内网。
$allowPrivateHosts = false;
// ==================================================

/**
 * 统一 JSON 输出并结束请求
 */
function outputJson($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data, 480);
    exit;
}

/**
 * 从输入中提取首个 http(s) 链接（支持整段分享文本）
 */
function extractShareUrl($text)
{
    if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $text, $matches)) {
        return rtrim($matches[0], '\'"，。；,.;');
    }
    return null;
}

/**
 * 根据关键词匹配平台配置
 */
function matchPlatform($text, $platforms)
{
    $lower = strtolower($text);
    foreach ($platforms as $config) {
        foreach ($config['keywords'] as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return $config;
            }
        }
    }
    return null;
}

/**
 * 校验主机名是否解析为公网 IP（拒绝本机、内网段、链路本地/云元数据等保留地址）
 */
function hostResolvesToPublicIp($host)
{
    $hostname = strtolower((string)preg_replace('/:\d+$/', '', trim((string)$host)));
    if ($hostname === '') {
        return false;
    }
    $ip = gethostbyname($hostname);
    if ($ip === $hostname && !filter_var($hostname, FILTER_VALIDATE_IP)) {
        return false; // 解析失败
    }
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * 解析本次请求可信的本站域名：
 * - 配置了 $allowedHosts 时，仅接受白名单内的 Host；
 * - 未配置时，仅信任服务器配置的 SERVER_NAME（HTTP_HOST 可被请求头伪造）；
 * - HTTP_HOST 的主机名与 SERVER_NAME 一致时保留其端口；
 * - 非 $allowPrivateHosts 模式下，选中的主机必须解析为公网 IP，防止伪造 Host 探测内网；
 * - 均不可信时返回空串，主流程拒绝转发。
 */
function resolveRequestHost($allowedHosts, $allowPrivateHosts)
{
    $hostOnly = function ($h) {
        return strtolower(trim((string)preg_replace('/:\d+$/', '', trim((string)$h))));
    };

    $httpHost = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
    $serverName = strtolower(trim($_SERVER['SERVER_NAME'] ?? ''));
    if ($serverName === '' && $httpHost === '') {
        return '';
    }

    if ($allowedHosts) {
        $allow = array_map(function ($h) {
            return strtolower(trim($h));
        }, $allowedHosts);
        if ($httpHost !== '' && in_array($hostOnly($httpHost), $allow, true)) {
            return $httpHost;
        }
        if ($serverName !== '' && in_array($hostOnly($serverName), $allow, true)) {
            return $serverName;
        }
        return '';
    }

    if ($serverName === '') {
        return '';
    }
    if ($httpHost !== '' && $hostOnly($httpHost) === $hostOnly($serverName)) {
        $pick = $httpHost;
    } else {
        $pick = $serverName;
    }

    if (!$allowPrivateHosts && !hostResolvesToPublicIp($pick)) {
        return '';
    }
    return $pick;
}

/**
 * 计算单平台接口的完整地址：
 * 相对路径基于可信本站域名与本文件所在目录拼接；
 * 配置为完整 URL（http 开头）时直接使用。
 */
function buildEndpoint($path, $host)
{
    if (stripos($path, 'http') === 0) {
        return $path;
    }

    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        $scheme = 'https';
    }

    // 本文件所在目录，例如 /api
    $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');

    return $scheme . '://' . $host . $baseDir . '/' . ltrim($path, '/');
}

/**
 * 转发 GET 请求并返回响应内容，失败返回 false
 */
function requestUrl($url, $timeout)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('聚合解析转发失败: ' . curl_error($ch));
        $response = false;
    }
    curl_close($ch);

    return $response;
}

/**
 * 校验字符串是否为有效 JSON
 */
function isValidJson($string)
{
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

// ==================== 主流程 ====================

// 获取请求参数（GET/POST 均支持，兼容未编码的分享文本）
$url = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fullUrl = $_SERVER['REQUEST_URI'];
    $urlParamPos = strpos($fullUrl, 'url=');
    if ($urlParamPos !== false) {
        $url = trim(urldecode(substr($fullUrl, $urlParamPos + 4)));
    }
} else {
    $url = trim($_POST['url'] ?? '');
}
if (!$url && isset($_GET['url'])) {
    $url = trim($_GET['url']);
}

if ($url === '' || ($shareUrl = extractShareUrl($url)) === null) {
    outputJson(['code' => 400, 'msg' => '请输入有效的链接', 'data' => []], 200);
}

$platform = matchPlatform($url, $platforms);
if (!$platform) {
    outputJson(['code' => 201, 'msg' => '不支持您输入的链接平台', 'data' => []], 200);
}

$requestHost = resolveRequestHost($allowedHosts, $allowPrivateHosts);
if ($requestHost === '') {
    outputJson(['code' => 500, 'msg' => '无法确定本站域名，请在 api/sv.php 配置 $allowedHosts', 'data' => []], 200);
}

$endpoint = buildEndpoint($platform['path'], $requestHost) . '?url=' . urlencode($shareUrl);
$response = requestUrl($endpoint, $requestTimeout);

if ($response === false) {
    outputJson(['code' => 500, 'msg' => '请求接口失败', 'data' => []], 200);
}

if (!isValidJson($response)) {
    outputJson(['code' => 500, 'msg' => '接口返回格式不正确', 'data' => []], 200);
}

// 上游单平台接口返回有效 JSON，原样透传
echo $response;
