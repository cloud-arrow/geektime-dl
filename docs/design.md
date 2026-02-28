# geektime-dl 整体设计文档

## 1. 项目概述

geektime-dl 是一个极客时间课程下载工具，从 Go 项目 [geektime-downloader v0.13.3](https://github.com/nicoxiang/geektime-downloader) 移植到 PHP，基于 Laravel Zero 框架构建。

支持下载极客时间平台上的多种课程类型：
- 专栏（文字课程）：PDF / Markdown / 音频
- 视频课程：TS 格式视频（含阿里云 VOD 加密视频解密）
- 训练营（大学版）
- 企业版课程
- 每日一课、公开课、大厂案例等

## 2. 技术栈

| 类别 | 技术 |
|------|------|
| 语言 | PHP 8.2+ |
| 框架 | Laravel Zero 12 |
| 测试 | Pest PHP |
| HTTP 客户端 | Guzzle HTTP |
| PDF 生成 | Spatie Browsershot（底层依赖 Puppeteer + Chrome） |
| Markdown 转换 | league/html-to-markdown |
| RSA 加密 | phpseclib 3.0（绕过 OpenSSL 3.x 对 1024 位密钥的限制） |
| AES 加解密 | PHP OpenSSL 扩展 |
| 代码规范 | Laravel Pint |

## 3. 项目结构

```
app/
├── Commands/
│   └── DownloadCommand.php        # CLI 入口命令，选项解析、配置加载、信号处理
├── Config/
│   └── AppConfig.php              # 配置模型，支持 JSON 文件持久化和校验
├── Crypto/
│   ├── AesCrypto.php              # AES-CBC/ECB 加解密，VOD 密钥派生
│   ├── HmacSigner.php             # HMAC-SHA1 签名（阿里云 VOD API）
│   └── RsaCrypto.php              # RSA-1024 PKCS1v15 加密（PlayAuth 参数）
├── Downloader/
│   ├── AudioDownloader.php        # MP3 音频下载
│   ├── CourseDownloader.php       # 下载编排器，统一调度各格式下载
│   ├── MarkdownDownloader.php     # HTML→Markdown 转换 + 图片本地化
│   ├── PdfDownloader.php          # 基于 Browsershot 的 PDF 生成
│   └── VideoDownloader.php        # 视频下载：VOD→M3U8→TS→合并
├── Enums/
│   ├── OutputType.php             # 输出格式位掩码枚举（PDF=1, Markdown=2, Audio=4）
│   ├── Quality.php                # 视频质量枚举（ld/sd/hd）
│   └── State.php                  # FSM 状态枚举
├── Fsm/
│   └── FsmRunner.php              # 有限状态机，驱动交互式下载流程
├── Geektime/
│   ├── Client.php                 # HTTP 客户端，Cookie 认证 + 错误处理 + 重试
│   ├── GeektimeApi.php            # 普通课程 API（v1/v3 端点）
│   ├── EnterpriseApi.php          # 企业版 API
│   ├── UniversityApi.php          # 大学版/训练营 API
│   ├── Dto/
│   │   ├── Article.php            # 文章 DTO
│   │   └── Course.php             # 课程 DTO
│   └── Exceptions/
│       ├── ApiException.php       # API 通用异常
│       ├── AuthFailedException.php # 认证失败异常（HTTP 452 / 响应码 -3050/-2000）
│       └── RateLimitException.php # 限流异常（HTTP 451）
├── Http/
│   └── FileDownloader.php         # 通用文件下载器
├── M3u8/
│   ├── M3u8Parser.php             # M3U8 播放列表解析
│   └── TsParser.php               # TS 分片解密（MPEG-TS 解析 + AES-ECB）
├── Support/
│   ├── FileHelper.php             # 文件辅助工具
│   └── Filenamify.php             # 文件名安全化处理
├── Ui/
│   ├── ArticleSelect.php          # 文章选择提示
│   ├── ProductAction.php          # 课程操作提示（下载全部/选择文章/返回）
│   ├── ProductIdInput.php         # 课程 ID 输入提示
│   ├── ProductTypeOption.php      # 课程类型选项定义
│   └── ProductTypeSelect.php      # 课程类型选择提示
└── Vod/
    ├── PlayInfo.php               # VOD PlayInfo 数据对象
    └── VodUrlBuilder.php          # 阿里云 VOD URL 构建（签名 + 参数编码）
```

## 4. 核心架构

### 4.1 FSM 交互流程

应用采用有限状态机（FSM）驱动交互式下载流程，定义了四个状态：

```
┌─────────────────────┐
│  SelectProductType   │ ← 用户选择课程类型
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│   InputProductID     │ ← 输入课程 ID，加载课程信息
└────┬────────┬───────┘
     │        │
     │ 需要选择 │ 直接下载（每日一课等）
     │ 文章    │ → 下载完成后回到 InputProductID
     ▼        │
┌─────────────────────┐
│   ProductAction      │ ← 选择操作：下载全部 / 选择文章 / 返回
└────┬────────┬───────┘
     │        │
     │ 选择   │ 下载全部 → 完成后回到 SelectProductType
     │ 文章   │ 返回 → 回到 SelectProductType
     ▼
┌─────────────────────┐
│   SelectArticle      │ ← 选择具体文章下载
└─────────────────────┘
     │ 返回 → 回到 ProductAction
     │ 选择文章 → 下载后留在 SelectArticle
```

状态转换由 `FsmRunner` 驱动，在无限循环中根据当前状态调用对应的处理方法。支持 SIGINT 信号优雅退出。

### 4.2 下载编排

`CourseDownloader` 是下载编排的核心，负责：

1. **区分课程类型**：文字课程（`isTextCourse()`）和视频课程走不同的下载路径
2. **文字课程**：根据 `--output` 位掩码组合下载 PDF / Markdown / Audio
3. **视频课程**：根据 API 类型（普通/企业/大学）调用对应的视频下载方法
4. **断点续传**：检查文件是否已存在，跳过已下载的文章
5. **限流重试**：捕获 `RateLimitException`，指数退避重试
6. **进度显示**：文字课程显示 `X/Y` 进度，视频课程显示字节进度条

### 4.3 API 层

三个 API 类共享同一个 `Client` 实例：

| API 类 | 基础 URL | 用途 |
|--------|---------|------|
| `GeektimeApi` | `https://time.geekbang.org` | 普通课程（专栏、每日一课、公开课等） |
| `EnterpriseApi` | 企业版端点 | 企业版课程 |
| `UniversityApi` | 大学版端点 | 训练营课程 |

`Client` 的职责：
- 基于 Guzzle HTTP，通过 CookieJar 管理 GCID / GCESS 认证 Cookie
- 自动重试（`retryCount=1`，共尝试 2 次）
- HTTP 451 → `RateLimitException`
- HTTP 452 / 响应码 -3050 / -2000 → `AuthFailedException`
- 其他非 0 响应码 → `ApiException`

## 5. 文字课程下载流程

```
v1ArticleInfo(articleId)
    │
    ├─ article_content (HTML)
    │    ├─ PDF: Browsershot 打开文章页面 → 注入 JS 清理 UI → 打印 PDF
    │    ├─ Markdown: HTML→Markdown → 提取图片 URL → 下载图片到本地 → 重写 URL
    │    └─ 内嵌视频: 提取 <video><source> MP4 URL → 下载 MP4
    │
    ├─ audio_download_url
    │    └─ Audio: 直接下载 MP3 文件
    │
    └─ inline_video_subtitles
         └─ 内嵌视频: 提取 video_url → 下载 MP4
```

### PDF 生成细节

- 使用 Browsershot (Spatie) 驱动 Puppeteer + Chrome
- 模拟 iPad Pro 11 视口（834×1194）
- 设置 GCID / GCESS Cookie 用于认证
- 注入 JavaScript 隐藏冗余 UI 元素（导航栏、底部工具栏、音频播放器、广告等）
- 评论模式：0=隐藏评论，1=显示首页评论（默认），2=滚动加载全部评论
- PDF 边距：0.4 英寸（10.16mm）

### Markdown 生成细节

- 使用 `league/html-to-markdown` 库转换 HTML
- 正则提取 Markdown 中的 `![alt](url)` 图片引用
- 验证图片 URL 扩展名（jpg/jpeg/png/gif/webp/bmp/tiff）
- 下载图片到 `images/{articleId}/` 子目录
- 将图片 URL 重写为相对路径

## 6. 视频课程下载流程

```
获取 videoId
    │
    ▼
videoPlayAuth(articleId, videoId) → playAuth
    │
    ▼
VodUrlBuilder::buildUrl(playAuth, videoId, clientRand)
    │
    ├─ 解码 playAuth（处理普通/签名两种格式）
    │    └─ 签名格式：年份推导位置 → 移除标记 → 逐字节递减
    │
    ├─ RSA 加密 clientRand
    │
    ├─ 构建阿里云 VOD API 参数
    │    ├─ Action=GetPlayInfo
    │    ├─ 公共参数：AccessKeyId, SignatureMethod, Format, etc.
    │    └─ 私有参数：AuthInfo, VideoId, SecurityToken, etc.
    │
    └─ HMAC-SHA1 签名 → 拼接完整 URL
    │
    ▼
GET PlayInfo → 选择目标画质的 PlayInfo
    │
    ▼
下载 M3U8 播放列表 → M3u8Parser 解析
    │
    ├─ 提取 TS 分片文件名列表
    └─ 检测是否为 VOD 加密视频（EXT-X-KEY: METHOD=AES-128）
    │
    ▼
如果加密：AesCrypto::getAesDecryptKey(clientRand, serverRand, plaintext)
    │
    ▼
逐个下载 TS 分片（显示进度条）
    │
    ▼
如果加密：TsParser 逐分片解密（MPEG-TS 解析 + AES-ECB）
    │
    ▼
合并所有 TS 分片 → 输出 .ts 文件
    │
    ▼
清理临时目录
```

## 7. 加密模块

### 7.1 AES-CBC (`AesCrypto::decryptCbc`)

用于 VOD 密钥派生过程中的中间解密步骤：
- OpenSSL `aes-128-cbc`（根据密钥长度自动选择 128/192/256）
- PKCS7 填充移除

### 7.2 AES-ECB (`AesCrypto::decryptEcb`)

用于 TS 视频分片解密：
- 逐 16 字节块解密，无填充
- 由 `TsParser` 在 MPEG-TS 包层面调用

### 7.3 RSA-1024 (`RsaCrypto::encrypt`)

用于加密 `clientRand` 参数，发送给阿里云 VOD API：
- 硬编码的 512-bit RSA 公钥（来自 Go 源码）
- PKCS1v15 填充
- 使用 phpseclib 3.0 实现，因为 OpenSSL 3.x 默认策略拒绝 1024 位密钥

### 7.4 HMAC-SHA1 (`HmacSigner::sign`)

用于阿里云 VOD API 请求签名：
- 密钥 = AccessKeySecret + `"&"`（阿里云签名规范）
- 结果 Base64 编码

### 7.5 VOD 密钥派生流程

```
clientRand (UUID)
    │
    ├─ r1 = md5(clientRand)
    ├─ iv = r1[8:24]                              # 16 字节
    │
    ├─ dc1 = AES-CBC-Decrypt(base64_decode(serverRand), key=iv, iv=iv)
    │
    ├─ r2 = clientRand + dc1
    ├─ r2md5 = md5(r2)
    ├─ key2 = r2md5[8:24]                         # 16 字节
    │
    ├─ d2c = AES-CBC-Decrypt(base64_decode(plaintext), key=key2, iv=iv)
    │
    └─ decryptKey = hex(base64_decode(d2c))        # 最终 AES-ECB 解密密钥
```

## 8. 限流与重试

### HTTP 451 限流处理

极客时间 API 在连续请求约 80 篇文章后会触发限流（返回 HTTP 451）。

**PHP 版改进**（Go 版在限流时直接终止）：

```
首次限流 → 等待 30s → 重试
再次限流 → 等待 60s → 重试
第三次限流 → 等待 120s → 重试
仍然限流 → 跳过当前文章，继续下一篇
```

### 断点续传

每次下载前检查目标文件是否已存在（且大小不为 0）：
- PDF：检查 `{title}.pdf`
- Markdown：检查 `{title}.md`
- Audio：检查 `{title}.mp3`
- Video：检查 `{title}.ts`

如果所有请求的输出格式都已存在，跳过该文章。

### 下载间隔

在相邻文章下载之间添加随机延迟：
- 基础间隔：`--interval` 秒（默认 1 秒）
- 随机抖动：0~2000 毫秒
- 总延迟 = `interval × 1000 + random(0, 2000)` 毫秒

## 9. 与 Go 版本对比

### 改进

| 功能 | Go 版本 | PHP 版本 |
|------|---------|---------|
| 限流处理 | 遇到 HTTP 451 直接终止 | 指数退避重试（30s→60s→120s），最多 3 次 |
| 交互界面 | 基于 terminal UI 库 | 基于 Laravel Prompts，更友好的交互体验 |
| 配置持久化 | JSON 文件 | 同样使用 JSON 文件，格式兼容 |

### 等价实现

| 模块 | 说明 |
|------|------|
| 加密算法 | AES-CBC/ECB、RSA-1024、HMAC-SHA1 完全对齐 |
| API 调用 | 所有端点路径、请求参数、响应解析一致 |
| 文件命名 | 使用相同的文件名安全化规则 |
| 目录结构 | 输出目录布局与 Go 版一致 |
| Cookie 认证 | GCID / GCESS Cookie 机制相同 |
| M3U8/TS 解析 | 解析逻辑和解密流程完全对齐 |
| PlayAuth 解码 | 支持普通和签名两种格式，算法一致 |

### 差异

| 方面 | Go 版本 | PHP 版本 |
|------|---------|---------|
| 并发下载 | Goroutine 并发 | 顺序下载（PHP 单线程，网络 I/O 为主要瓶颈） |
| PDF 生成 | chromedp (CDP 协议) | Browsershot (Puppeteer) |
| RSA 实现 | Go 标准库 crypto/rsa | phpseclib 3.0（兼容 OpenSSL 3.x） |
