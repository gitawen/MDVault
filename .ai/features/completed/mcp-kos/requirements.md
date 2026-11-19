# Requirements: MDVault MCP KOS Server

## Metadata
- **Feature Name**: MDVault MCP KOS Server (`mcp-kos`)
- **Feature ID**: feat-mcp-kos
- **Author**: System Analyst
- **Created Date**: 2026-11-18
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault stores local-first Markdown knowledge vaults and manages them via a NativePHP/Electron desktop UI and an SQLite registry (`vaults`, `notes`, `settings`). Currently, external AI coding and personal assistant clients (Claude Desktop, Cursor, Antigravity, Codex, Hermes) have no structured, safe protocol to read user context, search notes, scaffold agent project workspaces, store reusable skills, or update Markdown files with conflict protection—especially when the MDVault desktop UI is closed.

Adding a local stdio Laravel MCP server (`laravel/mcp`, `php artisan mcp:start mdvault`) turns MDVault into a **Knowledge Operating System (KOS)** for AI agents while strictly upholding MDVault's security invariants: encrypted vault key custody, two-tier vault permission boundaries, atomic file saves with SHA-256 optimistic locking, and non-destructive deletion semantics.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- **Local Stdio MCP Server**: Install `laravel/mcp` as a direct dependency, publish `routes/ai.php`, and expose `Mcp::local('mdvault', MdVaultServer::class)` via `php artisan mcp:start mdvault` offline (no Electron/HTTP server required).
- **Encrypted Vault Exclusion (v1)**: Strictly exclude encrypted vaults from all MCP reads, searches, listings of contents, and writes; return clear, actionable errors when an agent attempts to target an encrypted vault.
- **Two-Tier Vault Permissions**:
  - **`AI` Vault (Dedicated Agent Workspace)**: Designated/auto-provisioned unencrypted vault named `AI` where agents have full CRUD on notes and folders (`create`, `read`, `update`, `append`, `rename`, `move`, `delete` notes; `create`/`delete` folders; project scaffolding).
  - **Personal Vaults (all other active unencrypted vaults)**: Read + Safe Write only (`list_vaults`, `list_notes`, `search_notes`, `read_note`, `create_note`, `append_note`, `update_note`). Strictly forbid note delete, rename, move, folder create/delete, and vault-level management.
- **Project Scaffolding**: `init_ai_project` tool to scaffold `AI/<Project>/` with `overview.md`, `tasks.md`, `receipts.md`, and `skills/` for orchestrator + subagent workflows without overwriting existing files.
- **Concurrency & Optimistic Locking**: Return `file_hash` on reads/creates/writes; require `file_hash` on `update_note` and delegate to `NoteService::save()` atomic replace; fail `create_note` if target already exists; reconcile `IndexMode::Quick` and set SQLite `busy_timeout = 5000`.
- **Hybrid Search**: `search_notes` combining SQLite `Note` title/relative-path matching with on-demand Markdown file content grep (returning 1-based line numbers and snippets) across active unencrypted vaults with zero new DB tables/migrations.
- **KOS Memory, Skills & Prompts**:
  - Core memory resource (`mdvault://memory/about-me`) + `get_kos_context` tool reading `AI/about-me.md` (capped at ~2,000 chars by default).
  - Skill discovery & persistence (`list_skills`, `save_skill`, `mdvault://skills/{vault}/{skill}`, `mdvault://projects/{project}/skills/{skill}`).
  - Starter MCP prompts (`session_bootstrap`, `project_kickoff`, `synthesize_notes`, `record_receipt`).
- **Client Onboarding**: Copyable MCP client config snippets in a dedicated Settings page (`Settings → AI & MCP`) and an Artisan command (`php artisan mdvault:mcp-config`).

