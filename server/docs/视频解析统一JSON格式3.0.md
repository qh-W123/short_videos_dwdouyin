<div align="center">
  <h1>视频解析统一 JSON 格式 3.0</h1>
  <p>
    <img src="https://img.shields.io/badge/版本-3.0-blue" alt="版本 3.0" />
    <img src="https://img.shields.io/badge/更新日期-2026--10--08-green" alt="更新日期" />
    <img src="https://img.shields.io/badge/编码-UTF--8-orange" alt="UTF-8" />
  </p>
  <p><em>对外 API 数据契约 · 媒体解析 + 作品统计</em></p>
  <p>
    <a href="#-适用范围">适用范围</a> •
    <a href="#-通用调用约定">通用约定</a> •
    <a href="#-通用响应结构">响应结构</a> •
    <a href="#-媒体解析接口的-data-基础结构">基础字段</a> •
    <a href="#1-视频类型video">视频</a> •
    <a href="#2-图集类型image">图集</a> •
    <a href="#3-实况类型live">实况</a> •
    <a href="#4-music">音乐</a> •
    <a href="#5-媒体解析接口的-extra">附加信息</a> •
    <a href="#6-抖音作品统计接口专属-json-格式">作品统计</a> •
    <a href="#错误响应示例">错误示例</a> •
    <a href="#平台与媒体类型参考">平台参考</a> •
    <a href="#接入检查清单">接入清单</a>
  </p>
  <p><a href="./Unified-Video-Parsing-JSON-Format-3.0.md">🌐 English Version</a></p>
</div>

---

> **📘 本仓库适配说明**
>
> 本规范描述统一的解析结果数据契约。本仓库（short_videos）各平台解析接口的**媒体解析部分已遵循**本文的通用响应结构与第 1–5 节媒体字段约定；**第 6 节作品统计接口为规范预留章节**，本仓库当前未提供对应接口。
>
> 文中的域名、作品标识、作者信息及数值均为示例，不对应真实业务数据。`api.example.com` 与 `example.com` 是示例域名；正式服务地址和访问权限由服务提供方告知。

## 📖 适用范围

| 接口类别 | 成功数据结构 | 阅读范围 |
| --- | --- | --- |
| 媒体解析 | 视频、图集或实况媒体对象 | 通用约定、媒体基础结构、第 1–5 节 |
| 抖音作品统计 | 作品统计对象，不包含媒体资源 | 通用约定、第 6 节 |

3.0 保留原有媒体数据格式，并增加作品统计接口的专属说明。两类接口共用 `code` / `msg` / `data` 外层，但成功 `data` 结构不同。请根据调用的接口读取对应字段，不要混用媒体字段与统计字段。

`3.0` 是本文档版本，不是请求参数或响应字段；作品统计也不是新的 `data.type` 值。

## 🤝 通用调用约定

- 正式调用使用服务提供方给出的 HTTPS 地址。媒体解析接口的具体路径，以开通时提供的接入地址为准；作品统计接口见第 6 节。
- JSON 请求和响应采用 UTF-8。发送 JSON 请求体时使用 `Content-Type: application/json`。
- 如服务要求访问密钥，在 `X-API-Key` 请求头中提供服务方发放的密钥。作品统计接口也兼容 `key` 查询参数，建议优先使用请求头，避免密钥出现在 URL 中。
- 请妥善保管访问密钥，不要将其写入公开页面、分享链接或公开日志。
- 作品 ID 等标识按字符串处理，避免大整数精度丢失。字段顺序不构成接口约定。

## 📦 通用响应结构

| 字段 | JSON 类型 | 说明 |
| --- | --- | --- |
| `code` | integer | 业务状态码；200 表示成功 |
| `msg` | string | 可读状态说明，仅用于展示；具体文本可能变化，不作为固定枚举 |
| `data` | object / array | 成功时为相应接口的数据对象；失败时固定为 `[]` |

