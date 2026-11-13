<?php
/**
 * 访问分析模块：记录每次 API 调用的客户端信息与解析目标
 *
 * 记录内容（每条一行 JSON，追加到 /var/log/dy/app.jsonl）：
 *   time      请求时间（ISO8601）
 *   ip        客户端公网 IP
 *   geo       地理信息（国家/省/市/运营商）
 *   ua        User-Agent 原文
 *   os/device/browser/client   UA 解析结果
 *   lang      Accept-Language
 *   ref       Referer
 *   origin    Origin
 *   method    HTTP 方法
 *   uri       请求路径（已剥离秘密前缀）
 *   target    用户提交的原始链接/分享文本
 *   aweme_id  解析出的作品 ID
 *   code      业务返回码
 *   msg       业务返回信息
 *   title     作品标题
 *   author    作者昵称
 *   video_id  抖音 video_id
 *   cache     缓存命中（hit/miss）
 *   rt        处理耗时（秒）
 */

/** 日志目录（容器内挂载宿主机 /data/workspace/dy_logs） */
function dy_log_dir()
{
    return '/var/log/dy';
}

/**
 * 取客户端真实 IP。
 *
 * 前置反向代理会以真实客户端 IP 覆盖 X-Forwarded-For（客户端伪造的会被丢弃），
 * 因此该头可信；为稳妥起见仍取最后一段（离网关最近的那个）。
 * 无该头时（容器内直连）回退 REMOTE_ADDR。
 */
function dy_client_ip()
{
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        $parts = array_values(array_filter(array_map('trim', explode(',', $xff)), 'strlen'));
        if ($parts) {
            $ip = (string)end($parts);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/**
 * IP 地理查询，结果按 IP 缓存 24 小时（ip-api.com 免费额度 45 次/分钟）。
 * 内网/本机地址不查询。
 */
function dy_geo_lookup($ip, $ttl = 86400)
{
    $empty = ['country' => '', 'region' => '', 'city' => '', 'isp' => ''];
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return $empty;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '内网', 'region' => '', 'city' => '', 'isp' => 'LAN'];
    }

    $cacheDir = dy_log_dir() . '/geo';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/' . md5($ip) . '.json';
    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $result = $empty;
    $ch = curl_init('http://ip-api.com/json/' . rawurlencode($ip)
        . '?lang=zh-CN&fields=status,country,regionName,city,isp');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
        if (is_array($json) && ($json['status'] ?? '') === 'success') {
            $result = [
                'country' => (string)($json['country'] ?? ''),
                'region' => (string)($json['regionName'] ?? ''),
                'city' => (string)($json['city'] ?? ''),
                'isp' => (string)($json['isp'] ?? ''),
            ];
            @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    }

    return $result;
}

/**
 * 从 User-Agent 解析操作系统、设备型号、浏览器与客户端类型
 */
