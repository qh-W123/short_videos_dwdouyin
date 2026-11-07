<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/2/12 下午9:57
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: bilibili作品视频&作品视频合集解析-新版
 */

require_once 'BilibiliParser.php';

// Set headers
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');

// Get URL parameter
$url = isset($_GET['url']) ? $_GET['url'] : '';
//cookie必填
$cookie = '';

// Initialize parser and output result
$parser = new BilibiliParser($cookie);
echo $parser->parse($url);
