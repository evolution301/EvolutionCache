# Changelog / 更新日志

All notable changes are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).
版本遵循 [Keep a Changelog](https://keepachangelog.com/) 规范。

## [1.3.0] - 2026-09-26

### Added / 新增
- **AI takeover API**: REST endpoints `GET/POST /wp-json/evolutioncache/v1/{status,flush,warm,settings}`
  with `manage_options` permission — designed for Codex, ZCode, WorkBuddy, Claude Code.
  AI 接管 API：REST 端点 + 权限控制，AI 工具用应用密码即可管理插件。
- **AGENTS.md** machine-readable takeover guide + AI/API section in the admin panel.
- Automatic compression of oversized images (≥800px wide → 800w/q80 WebP copy, LCP-safe).
  超大图片自动压缩（≥800px → 800w/q80 WebP，LCP 图跳过）。
- A11y module: generic link texts (READ MORE, Learn more…) get aria-labels derived from
  the link slug. 泛化链接自动加 aria-label。
- `wp-includes` added to the JS delay exclusion list by default (fixes
  `ReferenceError: wp is not defined` console errors from load-order interleaving).
  默认把 WP 核心脚本排除出延迟管线，修复 `wp is not defined` 控制台报错。
- Contrast fix deployed for manufacturer-directory headings (a11y).
- Portable local test harness (51 assertions) — `php tests/run-tests.php`.

### Changed / 变更
- Mobile separate cache now defaults to **off** (responsive themes share one cached view;
  higher hit rate). Existing installs are migrated automatically by the upgrade routine.
  移动端单独缓存默认关闭（响应式主题共享缓存），老站点由升级例程自动迁移。
- Self-heal upgraded from hourly cron to per-request lightweight checks: runtime config
  is rebuilt on the next request after any external cache wipe.
  自愈机制升级为每请求轻量检查，外部清空缓存目录后下一个请求即恢复。
- Automatically takes over the inert `advanced-cache.php` stub left behind by
  Seraphinite Accelerator on deactivation. 自动接管 Seraphinite 停用后的占位 drop-in。

## [1.2.1] - 2026-09-26
- Rebrand to **EvolutionCache** by Evolution301. 更名为 EvolutionCache。

## [1.2.0] - 2026-09-26
- Admin toolbar "Clear cache" button (nonce-protected, referer-aware redirect).
  管理员工具栏"清缓存"快捷按钮。
- Mobile separate cache defaults to off; advanced settings collapsed behind a warning.
  移动缓存默认关闭；高级设置折叠防误操作。

## [1.1.0] - 2026-09-26
- JS delay-exclusion list hardening; asset write atomicity; Seraph stub takeover.

## [1.0.0] - 2026-09-26
- Initial internal release: page cache, CSS/JS/image optimization, warmer,
  WP-CLI, generation-based flush race protection.
