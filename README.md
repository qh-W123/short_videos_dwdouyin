# dwdouyin - 短视频作品解析下载工具

短视频作品解析与无水印下载工具。包含三部分：Android 客户端、Windows 桌面客户端，以及可自建的 PHP 解析服务器。

> ⚠️ 仅供个人技术学习使用。禁止商用、禁止批量爬取，请遵守各平台版权法规。下载内容版权归原作者所有。

## 仓库结构

```
.
├── app/          Android 客户端（Kotlin + Jetpack Compose）
├── desktop/      Windows 桌面客户端（C# WPF / .NET 6）
├── server/       PHP 解析服务器（short_videos，MIT）
└── dist/         已打包的成品
    ├── dwdouyin-v3.3.1.apk
    └── DwDouyin.exe
```

## Android 客户端（`app/`）

Kotlin + Jetpack Compose。支持抖音与 33+ 平台聚合解析（B站 / 快手 / YouTube / TikTok / 西瓜视频 / 知乎等）。

- **多平台解析**：粘贴任意分享链接（抖音短链 / 网页链、B站、快手等），自动识别并解析
- **全档位画质**：原画 / 1080P / 720P / 540P… 全部列出，每档显示文件大小与分辨率
- **图文下载**：视频与图文作品都支持，图片逐张保存
- **自定义接口**：可添加自己的解析接口（标准 / 完整画质两种格式），需要鉴权的接口支持填 API Key，请求时自动带 `X-API-Key` 头
- **历史记录**：解析过的作品本地留存，点击直接重新下载
- **保存位置**：系统相册（DCIM/dwdouyin）或自选目录，命名「日期_作者-文案」
- **界面配色**：墨白（默认）/ 紫霞 / 玫红 / 青瓷 / 赤金 / 蓝海 六套主题，设置页切换即时生效并持久保存
- **点档即下**：结果页点任一画质行直接开始下载该档，底部按钮一键存默认最高画质

> 注：主页批量解析因平台风控收紧已暂时下线（入口隐藏），恢复时间视平台情况而定。

### 技术栈

- Kotlin + Jetpack Compose（Material 3）
- Room（历史记录）、Coil（图片加载）
- WebView 引擎（抖音 Argus 风控环境内的解析通路）
- HttpURLConnection 下载引擎（多 CDN Referer 自适应）

### 构建

```bash
cd android
gradle :app:assembleRelease
```

API Key 配置：复制 `app/src/main/assets/api_keys.properties.example` 为 `api_keys.properties` 并填入自己的 Key。**没有 Key 也能构建**，只是 dyjx / svparse 两个通道不可用，可走 suxun 公共接口（https://api.suxun.site ）兜底。

签名：`keystore.properties` 不在仓库中（含密码），自行生成：

```bash
keytool -genkey -v -keystore dwdouyin-release.keystore -alias dwdouyin \
  -keyalg RSA -keysize 2048 -validity 10000
```

然后创建 `keystore.properties`：

```properties
storeFile=dwdouyin-release.keystore
storePassword=你的密码
keyAlias=dwdouyin
keyPassword=你的密码
```

## Windows 桌面客户端（`desktop/`）

C# WPF（.NET 6）原生界面。解析直接走自建服务器，支持批量链接、历史记录、断点续传下载。

- **批量解析**：一次贴多条链接，逐行解析，单条失败不影响其余
- **多档画质**：每档显示大小与分辨率，点任一档直接下载
- **历史记录**：本地留存 200 条，超过 2 小时的直链自动重新解析刷新
- **接口可配**：服务器地址与接口路径在设置页明文可见可编辑，实时预览最终请求 URL

### 构建

```bash
cd desktop
dotnet publish -c Release -r win-x64 --self-contained true ^
  -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true ^
  -p:EnableCompressionInSingleFile=true -o publish
```

> 重新打包前需关闭正在运行的 exe，否则会报 `MSB4018 GenerateBundle` 失败。

命令行自测（不开窗口，直接跑一遍解析链路）：

```bash
DwDouyin.exe --selftest "https://v.douyin.com/xxxxx/" [--download]
```

配置文件 `config.json` 与 exe 同级，首次运行自动生成：

```json
{
  "download_dir": "D:\\Downloads\\dwdouyin",
  "api_base": "http://127.0.0.1",
  "api_path": "/api/douyin/douyin.php"
}
```

## PHP 解析服务器（`server/`）

开源项目 short_videos（MIT，PHP 8.0+，零依赖）。支持抖音、快手、小红书、B站、微博、今日头条等多平台短视频无水印解析，返回统一 JSON。

### 关键接口

| 接口 | 说明 |
|---|---|
| `api/douyin/douyin.php?url=<链接>` | 抖音单平台解析 |
| `api/sv.php?url=<链接>` | 多平台聚合解析（按链接特征自动分流） |

### 返回格式

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "type": "video",
    "title": "作品标题",
    "author": { "name": "作者名", "id": 123456 },
    "cover": "https://...",
    "url": "https://... 视频直链",
    "quality": "original",
    "size": 335424499,
    "size_label": "319.89 MB",
    "duration": 303.6,
    "video_backup": ["https://...", "https://..."],
    "music": { "title": "...", "url": "https://...", "author": "..." }
  }
}
```

> `video_backup` 是**字符串数组**（不是对象数组），客户端解析时注意类型判断。

### 部署（Docker 方式）

```yaml
services:
  web:
    image: webdevops/php-nginx:8.1
    ports: ["80:80"]
    volumes: [".:/app"]
    environment:
      WEB_DOCUMENT_ROOT: /app
      WEB_DOCUMENT_INDEX: index.php
    restart: unless-stopped
```

```bash
cd server && docker compose up -d
curl "http://<服务器IP>/api/douyin/douyin.php?url=https://v.douyin.com/xxxxx/"
```

### 两个部署坑

1. **聚合接口报「无法确定本站域名」**：`api/sv.php` 第 65 行的 `$allowedHosts` 默认是空数组，需把服务器自身域名 / IP 填进去；内网地址还要把 `$allowPrivateHosts` 置为 `true`。
2. **短链末尾斜杠不能省**：`https://v.douyin.com/xxxxx`（无尾斜杠）会返回 HTTP 500 空响应，`https://v.douyin.com/xxxxx/` 才正常。客户端提取链接时不要做 `TrimEnd('/')`。

## 更新日志

### v3.3.1

- 新增六套界面配色（墨白/紫霞/玫红/青瓷/赤金/蓝海），设置页切换，默认墨白，重启后保留
- 修复取消下载无效：下载线程新增协程检查点与连接中断，取消后立即停止且进度浮层不会复活
- 结果页返回按钮改为固定栏，画质档位再多也不会点不到
- 交互改为点画质行直接下载该档，底部按钮直接保存默认最高画质
- 下载完成 / 失败提示改为自动收起
- 首页改为软件名 + 功能介绍，并保留「能解析什么」四个说明块
- 统一字阶体系（6 级字号/行高）

### v3.3.0

- 自定义接口支持 API Key（`X-API-Key` 请求头）
- 主页批量入口因风控收紧暂时隐藏

## 免责声明

本项目仅用于个人学习交流，使用本工具产生的一切后果由使用者自行承担。请尊重原创，下载后请勿用于商业用途或二次传播。
