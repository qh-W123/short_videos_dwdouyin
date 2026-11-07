# dwdouyin - 短视频作品解析下载工具（Android）

短视频作品解析与无水印下载工具，支持抖音与 33+ 平台聚合解析（B站/快手/YouTube/TikTok/西瓜视频/知乎等）。

> ⚠️ 仅供个人技术学习使用。禁止商用、禁止批量爬取，请遵守各平台版权法规。下载内容版权归原作所有。

## 功能

- **多平台解析**：粘贴任意分享链接（抖音短链/网页链、B站、快手等 33+ 平台），自动识别并解析
- **全档位画质**：原画 / 1080P / 720P / 540P… 全部列出，每档显示文件大小与分辨率
- **图文下载**：视频与图文作品都支持，图片逐张保存
- **自定义接口**：可添加自己的解析接口（标准/完整画质格式），需要鉴权的接口支持填 API Key，请求时自动带 `X-API-Key` 头
- **历史记录**：解析过的作品本地留存，点击直接重新下载
- **保存位置**：系统相册（DCIM/dwdouyin）或自选目录，命名「日期_作者-文案」
- **界面配色**：墨白（默认）/ 紫霞 / 玫红 / 青瓷 / 赤金 / 蓝海 六套主题，设置页切换即时生效并持久保存
- **点档即下**：结果页点任一画质行直接开始下载该档，底部按钮一键存默认最高画质

> 注：主页批量解析因平台风控收紧已暂时下线（入口隐藏），恢复时间视平台情况而定。

## 技术栈

- Kotlin + Jetpack Compose（Material 3）
- Room（历史记录）、Coil（图片加载）
- WebView 引擎（抖音 Argus 风控环境内的解析通路）
- HttpURLConnection 下载引擎（多 CDN Referer 自适应）

## 构建

1. Android Studio 打开 `android/` 目录，或命令行：

   ```bash
   cd android
   gradle :app:assembleRelease
   ```

2. API Key 配置：复制 `app/src/main/assets/api_keys.properties.example` 为 `api_keys.properties`，填入你自己的 KEY。**没有 Key 也能构建**，只是 dyjx/svparse 两个通道不可用，可走 suxun api地址(https://api.suxun.site）兜底通道。

3. 签名：`keystore.properties` 不在仓库中（含密码），自行生成：

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

## 更新日志

### v3.3.1

- 新增六套界面配色（墨白/紫霞/玫红/青瓷/赤金/蓝海），设置页切换，默认墨白，重启后保留
- 修复取消下载无效：下载线程新增协程检查点与连接中断，取消后立即停止且进度浮层不会复活
- 结果页返回按钮改为固定栏，画质档位再多也不会点不到
- 交互改为点画质行直接下载该档，底部按钮直接保存默认最高画质
- 下载完成/失败提示改为自动收起
- 首页改为软件名 + 功能介绍，并保留「能解析什么」四个说明块
- 统一字阶体系（6 级字号/行高），版本号升至 3.3.1
## 免责声明

本项目仅用于个人学习交流，使用本工具产生的一切后果由使用者自行承担。请尊重原创，下载后请勿用于商业用途或二次传播。
