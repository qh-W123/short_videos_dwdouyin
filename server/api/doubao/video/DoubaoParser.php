<?php
/**
*@Author: JH-Ahua
*@CreateTime: 2026/4/24 17:17
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 豆包ai视频无水印解析（非对话生成的视频）-实现类
*/
class DoubaoParser
{
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
    private $cookie = 'i18next=zh-CN; ttwid=1%7CFGYNcsGXqieXVBP4QNemEXTZLZoArf0NEPXt1WhSmm8%7C1772615343%7Cc6580d4acaa52df7479b610305cc558176ba8dfcdd55bf8d9ce6fe86d3948009';
    private $apiUrl = 'https://www.doubao.com/creativity/share/get_video_share_info?version_code=20800&language=zh-CN&device_platform=web&aid=497858&real_aid=497858&pkg_type=release_version&device_id=&pc_version=3.8.3&region=&sys_region=&samantha_web=1&use-olympus-account=1&web_tab_id=4d5d17a6-6729-4c1e-9f55-09f093100f0a';

    public function parse($url)
    {
        if (empty($url)) {
            return $this->output(400, '请输入豆包视频链接');
        }

        $realUrl = $this->getRealUrl($url);
        
        $params = $this->extractParams($realUrl);
        
        if (empty($params['share_id']) || empty($params['video_id'])) {
            return $this->output(400, '无法从链接中提取必要参数');
        }

        $result = $this->requestApi($params);
        
        if ($result) {
            // 只有当API响应的code为0时才返回成功
            if (isset($result['code']) && $result['code'] === 0) {
                // 避免嵌套重复标签，如果API响应包含data字段，直接使用其内容
                if (isset($result['data'])) {
                    return $this->output(200, '解析成功', $result['data']);
                } else {
                    return $this->output(200, '解析成功', $result);
                }
            } else {
                return $this->output(500, '解析失败: API返回错误', $result);
            }
        }
        
        return $this->output(500, '解析失败');
    }

    private function getRealUrl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        curl_exec($ch);
        $realUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        return $realUrl ?: $url;
    }

    private function extractParams($url)
    {
        $parsed = parse_url($url);
        $params = [];
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
        }
        
        return $params;
    }

    private function requestApi($params)
    {
        $postData = [
            'share_id' => $params['share_id'] ?? '',
            'vid' => $params['video_id'] ?? '',
            'creation_id' => ''
        ];

        $headers = [
            'accept: application/json, text/plain, */*',
            'accept-language: zh-CN,zh;q=0.9',
            'agw-js-conv: str',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://www.doubao.com',
            'pragma: no-cache',
            'priority: u=1, i',
            'referer: ' . $this->buildReferer($params),
            'sec-ch-ua: "Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: ' . $this->userAgent,
            'x-tt-logid;'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return null;
        }
        
        return json_decode($response, true);
    }

    private function buildReferer($params)
    {
        return 'https://www.doubao.com/video-sharing?share_id=' . 
               ($params['share_id'] ?? '') . 
               '&source_type=mobile&video_id=' . ($params['video_id'] ?? '') . 
               '&share_scene=video_viewer';
    }

    private function output($code, $msg, $data = [])
    {
        return json_encode([
            'code' => $code,
            'msg' => $msg,
            'data' => $data
        ], 480);
    }
}