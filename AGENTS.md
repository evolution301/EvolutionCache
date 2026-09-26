# AGENTS.md — AI takeover guide for EvolutionCache / EvolutionCache AI 接管指南

This file teaches AI agents (Codex, ZCode, Claude Code, WorkBuddy, Cursor, …) how to
manage the **EvolutionCache** WordPress plugin. 本文指导 AI 代理管理 EvolutionCache 插件。

---

## 1. What this plugin is / 插件简介

EvolutionCache is a lightweight full-page cache + front-end optimizer. All user-facing
operations are available through two machine interfaces:

| Interface | Best for | Auth |
|---|---|---|
| REST API `/wp-json/evolutioncache/v1/*` | HTTP-capable agents | WordPress Application Password (Basic) or cookie+nonce |
| WP-CLI `wp evolutioncache …` | SSH / server agents | none (server user) |

## 2. REST API / REST 接口

Base: `https://<site>/wp-json/evolutioncache/v1`
All routes require `manage_options` (use an Application Password:
WP Admin → Users → Profile → Application Passwords).

| Endpoint | Method | Purpose / 用途 |
|---|---|---|
| `/status` | GET | Version, cache state, drop-in state, file count/bytes, last flush/warm, endpoint list |
| `/flush` | POST | Purge all cached pages / 清空整页缓存 |
| `/warm` | POST | Queue sitemap warm-up; optional JSON body `{"limit": 100}` |
| `/settings` | GET | Current settings (read-only) |

Examples / 示例:

```bash
curl -u "admin:APPPASSWORD" https://example.com/wp-json/evolutioncache/v1/status
curl -u "admin:APPPASSWORD" -X POST https://example.com/wp-json/evolutioncache/v1/flush
curl -u "admin:APPPASSWORD" -X POST -H "Content-Type: application/json" \
     -d '{"limit":100}' https://example.com/wp-json/evolutioncache/v1/warm
```

## 3. WP-CLI / WP-CLI 命令

```bash
wp evolutioncache status          # page_cache, drop-in, WP_CACHE, files, last flush/warm
wp evolutioncache flush           # purge all cached pages + rebuild runtime config
wp evolutioncache warm --limit=50 # crawl sitemap and pre-build cache
wp evolutioncache config          # rewrite cache/feathercache/config.json
```

(`wp feathercache …` works as a legacy alias.)

## 4. Recommended agent playbook / 建议的标准操作流程

After content deploys / 内容发布后:

```bash
wp evolutioncache flush && wp evolutioncache warm --limit=100
```

Health check / 健康检查:

```bash
curl -sI https://example.com/ | grep -i x-feather   # expect: X-FeatherCache: HIT (d)
curl -s -u "admin:APPPASSWORD" https://example.com/wp-json/evolutioncache/v1/status
```

`X-FeatherCache: HIT (d|m)` means the page cache is serving; absence means the last
request was dynamic (logged-in, POST, excluded URL, or cache was just flushed).

## 5. Repository layout / 仓库结构

```
feathercache.php      Plugin bootstrap (hooks, activation, upgrade routine)
advanced-cache.php    Drop-in — serves cached pages before WP boots (self-contained)
includes/Options.php  Settings defaults + whitelist sanitizer
includes/CacheStore.php  SHA-256 addressed page/asset storage, atomic writes, GC
includes/CacheKey.php, CacheRules.php  Cache key + cacheability policy
includes/Dropin.php   Installs wp-content/advanced-cache.php, config.json, WP_CACHE
includes/HtmlPipeline.php  ob_start pipeline orchestration + HTML minify
includes/Opt/         Css / Js / Images / A11y / Fonts optimizers
includes/PurgeHooks.php  Content-change invalidation
includes/Warmer.php   Sitemap crawler (SSRF-hardened: own host, 80/443 only)
includes/Rest.php     AI endpoints (this document, section 2)
includes/Admin.php, Cli.php  Admin UI + WP-CLI
tests/                Portable harness: php tests/run-tests.php
```

## 6. Safety invariants — agents MUST NOT break / 安全约束（AI 不得破坏）

1. **Fail-open**: any optimizer error must return the original HTML. Never swallow
   this fallback.
2. **No user input in file paths**: cache paths are SHA-256 of normalized keys only.
3. **Capability + nonce on every mutating action** (`manage_options`).
4. **SSRF**: the warmer only requests the site's own host, ports 80/443, no redirects.
5. **No eval / no SQL / no telemetry / no external requests** — keep it that way.
6. The drop-in `advanced-cache.php` must never call WordPress functions (it runs
   before WP boots).
7. Bump `FEATHERCACHE_VERSION` and `Plugin::upgrade()` together when changing
   defaults for existing installs.

## 7. Local development / 本地开发

```bash
php tests/run-tests.php   # 51 assertions, no DB or web server needed (WP stubs included)
php -l <file>             # lint any file
```

Build the distributable zip (folder `feathercache/` must be the archive root,
forward slashes — do NOT use PowerShell Compress-Archive, it breaks WP installs):

```bash
tar -a -c -f evolutioncache.zip feathercache
```