> ⚠️ **收到 HTTP 200 不代表业务成功，必须同时检查 JSON 中的 `code`。** 本文接口的业务 JSON 响应使用 HTTP 200。CORS OPTIONS 预检成功时可返回无正文的 HTTP 204，不属于业务 JSON 响应。

成功响应应包含该接口约定的有效数据，不以空数据对象代替真实结果。不要将 `msg` 文案写死在成功判定逻辑中。

### 业务状态码

| code | 含义 | 建议处理 |
|:---:| --- | --- |
| 200 | 解析或查询成功 | 按对应接口的数据结构读取结果 |
| 400 | 请求参数缺失或不合法 | 修正参数后再请求 |
| 401 | 访问密钥缺失或无效 | 核对服务方提供的访问凭证 |
| 404 | 没有找到有效内容或有效作品统计 | 核对作品标识及内容可访问性 |
| 429 | 请求受限 | 降低请求频率，稍后重试 |
| 500 | 服务暂时无法完成请求 | 稍后重试；持续失败时联系服务方 |
| 504 | 请求处理超时 | 稍后进行有限次数重试 |

## 🧱 媒体解析接口的 data 基础结构

> 本节仅适用于视频、图集和实况解析，**不适用于第 6 节的作品统计接口**。

| 字段 | JSON 类型 | 含义与无数据时的约定 |
| --- | --- | --- |
| `type` | string | `video`、`image` 或 `live` |
| `title` | string | 作品标题；未知时为 `""` |
| `desc` | string | 作品描述；未知时为 `""` |
| `author` | object | 作者信息，包含 `name`、`id`、`avatar` 三个字符串字段；未知字段为 `""` |
| `cover` | string | 封面 URL；未知时为 `""` |
| `url` | string / null | 主视频 URL；图集、实况为 `null` |
| `quality` | string | 视频画质描述；未知或不适用时为 `""` |
| `duration` | number / null | 视频时长，单位秒，允许小数；未知或不适用时为 `null` |
| `size` | integer | 主视频文件大小，单位字节；未知或不适用时为 0 |
| `size_label` | string | 文件大小的可读说明；`size` 为 0 时为 `""` |
| `create_time` | integer | 作品发布时间的 Unix 秒数；未知时为 0 |
| `publish_time` | string | 对应的 `YYYY-MM-DD HH:mm:ss` 时间文本，时区 Asia/Shanghai（UTC+8）；未知时为 `""` |
| `images` | array[string] | 按顺序排列的图片 URL；无图片时为 `[]` |
| `live_photo` | array[object] | 实况图片与视频的配对列表；无实况时为 `[]` |
| `music` | object | 音乐信息；无音乐时为 `{}`，也允许保留值为空串的音乐字段 |
| `extra` | object | 该类内容的附加公开信息；无附加信息时为 `{}` |

上述 16 个基础字段属于成功媒体对象的固定结构。`size`、`size_label`、`create_time`、`publish_time` 不能因未知而删除，应使用表中的空值约定。数值运算使用 `size`，不要从 `size_label` 解析字节数。

## 1. 视频类型（video）

