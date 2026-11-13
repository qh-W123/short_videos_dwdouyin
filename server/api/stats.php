<?php
/**
 * 访问统计面板：查看谁在用这个接口、解析了什么
 *
 * 用法：
 *   stats.php                     返回 JSON 汇总（默认最近 24 小时）
 *   stats.php?hours=72            最近 72 小时
 *   stats.php?limit=100           最近 100 条明细
 *   stats.php?format=html         返回可视化页面
 *   stats.php?ip=1.2.3.4          只看某个 IP
 *
 * 受 nginx 秘密前缀保护，未带前缀一律 404。
 */

require_once '_analytics.php';

header('Access-Control-Allow-Origin: *');

$hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 24;
$limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 200;
$format = (string)($_GET['format'] ?? 'json');
$filterIp = trim((string)($_GET['ip'] ?? ''));

$rows = dy_read_log($limit * 5, $hours * 3600);

if ($filterIp !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($filterIp) {
        return (string)($r['ip'] ?? '') === $filterIp;
    }));
}

// ---------- 汇总 ----------
$byIp = [];
$byGeo = [];
$byClient = [];
$byOs = [];
$byDevice = [];
$byHour = [];
$works = [];
$okCount = 0;
$failCount = 0;

foreach ($rows as $r) {
    $ip = (string)($r['ip'] ?? '');
    $geo = is_array($r['geo'] ?? null) ? $r['geo'] : [];
    $place = trim(implode(' ', array_filter([
        (string)($geo['country'] ?? ''),
        (string)($geo['region'] ?? ''),
        (string)($geo['city'] ?? ''),
        (string)($geo['isp'] ?? ''),
    ])));

    if (!isset($byIp[$ip])) {
        $byIp[$ip] = ['ip' => $ip, 'place' => $place, 'count' => 0, 'last' => '', 'client' => [], 'titles' => []];
    }
    $byIp[$ip]['count']++;
    $byIp[$ip]['last'] = max($byIp[$ip]['last'], (string)($r['time'] ?? ''));
    $client = (string)($r['client'] ?? '');
    if ($client !== '') {
        $byIp[$ip]['client'][$client] = ($byIp[$ip]['client'][$client] ?? 0) + 1;
        $byClient[$client] = ($byClient[$client] ?? 0) + 1;
    }
    $title = (string)($r['title'] ?? '');
    if ($title !== '' && count($byIp[$ip]['titles']) < 5) {
        $byIp[$ip]['titles'][$title] = true;
    }

    if ($place !== '') {
        $country = (string)($geo['country'] ?? '');
        $region = (string)($geo['region'] ?? '');
        $key = trim($country . ' ' . $region);
        $byGeo[$key] = ($byGeo[$key] ?? 0) + 1;
    }

    $os = (string)($r['os'] ?? '');
    if ($os !== '') {
        $byOs[$os] = ($byOs[$os] ?? 0) + 1;
    }
    $device = (string)($r['device'] ?? '');
    if ($device !== '') {
        $byDevice[$device] = ($byDevice[$device] ?? 0) + 1;
    }

    $hour = substr((string)($r['time'] ?? ''), 0, 13);
    if ($hour !== '') {
        $byHour[$hour] = ($byHour[$hour] ?? 0) + 1;
    }

    $code = (int)($r['code'] ?? 0);
    if ($code === 200) {
        $okCount++;
    } else {
        $failCount++;
    }

    $aweme = (string)($r['aweme_id'] ?? '');
    if ($aweme !== '') {
        if (!isset($works[$aweme])) {
            $works[$aweme] = [
                'aweme_id' => $aweme,
                'title' => (string)($r['title'] ?? ''),
                'author' => (string)($r['author'] ?? ''),
                'count' => 0,
                'ips' => [],
                'last' => '',
            ];
        }
        $works[$aweme]['count']++;
        if ($ip !== '') {
            $works[$aweme]['ips'][$ip] = true;
        }
        $works[$aweme]['last'] = max($works[$aweme]['last'], (string)($r['time'] ?? ''));
        if ($works[$aweme]['title'] === '') {
            $works[$aweme]['title'] = (string)($r['title'] ?? '');
        }
    }
}

// 每个 IP 的 client 计数转成可读字符串
foreach ($byIp as &$item) {
    $item['client'] = implode(' / ', array_map(
        function ($k, $v) {
            return $k . '×' . $v;
        },
        array_keys($item['client']),
        array_values($item['client'])
    ));
    $item['titles'] = array_keys($item['titles']);
}
unset($item);

foreach ($works as &$w) {
    $w['ip_count'] = count($w['ips']);
    unset($w['ips']);
}
unset($w);

uasort($byIp, function ($a, $b) {
    return $b['count'] <=> $a['count'];
});
uasort($byGeo, function ($a, $b) {
    return $b <=> $a;
});
uasort($byOs, function ($a, $b) {
    return $b <=> $a;
});
uasort($byDevice, function ($a, $b) {
    return $b <=> $a;
});
uasort($works, function ($a, $b) {
    return $b['count'] <=> $a['count'];
});
ksort($byHour);

$summary = [
    'generated_at' => date('c'),
    'range_hours' => $hours,
    'total' => count($rows),
    'unique_ips' => count($byIp),
    'unique_works' => count($works),
    'ok' => $okCount,
    'fail' => $failCount,
    'by_ip' => array_values($byIp),
    'by_region' => $byGeo,
    'by_os' => $byOs,
    'by_device' => $byDevice,
    'by_client' => $byClient,
    'by_hour' => $byHour,
    'works' => array_values($works),
    'recent' => array_slice($rows, 0, $limit),
];

