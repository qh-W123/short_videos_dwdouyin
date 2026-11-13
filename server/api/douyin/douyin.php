<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/19 下午8:59
 * @UpdateTime: 2026/10/19
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 抖音解析统一入口，免 Cookie，支持短视频、图集、实况
 */

require_once 'DouyinParser.php';
require_once __DIR__ . '/../_analytics.php';

header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

$dyStartedAt = microtime(true);
$dyCacheState = 'miss';

// 获取请求参数（GET 原样截取 url= 之后的完整内容，兼容未编码的分享文本）
$url = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fullUrl = $_SERVER['REQUEST_URI'];
    $urlParamPos = strpos($fullUrl, 'url=');
    if ($urlParamPos !== false) {
        $url = urldecode(substr($fullUrl, $urlParamPos + 4));
    }
} else {
    $url = $_POST['url'] ?? null;
}

if (!$url && isset($_GET['url'])) {
    $url = $_GET['url'];
}

/**
 * 结果短缓存：同一个 aweme_id 在 TTL 内直接命中，避免重复走上游
 * （上游详情请求约 1-2s，是整条链路的主要耗时）
 */
function dy_cache_read($url, $ttl)
{
    $key = dy_cache_key($url);
    if ($key === null) {
        return null;
    }
    $file = '/tmp/dy_cache/' . $key . '.json';
    if (!is_file($file)) {
        return null;
    }
    $age = time() - (int)@filemtime($file);
    if ($age < 0 || $age > $ttl) {
        return null;
    }
    $body = @file_get_contents($file);
    return ($body === false || $body === '') ? null : $body;
}

function dy_cache_write($url, $body)
{
    $key = dy_cache_key($url);
    if ($key === null || !is_string($body) || $body === '') {
        return;
    }
    // 只缓存成功结果，失败（400/500）不缓存
    $json = json_decode($body, true);
    if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
        return;
    }
    $dir = '/tmp/dy_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir)) {
        return;
    }
    $tmp = $dir . '/.' . $key . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $body) !== false) {
        @rename($tmp, $dir . '/' . $key . '.json');
    }

    // 顺手清理过期文件，避免目录无限增长（约 1/20 概率触发，成本可忽略）
    if (mt_rand(1, 20) === 1 && $handle = @opendir($dir)) {
        $deadline = time() - 3600;
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $deadline) {
                @unlink($path);
            }
        }
        closedir($handle);
    }
}

function dy_cache_key($url)
{
    if (!is_string($url) || $url === '') {
        return null;
    }
    // 作品 ID 是 16-20 位数字，命中即可作为缓存键
    if (preg_match('/(\d{16,20})/', $url, $m)) {
        return 'id_' . $m[1];
    }
    return 'h_' . substr(md5($url), 0, 20);
}

/**
 * 把响应发给客户端后记录访问日志。
 *
 * 用 fastcgi_finish_request() 先把响应刷给客户端，再做地理查询与落盘，
 * 这样首次遇到新 IP 时的 1-3s 地理查询不会拖慢接口响应。
 */
function dy_finish_and_log($url, $body, $cacheState, $startedAt)
{
    // 内网自转发（sv.php -> 单平台）不重复记录，由 sv.php 那次请求统一记录
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '172.31.0.10') {
        return;
    }

    $json = json_decode($body, true);
    $data = (is_array($json) && is_array($json['data'] ?? null)) ? $json['data'] : [];
    $author = is_array($data['author'] ?? null) ? (string)($data['author']['name'] ?? '') : '';

    $ctx = [
        'target' => (string)$url,
        'aweme_id' => (string)($data['aweme_id'] ?? '') ?: dy_extract_aweme_id((string)$url),
        'code' => is_array($json) ? (int)($json['code'] ?? 0) : 0,
        'msg' => is_array($json) ? (string)($json['msg'] ?? '') : '',
        'title' => (string)($data['title'] ?? ''),
        'author' => $author,
        'video_id' => (string)($data['video_id'] ?? ''),
        'cache' => $cacheState,
        'rt' => microtime(true) - $startedAt,
    ];

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    dy_log_request($ctx);
}

$cacheTtl = 300;
$cached = dy_cache_read($url, $cacheTtl);
if ($cached !== null) {
    header('X-Dy-Cache: hit');
    echo $cached;
    dy_finish_and_log($url, $cached, 'hit', $dyStartedAt);
    exit;
}

$parser = new DouyinParser();
$body = $parser->parse($url);
header('X-Dy-Cache: miss');
dy_cache_write($url, $body);
echo $body;
dy_finish_and_log($url, $body, 'miss', $dyStartedAt);
