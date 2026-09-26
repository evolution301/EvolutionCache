=== EvolutionCache ===
Contributors: evolution301
Tags: performance, cache, pagespeed, speed, optimization
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

轻量级整页缓存与前端性能优化。无许可证、无遥测、零第三方依赖，fail-open 出错自动回退。

== Description ==

FeatherCache 只在缓存未命中时优化一次 HTML，命中时在 WordPress 加载之前直接吐出静态页面（支持 Gzip 预压缩）。

管理后台默认锁定为最佳配置（防误操作），并附带工具栏"清缓存"快捷按钮。

功能模块（均可独立开关）：

* 整页缓存：匿名访客静态直出、移动/桌面分视图、查询参数白名单、内容更新自动失效、sitemap 预热爬虫、WP-CLI（`wp feathercache flush|warm|status`）
* CSS：本地样式表压缩为内容哈希副本、非关键 CSS 延迟加载（preload+onload）、关键 CSS 内联、font-display:swap 注入
* JS：defer 模式 / 交互后执行模式（带排除名单与超时兜底），JSON-LD 与 noscript 永不触碰
* 图片：原生 loading="lazy" + decoding="async"、LCP 图 fetchpriority=high + preload（srcset 图仅设优先级避免重复下载）
* 字体：Google Fonts preconnect

安全设计：缓存路径全部 SHA-256 哈希（无路径注入）、管理操作 nonce + 权限双重校验、设置白名单清洗、输出全转义、预热仅请求本站域名（防 SSRF）、缓存目录防列举、无 eval / 无外部请求 / 无 SQL 拼接。

== Installation ==

1. 上传 `feathercache.zip` 并激活插件。
2. 在左侧菜单 "FeatherCache" 调整模块开关。
3. 建议先在测试环境验证交互后执行（delay）模式。

== Changelog ==

= 1.1.0 =
* 新增：管理员工具栏"清缓存"快捷按钮（带防点击劫持的 nonce，任何页面一键清缓存）
* 变更：移动端单独缓存默认关闭（响应式主题共享一份缓存，命中率更高）；已有站点升级时自动应用
* 加固：运行时配置缺失时每个请求自动自愈（不再依赖每小时守护 cron）
* 加固：自动接管 Seraphinite Accelerator 停用后留下的 drop-in 占位文件
* 优化：清缓存写入原子化，缓存代数防清缓存竞态

= 1.0.0 =
* Initial release.
