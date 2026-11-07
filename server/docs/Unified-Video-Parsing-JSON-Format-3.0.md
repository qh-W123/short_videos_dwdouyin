<div align="center">
  <h1>Unified Video Parsing JSON Format 3.0</h1>
  <p>
    <img src="https://img.shields.io/badge/Version-3.0-blue" alt="Version 3.0" />
    <img src="https://img.shields.io/badge/Updated-2026--10--08-green" alt="Updated" />
    <img src="https://img.shields.io/badge/Encoding-UTF--8-orange" alt="UTF-8" />
  </p>
  <p><em>Public API data contract · Media parsing + work statistics</em></p>
  <p>
    <a href="#-scope">Scope</a> •
    <a href="#-general-conventions">Conventions</a> •
    <a href="#-common-response-structure">Response</a> •
    <a href="#-base-data-structure-of-the-media-parsing-api">Base fields</a> •
    <a href="#1-video-type">Video</a> •
    <a href="#2-gallery-type-image">Gallery</a> •
    <a href="#3-live-photo-type">Live Photo</a> •
    <a href="#4-music">Music</a> •
    <a href="#5-extra-of-the-media-parsing-api">Extra</a> •
    <a href="#6-douyin-work-statistics-api-dedicated-json-format">Statistics</a> •
    <a href="#error-response-examples">Errors</a> •
    <a href="#platform-and-media-type-reference">Platforms</a> •
    <a href="#integration-checklist">Checklist</a>
  </p>
  <p><a href="./视频解析统一JSON格式3.0.md">🌐 中文版本</a></p>
</div>

---

> **📘 Repository adaptation note**
>
> This specification defines the unified data contract for parsing results. In this repository (short_videos), the **media-parsing part of every platform API follows** the common response structure and the media field conventions in Sections 1–5; **Section 6 (work statistics API) is a reserved chapter of the specification** — this repository does not currently expose such an endpoint.
>
> All domains, work identifiers, author information and numeric values in this document are examples and do not correspond to real data. `api.example.com` and `example.com` are example domains; official service addresses and access permissions are provided by the service operator.

## 📖 Scope

| API category | Success data structure | Reading scope |
| --- | --- | --- |
| Media parsing | A video, gallery or live-photo media object | Common conventions, base media structure, Sections 1–5 |
| Douyin work statistics | A statistics object without media resources | Common conventions, Section 6 |

Version 3.0 keeps the existing media data format and adds dedicated documentation for the work statistics API. Both API categories share the same `code` / `msg` / `data` envelope, but the success `data` structures differ. Read fields according to the API you call; do not mix media fields with statistics fields.

`3.0` is the documentation version — it is neither a request parameter nor a response field; work statistics is not a new `data.type` value either.

## 🤝 General Conventions

- Use the HTTPS address provided by the service operator for production calls. The specific path of a media-parsing API is subject to the onboarding address you were given; see Section 6 for the work statistics API.
- JSON requests and responses are UTF-8 encoded. When sending a JSON request body, use `Content-Type: application/json`.
- If the service requires an access key, provide the operator-issued key in the `X-API-Key` request header. The work statistics API also accepts a `key` query parameter, but prefer the header to keep the key out of URLs.
- Keep access keys safe; never embed them in public pages, share links or public logs.
- Treat work IDs and other identifiers as strings to avoid large-integer precision loss. Field order is not part of the contract.

## 📦 Common Response Structure

| Field | JSON type | Description |
| --- | --- | --- |
| `code` | integer | Business status code; `200` means success |
| `msg` | string | Human-readable status message, for display only; the exact text may change and is not a fixed enum |
| `data` | object / array | The data object of the called API on success; always `[]` on failure |

> ⚠️ **An HTTP 200 does not mean business success — always check `code` in the JSON body.** Business JSON responses of these APIs use HTTP 200. A successful CORS OPTIONS preflight may return HTTP 204 with no body, which is not a business JSON response.

A success response must contain the valid data defined for that API; empty data objects must not substitute real results. Never hard-code `msg` texts into success-determination logic.

### Business status codes

