<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/18 下午4:53
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 快手链接图片/视频信息提取工具
 */

require_once "KuaishouSpider.php";

// 跨域与响应头设置
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

// 配置
define('USER_AGENT', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1 Edg/122.0.0.0');
define('JSON_OPTIONS', 480);

// 主程序逻辑
$url = $_GET['url'] ?? '';

if (empty($url)) {
    echo json_encode(['code' => 201, 'msg' => 'url为空'], JSON_OPTIONS);
} else {
    //务必填cookie
    $spider = new KuaishouSpider('', USER_AGENT);
    $result = $spider->analyze($url);
    echo json_encode($result, JSON_OPTIONS);
}