function dy_parse_ua($ua)
{
    $ua = (string)$ua;
    $out = ['os' => '', 'device' => '', 'browser' => '', 'client' => ''];

    if ($ua === '') {
        return ['os' => '未知', 'device' => '', 'browser' => '未知', 'client' => '未知'];
    }

    // 操作系统
    if (preg_match('/Android\s*([\d.]+)?/i', $ua, $m)) {
        $out['os'] = 'Android' . (($m[1] ?? '') !== '' ? ' ' . $m[1] : '');
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $out['os'] = preg_match('/OS\s*([\d_]+)/i', $ua, $m) ? 'iOS ' . str_replace('_', '.', $m[1]) : 'iOS';
    } elseif (preg_match('/Windows NT\s*([\d.]+)/i', $ua, $m)) {
        $map = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $out['os'] = 'Windows ' . ($map[$m[1]] ?? $m[1]);
    } elseif (preg_match('/Mac OS X\s*([\d_]+)/i', $ua, $m)) {
        $out['os'] = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Linux/i', $ua)) {
        $out['os'] = 'Linux';
    }

    // Android 设备型号：UA 里 "(Linux; Android 13; SM-G991B)"
    if (preg_match('/Android[^;]*;\s*([^;)]+?)\s*(?:Build\/|\))/i', $ua, $m)) {
        $model = trim($m[1]);
        if ($model !== '' && !preg_match('/^(wv|U)$/i', $model)) {
            $out['device'] = $model;
        }
    }

    // 客户端 / 浏览器
    if (stripos($ua, 'okhttp') !== false) {
        $out['client'] = 'OkHttp';
    } elseif (stripos($ua, 'Dalvik') !== false) {
        $out['client'] = 'Android 原生 HttpURLConnection';
    } elseif (stripos($ua, 'python-requests') !== false) {
        $out['client'] = 'Python requests';
    } elseif (stripos($ua, 'python-urllib') !== false) {
        $out['client'] = 'Python urllib';
    } elseif (stripos($ua, 'WindowsPowerShell') !== false || stripos($ua, 'PowerShell') !== false) {
        $out['client'] = 'PowerShell';
    } elseif (stripos($ua, 'curl') !== false) {
        $out['client'] = 'curl';
    } elseif (stripos($ua, 'PostmanRuntime') !== false) {
        $out['client'] = 'Postman';
    } elseif (stripos($ua, 'axios') !== false) {
        $out['client'] = 'axios';
    } elseif (stripos($ua, 'okhttp') === false && stripos($ua, 'Mozilla') !== false) {
        $out['client'] = '浏览器';
    } else {
        $out['client'] = '其他';
    }

    foreach ([
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'Firefox' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            if (preg_match('/' . preg_quote($needle, '/') . '[\/ ]([\d.]+)/i', $ua, $m)) {
                $out['browser'] = $name . ' ' . $m[1];
            } else {
                $out['browser'] = $name;
            }
            break;
        }
    }

    return $out;
}

/** 剥离请求路径里的秘密前缀，只留 /api/... */
function dy_clean_uri($uri)
{
    $uri = (string)$uri;
    $uri = (string)preg_replace('#^/[A-Za-z0-9]{20,}/#', '/', $uri);
    return $uri;
}

