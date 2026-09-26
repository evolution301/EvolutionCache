# Theme & Environment Compatibility / 主题与环境兼容性

English | [中文](#中文)

---

## English

EvolutionCache is **theme-agnostic by design**. It never reads or writes theme
files; it filters the final HTML output once per cache miss and serves static
pages on hits. Any theme that renders through standard WordPress templates
(`wp_head` / `wp_footer` / the_content loop) is compatible.

### Verified in production / 生产实测

| Stack | Status |
|---|---|
| GeneratePress + TranslatePress (multilingual, EN/FR/ES/RU) | ✅ Verified |
| XStore-style builder theme (custom header/footer builders) | ✅ Verified |
| WooCommerce / CF7 + Cloudflare Turnstile / Rank Math (+PRO) | ✅ Verified |
| Imagify, Wordfence, Object Cache Pro (Redis), Jetpack | ✅ Verified |
| Cloudflare edge cache (respects `Cache-Control` + `Vary` emitted by the plugin) | ✅ Verified |

### Built-in compatibility tooling / 内置兼容工具

- Per-module switches (page cache, CSS, JS, images) — turn off only the misbehaving part.
- URL exclusions: prefix (`/checkout`) or regex (`#/product/.*#`).
- JS delay exclusion list by src fragment (cookie banners, captchas, forms, analytics
  are pre-configured; `wp-includes` core scripts are excluded by default).
- CSS exclusion list to keep a stylesheet render-blocking when deferring breaks a page.
- Mobile separate cache is OFF by default — responsive themes share one cached view.
- Multilingual: TranslatePress language cookie values are validated and folded into the
  cache key, so `/`, `/fr/`, `?lang=de` and cookie-switched languages never cross.

### Known limitations / 已知边界

- Server-level page caches (Varnish/Cloudflare APO rules) should respect the
  `Cache-Control` headers EvolutionCache emits; no special config is required for
  standard CDN caching.
- Themes that build entire sections client-side (JS-rendered grids) obviously can't
  be optimized server-side; their links are only reachable after JS runs.
- Bitnami-style One-Click stacks that pre-install another page cache plugin will
  show a drop-in conflict notice — deactivate the other plugin first; EvolutionCache
  deliberately refuses to overwrite a foreign `advanced-cache.php` (except the inert
  stub Seraphinite Accelerator leaves on deactivation).

---

## 中文

EvolutionCache **与主题无关**：从不读写主题文件，只在缓存未命中时对最终 HTML 做一次过滤，命中时直接输出静态页。任何通过标准 WordPress 模板渲染（`wp_head` / `wp_footer` / 主循环）的主题都兼容。

### 生产实测

| 环境 | 状态 |
|---|---|
| GeneratePress + TranslatePress 多语言（英/法/西/俄） | ✅ 已验证 |
| XStore 类可视化构建主题 | ✅ 已验证 |
| WooCommerce / CF7 + Cloudflare Turnstile / Rank Math（+PRO） | ✅ 已验证 |
| Imagify、Wordfence、Object Cache Pro（Redis）、Jetpack | ✅ 已验证 |
| Cloudflare 边缘缓存（尊重插件输出的 `Cache-Control` + `Vary`） | ✅ 已验证 |

### 内置兼容工具

- 模块独立开关（页面缓存/CSS/JS/图片），出问题只关出问题的那一个
- URL 排除：前缀或正则
- JS 延迟排除名单（cookie 横幅、验证码、表单、统计已预配置；WP 核心脚本默认排除）
- CSS 排除名单：延迟加载破坏页面时保持阻塞加载
- 移动端单独缓存默认关闭——响应式主题共享一份缓存
- 多语言：TranslatePress 语言 cookie 值严格校验后并入缓存键，语言之间永不串页

### 已知边界

- 服务器级页缓存（Varnish / Cloudflare APO 规则）会尊重插件输出的缓存头，无需特殊配置
- 纯客户端渲染的主题区块无法在服务端优化（其链接需 JS 运行后才存在）
- 预装其他页缓存插件的镜像环境会出现 drop-in 冲突提示——请先停用对方；EvolutionCache 出于安全考虑拒绝覆盖外来的 `advanced-cache.php`（Seraphinite 停用后的惰性占位除外，会自动接管）
