# QA Report: MDVault MCP KOS Server

## Metadata
- **Feature Name**: MDVault MCP KOS Server (`mcp-kos`)
- **Feature ID**: feat-mcp-kos
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-11-18
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 | yes | `tests/Feature/Mcp/MdVaultServerDiscoveryTest.php` | ✅ |
| FR-02 | yes | `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php`, `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-03 | yes | `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php` | ✅ |
| FR-04 | yes | `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php`, `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-05 | yes | `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-06 | yes | `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-07 | yes | `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-08 | yes | `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-09 | yes | `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-10 | yes | `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-11 | yes | `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php`, `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-12 | yes | `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php`, `tests/Feature/Mcp/McpNoteOperationsTest.php` | ✅ |
| FR-13 | yes | `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-14 | yes | `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-15 | yes | `tests/Feature/Mcp/MdVaultServerDiscoveryTest.php`, `tests/Feature/Mcp/McpSearchAndKosTest.php` | ✅ |
| FR-16 | yes | `tests/Feature/Mcp/MdVaultServerDiscoveryTest.php`, `tests/Feature/Settings/McpSettingsTest.php` | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Mcp tests/Feature/Settings/McpSettingsTest.php tests/Feature/Backups/BackupSettingsTest.php` | 36 passed, 0 failed (653 assertions) |
| `vendor/bin/phpstan analyse` | 2 errors (both pre-existing in untouched files; 0 errors in new/modified files) |
| `npm.cmd run types:check` | pass (`vue-tsc --noEmit` exited 0) |

### Verbatim Failure Output (Pre-existing Outside Feature Scope)

`vendor/bin/phpstan analyse`:
```json
{"tool":"phpstan","result":"failed","errors":2,"error_details":{"C:\\Users\\cherw\\Herd\\MDVault\\app\\Http\\Middleware\\HandleInertiaRequests.php":[{"line":51,"message":"Cannot cast non-empty-array|non-falsy-string to string.","identifier":"cast.string"}],"C:\\Users\\cherw\\Herd\\MDVault\\app\\Services\\DirectoryBrowserService.php":[{"line":287,"message":"Parameter #1 $array (list<array{name: string, path: non-falsy-string}>) of array_values is already a list, call has no effect.","identifier":"arrayValues.list"}]}}
```
Both `app/Http/Middleware/HandleInertiaRequests.php` and `app/Services/DirectoryBrowserService.php` are untouched by `mcp-kos`; zero PHPStan errors exist in any added or modified file.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| *(none)* | — | — | — | No open issues remaining. | — | — |

### Verified Fixes (Round 2)
- **QA-01 (Verified Fixed)** — `app/Mcp/Tools/MoveNoteTool.php:34,42` now validates `'target_folder' => ['present', 'nullable', 'string']` and passes `(string) ($validated['target_folder'] ?? '')` to `McpKosService::moveNote()`. `tests/Feature/Mcp/McpNoteOperationsTest.php:194-224` verifies moving notes back to vault root with both `""` and `"/"`.
- **QA-02 (Verified Fixed)** — `app/Mcp/Tools/UpdateNoteTool.php:36,45` now validates `'content' => ['present', 'nullable', 'string']` and passes `(string) ($validated['content'] ?? '')` to `McpKosService::updateNote()`. `tests/Feature/Mcp/McpNoteOperationsTest.php:180-191` verifies clearing a note to `""` while preserving BOM and returning the updated `file_hash`.
- **QA-03 (Verified Fixed)** — `app/Services/McpKosService.php:557-563` now computes `$absolute` and checks `$this->files->isSymlink($absolute)` before calling `$this->notes->preview($note)` in `appendNote()`, matching `readNote()`.

---

## 4. Code Review Notes
- **Security & Authorization**: Encrypted vaults (`is_encrypted === true`) and missing vaults (`VaultStatus::Missing`) are strictly excluded across all tools, search, skills, and resources; `list_vaults` skips `note_count` queries on excluded vaults. Two-tier permissions (`AI` full CRUD + `AI/.trash/` headless fallback vs. Personal `read_safe_write`) are consistently enforced via `assertAiVault()`. Path traversal checks reject `\0`, `\`, drive letters, `.` / `..`, dot-prefixed segments, `node_modules`, and symlinks (`appendNote()` now guards `isSymlink()` prior to `preview()`, matching `readNote()`).
- **Validation & Data Integrity**: `NoteService::createWithContent()` uses exclusive `'x'` creation, UTF-8/null-byte/size guards, and encrypted-vault refusal. `UpdateNoteTool` and `MoveNoteTool` now accept valid empty strings (`content: ""`, `target_folder: ""` / `"/"`) while preserving required key presence (`present`) and 64-char SHA-256 `file_hash` optimistic locking.
- **Performance (N+1, indexes)**: `McpSearchService` fetches candidate `Note` rows in a single `whereIn('vault_id', ...)` query and reads only eligible `<= 1 MiB` UTF-8 files line-by-line without regex ReDoS. `MdVaultServer::boot()` sets `PRAGMA busy_timeout = 5000;` for SQLite concurrency.
- **Conventions (AGENTS.md, PHP, Architecture)**: Services (`McpKosService`, `McpSearchService`, `McpClientConfigService`) are `final`, suffixed with `Service`, and keep all `Vault`/`Note` model and `FileStorageService` access out of `App\Mcp\*`. `BackupSettingsTest.php` passes untouched.
- **Frontend (Inertia/Vue/Wayfinder)**: `resources/js/pages/settings/Mcp.vue` uses a single root `<div>`, Wayfinder imports from `@/routes/settings/mcp`, `vue-sonner` + visual copy feedback, and dark-mode semantic tokens matching sibling Settings pages. `resources/js/layouts/settings/Layout.vue` adds `'AI & MCP'` via `editMcp()`. `vue-tsc --noEmit` passes cleanly.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/`
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: —
- [ ] **FAIL — MAJOR** → System Analyst: —
