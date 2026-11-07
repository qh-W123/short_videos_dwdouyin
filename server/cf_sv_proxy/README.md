# cf_sv_proxy — 短视频媒体直链代理（Cloudflare Workers 版）

<div align="center">
  <p><em>解决抖音、微博解析出来的视频/图片直链 <strong>403 防盗链</strong>问题</em></p>
</div>

---

## 📖 简介

短视频平台解析出来的无水印直链（如 `douyinvod.com`、`sinaimg.cn` 域名）通常校验请求头中的 `Referer`，直接在浏览器或其他服务中引用会返回 **403 Forbidden**。

本 Worker 在 Cloudflare 边缘节点上以正确的 `Referer`、`User-Agent` 向上游拉取媒体内容，并**流式转发**给请求方：

- 请求方无需携带任何防盗链头，拿到即播/即显/即下；
- 上游内容流式透传，不占用 Worker 内存，不受响应体大小限制；
- 抖音 / 微博双平台预设，视频 / 图片双媒体预设，可自动探测媒体类型。

> **与本仓库 PHP 版的关系**：`api/svproxyurl.php` 是同一功能的 PHP 实现。二者按部署环境二选一即可——有 Cloudflare 账号用本 Worker（免服务器、全球边缘节点加速），否则用 PHP 版。

---

## ✨ 功能特性

- **302 手动跟随**：最多 10 跳，每一跳都重新注入 `Referer` 与 `User-Agent`（标准 `redirect: follow` 跨域会丢失 Referer，防盗链场景必须手动跟）
- **双平台预设**：`type=douyin|weibo`，自动匹配对应平台的 Referer 与下载文件名前缀
- **双媒体预设**：视频走 `video/*` Accept 与下载头，图片走 `image/*` Accept 与 24 小时缓存
- **媒体类型自动探测**：不传 `mediatype` 时自动 HEAD 探测上游 Content-Type
- **两种 URL 输入**：Base64 编码（兼容空格还原 `+`）或明文 http(s) URL 直传
- **统一 JSON 错误**：所有失败路径返回 `{code, msg}` 结构，403 附带排查提示
- **CORS 全开放**：带 `OPTIONS` 预检响应，可直接被前端页面跨域引用
- **可选域名白名单**：一行配置限定代理目标，防止被当作任意资源代理滥用

---

## 🚀 部署

### 方式一：Cloudflare Dashboard（推荐，零工具）

