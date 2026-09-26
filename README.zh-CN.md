# EvolutionCache 中文说明（完整版）

> 本文档是 [README.md](README.md) 中文部分的展开。English users: see [README.md](README.md).

EvolutionCache 是一个**轻量级 WordPress 整页缓存与前端性能优化插件**，目标是替代 Seraphinite Accelerator、WP Rocket 等重型方案：无许可证验证、无遥测、零第三方依赖，并且**为 AI 工具接管而设计**。

## 它如何工作

1. **命中路径**：匿名访客的请求由 `advanced-cache.php` 在 WordPress 核心加载之前直接输出静态页（Gzip 预压缩副本，零 CPU）。
2. **未命中路径**：WordPress 正常渲染，插件在输出缓冲中对最终 HTML 做**一次性**优化，然后落盘。
3. **fail-open**：任何优化模块抛异常，自动回退原样输出——永远不白屏。

## 功能模块（均可独立开关）

| 模块 | 说明 |
|---|---|
| 整页缓存 | SHA-256 哈希键、Gzip 预压缩、TTL + 内容更新自动失效、每小时 GC、缓存代数防竞态 |
| CSS | 压缩为内容哈希副本、非关键 CSS 延迟加载、`font-display:swap`、关键 CSS 内联 |
| JS | defer / **交互后执行** 两种模式 + 排除名单；JSON-LD、noscript 永不触碰 |
| 图片 | 原生懒加载、LCP 预加载（fetchpriority=high）、**超大图自动压缩**（≥800px → 800w/q80 WebP） |
| 缓存预热 | 每小时按 sitemap 爬取 + 手动触发 + WP-CLI |
| 多语言 | 支持 TranslatePress（语言 cookie 并入缓存键、语言参数白名单） |
| 管理后台 | 一屏状态板、工具栏"清缓存"按钮、安全默认锁定、高级项折叠防误操作 |
| **AI / API** | REST 端点 + WP-CLI + AGENTS.md，AI 工具连接 WordPress 后即可接管 |

## AI 工具接管指南

EvolutionCache 内置机器可读的管理接口。任何能发 HTTP 请求或执行 WP-CLI 的 AI 工具（Codex、ZCode、WorkBuddy、Claude Code、Cursor 等）都可以管理它。

### 第一步：创建凭据

后台 → **用户 → 个人资料 → 应用密码**，创建一个应用密码。

### 第二步：调用 REST API

```bash
# 查看状态
curl -u "admin:你的应用密码" https://你的域名/wp-json/evolutioncache/v1/status

# 清空缓存
curl -u "admin:你的应用密码" -X POST https://你的域名/wp-json/evolutioncache/v1/flush

# 触发预热（可选 limit 参数）
curl -u "admin:你的应用密码" -X POST https://你的域名/wp-json/evolutioncache/v1/warm?limit=100

# 查看设置
curl -u "admin:你的应用密码" https://你的域名/wp-json/evolutioncache/v1/settings
```

### 第三步（可选）：WP-CLI

```bash
wp evolutioncache status
wp evolutioncache flush
wp evolutioncache warm --limit=100
wp evolutioncache config
```

### 给 AI 的完整接管文档

把仓库里的 [AGENTS.md](AGENTS.md) 交给你的 AI 工具（ZCode 用户可直接放工作区；Codex/Claude Code 用户放仓库根目录），其中包含全部命令、端点、返回示例与安全约束。

## 主题兼容性

EvolutionCache 与主题无关——只过滤最终 HTML 输出，不修改主题文件，不依赖任何主题函数。

- **生产环境实测**：GeneratePress、XStore、WooCommerce、TranslatePress、Rank Math、Wordfence、Object Cache Pro、Imagify、Cloudflare
- **设计保证**：任何遵循 WordPress 输出标准的主题都兼容
- **兜底机制**：模块开关、URL 排除（前缀/正则）、JS 排除名单、CSS 排除名单

详见 [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md)。

## 常见问题

**激活后页面显示异常？** 关闭对应模块（CSS/JS/图片均为独立开关），异常立即消失——fail-open 保证不影响显示。
**和 Cloudflare 冲突吗？** 不冲突。页面头部输出 `Cache-Control: public, max-age=3600`，Cloudflare 可直接边缘缓存。
**和 WP Rocket / Seraphinite 冲突吗？** 激活 EvolutionCache 前请停用它们；插件会检测 drop-in 占用并提示。

## 许可证

GPL-2.0-or-later。作者：**Evolution301**。
