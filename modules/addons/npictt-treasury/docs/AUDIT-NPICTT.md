# NPICTT modules vs ownpay-docs — audit

Date: 2026-04-17  
Sources: [ownpay-docs addon/gateway guides](https://github.com/own-pay/ownpay-docs), in-repo `PluginSandbox` / `PluginMigrator`.

## npictt-voucher (gateway)

| Check | Result | Notes |
|-------|--------|--------|
| Manifest slug/type/entrypoint | Pass | |
| `PluginInterface` + `GatewayAdapterInterface` | Pass | |
| `op_plugin_*` tables | Pass | `op_plugin_voucherpay_*` |
| `migrations/` on activate | Gap | Uses runtime `schema.sql` via `ensureSchema()` (avoids migrator DDL txn issue) |
| `uninstall()` cleanup | Gap | Empty — does not drop tables |
| Admin routes + `dashboard` | Pass | |
| `pushOwner('core')` for core reads | Pass | Documented in checkout label filter |
| Blocked PHP functions | Pass | |

**Remediation (done):** `migrations/` stub (DDL via `ensureSchema()` — MySQL migrator txn); `uninstall()` drops `op_plugin_voucherpay_*`.

## npictt-card (gateway)

| Check | Result | Notes |
|-------|--------|--------|
| Manifest / interfaces | Pass | |
| Plugin DB tables | N/A | Session in txn metadata |
| `uninstall()` | Pass | No plugin tables |
| `pushOwner('core')` | Pass | Checkout label |
| CSP in manifest | Gap (low) | Hosted pages are first-party; add if external scripts appear |

## npictt theme

| Check | Result | Notes |
|-------|--------|--------|
| Manifest type theme | Pass | |
| Template overrides | Pass | Prepend path via TwigFactory |
| `capabilities` / entrypoint keys | Gap (low) | Works; could add `"capabilities":["theme"]` and `"entrypoint":"Theme.php"` for docs parity |
| Core template edits | Remediated | Brand UI moved to theme overrides; see `modules/themes/npictt/docs/CORE-DIFF-INVENTORY.md` |
| `capabilities` / entrypoint | Pass | `theme`, `checkout_ui`, `entrypoint` |

## Related core diffs

Addressed by **NPICTT Core Diff Cleanup**: inventory + theme relocate of admin branding and `_customer_identify`; generic core hunks kept (`TransactionService` `__reject`, Twig prepend/`auto_reload`, ledger currency, dashboard month).
