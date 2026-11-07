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

header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

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

$parser = new DouyinParser();
echo $parser->parse($url);
