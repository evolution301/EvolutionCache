# AI Integration Guide / AI 集成指南

English | [中文](#中文)

This guide is the deep-dive companion to [AGENTS.md](../AGENTS.md), written for
teams connecting AI coding agents (Codex, ZCode, WorkBuddy, Claude Code, Cursor…)
to a WordPress site running EvolutionCache.

---

## English

### Option A — REST API (recommended for remote agents)

1. Create an Application Password: **WP Admin → Users → Profile → Application Passwords**.
2. Call the API with HTTP Basic auth (use the application password, not the login password):

```bash
SITE=https://example.com
AUTH="admin:YOUR-APP-PASSWORD"

curl -u "$AUTH" "$SITE/wp-json/evolutioncache/v1/status"    # full health snapshot
curl -u "$AUTH" "$SITE/wp-json/evolutioncache/v1/settings"  # current settings
curl -u "$AUTH" -X POST "$SITE/wp-json/evolutioncache/v1/flush"
curl -u "$AUTH" -X POST -H "Content-Type: application/json" \
     -d '{"limit":150}' "$SITE/wp-json/evolutioncache/v1/warm"
```

`status` response fields: `version`, `page_cache`, `dropin` (`active|conflict|
no-wp-cache|missing`), `js_mode`, `files`, `bytes`, `last_flush`, `last_warm`,
and the endpoint list (self-describing for agents).

### Option B — WP-CLI (server/SSH agents)

```bash
wp evolutioncache status
wp evolutioncache flush
wp evolutioncache warm --limit=200
wp evolutioncache config
```

### Agent playbook / 代理标准流程

1. **Read** `status` → decide.
2. **After content publishes**: `flush` then `warm`.
3. **If `dropin: conflict`**: another page-cache plugin holds
   `wp-content/advanced-cache.php` — deactivate it, then reactivate EvolutionCache.
4. **If `dropin: missing/no-wp-cache`**: deactivate + reactivate EvolutionCache (the
   activation hook installs the drop-in and sets `WP_CACHE`).
5. **Verify**: `curl -sI https://example.com/ | grep -i x-feather` → expect `HIT (d)`.

### Safety contract

All endpoints require `manage_options`. Agents get an administrator-scoped
application password and nothing more. Mutating endpoints accept no user input
beyond an integer `limit`. The plugin itself never performs outbound requests.

---

## 中文

### 方案 A — REST API（推荐给远程代理）

1. 创建应用密码：**后台 → 用户 → 个人资料 → 应用密码**。
2. 使用 HTTP Basic 认证调用（用应用密码，不是登录密码）：

```bash
SITE=https://你的域名
AUTH="admin:你的应用密码"

curl -u "$AUTH" "$SITE/wp-json/evolutioncache/v1/status"    # 完整状态
curl -u "$AUTH" "$SITE/wp-json/evolutioncache/v1/settings"  # 当前设置
curl -u "$AUTH" -X POST "$SITE/wp-json/evolutioncache/v1/flush"
curl -u "$AUTH" -X POST -H "Content-Type: application/json" \
     -d '{"limit":150}' "$SITE/wp-json/evolutioncache/v1/warm"
```

`status` 返回：`version`、`page_cache`、`dropin`（active/conflict/no-wp-cache/missing）、
`js_mode`、`files`、`bytes`、`last_flush`、`last_warm` 和端点列表（自描述，方便 AI 探索）。

### 方案 B — WP-CLI（服务器/SSH 代理）

```bash
wp evolutioncache status
wp evolutioncache flush
wp evolutioncache warm --limit=200
wp evolutioncache config
```

### 代理标准流程

1. **读** `status` → 判断状态。
2. **内容发布后**：先 `flush` 再 `warm`。
3. **`dropin: conflict`**：另一个页缓存插件占用了 `wp-content/advanced-cache.php`——
   停用它，再重新激活 EvolutionCache。
4. **`dropin: missing/no-wp-cache`**：停用再激活 EvolutionCache（激活钩子会安装
   drop-in 并写入 `WP_CACHE`）。
5. **验证**：`curl -sI https://你的域名/ | grep -i x-feather`，期望 `HIT (d)`。

### 安全约定

所有端点要求 `manage_options` 权限。给 AI 的应用密码只有管理员作用域，别无其他。
写操作端点除整数 `limit` 外不接受任何用户输入。插件自身从不发起外部请求。