if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    $title = '抖音解析接口 · 访问统计';
    $total = $summary['total'];
    $ips = $summary['unique_ips'];
    $worksN = $summary['unique_works'];
    $ok = $summary['ok'];
    $fail = $summary['fail'];

    $ipRows = '';
    foreach (array_slice($summary['by_ip'], 0, 100) as $it) {
        $ipRows .= '<tr><td>' . htmlspecialchars($it['ip']) . '</td><td>'
            . htmlspecialchars($it['place']) . '</td><td class="n">' . $it['count'] . '</td><td>'
            . htmlspecialchars($it['client']) . '</td><td>' . htmlspecialchars($it['last'])
            . '</td></tr>';
    }
    $workRows = '';
    foreach (array_slice($summary['works'], 0, 100) as $w) {
        $workRows .= '<tr><td>' . htmlspecialchars($w['aweme_id']) . '</td><td>'
            . htmlspecialchars($w['title']) . '</td><td>' . htmlspecialchars($w['author'])
            . '</td><td class="n">' . $w['count'] . '</td><td class="n">' . $w['ip_count'] . '</td></tr>';
    }
    $geoRows = '';
    foreach (array_slice($summary['by_region'], 0, 50, true) as $k => $v) {
        $geoRows .= '<tr><td>' . htmlspecialchars((string)$k) . '</td><td class="n">' . $v . '</td></tr>';
    }
    $devRows = '';
    foreach (array_slice($summary['by_device'], 0, 50, true) as $k => $v) {
        $devRows .= '<tr><td>' . htmlspecialchars((string)$k) . '</td><td class="n">' . $v . '</td></tr>';
    }
    $recentRows = '';
    foreach (array_slice($summary['recent'], 0, 100) as $r) {
        $geo = is_array($r['geo'] ?? null) ? $r['geo'] : [];
        $place = trim(implode(' ', array_filter([
            (string)($geo['city'] ?? ''),
            (string)($geo['isp'] ?? ''),
        ])));
        $code = (int)($r['code'] ?? 0);
        $cls = $code === 200 ? 'ok' : 'bad';
        $recentRows .= '<tr><td>' . htmlspecialchars((string)($r['time'] ?? ''))
            . '</td><td>' . htmlspecialchars((string)($r['ip'] ?? ''))
            . '</td><td>' . htmlspecialchars($place)
            . '</td><td>' . htmlspecialchars((string)($r['os'] ?? ''))
            . '</td><td>' . htmlspecialchars((string)($r['client'] ?? ''))
            . '</td><td>' . htmlspecialchars((string)($r['title'] ?? ''))
            . '</td><td class="' . $cls . '">' . $code . '</td><td class="n">'
            . htmlspecialchars((string)($r['rt'] ?? '')) . 's</td></tr>';
    }

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title><style>'
        . 'body{font:14px/1.6 -apple-system,"Microsoft YaHei",sans-serif;background:#0f1115;color:#d8dbe2;margin:0;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 4px}h2{font-size:15px;margin:28px 0 10px;color:#8fa8c8}'
        . '.sub{color:#6b7280;font-size:12px;margin-bottom:18px}'
        . '.cards{display:flex;gap:12px;flex-wrap:wrap}'
        . '.card{background:#191d26;border:1px solid #262b36;border-radius:10px;padding:14px 18px;min-width:110px}'
        . '.card .k{color:#6b7280;font-size:12px}.card .v{font-size:24px;font-weight:600;margin-top:2px}'
        . 'table{width:100%;border-collapse:collapse;background:#191d26;border-radius:10px;overflow:hidden}'
        . 'th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #222731;font-size:13px;vertical-align:top}'
        . 'th{background:#1f2530;color:#9aa7b8;font-weight:500;font-size:12px}'
        . 'tr:last-child td{border-bottom:none}'
        . 'td.n{text-align:right;font-variant-numeric:tabular-nums}'
        . 'td.ok{color:#4ade80}td.bad{color:#f87171}'
        . 'a{color:#60a5fa}'
        . '</style></head><body>'
        . '<h1>' . $title . '</h1>'
        . '<div class="sub">最近 ' . $hours . ' 小时 · 生成于 ' . $summary['generated_at']
        . ' · <a href="?format=json&hours=' . $hours . '">JSON</a></div>'
        . '<div class="cards">'
        . '<div class="card"><div class="k">总请求</div><div class="v">' . $total . '</div></div>'
        . '<div class="card"><div class="k">独立 IP</div><div class="v">' . $ips . '</div></div>'
        . '<div class="card"><div class="k">解析作品</div><div class="v">' . $worksN . '</div></div>'
        . '<div class="card"><div class="k">成功</div><div class="v">' . $ok . '</div></div>'
        . '<div class="card"><div class="k">失败</div><div class="v">' . $fail . '</div></div>'
        . '</div>'
        . '<h2>按 IP</h2><table><tr><th>IP</th><th>归属地</th><th class="n">次数</th><th>客户端</th><th>最近访问</th></tr>' . $ipRows . '</table>'
        . '<h2>按作品</h2><table><tr><th>作品 ID</th><th>标题</th><th>作者</th><th class="n">次数</th><th class="n">IP 数</th></tr>' . $workRows . '</table>'
        . '<h2>按地区</h2><table><tr><th>地区</th><th class="n">次数</th></tr>' . $geoRows . '</table>'
        . '<h2>按设备</h2><table><tr><th>设备</th><th class="n">次数</th></tr>' . $devRows . '</table>'
        . '<h2>最近请求</h2><table><tr><th>时间</th><th>IP</th><th>归属地</th><th>系统</th><th>客户端</th><th>作品</th><th>状态</th><th class="n">耗时</th></tr>' . $recentRows . '</table>'
        . '</body></html>';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
