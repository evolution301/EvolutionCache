# EvolutionCache

**Lightweight WordPress page cache & front-end optimization — AI-ready.**
**轻量级 WordPress 整页缓存与前端性能优化 —— 为 AI 接管而生。**

[English](#english) | [中文说明](#中文说明)

---

<a id="english"></a>
## English

EvolutionCache stores fully rendered pages and serves them **before WordPress even boots** (via `advanced-cache.php`), then optimizes HTML **once** on cache misses: CSS minify + defer, JS defer or defer-until-interaction, native image lazy-loading, LCP preloading, and automatic compression of oversized images.

- **No license keys. No telemetry. No third-party libraries. Zero external requests.**
- **Fail-open by design:** if any optimization errors, the original HTML is served — a broken rule can never white-screen your site.
- **AI-ready:** REST API + WP-CLI + [`AGENTS.md`](AGENTS.md) so tools like Codex, ZCode, Claude Code or WorkBuddy can manage the plugin after being connected to WordPress.

### Features

| Module | What it does |
|---|---|
| Page cache | Static serving pre-WP boot, SHA-256 keys, gzip sidecars, TTL + content-change invalidation, hourly GC, generation counter (race-free flush) |
| CSS | Minify local stylesheets to content-hashed copies, defer non-critical CSS (preload+onload), `font-display:swap` injection, optional critical CSS |
| JS | `defer` or **delay-until-interaction** modes with exclusion list; JSON-LD & `<noscript>` untouched |
| Images | Native `loading="lazy"`, LCP `fetchpriority=high` + preload, **auto-compression of oversized images** (≥800px → 800w/q80 WebP copy) |
| Cache warmer | Hourly sitemap crawl + manual trigger |
| Admin | One-screen status board, toolbar "Clear cache" button, collapsed advanced settings (safe defaults locked) |
| AI / API | REST endpoints (`/wp-json/evolutioncache/v1/…`) + WP-CLI (`wp evolutioncache …`) — see [AGENTS.md](AGENTS.md) |

### Installation

1. Download `evolutioncache.zip` from [Releases](../../releases).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → activate.
3. Done. Optimal settings are applied automatically; the toolbar gets a "Clear cache" button.

### AI takeover (Codex / ZCode / WorkBuddy / Claude Code …)

Connect any agent that speaks HTTP or WP-CLI:

```bash
# 1. Create an Application Password: WP Admin → Users → Profile → Application Passwords
# 2. Talk to the REST API:
curl -u "admin:YOUR_APP_PASSWORD" https://example.com/wp-json/evolutioncache/v1/status

# 3. Or use WP-CLI over SSH:
wp evolutioncache status
wp evolutioncache flush
wp evolutioncache warm --limit=100
```

Point your agent at [AGENTS.md](AGENTS.md) — it documents every command, endpoint and safety invariant.

### Theme compatibility

EvolutionCache is **theme-agnostic**: it filters the final HTML output and never modifies theme files. Verified in production with GeneratePress, XStore-style builders, WooCommerce, TranslatePress, WPML-style multilingual (cookie-aware cache keys), Rank Math, Wordfence, Object Cache Pro, Cloudflare. If a theme needs special handling, per-module switches and URL/script exclusion lists are built in — see [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

### Documentation

- [中文说明 README](README.zh-CN.md)
- [AI integration guide](AGENTS.md) · [AI 集成指南](docs/AI-INTEGRATION.zh-CN.md)
- [Theme compatibility](docs/COMPATIBILITY.md)
- [Security notes](SECURITY.md) · [Changelog](CHANGELOG.md)

### Requirements

WordPress 5.8+, PHP 7.4+ (tested on PHP 8.4), any modern server (nginx/Apache/LiteSpeed).

---

<a id="中文说明"></a>
## 中文说明

EvolutionCache 把完整渲染的页面存为静态文件，在 WordPress 启动之前直接输出（`advanced-cache.php` 直出），并且只在缓存未命中时做**一次** HTML 优化：CSS 压缩+延迟、JS 延迟/交互后执行、原生图片懒加载、LCP 预加载、超大图片自动压缩。

- **无许可证、无遥测、零第三方库、零外部请求。**
- **fail-open 设计**：任何优化出错自动回退原样输出，绝不会白屏。
- **AI 就绪**：REST API + WP-CLI + [AGENTS.md](AGENTS.md)，Codex、ZCode、Claude Code、WorkBuddy 等工具连接 WordPress 后即可接管管理。

### 功能一览

| 模块 | 说明 |
|---|---|
| 整页缓存 | WP 加载前静态直出、SHA-256 键、Gzip 预压缩、内容更新自动失效、每小时 GC、代数防竞态 |
| CSS | 本地样式压缩为内容哈希副本、非关键 CSS 延迟、`font-display:swap`、可选关键 CSS 内联 |
| JS | defer / **交互后执行** 两种模式 + 排除名单；JSON-LD 与 noscript 永不触碰 |
| 图片 | 原生懒加载、LCP 预加载、**超大图自动压缩**（≥800px → 800w/q80 WebP 副本） |
| 缓存预热 | 每小时按 sitemap 预热 + 手动触发 |
| 管理后台 | 一屏状态板、工具栏"清缓存"按钮、安全默认锁定、高级项折叠 |
| AI / API | REST 端点（`/wp-json/evolutioncache/v1/…`）+ WP-CLI（`wp evolutioncache …`），见 [AGENTS.md](AGENTS.md) |

### 安装

1. 从 [Releases](../../releases) 下载 `evolutioncache.zip`。
2. 后台 **插件 → 安装插件 → 上传** → 激活。
3. 完成。最佳配置自动生效，工具栏出现"清缓存"按钮。

### AI 接管（Codex / ZCode / WorkBuddy / Claude Code…）

```bash
# 1. 创建应用密码：后台 → 用户 → 个人资料 → 应用密码
# 2. 调 REST API：
curl -u "admin:应用密码" https://你的域名/wp-json/evolutioncache/v1/status

# 3. 或 SSH 下使用 WP-CLI：
wp evolutioncache status
wp evolutioncache flush
wp evolutioncache warm --limit=100
```

把 [AGENTS.md](AGENTS.md) 交给你的 AI 工具——里面写清了全部命令、端点和安全约束。

### 主题兼容

EvolutionCache **与主题无关**：只过滤最终 HTML 输出，从不修改主题文件。生产环境已验证 GeneratePress、XStore 类构建、WooCommerce、TranslatePress、Rank Math、Wordfence、Object Cache Pro、Cloudflare。特殊主题可用模块开关 + 排除名单处理，详见 [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md)。

### 环境要求

WordPress 5.8+，PHP 7.4+（已在 PHP 8.4 实测），任意主流服务器（nginx/Apache/LiteSpeed）。

---

## License / 许可证

GPL-2.0-or-later. Author / 作者: **Evolution301**.