### Out of Scope (Non-Goals)
- Accessing or unlocking encrypted vaults via MCP (deferred beyond v1 to preserve `VaultKeyService` renderer key-custody).
- Remote HTTP/SSE/Web MCP endpoints (`Mcp::web`) or OAuth/Sanctum auth (local stdio only in v1).
- Vault-level CRUD (`create_vault` outside auto-provisioning `AI`, `rename_vault`, `delete_vault`, `open_vault`, `close_vault`, `reset_registry`) over MCP.
- Full-text search indexing tables (FTS5) or any new database migrations.
- Recursive non-empty folder deletion (`rm -rf`); `delete_folder` strictly preserves MDVault's empty-folder-only invariant.

---

## 3. User Personas & Stories
- **As a** solo developer using Claude Desktop, Cursor, Antigravity, Codex, or Hermes,
  **I want** my AI agent to load my `about-me.md` rules, search my personal notes, and manage project workspaces inside an `AI` vault via stdio MCP even when MDVault's GUI is closed,
  **So that** my knowledge base acts as persistent agent memory without risking my personal notes or encrypted vaults.
- **As a** human MDVault user editing notes concurrently in the desktop app,
  **I want** MCP writes to enforce `file_hash` optimistic locking and refuse destructive actions on my personal vaults,
  **So that** an AI agent never silently overwrites my open editor edits or deletes/reorganizes my personal folders.
- **As a** user setting up a new AI client,
  **I want** a copyable MCP client JSON/TOML configuration snippet inside MDVault Settings and via CLI,
  **So that** I can connect any MCP-compatible client in seconds with the correct PHP, `artisan`, and database paths.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | **Local MCP Server Registration** | Register `MdVaultServer` as a local `laravel/mcp` server under handle `mdvault` in `routes/ai.php`, runnable via `php artisan mcp:start mdvault`. Never emit non-JSON-RPC output to `STDOUT`. | Given `laravel/mcp` is installed and `routes/ai.php` is published, When `Mcp::local('mdvault', MdVaultServer::class)` is registered, Then `php artisan mcp:start mdvault` boots `MdVaultServer` over `StdioTransport` offline and `MdVaultServer::tools()/resources()/prompts()` expose all registered primitives. |