`url` 为主视频地址，`images` 和 `live_photo` 为 `[]`。如有备选视频，可额外返回 `video_backup`。

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "type": "video",
    "title": "示例视频",
    "desc": "示例视频描述",
    "author": {
      "name": "示例作者",
      "id": "author_001",
      "avatar": "https://example.com/avatar.jpg"
    },
    "cover": "https://example.com/cover.jpg",
    "url": "https://example.com/video.mp4",
    "quality": "1080p",
    "duration": 15,
    "size": 10485760,
    "size_label": "10.00MB",
    "create_time": 1735660800,
    "publish_time": "2025-01-01 00:00:00",
    "images": [],
    "live_photo": [],
    "video_backup": [
      {
        "label": "1080p",
        "quality": "1080p",
        "url": "https://example.com/video_1080p.mp4",
        "bit_rate": 2500000,
        "width": 1920,
        "height": 1080,
        "format": "mp4",
        "codec": "h265",
        "quality_type": 8,
        "gear_name": "正常",
        "size": 10485760,
        "size_label": "10.00MB"
      },
      {
        "label": "720p",
        "quality": "720p",
        "url": "https://example.com/video_720p.mp4",
        "bit_rate": 1200000,
        "width": 1280,
        "height": 720,
        "format": "mp4",
        "codec": "h264",
        "quality_type": 4,
        "gear_name": "流畅",
        "size": 5242880,
        "size_label": "5.00MB"
      }
    ],
    "music": {
      "title": "示例音乐",
      "author": "示例音乐作者",
      "url": "https://example.com/music.mp3",
      "cover": "https://example.com/music_cover.jpg"
    },
    "extra": {
      "aweme_id": "1234567890123456789",
      "create_time": 1735660800,
      "is_top": 0,
      "statistics": {
        "play_count": 10000,
        "digg_count": 100,
        "comment_count": 10,
        "share_count": 30
      }
    }
  }
}
```

### video_backup

`video_backup` 是可选数组，每项描述一个备选视频。

| 字段 | JSON 类型 | 说明 |
| --- | --- | --- |
| `label` | string | 展示标签 |
| `quality` | string | 画质描述 |
| `url` | string | 该视频的 URL |
| `bit_rate` | integer | 码率，单位 bit/s |
| `width` / `height` | integer | 视频宽度和高度，单位像素 |
| `format` | string | 媒体格式，如 `mp4` 或 `webm` |
| `codec` | string | 编解码标识，如 `h264` 或 `h265` |
| `quality_type` | integer | 画质类型编号，见下表 |
| `gear_name` | string | 档位名称 |
| `size` | integer | 该条视频自身的字节数；未知时为 0 |
| `size_label` | string | 该条视频大小的可读说明；`size` 为 0 时为空串 |

备选视频的大小只对应这一条地址，不能当作主视频或其他备选视频的大小。

### quality_type

| 值 | 含义 |
|:---:| --- |
| 1 | 360p |
| 2 | 480p |
| 4 | 720p |
| 8 | 1080p |
| 16 | 2K |
| 32 | 4K |
| 64 | 原画 |
| 72 | 4K+ |

## 2. 图集类型（image）

图片位于 `images` 中。`url: null`、`quality: ""`、`duration: null`、`size: 0`、`size_label: ""`，没有实况配对时 `live_photo: []`。

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "type": "image",
    "title": "示例图集",
    "desc": "示例图集描述",
    "author": {
      "name": "示例作者",
      "id": "author_001",
      "avatar": "https://example.com/avatar.jpg"
    },
    "cover": "https://example.com/image_1.jpg",
    "url": null,
    "quality": "",
    "duration": null,
    "size": 0,
    "size_label": "",
    "create_time": 1735660800,
    "publish_time": "2025-01-01 00:00:00",
    "images": [
      "https://example.com/image_1.jpg",
      "https://example.com/image_2.jpg",
      "https://example.com/image_3.jpg"
    ],
    "live_photo": [],
    "music": {
      "title": "",
      "author": "",
      "url": "",
      "cover": ""
    },
    "extra": {}
  }
}
```

## 3. 实况类型（live）

每个 `live_photo` 项目是一组相关联的图片和视频。主媒体字段与图集一致：`url: null`、`quality: ""`、`duration: null`、`size: 0`、`size_label: ""`。

