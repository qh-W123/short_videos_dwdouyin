<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/1/24 下午5:05
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 抖音主页解析
 */
header('Content-Type: application/json; charset=utf-8');
// 关闭错误显示，避免破坏JSON格式
error_reporting(E_ALL);
ini_set('display_errors', 0);
// 设置超时和内存
set_time_limit(600);
ini_set('memory_limit', '512M');

require_once 'DouyinHomeParser.php';

// ================= 配置区域 =================

// 请在此处填入抖音Cookie
$cookie = '';
//cookie比较严格 feed接口请求头的cookie比较全
// ===========================================


// 获取参数
$url = $_GET['url'] ?? ''; // 分享链接
$id = $_GET['id'] ?? '';   // 用户ID (sec_uid)
$count = isset($_GET['count']) ? (int)$_GET['count'] : 18; // 获取数量，默认18

try {
    // 实例化解析器
    $parser = new DouyinHomeParser($cookie);

    // 获取数据
    $result = $parser->getData($url, $id, $count);

    // 输出结果
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'code' => 500,
        'msg' => 'error',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