/** 从用户提交的文本/链接里提取作品 ID */
function dy_extract_aweme_id($target)
{
    $target = (string)$target;
    if (preg_match('#/video/(\d{16,20})#', $target, $m)) {
        return $m[1];
    }
    if (preg_match('#[?&]modal_id=(\d{16,20})#', $target, $m)) {
        return $m[1];
    }
    if (preg_match('/(?<!\d)(\d{16,20})(?!\d)/', $target, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 写入一条访问记录
 *
 * @param array $ctx 上下文，常用键：
 *   target / aweme_id / code / msg / title / author / video_id / cache / rt
 */
function dy_log_request(array $ctx = [])
{
    $dir = dy_log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $ip = dy_client_ip();
    $geo = dy_geo_lookup($ip);
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $parsed = dy_parse_ua($ua);

    $record = [
        'time' => date('c'),
        'ip' => $ip,
        'geo' => $geo,
        'ua' => mb_substr($ua, 0, 400),
        'os' => $parsed['os'],
        'device' => $parsed['device'],
        'browser' => $parsed['browser'],
        'client' => $parsed['client'],
        'lang' => mb_substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 120),
        'ref' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 400),
        'origin' => mb_substr((string)($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 200),
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'uri' => dy_clean_uri((string)($_SERVER['REQUEST_URI'] ?? '')),
        'target' => mb_substr((string)($ctx['target'] ?? ''), 0, 600),
        'aweme_id' => (string)($ctx['aweme_id'] ?? ''),
        'code' => (int)($ctx['code'] ?? 0),
        'msg' => mb_substr((string)($ctx['msg'] ?? ''), 0, 200),
        'title' => mb_substr((string)($ctx['title'] ?? ''), 0, 200),
        'author' => mb_substr((string)($ctx['author'] ?? ''), 0, 100),
        'video_id' => (string)($ctx['video_id'] ?? ''),
        'cache' => (string)($ctx['cache'] ?? ''),
        'rt' => round((float)($ctx['rt'] ?? 0), 3),
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    $logFile = $dir . '/app.jsonl';

    // 单文件超过 20MB 时轮转一次（保留最近 5 个历史文件），避免日志无限增长
    $maxSize = 20 * 1024 * 1024;
    $maxKeep = 5;
    if (is_file($logFile) && (int)@filesize($logFile) > $maxSize) {
        for ($i = $maxKeep - 1; $i >= 1; $i--) {
            $from = $logFile . '.' . $i;
            $to = $logFile . '.' . ($i + 1);
            if (is_file($from)) {
                @rename($from, $to);
            }
        }
        @rename($logFile, $logFile . '.1');
    }

    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * 读取访问记录（按时间倒序，最多 $limit 条）
 *
 * @param int $limit  最多返回条数
 * @param int $maxAge 只读取最近多少秒内的记录，0 表示不限
 * @return array<int,array>
 */
function dy_read_log($limit = 200, $maxAge = 0)
{
    $deadline = $maxAge > 0 ? time() - $maxAge : 0;
    $rows = [];

    // 依次读 app.jsonl、app.jsonl.1、app.jsonl.2 ...，凑够条数即停
    $files = [dy_log_dir() . '/app.jsonl'];
    for ($i = 1; $i <= 5; $i++) {
        $files[] = dy_log_dir() . '/app.jsonl.' . $i;
    }

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $part = dy_read_log_file($file, $limit * 3 - count($rows), $deadline);
        foreach ($part as $row) {
            $rows[] = $row;
        }
        if (count($rows) >= $limit * 3) {
            break;
        }
        // 若本文件已存在早于时间窗的记录，更老的文件不必再读
        if ($deadline > 0 && $part) {
            $oldest = strtotime((string)($part[count($part) - 1]['time'] ?? ''));
            if ($oldest && $oldest < $deadline) {
                break;
            }
        }
    }

    return array_slice($rows, 0, $limit);
}

/**
 * 从单个日志文件尾部倒序读取记录
 *
 * @param string $file
 * @param int    $limit   最多取多少条
 * @param int    $deadline 早于该时间戳即停止（0 表示不限）
 * @return array<int,array>
 */
function dy_read_log_file($file, $limit, $deadline = 0)
{
    if ($limit <= 0) {
        return [];
    }
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return [];
    }

    $stat = fstat($handle);
    $size = (int)($stat['size'] ?? 0);
    $chunk = 262144;
    $pos = $size;
    $buffer = '';
    $rows = [];
    $stop = false;

    while ($pos > 0 && !$stop && count($rows) < $limit) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($handle, $pos);
        $buffer = fread($handle, $read) . $buffer;
        $split = explode("\n", $buffer);
        // 首行可能被块边界截断，留到下一轮拼接
        $buffer = array_shift($split);
        foreach (array_reverse($split) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if ($deadline > 0 && strtotime((string)($row['time'] ?? '')) < $deadline) {
                $stop = true;
                break;
            }
            $rows[] = $row;
            if (count($rows) >= $limit) {
                $stop = true;
                break;
            }
        }
    }

    // 收尾：文件开头那一行（整文件只读了一块时它仍在 buffer 里）
    if (!$stop && $buffer !== '' && count($rows) < $limit) {
        $buffer = trim($buffer);
        if ($buffer !== '') {
            $row = json_decode($buffer, true);
            if (is_array($row)) {
                $tooOld = $deadline > 0 && strtotime((string)($row['time'] ?? '')) < $deadline;
                if (!$tooOld) {
                    $rows[] = $row;
                }
            }
        }
    }

    fclose($handle);
    return $rows;
}
