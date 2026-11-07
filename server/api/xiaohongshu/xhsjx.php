<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/19 下午8:58
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 小红书解析统一接口
 */

require_once 'XiaohongshuParser.php';

header("Access-Control-Allow-Origin: *");
header('Content-type: application/json');

// 获取请求参数
$url = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fullUrl = $_SERVER['REQUEST_URI'];
    $urlParamPos = strpos($fullUrl, 'url=');
    if ($urlParamPos !== false) {
        $encodedUrl = substr($fullUrl, $urlParamPos + 4);
        $url = urldecode($encodedUrl);
    }
} else {
    $url = $_POST['url'] ?? null;
}

if (!$url && isset($_GET['url'])) {
    $url = $_GET['url'];
}

// 配置Cookie
$cookie = "";

$parser = new XiaohongshuParser();
$parser->setCookie($cookie);
echo $parser->parse($url);
