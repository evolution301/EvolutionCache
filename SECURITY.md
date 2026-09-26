# Security / 安全说明

## Reporting / 漏洞报告

Please report security issues privately to the repository owner via GitHub
Security Advisories ("Report a vulnerability" button) instead of opening a
public issue. 请通过 GitHub 安全通告私密上报，勿开公开 issue。

## Design guarantees / 设计保证

EvolutionCache follows WordPress hardening practice:

| Area | Guarantee |
|---|---|
| File paths | Cache paths are SHA-256 of normalized keys only; no user input reaches a filesystem path; deletions are realpath-contained |
| Authz | Every mutating action requires `manage_options`; REST routes use `permission_callback`; no unauthenticated endpoints |
| CSRF | Nonces on all admin-post actions and settings saves |
| Input | Settings pass a strict whitelist sanitizer; custom regex lines are syntax-validated before use; output is escaped |
| Cache poisoning | Query-string whitelist (unknown params ⇒ dynamic), auth/cart/comment cookies bypass, language-cookie values folded into the key after strict validation, cache generation counter closes flush races |
| SSRF | The warmer only fetches the site's own host on ports 80/443 over http(s), never follows redirects, and validates every child-sitemap URL before fetching |
| Dangerous primitives | No `eval`, no SQL, no deserialization, no process execution, no outbound requests |
| Fail-open | Any optimization error returns the original HTML; the drop-in silently steps aside on any inconsistency |

## Ops notes / 运维提示

- Platform "clear cache" buttons that wipe `wp-content/cache/` are safe: the
  plugin rebuilds its runtime config on the next request (self-heal).
- Deactivating EvolutionCache fully restores the previous state (drop-in,
  `WP_CACHE`, cron events, stored options are all removed on uninstall).
