# QA Report: Sidebar Vault Tree

## Metadata
- **Feature**: Sidebar Vault Tree (ui-sidebar-vault-tree)
- **QA Round**: 1
- **Verdict**: **FAIL** (one MODERATE defect, QA-01)

## Verification
- Scoped Pest: 107 passed. Full suite: 753 passed, 11 skipped, 0 failed.
- PHPStan 0 errors; types:check pass; test:js 142 passed; build OK (deletes tracked public/fonts-manifest.dev.json — environment side effect).
- Pint --test fails only on pre-existing untouched tests/Unit/ExampleTest.php.
- Manual M1–M8 not run (no browser).

## Issues
| ID | Classification | Fix Attempts | Description | Location | Recommended fix |
|---|---|---|---|---|---|
| QA-01 | MODERATE | 0 | `pr-20` on the vault button is overridden by SidebarMenuButton's `group-has-data-[sidebar=menu-action]/menu-item:pr-8` (later in CSS, higher specificity; tailwind-merge doesn't dedupe across variants). Chevron and name end sit under the three row actions. | `resources/js/components/vaults/SidebarVaultItem.vue` | Use `group-has-data-[sidebar=menu-action]/menu-item:pr-20` (or `pr-20!`); rebuild and confirm padding-right 5rem. |
| QA-02 | MINOR | 0 | Name span is no longer last child, so base `[&>span:last-child]:truncate` no longer applies; long names don't ellipsize. | `SidebarVaultItem.vue` | Add `truncate` to the name span. |
| QA-03 | MINOR | 0 | `aria-expanded="false"` announced on rows where clicking navigates instead of expanding. | `SidebarVaultItem.vue` | Emit `aria-expanded` only when `hasTree`. |
| QA-04 | MINOR (pre-existing, out of scope) | 0 | Pint fails on untouched `tests/Unit/ExampleTest.php`. | `tests/Unit/ExampleTest.php` | Fix separately. |
| QA-05 | MINOR (coverage, optional) | 0 | No frontend tests for activate/toggle or mobile sheet close. | n/a | Optional follow-up. |

## Routing
QA-01, QA-02, QA-03 → senior-developer, then QA re-test. QA-04/05 optional.

---

# QA Round 2

- **Verdict**: **PASS** (zero open Critical/High/Moderate issues)
- Verification: types:check pass; test:js 142 passed; scoped Pest 107 passed (536 assertions); build OK (fonts-manifest side effect only). Manual M1–M8 not run.
- QA-01 confirmed: `group-has-data-[sidebar=menu-action]/menu-item:pr-20` is deduped by twMerge (no residual pr-8); built CSS gives padding-right 5rem.

| ID | Classification | Fix Attempts | Status |
|---|---|---|---|
| QA-01 | MODERATE | 1 | RESOLVED |
| QA-02 | MINOR | 1 | RESOLVED |
| QA-03 | MINOR | 1 | RESOLVED |
| QA-04 | MINOR (pre-existing) | 0 | Accepted follow-up |
| QA-05 | MINOR (coverage) | 0 | Accepted follow-up |