| code | Meaning | Suggested handling |
|:---:| --- | --- |
| 200 | Parsing or query succeeded | Read the result per the corresponding API structure |
| 400 | Missing or invalid request parameters | Fix the parameters and retry |
| 401 | Access key missing or invalid | Verify the credentials issued by the operator |
| 404 | No valid content or valid work statistics found | Verify the work identifier and content accessibility |
| 429 | Requests throttled | Reduce request rate and retry later |
| 500 | Service temporarily unable to complete the request | Retry later; contact the operator if failures persist |
| 504 | Request processing timed out | Retry a limited number of times later |

## 🧱 Base data structure of the media-parsing API

> This section applies only to video, gallery and live-photo parsing — **not** to the work statistics API in Section 6.

| Field | JSON type | Meaning and no-data convention |
| --- | --- | --- |
| `type` | string | `video`, `image` or `live` |
| `title` | string | Work title; `""` when unknown |
| `desc` | string | Work description; `""` when unknown |
| `author` | object | Author info with three string fields `name`, `id`, `avatar`; `""` when unknown |
| `cover` | string | Cover URL; `""` when unknown |
| `url` | string / null | Main video URL; `null` for galleries and live photos |
| `quality` | string | Video quality description; `""` when unknown or not applicable |
| `duration` | number / null | Video duration in seconds, decimals allowed; `null` when unknown or not applicable |
| `size` | integer | Main video file size in bytes; `0` when unknown or not applicable |
| `size_label` | string | Human-readable size; `""` when `size` is 0 |
| `create_time` | integer | Unix seconds of the publish time; `0` when unknown |
| `publish_time` | string | Corresponding `YYYY-MM-DD HH:mm:ss` text in Asia/Shanghai (UTC+8); `""` when unknown |
| `images` | array[string] | Ordered image URLs; `[]` when none |
| `live_photo` | array[object] | Paired live-photo image/video list; `[]` when none |
| `music` | object | Music info; `{}` when none, or keep fields with empty-string values |
| `extra` | object | Additional public info of this content type; `{}` when none |

These 16 base fields are the fixed structure of a successful media object. `size`, `size_label`, `create_time` and `publish_time` must not be removed when unknown — use the empty-value conventions above. Use `size` for numeric calculations; never parse byte counts out of `size_label`.

## 1. Video type

`url` is the main video address; `images` and `live_photo` are `[]`. If alternative videos exist, an optional `video_backup` array may be returned.