| **FR-02** | **Encrypted & Missing Vault Exclusion** | Encrypted vaults (`is_encrypted === true`) and missing vaults (`status === Missing`) cannot be read, searched, or mutated via MCP. | Given an encrypted vault `Secrets` and a missing vault `Offline`, When any MCP tool or resource targets `Secrets` or `Offline` (or runs cross-vault search), Then explicit access returns a clear error naming the reason (`encrypted` / `missing`) and cross-vault tools skip them without exposing note paths or contents. |
| **FR-03** | **Two-Tier Vault Listing & Permissions** | `list_vaults` lists all registered vaults with `accessible`, `tier` (`ai_workspace` / `personal` / `excluded`), `permission` (`full_crud` / `read_safe_write` / `none`), and hides `note_count` for excluded vaults. Enforce tier checks on every write/structural tool. | Given vaults `AI` (unencrypted), `Personal` (unencrypted), and `Secrets` (encrypted), When `list_vaults` runs, Then `AI` shows `full_crud`, `Personal` shows `read_safe_write`, and `Secrets` shows `none`/`accessible: false`; When `delete_note`, `rename_note`, `move_note`, `create_folder`, or `delete_folder` targets `Personal`, Then the tool returns an error and leaves disk and DB unchanged. |
| **FR-04** | **`AI` Vault Auto-Provisioning** | Any write/scaffold operation targeting `AI` resolves an existing unencrypted active `AI` vault (case-insensitive) or auto-creates it via `VaultService::create('AI', ...)`. If an existing `AI` vault is encrypted, fail clearly. | Given no `AI` vault exists, When `init_ai_project` or `create_note(vault: "AI", ...)` runs, Then `AI` vault is created on disk under the effective storage root and registered in SQLite; Given `AI` exists and is encrypted, When any tool targets `AI`, Then it returns an explicit error refusing to use an encrypted vault as the AI workspace. |
| **FR-05** | **Note Tree & Listing (`list_notes`)** | `list_notes` reconciles `IndexMode::Quick` on the requested active unencrypted vault and returns the folder/note tree (optionally scoped to a relative `folder`). If `vault` is `AI` and not yet provisioned, returns an empty tree (`provisioned: false`) without side effects. | Given an active unencrypted vault with nested folders and notes (including a `.md` file newly placed on disk), When `list_notes` runs, Then it reconciles the quick index and returns the updated tree with `uuid`, `title`, `relative_path`, and `folder`. |
| **FR-06** | **Hybrid Search (`search_notes`)** | `search_notes` reconciles `IndexMode::Quick` on target active unencrypted vault(s), queries SQLite `Note` `title`/`relative_path`, and greps readable UTF-8 `.md` files (`<= 1 MiB`) line-by-line on disk, returning ranked results with `matched_in`, `match_count`, 1-based `line` numbers, and concise `snippet`s. | Given matching notes across `AI` and `Personal` plus an encrypted vault `Secrets` containing the same keyword, When `search_notes(query: "...")` runs, Then matches from `AI` and `Personal` include line numbers and snippets, `Secrets` is skipped, and no new DB tables are used. |
| **FR-07** | **Read Note (`read_note`)** | `read_note` resolves a note by `(vault, path)` or `(vault, uuid)`, reconciles if needed, calls `NoteService::preview()`, and returns note metadata, fresh `file_hash`, `content`, `frontmatter_yaml`, and `body`. Fails clearly on missing, `> 1 MiB`, or invalid-UTF-8 files. | Given an active unencrypted note, When `read_note` is called, Then it returns `uuid`, `relative_path`, `file_hash` (64-char SHA-256), `content`, `frontmatter_yaml`, and `body`, updating a stale DB `file_hash` if modified on disk. |
| **FR-08** | **Safe Create Note (`create_note`)** | `create_note` creates a new `.md` note in an existing folder of any active unencrypted vault (auto-provisioning `AI` if target is `AI`). Fails if a file/row already exists at that path (case-insensitive) or if the target folder does not exist. | Given an existing folder in `Personal` or `AI`, When `create_note` runs with optional `content`, Then the file is created exclusively (`fopen(..., 'x')`), indexed in `notes`, and returns `uuid` + `file_hash`; When called again for the same path, Then it fails without overwriting. |
| **FR-09** | **Optimistic-Locked Update (`update_note`)** | `update_note` requires `file_hash` and calls `NoteService::save(..., NoteSaveMode::Source)` atomic replace. Fails with conflict details (including `current_hash`) if disk changed or file went missing. | Given a note whose on-disk SHA-256 is `H1`, When `update_note` passes `file_hash: H1`, Then the note is atomically replaced and a new `file_hash` `H2` is returned; When `update_note` passes a stale hash `H0`, Then it returns an error with `current_hash: H1` and leaves disk untouched. |
| **FR-10** | **Append Note (`append_note`)** | `append_note` appends Markdown text cleanly to an existing note in `AI` or Personal vaults via `NoteService::save()`, enforcing `file_hash` when supplied and always protecting against concurrent mid-save races via atomic replace. | Given an existing note with content `"# Log"`, When `append_note` appends `"- entry"` (with or without `file_hash`), Then the note contains `"# Log\n- entry"` with original EOL/BOM preserved; When an explicit stale `file_hash` is passed, Then it fails with conflict error. |
| **FR-11** | **`AI`-Only Note Rename, Move & Safe Delete** | `rename_note`, `move_note`, and `delete_note` operate exclusively on the `AI` vault. `rename_note` and `move_note` use `NoteService::rename()` / `NoteService::move()`. `delete_note` uses `NoteService::delete()` when OS trash is available, or moves the file to `AI/.trash/` (dot-ignored by indexer/backups) and deletes the `Note` row when running headless via CLI (`canTrash() === false`). | Given a note in `AI/work/draft.md`, When `rename_note`, `move_note`, and `delete_note` run in CLI mode (`canTrash() === false`), Then rename/move relocate file + DB row and `delete_note` removes the DB row and moves the file out of `AI/work/` into `AI/.trash/`, never permanently unlinking; When called on `Personal`, all three return a permission error. |
| **FR-12** | **`AI`-Only Folder Management (`create_folder`, `delete_folder`)** | `create_folder` creates a folder (and optional missing parent segments within `AI`) using `NoteService::createFolder()`. `delete_folder` deletes only empty folders inside `AI` using `NoteService::deleteFolder()`. Both fail on Personal or encrypted vaults. | Given `AI` vault, When `create_folder(path: "HRIS/specs")` runs, Then both segments are created; When `delete_folder` targets an empty folder, Then it removes it; When `delete_folder` targets a non-empty folder or any folder in `Personal`, Then it fails. |
| **FR-13** | **Project Scaffolding (`init_ai_project`)** | `init_ai_project(name, description?, stack?)` ensures `AI` vault exists, creates `AI/<name>/` and `AI/<name>/skills/`, and creates starter `overview.md`, `tasks.md`, and `receipts.md` only if missing (idempotent; never overwrites existing notes). | Given `init_ai_project(name: "HRIS")`, When called once, Then `AI/HRIS/overview.md`, `AI/HRIS/tasks.md`, `AI/HRIS/receipts.md`, and `AI/HRIS/skills/` exist and are indexed; When called again after user edits `tasks.md`, Then user edits are preserved verbatim. |
| **FR-14** | **Core Memory (`mdvault://memory/about-me` & `get_kos_context`)** | Expose static resource `mdvault://memory/about-me` and read-only tool `get_kos_context(max_chars?)` reading `AI/about-me.md` (default cap 2,000 chars). Gracefully guides creation when `AI/about-me.md` does not yet exist. | Given `AI/about-me.md` exists with 2,500 chars, When `get_kos_context` or `mdvault://memory/about-me` runs with default cap, Then it returns 2,000 chars with `truncated: true`; When `AI/about-me.md` is missing, Then both return non-crashing onboarding instructions for `AI/about-me.md`. |
| **FR-15** | **Skills & Starter Prompts** | Expose `list_skills`, `save_skill`, resource templates `mdvault://skills/{vault}/{skill}`, `mdvault://projects/{project}/skills/{skill}`, and `mdvault://notes/{vault}/{uuid}`, plus prompts `session_bootstrap`, `project_kickoff`, `synthesize_notes`, `record_receipt`. | Given skills in `Personal/skills/` and `AI/HRIS/skills/`, When `list_skills` runs, Then both scopes appear without full bodies; When `save_skill` updates an existing skill, Then matching `file_hash` is required; When `SkillResource` / `ProjectSkillResource` / Prompts are invoked, Then valid Markdown / prompt messages are returned and encrypted vaults are rejected. |
| **FR-16** | **Client Onboarding UI & CLI Command** | Add `Settings → AI & MCP` (`settings/mcp`) Inertia page + `php artisan mdvault:mcp-config` command generating copyable JSON/TOML configs (`php artisan mcp:start mdvault` + `env.DB_DATABASE` when active DB differs from default `database/mdvault.sqlite`), plus `AI` workspace status and one-click provisioning (`POST settings/mcp/provision`). | Given a user visits `/settings/mcp` or runs `php artisan mdvault:mcp-config`, Then valid copyable config snippets with resolved PHP binary and `artisan` path are shown; When user clicks `"Provision AI Workspace"`, Then `AI` vault and starter `AI/about-me.md` are created cleanly without overwriting existing files. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - Zero access to encrypted vaults (`is_encrypted === true`) or `.mdenc` files; `VaultKeyService` is never bypassed or weakened.
  - Strict path-traversal validation rejecting `\0`, `\`, drive letters (`C:`), `.` / `..`, dot-prefixed entries, `node_modules`, and symlinks (`FileStorageService::isSymlink`, canonical containment checks).
  - Plaintext substring search (`mb_strpos`/`mb_stripos`) avoids regex injection / ReDoS.
  - Read/edit operations respect `NoteService::PREVIEW_LIMIT` / `EDIT_LIMIT` (1 MiB) and UTF-8 validity.
- **Performance**:
  - `IndexMode::Quick` avoids re-hashing unchanged files on every MCP call.
  - `search_notes` enforces `limit` (default 20, max 50) and `max_matches_per_note` (default 5, max 20) and bounds snippets to 240 chars to protect LLM context windows.
  - `MdVaultServer::boot()` sets SQLite `PRAGMA busy_timeout = 5000;` so concurrent desktop UI polling and CLI MCP calls avoid `SQLITE_BUSY`.
- **Accessibility & UX**:
  - `resources/js/pages/settings/Mcp.vue` uses a single root element, matches existing Settings pages (`General.vue`, `Editor.vue`, `Storage.vue`, `Backup.vue`), supports dark mode, provides accessible labels/ARIA feedback on copy actions, and uses Wayfinder route helpers (`@/routes/settings/mcp`).
- **Reliability & Data Integrity**:
  - Follows all `tests/Unit/ArchitectureTest.php` boundaries: `Vault` and `Note` models are accessed only in `App\Services` / `App\Http\Controllers`; file writes/deletes/renames go through `NoteService` and `FileStorageService`; all services are `final` and suffixed with `Service`.
  - `StdioTransport` integrity: no `echo`, `print`, or `dump` output anywhere in the MCP execution path.
  - Existing `BackupSettingsTest.php` exhaustive assertions on `SettingKey::portableCases()` (13) and `SettingGroup::portableGroups()` (4) remain unbroken.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (`13.33.0`), PHP `8.4`
- MCP Package: `laravel/mcp` (`^1.0`, currently `1.0.1` transitive; promote to direct `require` in `composer.json`)
- Frontend: Inertia.js v3 (`3.3.4` / `@inertiajs/vue3` `3.7.1`) + Vue 3 + Tailwind CSS v4 + `@lucide/vue` + `vue-sonner`
- Routing: Laravel Wayfinder (`@/routes/settings/mcp`)
- Testing: Pest 5 (`5.2.1`)
- Static Analysis & Style: Larastan/PHPStan (`level: 7`), `vue-tsc --noEmit`, Laravel Pint (`vendor/bin/pint --dirty --format agent`)
- Database: SQLite (`database/mdvault.sqlite` by default; NativePHP dev/prod may override path via `DB_DATABASE`)

---

## 7. Risks & Assumptions
- **Assumption 1**: Running `php artisan mcp:start mdvault` locally connects to the same SQLite database as MDVault. When the user runs MDVault via NativePHP desktop (where `nativephp.sqlite` or AppData SQLite is used), passing `env.DB_DATABASE` in the generated client config ensures CLI MCP points at the active DB.
- **Assumption 2**: `AI/about-me.md` is the canonical Core Memory note location (convention over new `SettingKey` entries), editable directly in MDVault UI and automatically included in MDVault backups of the `AI` vault.
- **Risk 1 & Mitigation**: `NoteService::delete()` refuses deletion when `NativeTrash::isAvailable()` is false (every CLI session when Electron is closed). — *Mitigation*: `McpKosService::deleteAiNote()` uses `NoteService::delete()` when `canTrash()` is true, and otherwise safely moves `AI` notes to `AI/.trash/<timestamp>_<uuid>_<filename>` via `FileStorageService` and deletes the `Note` row, preserving recoverability and keeping `.trash` ignored by `VaultIndexService` and `BackupService`.
- **Risk 2 & Mitigation**: `laravel/mcp` `UriTemplate` matches `{var}` with `([^/]+)` (cannot contain `/`). — *Mitigation*: Resource templates use single-segment placeholders (`mdvault://skills/{vault}/{skill}`, `mdvault://projects/{project}/skills/{skill}`, `mdvault://notes/{vault}/{uuid}`), while tools handle arbitrary nested `relative_path`s.
- **Risk 3 & Mitigation**: `tests/Unit/ArchitectureTest.php` prohibits using `App\Models\Vault` or `App\Models\Note` inside `App\Mcp\*` and raw filesystem functions outside `FileStorageService`. — *Mitigation*: Put all model/index/file orchestration in `App\Services\McpKosService` and `App\Services\McpSearchService`, and inject only service classes into `App\Mcp\*`.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