1. 登录 [Cloudflare Dashboard](https://dash.cloudflare.com/) → **Workers 和 Pages** → **创建应用程序** → **创建 Worker**
2. 全选并删除在线编辑器默认代码，粘贴本目录 `worker.js` 的全部内容
3. 点击 **部署**，得到形如 `https://xxx.workers.dev` 的地址

### 方式二：Wrangler CLI

```bash
npm install -g wrangler
wrangler login
cd cf_sv_proxy
echo 'name = "cf_sv_proxy"
main = "worker.js"
compatibility_date = "2024-01-01"' > wrangler.toml
wrangler deploy
```

---

## 📡 接口文档

### 请求

```plaintext
GET https://你的worker域名/?proxyurl={媒体直链}&type={平台}&mediatype={媒体类型}&download={是否下载}
```

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|:---:|------|
| `proxyurl` | 字符串 | ✅ | 媒体直链。支持 **Base64 编码**（推荐，兼容 `+` 被转成空格）或**明文 http(s) URL** |
| `type` | 字符串 | ❌ | 目标平台：`douyin`（默认）/ `weibo`，决定注入的 Referer 与文件名前缀 |
| `mediatype` | 字符串 | ❌ | `video` / `image`；不传时自动 HEAD 探测上游 Content-Type |
| `download` | 字符串 | ❌ | `true`（默认）返回 `Content-Disposition: attachment` 触发下载；`false` 内联播放/显示 |

### 响应

**成功（HTTP 200）**：流式返回媒体内容，关键响应头：

| 响应头 | 说明 |
|--------|------|
| `Content-Type` | 透传上游类型（如 `video/mp4`、`image/jpeg`） |
| `Content-Disposition` | `download=true` 时返回 `attachment; filename="{platform}_{media}_{hash}.{ext}"` |
| `Cache-Control` | 图片恒为 `public, max-age=86400`；视频仅 `download=false` 时不设置 |
| `X-Proxy` | 标识代理渠道，如 `douyin-video-proxy` |
| `Access-Control-Allow-*` | CORS 全开放 |

**失败**：返回 JSON，结构与上游 HTTP 状态码一致：

```json
{"code": 403, "msg": "请求失败，HTTP状态码: 403（可能需要更新Cookie或Referer头信息）"}
```

| 状态码 | 场景 |
|:---:|------|
| `400` | `proxyurl` 缺失 / URL 无效 / Base64 解码失败 |
| `403` | 上游防盗链拦截（含白名单拒绝） |
| `404` | 上游资源不存在或直链已过期 |
| `500` | 网络错误 / 请求超时（30s） |

### 调用示例

```bash
# 1. 明文 URL，代理抖音原画视频（默认触发下载）
curl -L -o video.mp4 "https://xxx.workers.dev/?proxyurl=https://aweme.snssdk.com/aweme/v1/play/?video_id=xxxx%26ratio=default"

# 2. Base64 编码 URL（PHP 生成：base64_encode($url)）
curl -L -o video.mp4 "https://xxx.workers.dev/?proxyurl=aHR0cHM6Ly9hd2VtZS5zbnNzZGsuY29tL..."

# 3. 代理图片并内联显示（不触发下载）
curl -o cover.jpg "https://xxx.workers.dev/?proxyurl=<base64>&mediatype=image&download=false"

# 4. 代理微博视频
curl -L -o weibo.mp4 "https://xxx.workers.dev/?proxyurl=<base64>&type=weibo"
```

> **在解析结果中拼接**：抖音解析接口返回的视频 `url` 出现 403 时，将其 Base64 编码后拼入本代理即可：
> `https://xxx.workers.dev/?proxyurl=<?php echo base64_encode($videoUrl); ?>`

---

## ⚙️ 配置项

`worker.js` 顶部常量：

| 常量 | 默认值 | 说明 |
|------|--------|------|
| `ALLOWED_HOST_SUFFIXES` | `[]`（不限制） | 目标域后缀白名单。**公开部署建议填写**，例如 `['douyinvod.com', 'douyinpic.com', 'snssdk.com', 'weibo.com', 'sinaimg.cn']`，防止 Worker 被当作任意资源代理滥用 |
| `REQUEST_TIMEOUT` | `30000` | 媒体拉取超时（毫秒，302 每跳独立计时） |
| `PROBE_TIMEOUT` | `15000` | 媒体类型 HEAD 探测超时 |
| `MAX_REDIRECTS` | `10` | 302 手动跟随的最大跳数 |

---

## ⚠️ 注意事项

1. **防盗链原理**：代理不是"破解"，只是以合法的 Referer（`douyin.com` / `weibo.com`）代为请求。若平台升级签名校验（如部分带 `x-signature` 的链接），仍可能 403，需更新解析接口获取新的带签名直链。
2. **直链时效性**：抖音 CDN 直链通常带 `x-expires` 过期参数，过期后返回 404，请实时解析、即时代理，不要缓存直链。
3. **Workers 配额**：免费版每日 10 万次请求；每次代理消耗 1 个子请求（302 跟随每跳另计），批量下载请注意配额。
4. **滥用防护**：本 Worker 默认不限制目标域名（与历史行为一致）。公开部署请务必配置 `ALLOWED_HOST_SUFFIXES` 白名单。
5. **文件名哈希变更**：下载文件名的哈希由 MD5 调整为 SHA-256 前 16 位（标准 WebCrypto，跨运行时通用），文件名格式仍为 `{平台}_{媒体}_{哈希}.{扩展名}`。

---

## 📄 许可证

遵循本仓库 [MIT 许可证](../LICENSE)。

---

*[返回项目主文档](../README.md)*