```json
{
  "code": 200,
  "msg": "Parsing successful",
  "data": {
    "type": "video",
    "title": "Example video",
    "desc": "Example video description",
    "author": {
      "name": "Example author",
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
        "gear_name": "Standard",
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
        "gear_name": "Smooth",
        "size": 5242880,
        "size_label": "5.00MB"
      }
    ],
    "music": {
      "title": "Example music",
      "author": "Example music author",
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

`video_backup` is an optional array; each item describes one alternative video.

| Field | JSON type | Description |
| --- | --- | --- |
| `label` | string | Display label |
| `quality` | string | Quality description |
| `url` | string | URL of this video |
| `bit_rate` | integer | Bit rate in bit/s |
| `width` / `height` | integer | Video width and height in pixels |
| `format` | string | Media format, e.g. `mp4` or `webm` |
| `codec` | string | Codec identifier, e.g. `h264` or `h265` |
| `quality_type` | integer | Quality type code, see table below |
| `gear_name` | string | Gear name |
| `size` | integer | Byte size of this very video; `0` when unknown |
| `size_label` | string | Human-readable size of this very video; empty string when `size` is 0 |

The size of an alternative video applies only to that single address — never treat it as the size of the main video or of other alternatives.

### quality_type

| Value | Meaning |
|:---:| --- |
| 1 | 360p |
| 2 | 480p |
| 4 | 720p |
| 8 | 1080p |
| 16 | 2K |
| 32 | 4K |
| 64 | Original quality |
| 72 | 4K+ |

## 2. Gallery type (image)

Images are located in `images`. `url: null`, `quality: ""`, `duration: null`, `size: 0`, `size_label: ""`, and `live_photo: []` when there are no live-photo pairs.

```json
{
  "code": 200,
  "msg": "Parsing successful",
  "data": {
    "type": "image",
    "title": "Example gallery",
    "desc": "Example gallery description",
    "author": {
      "name": "Example author",
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

## 3. Live-photo type (live)

Each `live_photo` item is one associated image + video pair. Main media fields match the gallery type: `url: null`, `quality: ""`, `duration: null`, `size: 0`, `size_label: ""`.

| live_photo item field | JSON type | Meaning |
| --- | --- | --- |
| `image` | string | Live-photo image URL |
| `video` | string | Video URL paired with this image |

```json
{
  "code": 200,
  "msg": "Parsing successful",
  "data": {
    "type": "live",
    "title": "Example live photo",
    "desc": "Example live-photo description",
    "author": {
      "name": "Example author",
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

| Field | JSON type | Meaning |
| --- | --- | --- |
| `title` | string | Music title |
| `author` | string | Music author |
| `url` | string | Music file URL |
| `cover` | string | Music cover URL |

When there is no music, return `{}`, or keep these fields with empty-string values.

## 5. extra of the media-parsing API

`extra` carries additional public info, which may vary by content platform. The example below only illustrates possible fields; not every media result contains all of them.

| Field | JSON type | Meaning |
| --- | --- | --- |
| `aweme_id` | string | Douyin work identifier, returned when applicable |
| `create_time` | integer | Unix seconds of the publish time, returned when applicable |
| `is_top` | integer | Pinned or not, commonly 0 / 1 |
| `statistics` | object | Available work statistics, fields below |

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

| Field | JSON type | Meaning |
| --- | --- | --- |
| `play_count` | integer | Play count |
| `digg_count` | integer | Like count |
| `comment_count` | integer | Comment count |
| `collect_count` | integer | Collect (favorite) count |
| `share_count` | integer | Share count |
| `interact_count` | integer | Interaction count |
| `interact_rate` | number | Interaction rate, range `[0,1]` |
| `finish_rate` | number | Finish rate, range `[0,1]` |

Count fields are non-negative integers. Statistics fields are returned by availability — a missing field is not 0; an explicit 0 is a valid value. Rates are already decimals: `0.025` means `2.5%`; do not rescale them again.

The interaction count does not have to equal the simple sum of other counts, and interaction/finish rates must not be inferred from other fields. Always trust the values in the response.

## 6. Douyin work statistics API (dedicated JSON format)

> This section applies only to the work statistics API — it is not one of the media-parsing `video` / `image` / `live` types. This API returns no video, cover, title, music or author identity data; the 16 media fields above do not apply, and there is no `data.type`.

### 6.1 Requests

| Item | Convention |
| --- | --- |
| Method | GET / POST |
| Default path | `/api/statistics`; the official request address is subject to the onboarding address provided by the operator |
| Request encoding | UTF-8; POST accepts JSON, URL-encoded forms, or plain-text links / IDs |
| Response format | `application/json; charset=utf-8` |
| Query target | One Douyin work per request |

| Parameter | JSON type | Requiredness | Description |
| --- | --- | --- | --- |
| `url` | string | At least one of the work-ID parameters | Work link, short link, share text, or work ID string |
| `id` | string | At least one of `url` / `id` | Work ID |
| `aweme_id` / `item_id` | string | Alternative to `id` | Compatible parameter names for the same work ID |
| `key` | string | As required by the service | URL query parameter only; prefer the `X-API-Key` header — a `key` inside a JSON body is not an access credential |

- Pass work IDs as strings: 15–22 digit decimal identifiers not starting with 0 are supported. Never convert large integers to floats.
- Explicit IDs take precedence over `url`. Multiple ID parameters must be consistent; ID arrays and multi-work queries are not supported.
- URL-encode links or share texts placed in query parameters. Omit unused optional parameters or pass empty strings — never arrays, objects or `null`.
- With `url`, full Douyin work links, short links and share texts are supported; arbitrary web addresses are not valid query targets.
- Request bodies are limited to 16 KiB; links or share texts may be up to 4096 characters.

GET example:

```http
GET https://api.example.com/api/statistics?id=1234567890123456789
Accept: application/json
X-API-Key: YOUR_ACCESS_KEY
```

POST example:

```http
POST https://api.example.com/api/statistics
Content-Type: application/json
X-API-Key: YOUR_ACCESS_KEY
```

```json
{"id":"1234567890123456789"}
```

A link may be submitted as well:

```json
{"url":"https://www.douyin.com/video/1234567890123456789"}
```

> The work IDs and access keys in the examples are not real usable values — replace them with your actual query target and the credentials issued by the operator. Services that do not require a key may omit the corresponding header.

### 6.2 Success response structure

| Path | Required | Meaning |
| --- | :---: | --- |
| `code` / `msg` / `data` | Yes | Common envelope, `code:200` on success |
| `data.extra` | Yes | The only top-level business field inside a success `data` |
| `data.extra.aweme_id` | Yes | The work ID string actually queried |
| `data.extra.statistics` | Yes | Cumulative statistics object; each metric returned by availability |
| `data.extra.author_extra` | No | Available author follower statistics, containing only `follower_count` |
| `data.extra.statistics_trend` | No | Available daily trend; omitted when no valid daily records exist |

Cumulative statistics fields match Section 5, but the returned object is the statistics object of this section — not a media object.

`author_extra.follower_count` is a non-negative integer for the author's total follower stock — **not the new followers gained by this work**. Omit the whole `author_extra` when unknown; keep an explicit 0.

A success requires at least one valid cumulative statistics field or one valid daily trend. When only the trend is available, `statistics` may be `{}`; an author follower count alone, or an empty `statistics` without any trend, does not constitute a successful query.

The statistics response structure is shown below. All numbers are synthetic examples, not real work results; missing optional metrics do not imply a value of 0.

```json
{
  "code": 200,
  "msg": "Query successful",
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

| Field | JSON type | Meaning |
| --- | --- | --- |
| `granularity` | string | Always `day` |
| `start_date` / `end_date` | string | Earliest / latest date with valid metrics inside the returned range, `YYYY-MM-DD` |
| `days` | integer | Number of distinct dates having valid metrics; the calendar range may still contain gaps |
| `daily` | array[object] | Daily metrics in ascending date order, at most one item per date |
| `summary` | object | Summary corresponding to the returned range |

The range is defined by the dates in the response — **not necessarily the last 30 days**. No filler records are generated for missing dates or metrics; callers must not treat missing values as 0.

### 6.4 daily metrics

Each item contains `date` plus the metrics available for that day. Except `date`, every metric may be absent.

| Field | JSON type | Meaning / unit |
| --- | --- | --- |
| `date` | string | Date in `YYYY-MM-DD` |
| `play_count` | integer | Daily play increment |
| `valid_play_count` | integer | Daily valid-play increment |
| `digg_count` | integer | Daily like increment |
| `comment_count` | integer | Daily comment increment |
| `share_count` | integer | Daily share increment |
| `collect_count` | integer | Daily collect increment |
| `interact_count` | integer | Daily interaction increment |
| `interact_rate` | number | Daily interaction rate, range `[0,1]` |
| `finish_rate` | number | Daily finish rate, range `[0,1]` |
| `hot_score` | number | Daily hotness score, non-negative |

- Daily increments may be negative, meaning net decrease or data correction — do not take absolute values. Counts in cumulative statistics remain non-negative integers.
- Valid plays and ordinary plays use different criteria; never assume `valid_play_count` ≤ `play_count`.
- Rates are decimals in `[0,1]`: `0.1` means `10%`.
- Hotness and play count are different metrics; never treat summed hotness as play count.

### 6.5 summary of the range

| Field | JSON type | Meaning |
| --- | --- | --- |
| `totals` | object | Sum of the 7 count-type daily increments over observed dates; may be negative |
| `observed_days` | object | Number of dates each metric is actually valid, useful for coverage checks |
| `weighted_rates` | object | Range interaction/finish rates weighted by daily play count; not a simple average of daily rates |
| `weighted_rate_days` | object | Number of valid dates behind each weighted rate |
| `peaks` | object | Peak and date of daily play increment and hotness |
| `latest` | object | Last valid hotness value and its date |
| `trailing_zero_play` | object, optional | Observed consecutive trailing zero-play days up to the last valid play date |

The six objects `totals`, `observed_days`, `weighted_rates`, `weighted_rate_days`, `peaks`, `latest` always exist and are `{}` when they have no content.

- `totals` only uses daily count-metric field names — no rates or hotness. It is **the total over the returned range, not a lifetime cumulative value**; never use it as a replacement for `extra.statistics`.
- `observed_days` uses daily metric field names with the count of valid dates; coverage days may differ per metric.
- `weighted_rates` can only contain `interact_rate` and `finish_rate`. Only dates with a valid rate and play count > 0 are counted; fields are omitted when no valid samples exist — no fabricated 0 is returned.
- `weighted_rate_days` uses the field names corresponding to `weighted_rates`, valued with valid date counts.
- `peaks` can only contain `play_count` and `hot_score`, valued `{date, value}`; ties use the earliest date.
- `latest` can only contain `hot_score`, valued `{date, value}` — the last valid hotness observation.
- `trailing_zero_play` contains `days` and `end_date`, plus `start_date` when a consecutive zero-play streak exists. Missing dates do not break the streak; the whole field is omitted when there are no play observations. Its end is the last valid play date, which may differ from the last dates of other metrics.

### 6.6 Availability and numeric conventions

- Statistics fields are returned by availability. Missing field, `null` and 0 have different meanings; when missing, statistics fields in this document are **omitted** — never padded with `null` or 0.
- Author follower counts, cumulative statistics and daily trends are not guaranteed to coexist. Partial success data may be returned when at least one of cumulative statistics or a valid daily trend is available.
- A business error with `data: []` is returned when no valid statistics exist at all; sample data never substitutes real results.
- Returned integers lie within the safe range `-9007199254740991` to `9007199254740991`; cumulative counts and follower counts are additionally non-negative. All numbers are finite — no `NaN` or `Infinity`.
- The returned range, statistics update times and metric coverage follow the actual results; example dates and values are never service promises.

## ❌ Error response examples

Error responses always keep `code` / `msg` / `data`, with `data` as `[]`. The `msg` texts below are display examples, not fixed matching conditions.

| Scenario | Example |
| --- | --- |
| Invalid parameters | `{"code":400,"msg":"请求参数不正确","data":[]}` |
| Access key error | `{"code":401,"msg":"访问密钥缺失或无效","data":[]}` |
| No valid content found | `{"code":404,"msg":"未找到有效内容","data":[]}` |
| Requests throttled | `{"code":429,"msg":"请求受限，请稍后重试","data":[]}` |
| Service unavailable | `{"code":500,"msg":"服务暂不可用，请稍后重试","data":[]}` |
| Processing timeout | `{"code":504,"msg":"请求处理超时，请稍后重试","data":[]}` |

## 🌐 Platform and media type reference

> This table shows the applicability of media types; the actually enabled platforms, content accessibility and available fields are subject to the service scope confirmed by the operator and the concrete results. The work statistics API currently covers Douyin works only.

| Content platform | Media types | Optional extras |
| --- | --- | --- |
| Douyin | video / image / live | Videos may include `video_backup` |
| Xiaohongshu | video / image / live | Returned per actual content |
| Jimeng | video / image | Returned per actual content |
| Qianwen | video / image | Returned per actual content |
| Weibo | video / image | Returned per actual content |
| Kuaishou | video / image | Returned per actual content |

## ✅ Integration checklist

1. Use the official service address; replace example domains, example work IDs and example access keys.
2. Check the business `code`; never judge success by HTTP status or `msg` alone.
3. Distinguish media-parsing objects from work-statistics objects and read `data` per the matching chapter.
4. Treat identifiers as strings; tolerate missing statistics fields — never pad them with 0 yourself.
5. Convert rates to percentages as decimals for display; keep the minus sign when displaying daily increments.
6. Never mix lifetime cumulative counts, range daily-increment totals and hotness values.
7. Retry 429 / 500 / 504 with intervals and a limited number of attempts; avoid sustained high-frequency requests.

---

<div align="center">
  <p><a href="../README_EN.md">← Back to main documentation</a> · <a href="./视频解析统一JSON格式3.0.md">中文版本</a></p>
</div>
