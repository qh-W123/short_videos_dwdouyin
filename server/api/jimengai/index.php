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

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$base = realpath(__DIR__);
$base = ($base !== false) ? $base : __DIR__;
$parserFile = $base . DIRECTORY_SEPARATOR . 'JimengParser.php';
if (!is_readable($parserFile)) {
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '缺少 JimengParser.php：' . $parserFile, 'data' => []], 480);
    exit;
}
require_once $parserFile;
unset($base, $parserFile);

$itemId = '';

$rawBody = file_get_contents('php://input');
if ($rawBody !== '' && $rawBody !== false) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        if (isset($json['id']) && is_string($json['id']) && $json['id'] !== '') {
            $itemId = $json['id'];
        } elseif (isset($json['url']) && is_string($json['url'])) {
            $itemId = JimengParser::extractItemIdFromUrl($json['url']);
        }
    } else {
        $itemId = JimengParser::extractItemIdFromUrl(trim($rawBody));
    }
}

if ($itemId === '') {
    if (isset($_GET['id']) && is_string($_GET['id']) && $_GET['id'] !== '') {
        $itemId = $_GET['id'];
    } elseif (isset($_GET['url']) && is_string($_GET['url'])) {
        $itemId = JimengParser::extractItemIdFromUrl($_GET['url']);
    }
}

if ($itemId === '') {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => '请提供 id 参数', 'data' => []], 480);
    exit;
}

$parser = new JimengParser();
echo $parser->parseByItemId($itemId);