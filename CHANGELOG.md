## Changelog / 更新日志

All notable changes are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).
版本遵循 [Keep a Changelog](https://keepachangelog.com/) 规范。

## [1.3.3] - 2026-09-27

### Fixed / 修复
- **"Clear cache" now takes effect immediately in the browser.** Cached pages
  previously shipped `max-age=3600`, letting browsers keep pages locally for an
  hour after a purge. Pages now send `max-age=0, must-revalidate, s-maxage=TTL`:
  browsers revalidate every visit (fast 304 when unchanged, fresh content
  immediately after a flush); CDN/edge caching is unaffected.
  **"清缓存"现在浏览器立即生效。**此前浏览器会本地留存页面 1 小时；现在每次访问都向服务器协商（未变 304 秒回，已变立即拿新页），CDN 缓存不受影响。

### Added / 新增
- **Automatic OPcache self-clear**: replace-installs can leave stale bytecodes for
  the plugin's absolute paths (mixed old/new files → blank wp-admin). The plugin
  now clears OPcache on activation and once per version bump (single stat check
  per request). 替换安装/升级后自动清理 OPcache 旧字节码，杜绝后台白屏。
- **API key auth for the AI takeover API** (`X-EvolutionCache-Key` header) —
  some hosts strip the Authorization header that Application Passwords need.
  AI 接管 API 新增专用密钥认证，兼容会剥离 Authorization 头的主机。

## [1.3.0] - 2026-09-26

### Added / 新增
- **AI takeover API**: REST endpoints `GET/POST /wp-json/evolutioncache/v1/{status,flush,warm,settings}`
  with `manage_options` permission — designed for Codex, ZCode, WorkBuddy, Claude Code.
- **AGENTS.md** machine-readable takeover guide.
- Automatic compression of oversized images (≥800px wide → 800w/q80 WebP copy, LCP-safe).
  超大图片自动压缩（≥800px → 800w/q80 WebP，LCP 图跳过）。
- A11y module: generic link texts (READ MORE, Learn more…) get aria-labels derived
  from the link slug. 泛化链接自动加 aria-label。
- `wp-includes` added to the JS delay exclusion list by default (fixes
  `ReferenceError: wp is not defined` console errors).
  默认把 WP 核心脚本排除出延迟管线，修复 `wp is not defined` 报错。
- Portable local test harness (51 assertions) — `php tests/run-tests.php`.

### Changed / 变更
- Mobile separate cache now defaults to **off** (responsive themes share one cached
  view; higher hit rate). Existing installs are migrated automatically.
  移动端单独缓存默认关闭，老站点由升级例程自动迁移。
- Self-heal upgraded to per-request lightweight checks: runtime config is rebuilt
  on the next request after any external cache wipe.
  自愈机制升级为每请求轻量检查，外部清空缓存目录后下一个请求即恢复。
- Automatically takes over the inert `advanced-cache.php` stub left behind by
  Seraphinite Accelerator on deactivation. 自动接管 Seraphinite 停用后的占位 drop-in。

## [1.2.1] - 2026-09-26
- Rebrand to **EvolutionCache** by Evolution301. 更名为 EvolutionCache。

## [1.2.0] - 2026-09-26
- Admin toolbar "Clear cache" button (nonce-protected, referer-aware redirect).
  管理员工具栏"清缓存"快捷按钮。
- Advanced settings collapsed behind a warning to prevent misconfiguration.
  高级设置折叠防误操作。

## [1.1.0] - 2026-09-26
- Cache hardening: generation counter for race-free flush, atomic config writes,
  per-directory .htaccess protection, hourly GC, JS/CSS exclusion lists.

## [1.0.0] - 2026-09-26
- Initial internal release: page cache, CSS/JS/image optimization, warmer, WP-CLI.