| live_photo 项目字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `image` | string | 实况图片 URL |
| `video` | string | 与该图片配对的视频 URL |

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "type": "live",
    "title": "示例实况",
    "desc": "示例实况描述",
    "author": {
      "name": "示例作者",
      "id": "author_001",
      "avatar": "https://example.com/avatar.jpg"
    },
    "cover": "https://example.com/image_1.jpg",
    "url": null,
    "quality": "",
    "duration": null,
    "size": 0,
    "size_label": "",
    "create_time": 1735660800,
    "publish_time": "2025-01-01 00:00:00",
    "images": [
      "https://example.com/image_1.jpg",
      "https://example.com/image_2.jpg"
    ],
    "live_photo": [
      {
        "image": "https://example.com/image_1.jpg",
        "video": "https://example.com/live_1.mp4"
      },
      {
        "image": "https://example.com/image_2.jpg",
        "video": "https://example.com/live_2.mp4"
      }
    ],
    "music": {},
    "extra": {}
  }
}
```

## 4. music

| 字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `title` | string | 音乐标题 |
| `author` | string | 音乐作者 |
| `url` | string | 音乐文件 URL |
| `cover` | string | 音乐封面 URL |

无音乐时可返回 `{}`，或保留以上字段并将值设为空串。

## 5. 媒体解析接口的 extra

`extra` 用于附加公开信息，其内容可能随内容平台而不同。下例只说明可能出现的字段，并非每个媒体结果都会包含全部字段。

| 字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `aweme_id` | string | 抖音作品标识，适用时返回 |
| `create_time` | integer | 作品发布时间的 Unix 秒数，适用时返回 |
| `is_top` | integer | 是否置顶，常见值为 0 / 1 |
| `statistics` | object | 可用的作品统计，字段说明如下 |

```json
{
  "extra": {
    "aweme_id": "1234567890123456789",
    "create_time": 1735660800,
    "is_top": 0,
    "statistics": {
      "play_count": 10000,
      "digg_count": 100,
      "comment_count": 10,
      "share_count": 30,
      "collect_count": 20,
      "interact_count": 140,
      "interact_rate": 0.014,
      "finish_rate": 0.6
    }
  }
}
```

### statistics

| 字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `play_count` | integer | 播放量 |
| `digg_count` | integer | 点赞数 |
| `comment_count` | integer | 评论数 |
| `collect_count` | integer | 收藏数 |
| `share_count` | integer | 分享数 |
| `interact_count` | integer | 互动数 |
| `interact_rate` | number | 互动率，范围 `[0,1]` |
| `finish_rate` | number | 完播率，范围 `[0,1]` |

计数字段为非负整数。统计字段按可用性返回，缺失不等于 0；明确返回的 0 是有效数值。比率已是小数，例如 `0.025` 表示 `2.5%`，不应再次按其他比例单位缩放。

互动数不必等于其他计数的简单相加，互动率或完播率也不应根据其他字段自行推断。请以响应中的值为准。

## 6. 抖音作品统计接口（专属 JSON 格式）

> 本节只适用于作品统计接口，不属于媒体解析的 `video` / `image` / `live` 类型。此接口不返回视频、封面、标题、音乐或作者身份资料，不适用前述 16 个媒体字段，也不包含 `data.type`。

### 6.1 请求方式

| 项目 | 约定 |
| --- | --- |
| 方法 | GET / POST |
| 默认路径 | `/api/statistics`；正式请求地址以服务方提供的接入地址为准 |
| 请求编码 | UTF-8；POST 支持 JSON、URL 编码表单或纯文本链接 / ID |
| 响应格式 | `application/json; charset=utf-8` |
| 查询对象 | 每次一件抖音作品 |

| 参数 | JSON 类型 | 必填关系 | 说明 |
| --- | --- | --- | --- |
| `url` | string | 与作品 ID 参数至少提供一项 | 作品链接、短链接、分享文案或作品 ID 字符串 |
| `id` | string | 与 `url` 至少提供一项 | 作品 ID |
| `aweme_id` / `item_id` | string | 可替代 `id` | 同一作品 ID 的兼容参数名 |
| `key` | string | 按服务的访问要求提供 | 仅用于 URL 查询参数；建议使用 `X-API-Key` 请求头，JSON 请求体中的 `key` 不作为访问凭证 |

- 作品 ID 按字符串传入，支持 15–22 位、非 0 开头的十进制标识。不要把大整数转换成浮点数。
- 显式 ID 优先于 `url`。多个 ID 参数必须一致，不支持 ID 数组或一次查询多件作品。
- 查询参数中的链接或分享文案请进行 URL 编码。未使用的可选参数应省略或传空串，不要传数组、对象或 `null`。
- 使用 `url` 查询时，支持抖音作品完整链接、短链接与分享文案；不支持将任意网络地址作为查询对象。
- 请求体上限为 16 KiB，采用的链接或分享文本最长 4096 个字符。

GET 示例：

```http
GET https://api.example.com/api/statistics?id=1234567890123456789
Accept: application/json
X-API-Key: YOUR_ACCESS_KEY
```

POST 示例：

```http
POST https://api.example.com/api/statistics
Content-Type: application/json
X-API-Key: YOUR_ACCESS_KEY
```

```json
{"id":"1234567890123456789"}
```

也可提交链接：

```json
{"url":"https://www.douyin.com/video/1234567890123456789"}
```

> 示例中的作品 ID 与访问密钥不是可直接使用的真实值，请替换为实际查询对象和服务方提供的访问密钥。不要求访问密钥的服务可省略相应请求头。

### 6.2 成功响应结构

| 路径 | 是否必填 | 含义 |
| --- | :---: | --- |
| `code` / `msg` / `data` | 是 | 通用响应信封，成功时 `code:200` |
| `data.extra` | 是 | 成功 `data` 中唯一的顶层业务字段 |
| `data.extra.aweme_id` | 是 | 实际查询的作品 ID 字符串 |
| `data.extra.statistics` | 是 | 累计统计对象，各指标按实际可用性返回 |
| `data.extra.author_extra` | 否 | 可用的作者粉丝统计，仅包含 `follower_count` |
| `data.extra.statistics_trend` | 否 | 可用的逐日趋势；没有有效日记录时省略 |

累计统计字段与第 5 节一致，但返回对象仍是本节的统计对象，不是媒体对象。

`author_extra.follower_count` 为非负整数，表示作者粉丝存量，**不是这件作品带来的新增粉丝数**。未知时省略整个 `author_extra`；明确的 0 保留。

成功至少需要一个有效累计作品统计字段或一条有效日趋势。仅趋势可用时，`statistics` 可以为 `{}`；仅有作者粉丝数，或空 `statistics` 且没有趋势，不构成成功查询。

下面展示统计响应结构。所有数值均为合成示例，不是实际作品结果；缺少的可选指标不表示其值为 0。

```json
{
  "code": 200,
  "msg": "查询成功",
  "data": {
    "extra": {
      "aweme_id": "1234567890123456789",
      "statistics": {
        "play_count": 10000,
        "digg_count": 100,
        "comment_count": 10,
        "collect_count": 20,
        "share_count": 30,
        "interact_count": 140,
        "interact_rate": 0.014,
        "finish_rate": 0.6
      },
      "author_extra": {
        "follower_count": 5000
      },
      "statistics_trend": {
        "granularity": "day",
        "start_date": "2025-10-01",
        "end_date": "2025-10-01",
        "days": 1,
        "daily": [
          {
            "date": "2025-10-01",
            "play_count": 100,
            "valid_play_count": 120,
            "collect_count": -4,
            "interact_rate": 0.1,
            "finish_rate": 0.5,
            "hot_score": 10
          }
        ],
        "summary": {
          "totals": {
            "play_count": 100,
            "valid_play_count": 120,
            "collect_count": -4
          },
          "observed_days": {
            "play_count": 1,
            "valid_play_count": 1,
            "collect_count": 1,
            "interact_rate": 1,
            "finish_rate": 1,
            "hot_score": 1
          },
          "weighted_rates": {
            "interact_rate": 0.1,
            "finish_rate": 0.5
          },
          "weighted_rate_days": {
            "interact_rate": 1,
            "finish_rate": 1
          },
          "peaks": {
            "play_count": {
              "date": "2025-10-01",
              "value": 100
            },
            "hot_score": {
              "date": "2025-10-01",
              "value": 10
            }
          },
          "latest": {
            "hot_score": {
              "date": "2025-10-01",
              "value": 10
            }
          },
          "trailing_zero_play": {
            "days": 0,
            "end_date": "2025-10-01"
          }
        }
      }
    }
  }
}
```

### 6.3 statistics_trend

| 字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `granularity` | string | 固定为 `day` |
| `start_date` / `end_date` | string | 返回区间内有有效指标的最早 / 最晚日期，格式 `YYYY-MM-DD` |
| `days` | integer | 有有效指标的不同日期数，不代表日历区间内没有缺日 |
| `daily` | array[object] | 按日期升序排列的每日指标，每个日期最多一项 |
| `summary` | object | 与本次返回区间对应的汇总信息 |

区间以响应中的日期为准，**不固定等于最近 30 天**。某天或某项指标没有数据时，不会生成填充记录；调用方也不应将缺失值当作 0。

### 6.4 daily 每日指标

每项包含 `date`，以及当日可用的指标。除 `date` 外，各指标均可缺省。

| 字段 | JSON 类型 | 含义 / 单位 |
| --- | --- | --- |
| `date` | string | `YYYY-MM-DD` 格式的日期 |
| `play_count` | integer | 播放日增量 |
| `valid_play_count` | integer | 有效播放日增量 |
| `digg_count` | integer | 点赞日增量 |
| `comment_count` | integer | 评论日增量 |
| `share_count` | integer | 分享日增量 |
| `collect_count` | integer | 收藏日增量 |
| `interact_count` | integer | 互动日增量 |
| `interact_rate` | number | 当日互动率，范围 `[0,1]` |
| `finish_rate` | number | 当日完播率，范围 `[0,1]` |
| `hot_score` | number | 当日热度值，非负 |

- 日增量可能为负数，表示净减少或数据修正，不应取绝对值。累计统计的计数仍为非负整数。
- 有效播放和普通播放的口径不同，不能假定 `valid_play_count` 一定小于或等于 `play_count`。
- 比率已经是 `[0,1]` 的小数。例如 `0.1` 表示 `10%`。
- 热度和播放量不是同一指标，不应将热度合计当作播放量。

### 6.5 summary 区间汇总

| 字段 | JSON 类型 | 含义 |
| --- | --- | --- |
| `totals` | object | 已观测日期的 7 个计数类日增量合计，可为负值 |
| `observed_days` | object | 各指标实际有效的日期数，可用于判断覆盖程度 |
| `weighted_rates` | object | 区间互动率 / 完播率，以每日播放量为权重；不是每日比率的简单平均 |
| `weighted_rate_days` | object | 各加权比率实际包含的有效日期数 |
| `peaks` | object | 播放日增量、热度的峰值和日期 |
| `latest` | object | 最后一个有效热度值及其日期 |
| `trailing_zero_play` | object，可选 | 截至最后一个有效播放日期，已观测且连续的尾部零播放天数 |

`totals`、`observed_days`、`weighted_rates`、`weighted_rate_days`、`peaks`、`latest` 六个对象固定存在，没有对应内容时为 `{}`。

- `totals` 仅使用每日计数指标的字段名，不包含比率或热度。它是**返回区间的合计，不是作品全生命周期累计值**，不能用来替换 `extra.statistics`。
- `observed_days` 使用每日指标字段名，值为有效日期数量；不同指标的覆盖天数可能不同。
- `weighted_rates` 仅可能包含 `interact_rate`、`finish_rate`。只有存在有效比率且播放量大于 0 的日期才计入；没有有效样本时省略对应字段，不返回虚构的 0。
- `weighted_rate_days` 使用与 `weighted_rates` 对应的字段名，值为有效日期数量。
- `peaks` 仅可能包含 `play_count`、`hot_score`，值为 `{date, value}`；并列峰值使用最早日期。
- `latest` 仅可能包含 `hot_score`，值为 `{date, value}`，表示最后一个有效热度观测。
- `trailing_zero_play` 包含 `days`、`end_date`，存在连续零播放日时还包含 `start_date`。缺失日期不计入连续段；没有播放观测时整个字段省略。该字段的终点是最后一个有效播放日期，可能不同于其他指标的最后日期。

### 6.6 可用性与数值约定

- 统计字段按实际可用性返回。字段缺失、`null`、0 含义不同；本文统计字段缺失时采用**省略字段**，不以 `null` 或 0 填充。
- 作者粉丝数、累计统计和日趋势不保证每次同时具备。累计统计或有效日趋势至少一项可用时可以返回部分成功数据。
- 没有任何有效作品统计时返回业务错误，`data` 为 `[]`，不以示例数据代替真实结果。
- 返回的整数位于 `-9007199254740991` 至 `9007199254740991` 的安全范围内；累计计数和粉丝数额外要求非负。所有数值均有限，不返回 `NaN` 或 `Infinity`。
- 返回区间、统计更新时间和指标覆盖程度以实际结果为准，不应将示例日期或数值视为服务承诺。

## ❌ 错误响应示例

错误响应固定保留 `code` / `msg` / `data`，其中 `data` 为 `[]`。以下 `msg` 为展示示例，不是固定判断条件。

| 场景 | 示例 |
| --- | --- |
| 参数错误 | `{"code":400,"msg":"请求参数不正确","data":[]}` |
| 访问密钥错误 | `{"code":401,"msg":"访问密钥缺失或无效","data":[]}` |
| 未找到有效内容 | `{"code":404,"msg":"未找到有效内容","data":[]}` |
| 请求受限 | `{"code":429,"msg":"请求受限，请稍后重试","data":[]}` |
| 服务暂不可用 | `{"code":500,"msg":"服务暂不可用，请稍后重试","data":[]}` |
| 处理超时 | `{"code":504,"msg":"请求处理超时，请稍后重试","data":[]}` |

## 🌐 平台与媒体类型参考

> 本表说明媒体类型的适用情况；实际开通的平台、内容可访问性和可用字段，以服务提供方确认的服务范围及具体结果为准。作品统计接口当前仅面向抖音作品。

| 内容平台 | 媒体类型 | 可选附加内容 |
| --- | --- | --- |
| 抖音 | video / image / live | 视频可包含 `video_backup` |
| 小红书 | video / image / live | 按实际内容返回 |
| 即梦 | video / image | 按实际内容返回 |
| 千问 | video / image | 按实际内容返回 |
| 微博 | video / image | 按实际内容返回 |
| 快手 | video / image | 按实际内容返回 |

## ✅ 接入检查清单

1. 使用正式服务地址，替换示例域名、示例作品 ID 和示例访问密钥。
2. 检查业务 `code`，不要仅根据 HTTP 状态或 `msg` 判断成功。
3. 区分媒体解析对象和作品统计对象，按各自章节读取 `data`。
4. 对标识使用字符串；对缺失统计字段进行兼容，不自行补成 0。
5. 显示比率时按小数转百分比，显示日增量时保留负号。
6. 不混用作品累计量、区间日增量合计和热度值。
7. 对 429、500、504 进行有间隔、有限次数的重试，避免持续高频请求。

---

<div align="center">
  <p><a href="../README.md">← 返回项目主文档</a> · <a href="./Unified-Video-Parsing-JSON-Format-3.0.md">English Version</a></p>
</div>
