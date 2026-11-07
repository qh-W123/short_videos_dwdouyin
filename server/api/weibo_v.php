<?php
/**
*@Author: JH-Ahua
*@CreateTime: 2025/6/13 下午3:31
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 微博短视频解析爬虫版
*/
header("Access-Control-Allow-Origin: *");
header('content-type:application/json; charset=utf-8');
function weibo($url)
{
    $proxyurl = 'https://api.bugpk.com/api/weibo?proxyurl=';
    if (strpos($url, 'show?fid=') != false) {
        preg_match('/fid=(.*)/', $url, $id);
        $arr = json_decode(weibo_curl($id[1]), true);
    } else {
        preg_match('/\d+\:\d+/', $url, $id);
        $arr = json_decode(weibo_curl($id[0]), true);
    }
    if ($arr) {
        $one = key($arr['data']['Component_Play_Playinfo']['urls']);
        $video_url = $arr['data']['Component_Play_Playinfo']['urls'][$one];
        $arr = [
            'code' => 200,
            'msg' => '解析成功',
            'data' => [
                'author' => $arr['data']['Component_Play_Playinfo']['author'],
                'avatar' => $proxyurl.base64_encode('https:'.$arr['data']['Component_Play_Playinfo']['avatar']),
                'time' => $arr['data']['Component_Play_Playinfo']['real_date'],
                'title' => $arr['data']['Component_Play_Playinfo']['title'],
                'cover' => $proxyurl.base64_encode('https:'.$arr['data']['Component_Play_Playinfo']['cover_image']),
                'url' => $proxyurl.base64_encode('https:'.$video_url)
            ]
        ];
        return $arr;
    }
}

function weibo_curl($id)
{
    $cookie = "login_sid_t=6b652c77c1a4bc50cb9d06b24923210d; cross_origin_proto=SSL; WBStorage=2ceabba76d81138d|undefined; _s_tentry=passport.weibo.com; Apache=7330066378690.048.1625663522444; SINAGLOBAL=7330066378690.048.1625663522444; ULV=1625663522450:1:1:1:7330066378690.048.1625663522444:; TC-V-WEIBO-G0=35846f552801987f8c1e8f7cec0e2230; SUB=_2AkMXuScYf8NxqwJRmf8RzmnhaoxwzwDEieKh5dbDJRMxHRl-yT9jqhALtRB6PDkJ9w8OaqJAbsgjdEWtIcilcZxHG7rw; SUBP=0033WrSXqPxfM72-Ws9jqgMF55529P9D9W5Qx3Mf.RCfFAKC3smW0px0; XSRF-TOKEN=JQSK02Ijtm4Fri-YIRu0-vNj";
    $post_data = "data={\"Component_Play_Playinfo\":{\"oid\":\"$id\"}}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://weibo.com/tv/api/component?page=/tv/show/" . $id);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_REFERER, "https://weibo.com/tv/show/" . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}
$result = [];
$url = $_GET['url'];
if (empty($url)){
    $result = ['code' => 201, 'msg' => '链接不能为空！'];
} else {
    $info = weibo($url);
    // 检查 $info 是否为数组
    if (is_array($info) && $info['code'] == 200){
        $result = $info;
    } else{
        $result = ['code' => 404, 'msg' => '解析失败！'];
    }
}
echo json_encode($result, 480);
?>
