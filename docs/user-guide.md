# geektime-dl 用户使用说明

## 1. 系统要求

### 使用预编译二进制（推荐）

无需安装 PHP 和 Composer，直接下载对应平台的可执行文件即可运行。

### 使用源码运行

- **PHP 8.2+**（需启用 openssl、mbstring、json 扩展）
- **Composer**（用于安装依赖）

### PDF 功能（可选）

- **Google Chrome / Chromium**
- **Node.js + Puppeteer**

> 如果不需要 PDF 下载功能，可以不安装 Chrome 和 Puppeteer。

## 2. 安装

### 方式一：下载预编译二进制（推荐）

从 [GitHub Releases](https://github.com/cloud-arrow/geektime-dl/releases) 下载对应平台的可执行文件：

| 平台 | 文件名 |
|------|--------|
| Linux x64 | `geektime-dl-linux-x64` |
| macOS Apple Silicon | `geektime-dl-mac-arm64` |
| macOS Intel | `geektime-dl-mac-x64` |
| Windows x64 | `geektime-dl-windows-x64.exe` |

```bash
# Linux / macOS 示例
chmod +x geektime-dl-linux-x64
./geektime-dl-linux-x64 download --gcid=xxx --gcess=xxx

# 可选：移动到 PATH 中方便全局使用
sudo mv geektime-dl-linux-x64 /usr/local/bin/geektime-dl
geektime-dl download --gcid=xxx --gcess=xxx
```

```powershell
# Windows 示例
.\geektime-dl-windows-x64.exe download --gcid=xxx --gcess=xxx
```

### 方式二：从源码安装

```bash
git clone https://github.com/cloud-arrow/geektime-dl.git
cd geektime-dl
composer install
```

### PDF 功能额外安装

PDF 生成依赖 Puppeteer 驱动 Chrome，需要额外安装：

```bash
npm install puppeteer
```

确保系统已安装 Google Chrome 或 Chromium。在 Linux 服务器上可能需要安装额外依赖：

```bash
# Ubuntu/Debian
sudo apt-get install -y libgbm1 libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libasound2
```

## 3. 获取认证 Cookie

geektime-dl 使用极客时间的 Cookie 进行认证，需要从浏览器中获取 GCID 和 GCESS 两个 Cookie 值。

### 步骤

1. 在浏览器中打开 [time.geekbang.org](https://time.geekbang.org) 并登录
2. 按 `F12` 打开开发者工具
3. 切换到 **Application**（应用）标签页
4. 在左侧展开 **Cookies** → 选择 `https://time.geekbang.org`
5. 找到并复制以下两个 Cookie 的值：
   - `GCID`
   - `GCESS`

## 4. 基本用法

### 使用 Cookie 直接运行

```bash
# 预编译二进制
geektime-dl download --gcid=你的GCID值 --gcess=你的GCESS值

# 源码运行
php application download --gcid=你的GCID值 --gcess=你的GCESS值
```

> 以下示例统一使用 `geektime-dl` 命令，源码运行请替换为 `php application`。

### 交互式 Cookie 输入

如果不提供 `--gcid` 和 `--gcess` 参数，**且没有已保存的配置文件**（`~/.geektime/config.json`），程序会提示你交互式输入 Cookie：

```bash
geektime-dl download
# 提示输入: 请输入极客时间 Cookie (格式: GCID=xxx; GCESS=xxx)
```

### 使用手机号登录

```bash
geektime-dl download --phone=13800138000
# 提示输入密码
```

### 交互流程

启动后，程序会引导你完成以下步骤：

1. **选择课程类型**：普通课程、每日一课、公开课、大厂案例、训练营、其他
2. **输入课程 ID**：从极客时间 URL 中获取
3. **选择操作**：下载全部 / 选择文章 / 返回
4. **等待下载完成**

下载完成后，程序会回到课程类型选择界面，可以继续下载其他课程，按 `Ctrl+C` 退出。

## 5. 命令行选项

| 选项 | 默认值 | 说明 |
|------|--------|------|
| `--phone` | 无 | 手机号登录（会提示输入密码） |
| `--gcid` | 无 | GCID Cookie 值 |
| `--gcess` | 无 | GCESS Cookie 值 |
| `-o`, `--folder` | `~/geektime-downloader` | 下载输出目录 |
| `--quality` | `sd` | 视频质量：`ld`(流畅) / `sd`(标清) / `hd`(高清) |
| `--output` | `2` | 文字课程输出格式位掩码（1-7），详见下方说明 |
| `--comments` | `1` | PDF 评论模式：`0`(隐藏) / `1`(首页) / `2`(全部) |
| `--interval` | `1` | 下载间隔（秒，0-10） |
| `--enterprise` | 否 | 启用企业版模式 |
| `--log-level` | `info` | 日志级别：`debug` / `info` / `warn` / `error` / `none` |
| `--print-pdf-wait` | `5` | Chrome PDF 打印前等待时间（秒，0-60） |
| `--print-pdf-timeout` | `60` | Chrome PDF 超时时间（秒，1-120） |

## 6. 下载格式说明

### `--output` 位掩码

`--output` 使用位掩码组合多种输出格式：

| 值 | 格式 |
|----|------|
| `1` | PDF |
| `2` | Markdown |
| `4` | Audio (MP3) |

组合示例：

| 值 | 效果 |
|----|------|
| `1` | 仅 PDF |
| `2` | 仅 Markdown（默认） |
| `3` | PDF + Markdown |
| `4` | 仅 Audio |
| `5` | PDF + Audio |
| `6` | Markdown + Audio |
| `7` | PDF + Markdown + Audio（全部） |

```bash
# 下载 PDF + Markdown
geektime-dl download --gcid=xxx --gcess=xxx --output=3

# 下载所有格式
geektime-dl download --gcid=xxx --gcess=xxx --output=7
```

### PDF

- 使用 Chrome 无头浏览器渲染极客时间文章页面
- 完整保留页面排版和样式
- 需要安装 Chrome 和 Puppeteer
- 可通过 `--comments` 控制评论显示

### Markdown

- 将文章 HTML 转换为 Markdown 纯文本格式
- 自动下载文章中的图片到本地 `images/` 子目录
- 图片链接重写为相对路径，支持离线查看

### Audio (MP3)

- 下载极客时间提供的文章音频朗读版
- 仅在文章有音频资源时有效

## 7. 视频质量

通过 `--quality` 选项设置视频下载质量：

| 值 | 说明 |
|----|------|
| `ld` | 流畅（低清） |
| `sd` | 标清（默认） |
| `hd` | 高清 |

```bash
# 下载高清视频
geektime-dl download --gcid=xxx --gcess=xxx --quality=hd
```

> 如果请求的质量不可用，会自动回退到第一个可用的质量选项。

## 8. 课程类型与 ID 获取

### 普通课程（专栏）

URL 格式：`https://time.geekbang.org/column/intro/100xxx`

课程 ID 为 URL 末尾的数字，例如 `100xxx`。

### 每日一课

URL 格式：`https://time.geekbang.org/dailylesson/detail/100xxx`

输入文章/课程 ID。

### 公开课

URL 格式：`https://time.geekbang.org/opencourse/intro/100xxx`

输入课程 ID。

### 大厂案例

URL 格式：`https://time.geekbang.org/qconplus/detail/100xxx`

输入文章/课程 ID。

### 训练营

需要输入训练营 class ID。

### 企业版

使用 `--enterprise` 标志启用企业版模式：

```bash
geektime-dl download --gcid=xxx --gcess=xxx --enterprise
```

企业版只支持训练营类型课程。

## 9. 输出目录结构

默认下载到 `~/geektime-downloader/` 目录，结构如下：

```
~/geektime-downloader/
└── 课程名称/
    ├── 第01讲 文章标题.pdf            # PDF 格式
    ├── 第01讲 文章标题.md             # Markdown 格式
    ├── 第01讲 文章标题.mp3            # 音频格式
    ├── 第01讲 文章标题.ts             # 视频格式
    ├── images/                        # Markdown 引用的图片
    │   └── 12345/                     # 按文章 ID 分组
    │       ├── image1.jpg
    │       └── image2.png
    └── videos/                        # 文章内嵌视频
        └── 第01讲 文章标题/
            └── video.mp4
```

企业版课程会额外按章节创建子目录：

```
~/geektime-downloader/
└── 课程名称/
    ├── 第一章 章节标题/
    │   ├── 第01讲 文章标题.ts
    │   └── 第02讲 文章标题.ts
    └── 第二章 章节标题/
        └── ...
```

## 10. 配置持久化

首次运行后，配置会自动保存到 `~/.geektime/config.json`，包括 Cookie、下载目录等设置。下次运行时自动加载，无需重复输入。

**优先级**：命令行参数 > 已保存的配置 > 默认值。

如需更换账号或 Cookie 过期，有以下方式：

```bash
# 方式一：命令行参数覆盖保存的 Cookie
geektime-dl download --gcid=新的GCID --gcess=新的GCESS

# 方式二：删除配置文件，重新交互式输入
rm ~/.geektime/config.json
geektime-dl download
```

## 11. 常见问题

### 触发限流（HTTP 451）

**现象**：下载过程中提示"触发限流，等待 Xs 后重试"。

**原因**：极客时间 API 在连续请求约 80 篇文章后会触发频率限制。

**处理**：程序会自动等待后重试（30s→60s→120s），无需手动干预。如果 3 次重试后仍然限流，会跳过当前文章继续下一篇。支持断点续传，重新运行程序会跳过已下载的文件。

### PDF 生成为空白或失败

**可能原因**：
1. 未安装 Chrome / Chromium
2. 未安装 Puppeteer（`npm install puppeteer`）
3. Chrome 缺少系统依赖（Linux 服务器常见）

**解决**：
- 确认 Chrome 已安装：`google-chrome --version` 或 `chromium --version`
- 确认 Puppeteer 已安装：检查 `node_modules/puppeteer` 目录
- 尝试增加等待时间：`--print-pdf-wait=10`
- 尝试增加超时时间：`--print-pdf-timeout=120`

### 认证失败

**现象**：提示认证失败或 Cookie 过期。

**原因**：GCID 和 GCESS Cookie 有有效期，过期后需要重新获取。

**解决**：重新从浏览器获取 Cookie（参见第 3 节），重新运行时提供新的 `--gcid` 和 `--gcess` 参数。

### 提示"尚未购买该课程"

**原因**：当前账号未购买该课程，无法下载。

**解决**：确认已购买该课程，或检查课程 ID 是否正确。

### 提示"输入的课程 ID 有误"

**原因**：输入的课程 ID 与选择的课程类型不匹配。

**解决**：确认课程类型选择正确，例如专栏课程应选"普通课程"，训练营应选"训练营"。

### 视频下载后无法播放

**说明**：视频以 `.ts` 格式保存（MPEG Transport Stream），大部分播放器（VLC、PotPlayer、mpv）都支持直接播放。如需转换格式，可使用 FFmpeg：

```bash
ffmpeg -i video.ts -c copy video.mp4
```
