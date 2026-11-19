# Plan: MDVault MCP KOS Server

## Metadata
- **Feature Name**: MDVault MCP KOS Server (`mcp-kos`)
- **Feature ID**: feat-mcp-kos
- **Author**: System Analyst
- **Created Date**: 2026-11-18
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
We will expose MDVault as a local stdio Knowledge Operating System (KOS) MCP server (`Mcp::local('mdvault', MdVaultServer::class)`) using `laravel/mcp`. All vault/note registry access, encrypted-vault exclusion, two-tier permission enforcement (`AI` vault full CRUD + `AI/.trash` CLI fallback vs. Personal vaults read + safe write), hybrid SQLite/on-demand grep search, KOS core memory (`AI/about-me.md`), project scaffolding (`init_ai_project`), and skills management live in three final service classes (`McpKosService`, `McpSearchService`, `McpClientConfigService`), keeping `App\Mcp\*` strictly compliant with MDVault's Pest architecture rules. A dedicated `Settings → AI & MCP` Inertia page (`settings/mcp`) and `mdvault:mcp-config` Artisan command provide copyable client configurations and one-click `AI` workspace provisioning.

---

## 2. Architecture & Design
- **Approach**: Thin MCP primitives (`App\Mcp\Tools\*`, `App\Mcp\Resources\*`, `App\Mcp\Prompts\*`) delegating to domain services (`App\Services\McpKosService`, `App\Services\McpSearchService`, `App\Services\McpClientConfigService`), reusing `VaultService`, `NoteService`, `VaultIndexService`, `MarkdownService`, and `FileStorageService` for all disk/DB invariants.
- **Alternatives Considered**:
  1. *Querying `Vault`/`Note` models or filesystem directly inside `App\Mcp\Tools\*`* — Rejected because `tests/Unit/ArchitectureTest.php` restricts `Vault`/`Note` models to `App\Services`/`App\Http\Controllers`/`App\Models`/`Database\Factories`, and centralizing logic in services prevents duplication between tools, resources, and Settings UI.
  2. *Adding `SettingGroup::Mcp` / `SettingKey` entries for Core Memory path* — Rejected to avoid unnecessary churn in `tests/Feature/Backups/BackupSettingsTest.php`'s exhaustive 13-portable-key / 4-group contract; `AI/about-me.md` convention is simpler and immediately editable/backupable.
  3. *Building an SQLite FTS5 index table* — Rejected per requirement (no new DB tables/migrations); SQLite metadata query + on-demand `FileStorageService::read()` grep is fast and always matches disk truth.
  4. *Weakening `NoteService::delete()` to permanently `unlink()` when `canTrash()` is false* — Rejected because personal-vault HTTP/UI paths must keep OS-Trash safety; `McpKosService::deleteAiNote()` isolates `AI/.trash/` fallback strictly to the `AI` vault.
- **Dependency & New Directory Flags (Requires User Approval)**:
  - **Dependency**: `composer require laravel/mcp --no-interaction` (promotes `laravel/mcp` `^1.0` from transitive dev-dependency via `laravel/boost` to direct production `require` in `composer.json` — explicitly requested by user).
  - **New Base Directories**: `routes/ai.php` (published by `laravel/mcp`) and `app/Mcp/{Servers,Tools,Resources,Prompts}` (created by `php artisan make:mcp-*` commands).
- **Decision Records**: `.ai/decisions/mcp-kos-architecture.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| *(none)* | No database migrations or schema changes | Reuses existing `vaults` and `notes` tables |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Exception | `app/Exceptions/McpKosException.php` | Domain/permission error messages for MCP operations (encrypted vault, permission denied, missing/invalid path, conflict, etc.) |
| Service (Modify) | `app/Services/NoteService.php` | Add `createWithContent()` for single-write exclusive (`'x'`) creation with optional template suppression and UTF-8/1 MiB checks |
| Service | `app/Services/McpKosService.php` | Vault resolution & `AI` auto-provisioning, encrypted/missing guards, two-tier permission enforcement, note CRUD/append/rename/move/delete (`AI/.trash` CLI fallback), folder CRUD, `init_ai_project`, core memory (`AI/about-me.md`), skills CRUD |
| Service | `app/Services/McpSearchService.php` | Hybrid SQLite `Note` title/path + on-demand line-by-line Markdown content grep across active unencrypted vaults |
| Service | `app/Services/McpClientConfigService.php` | Generates copyable JSON/TOML client configs (`php artisan mcp:start mdvault` + `env.DB_DATABASE` when applicable) and workspace status summary |
| MCP Server | `app/Mcp/Servers/MdVaultServer.php` | Registers all 16 tools, 4 resources, and 4 prompts; configures SQLite `busy_timeout = 5000` in `boot()` |
| MCP Tools | `app/Mcp/Tools/{ListVaults,ListNotes,SearchNotes,ReadNote,CreateNote,UpdateNote,AppendNote,RenameNote,MoveNote,DeleteNote,CreateFolder,DeleteFolder,InitAiProject,GetKosContext,ListSkills,SaveSkill}Tool.php` | 16 MCP tools with `#[Name]`, MCP tool annotations, `JsonSchema` input definitions, and `Response::structured()` / `Response::error()` outputs |
| MCP Resources | `app/Mcp/Resources/{AboutMe,Skill,ProjectSkill,Note}Resource.php` | `mdvault://memory/about-me`, `mdvault://skills/{vault}/{skill}`, `mdvault://projects/{project}/skills/{skill}`, `mdvault://notes/{vault}/{uuid}` |
| MCP Prompts | `app/Mcp/Prompts/{SessionBootstrap,ProjectKickoff,SynthesizeNotes,RecordReceipt}Prompt.php` | 4 KOS workflow prompt templates with validated arguments |
| Console Command | `app/Console/Commands/McpConfigCommand.php` | `php artisan mdvault:mcp-config` prints copyable client JSON/TOML snippets in terminal |
| Controller | `app/Http/Controllers/Settings/McpController.php` | Renders `settings/Mcp` Inertia page (`edit`) and provisions `AI` vault + starter `about-me.md` (`provision`) |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Layout (Modify) | `resources/js/layouts/settings/Layout.vue` | Add `'AI & MCP'` nav entry pointing to `editMcp()` (`settings/mcp`) |
| Page | `resources/js/pages/settings/Mcp.vue` | Copyable MCP client config snippets (Claude Desktop/Cursor/Antigravity, Codex TOML, Hermes/CLI), `AI` workspace & `about-me.md` status/provision button, vault permission summary, and KOS primitives overview |
| Types (Modify) | `resources/js/types/settings.ts` | Add `McpSettingsProps`, `McpVaultAccessSummary`, `McpClientSnippet` TypeScript types |

### Routes
| Method | URI | Name | Controller@action / Handler | Middleware |
|---|---|---|---|---|
| LOCAL MCP | `mdvault` | — | `Mcp::local('mdvault', MdVaultServer::class)` in `routes/ai.php` | StdioTransport |
| GET | `/settings/mcp` | `settings.mcp.edit` | `Settings\McpController@edit` | `web` (existing `settings.php` group) |
| POST | `/settings/mcp/provision` | `settings.mcp.provision` | `Settings\McpController@provision` | `web` (existing `settings.php` group) |

---

## 3. Implementation Tasks

- [ ] **T1 — Install `laravel/mcp` dependency & publish `routes/ai.php`** *(Requires user approval for composer dependency & `routes/ai.php`)*
  - Files: `composer.json`, `composer.lock`, `routes/ai.php`
  - Command: `composer require laravel/mcp --no-interaction && php artisan vendor:publish --tag=ai-routes --no-interaction`
  - Details: Promote `laravel/mcp` (`^1.0`) to direct `require` in `composer.json` so `--no-dev` / NativePHP builds include it. Publish `routes/ai.php`.
  - Covers: FR-01

- [ ] **T2 — Add `NoteService::createWithContent()` & `McpKosException`**
  - Files: `app/Services/NoteService.php` (modify), `app/Exceptions/McpKosException.php` (create)
  - Command: `php artisan make:class Exceptions/McpKosException --no-interaction`
  - Details:
    - In `NoteService`, add `createWithContent(Vault $vault, ?string $folder, string $name, string $content, bool $applyTemplate = false, ?string $timezone = null): Note`. Refuse encrypted vaults (`NoteOperationException::notEditable($name)`), validate folder and note name, check `assertNoConflict()`, validate UTF-8, reject `\0`, normalize `\r\n` to `\n`, optionally prepend `renderNewNoteTemplate()` when `$applyTemplate` is true and `$content` does not start with `"---\n"`, enforce `strlen($source) <= self::EDIT_LIMIT`, create exclusively via `$this->files->createFile($absolute, $source)`, and register via `$this->registerNewFile(...)`. Keep existing `NoteService::create()` untouched.
    - Create `final class McpKosException extends \RuntimeException` with static factories for: `encryptedVaultForbidden(string $vaultName)`, `vaultNotFound(string $identifier)`, `vaultMissing(string $vaultName, string $path)`, `permissionDenied(string $action, string $vaultName)`, `aiVaultUnavailable(string $reason)`, `invalidPath(string $field)`, `noteNotFound(string $identifier, string $vaultName)`, `noteUnreadable(string $relativePath, string $reason)`, `conflict(string $message, ?string $currentHash, string $reason)`, `hashRequiredForExistingSkill(string $relativePath, string $currentHash)`. Carry `?string $currentHash` and `array<string, mixed> $context` accessors for structured error responses.
  - Covers: FR-02, FR-03, FR-04, FR-08, FR-09, FR-15

- [ ] **T3 — Implement `McpKosService` (Core KOS, Two-Tier Permissions, Safe Note/Folder CRUD, Scaffolding & Skills)**
  - Files: `app/Services/McpKosService.php` (create)
  - Command: `php artisan make:class Services/McpKosService --no-interaction`
  - Details:
    - Mark class `final class McpKosService` (ArchTest rule). Inject `VaultService`, `NoteService`, `VaultIndexService`, `FileStorageService`, `MarkdownService`, `StoragePathService`. Never use raw filesystem functions or `Illuminate\Support\Facades\File`.
    - Constants: `AI_VAULT_NAME = 'AI'`, `ABOUT_ME_FILENAME = 'about-me.md'`, `SKILLS_FOLDER = 'skills'`, `DEFAULT_CONTEXT_MAX_CHARS = 2000`.
    - **Vault Resolution & Permissions**:
      - `listVaults()`: call `$this->vaults->all()`. For each vault return `uuid`, `name`, `description`, `status`, `is_current`, `is_encrypted`, `accessible` (`Active && !is_encrypted`), `tier` (`'ai_workspace'` if case-insensitive `'AI'` and accessible, `'personal'` if accessible non-AI, else `'excluded'`), `permission` (`'full_crud'`, `'read_safe_write'`, or `'none'`), `note_count` (count query ONLY when `accessible`, else `null`), `exclusion_reason`. Also return `ai_workspace` status (`name`, `provisioned`, `note`).
      - `resolveAccessibleVault(string $identifier)`: match by UUID or case-insensitive `name` via `Vault::query()->get()`, call `refreshStatus()`, throw `McpKosException::encryptedVaultForbidden()` if `is_encrypted`, `McpKosException::vaultMissing()` if `Missing`, `McpKosException::vaultNotFound()` if missing.
      - `resolveOrProvisionAiVault()`: locate existing `'AI'` (case-insensitive) via `Vault::query()->get()`. If found, verify not encrypted and not missing. If not found, create via `$this->vaults->create(self::AI_VAULT_NAME, 'Dedicated AI Agent KOS Workspace')` catching `VaultOperationException` and wrapping in `McpKosException::aiVaultUnavailable($e->getMessage())`.
      - `assertAiVault(Vault $vault, string $action)`: verify `mb_strtolower($vault->name) === 'ai'` else throw `McpKosException::permissionDenied($action, $vault->name)`.
    - **Path Validation & Note Resolution**:
      - `normalizeRelativePath(string $path, string $field, bool $expectMarkdown = false)`: reject `\0`, `\`, `^[A-Za-z]:`, `..` segments, empty internal segments (`//`), `.` segments, dot-prefixed segments, or `node_modules`. Trim single leading `/`. Optionally append `.md` if not ending with `.md` (case-insensitive).
      - `resolveNote(Vault $vault, ?string $path, ?string $uuid)`: reconcile `IndexMode::Quick` on `$vault` first. Match by `$uuid` (`Str::isUuid`) or normalized `relative_path` (exact match first, then case-insensitive fallback within `$vault`). Throw `McpKosException::noteNotFound()` if absent.
    - **Note & Folder Operations**:
      - `listNotes(string $vaultIdentifier, ?string $folder = null)`: handle unprovisioned `'AI'` gracefully (`provisioned: false, tree: [], folders: ['']`); otherwise reconcile `IndexMode::Quick`, call `$this->index->browse($vault)`, filter/subtree if `$folder` specified (throwing `McpKosException` if requested folder doesn't exist).
      - `readNote(string $vaultIdentifier, ?string $path, ?string $uuid)`: resolve vault + note, call `$this->notes->preview($note)`, fail with `McpKosException::noteUnreadable()` if `state !== 'ok'` or `! $preview['editable']`, return metadata + fresh `base_hash` as `file_hash`, `content`, `body`, `frontmatter_yaml`.
      - `createNote(string $vaultIdentifier, string $path, ?string $content = null, bool $applyTemplate = false)`: if `$vaultIdentifier` is case-insensitive `'AI'` and doesn't exist, call `resolveOrProvisionAiVault()`; else `resolveAccessibleVault()`. Split `path` into `folder` + `name`. Call `NoteService::create()` when `$content === null || $content === ''` and `$applyTemplate === true` (default when content omitted), or `NoteService::createWithContent()` when content supplied. Wrap `NoteOperationException` into `McpKosException`. Return presented note + `file_hash`.
      - `updateNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $content, string $fileHash)`: validate 64-hex `$fileHash` (`preg_match('/^[0-9a-f]{64}$/i', ...)`), resolve vault + note, call `NoteService::save($note, $content, strtolower($fileHash), NoteSaveMode::Source)`. Catch `NoteSaveConflictException` -> throw `McpKosException::conflict(...)` carrying `current_hash` and `reason`.
      - `appendNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $content, ?string $fileHash = null, bool $ensureNewline = true)`: resolve vault + note, call `preview($note)` to get live `content` + `base_hash`. Verify `$fileHash` if provided (throw `McpKosException::conflict()` if mismatch). Combine existing content + clean `\n` separator (if `ensureNewline && $existing !== '' && !str_ends_with($existing, "\n")`) + `$content`. Save via `NoteService::save($note, $combined, $preview['base_hash'], NoteSaveMode::Source)`.
      - `renameNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $newName)`: require `AI` vault via `assertAiVault()`, resolve note, call `NoteService::rename($note, $newName)`.
      - `moveNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $targetFolder)`: require `AI` vault, resolve note, call `NoteService::move($note, $targetFolder === '' ? null : $targetFolder)`.
      - `deleteNote(string $vaultIdentifier, ?string $path, ?string $uuid)`: require `AI` vault, resolve note. If `$this->notes->canTrash()`, delegate to `NoteService::delete($note)` (`trashed_to: 'os_trash'`). Otherwise check `$absolute` inside `$vault->path` and `!isSymlink`: if missing on disk, delete `$note` row (`trashed_to: 'already_missing'`); else ensure `$vault->path . '/.trash'` exists via `$this->files->makeDirectory()`, move `$absolute` to `$trashDir . '/' . date('Ymd-His') . '_' . $note->uuid . '_' . $note->filename` via `$this->files->renameFile()`, then `$note->delete()` (`trashed_to: 'vault_trash'`).
      - `createFolder(string $vaultIdentifier, string $path, bool $recursive = true)`: resolve/provision `AI` vault and assert `AI` only (`assertAiVault`). Validate all path segments. If `recursive`, walk segments and create missing ones with `NoteService::createFolder()` (throw `targetExists` if final folder already exists unless caller only needed parent segments, or return `{ path, created_segments }`). If `!recursive`, call `NoteService::createFolder($aiVault, $parent, $leaf)`.
      - `deleteFolder(string $vaultIdentifier, string $path)`: resolve `AI` vault (`assertAiVault`), call `NoteService::deleteFolder($aiVault, $path)`.
    - **Scaffolding, Core Memory & Skills**:
      - `initAiProject(string $name, ?string $description = null, ?string $stack = null)`: validate `$name` via `NoteService::assertValidFolderName()`, call `resolveOrProvisionAiVault()`, ensure `AI/<name>` and `AI/<name>/skills` exist (via `createFolder` if missing), and for each starter note (`overview.md`, `tasks.md`, `receipts.md`) create via `NoteService::createWithContent(..., applyTemplate: false)` ONLY if no file/note exists there yet. Reconcile `IndexMode::Quick`. Return summary of created vs existing items.
      - `getKosContext(int $maxChars = self::DEFAULT_CONTEXT_MAX_CHARS)`: find `AI` vault without provisioning if missing (if encrypted/missing, report cleanly). If `AI` exists and active, reconcile quick index and look up `about-me.md` (case-insensitive). Read via `NoteService::preview()`, truncate cleanly at `$maxChars` with `mb_substr`, return `found`, `vault`, `relative_path`, `file_hash`, `char_count`, `truncated`, `content`, `vaults_summary`, `ai_projects`. Also add `provisionAiWorkspace(bool $createStarterMemory = true)` for Settings UI onboarding (`about-me.md` starter template created only if missing).
      - `listSkills(?string $vaultIdentifier = null, ?string $project = null)`: scan active unencrypted vaults (`resolveAccessibleVault` if specified) after `IndexMode::Quick` reconcile for `<vault>/skills/*.md` (and subfolders) and `AI/<project>/skills/*.md`. Build structured list with `slug`, `title`, `vault`, `scope`, `project`, `relative_path`, `uuid`, `file_hash`, `file_size`, `preview` (first non-frontmatter heading/line up to 160 chars), and `resource_uri` (populated for direct 1-segment skill names matching `SkillResource` / `ProjectSkillResource`).
      - `saveSkill(string $name, string $content, ?string $project = null, ?string $vaultIdentifier = null, ?string $fileHash = null)`: if `$project !== null`, assert `$vaultIdentifier` is null or `'AI'`, ensure `AI/<project>/skills` exists; else resolve `$vaultIdentifier ?? 'AI'` (auto-provisioning `'AI'` + `AI/skills` if targeting `AI`, or requiring existing `<vault>/skills` on Personal vaults). Check if `<skillsDir>/<stem>.md` already exists: if new -> `NoteService::createWithContent(..., applyTemplate: false)`; if existing -> require `$fileHash` (throw `McpKosException::hashRequiredForExistingSkill()` with `current_hash` if null) and save via `NoteService::save(..., NoteSaveMode::Source)`. Include `length_warning` if `mb_strlen($content) > 4000`.
      - `readSkillResource(string $vaultIdentifier, string $skillSlug)` & `readProjectSkillResource(string $project, string $skillSlug)` & `readNoteResource(string $vaultIdentifier, string $uuid)`: decode `rawurldecode()`, enforce accessible unencrypted vault, read via `NoteService::preview()`, return markdown text.
  - Covers: FR-02, FR-03, FR-04, FR-05, FR-07, FR-08, FR-09, FR-10, FR-11, FR-12, FR-13, FR-14, FR-15

- [ ] **T4 — Implement `McpSearchService` (Hybrid SQLite Metadata + On-Demand Content Grep)**
  - Files: `app/Services/McpSearchService.php` (create)
  - Command: `php artisan make:class Services/McpSearchService --no-interaction`
  - Details:
    - Mark `final class McpSearchService`. Inject `VaultService`, `VaultIndexService`, `FileStorageService`, `McpKosService`.
    - `search(string $query, ?string $vaultIdentifier = null, ?string $folder = null, bool $caseSensitive = false, int $limit = 20, int $maxMatchesPerNote = 5): array`.
    - Validate `$query` is non-empty after trim (`<= 500` chars), clamp `limit` `[1, 50]` and `maxMatchesPerNote` `[1, 20]`.
    - If `$vaultIdentifier !== null`: resolve single accessible vault via `McpKosService::resolveAccessibleVault()`; else collect all active unencrypted vaults via `VaultService::all()`, tracking `skipped_encrypted_vaults` and `skipped_missing_vaults`.
    - Reconcile `IndexMode::Quick` on each target vault.
    - Query `Note::query()->whereIn('vault_id', $vaultIds)` (optionally filtered by `$folder` prefix).
    - Match `title` and `relative_path` (`mb_strpos` / `mb_stripos`), AND read readable regular non-symlink `.md` files (`file_size <= NoteService::PREVIEW_LIMIT`) via `FileStorageService::read()`, normalize `\r\n` -> `\n`, scan lines for `$query`, collect up to `$maxMatchesPerNote` `{ line: int, text: string }` (1-based line numbers, line snippet truncated safely around match to 240 chars).
    - Rank results (exact title > title match > path match > content match count), slice to `$limit`, return structured search payload `{ query, total_matched_notes, truncated, skipped_encrypted_vault_count, results }`.
  - Covers: FR-02, FR-06

- [ ] **T5 — Implement `McpClientConfigService` & `McpConfigCommand`**
  - Files: `app/Services/McpClientConfigService.php` (create), `app/Console/Commands/McpConfigCommand.php` (create)
  - Commands:
    - `php artisan make:class Services/McpClientConfigService --no-interaction`
    - `php artisan make:command McpConfigCommand --no-interaction`
  - Details:
    - `McpClientConfigService` (`final`): inspects `PHP_BINARY`, `base_path('artisan')`, and active SQLite DB path via `DatabaseManager::connection()->getDatabaseName()`. When active DB is an SQLite file path other than `database_path('mdvault.sqlite')` (and not `:memory:`), includes `'env' => ['DB_DATABASE' => $activeDb]` so external CLI processes target the same SQLite DB (critical for NativePHP desktop dev/prod builds).
    - Produces formatted snippets for:
      1. `json` (`mcpServers` format compatible with Claude Desktop, Cursor, Antigravity)
      2. `codex_toml` (`[mcp_servers.mdvault]` TOML block for Codex CLI)
      3. `cli_command` (raw command string + args)
    - `McpConfigCommand`: signature `mdvault:mcp-config {--client=json : Output format (json, codex, all)}`; outputs clean copyable config to console.
  - Covers: FR-16

- [ ] **T6 — Create `MdVaultServer` & Register in `routes/ai.php`**
  - Files: `app/Mcp/Servers/MdVaultServer.php` (create), `routes/ai.php` (modify)
  - Command: `php artisan make:mcp-server MdVaultServer --no-interaction`
  - Details:
    - Configure `#[Name('MDVault KOS')]`, `#[Version('1.0.0')]`, `#[Instructions(...)]` summarizing session-start core memory loading, two-tier permissions, encrypted-vault exclusion, optimistic locking, and `init_ai_project` workflow.
    - Set `$defaultPaginationLength = 50;`, `$maxPaginationLength = 100;`.
    - Override `boot()` to execute `DB::connection()->statement('PRAGMA busy_timeout = 5000;')` when the active connection driver is `'sqlite'`, wrapped defensively so `:memory:` or test connections never fail.
    - Register all 16 tools, 4 resources, and 4 prompts in `$tools`, `$resources`, `$prompts`.
    - In `routes/ai.php`, register `Mcp::local('mdvault', MdVaultServer::class);`.
  - Covers: FR-01

- [ ] **T7 — Implement Read & Search MCP Tools**
  - Files:
    - `app/Mcp/Tools/ListVaultsTool.php`
    - `app/Mcp/Tools/ListNotesTool.php`
    - `app/Mcp/Tools/SearchNotesTool.php`
    - `app/Mcp/Tools/ReadNoteTool.php`
    - `app/Mcp/Tools/GetKosContextTool.php`
    - `app/Mcp/Tools/ListSkillsTool.php`
  - Commands: `php artisan make:mcp-tool <Class> --no-interaction` for each tool.
  - Details:
    - Annotate all 6 with `#[IsReadOnly]`, `#[IsIdempotent]`, `#[IsOpenWorld(false)]` and explicit snake_case `#[Name('...')]`, `#[Title('...')]`, `#[Description('...')]`.
    - Inject only `McpKosService` / `McpSearchService` (never `Vault` / `Note` models!).
    - Validate inputs via `$request->validate(...)` and `schema(JsonSchema $schema)`.
    - Catch `McpKosException`, `NoteOperationException`, `VaultOperationException` and return `Response::error($e->getMessage())`; return `Response::structured($data)` on success.
  - Covers: FR-02, FR-03, FR-05, FR-06, FR-07, FR-14, FR-15

- [ ] **T8 — Implement Safe-Write, `AI`-Workspace CRUD & Scaffolding MCP Tools**
  - Files:
    - `app/Mcp/Tools/CreateNoteTool.php`
    - `app/Mcp/Tools/UpdateNoteTool.php`
    - `app/Mcp/Tools/AppendNoteTool.php`
    - `app/Mcp/Tools/RenameNoteTool.php`
    - `app/Mcp/Tools/MoveNoteTool.php`
    - `app/Mcp/Tools/DeleteNoteTool.php`
    - `app/Mcp/Tools/CreateFolderTool.php`
    - `app/Mcp/Tools/DeleteFolderTool.php`
    - `app/Mcp/Tools/InitAiProjectTool.php`
    - `app/Mcp/Tools/SaveSkillTool.php`
  - Commands: `php artisan make:mcp-tool <Class> --no-interaction` for each tool.
  - Details:
    - Explicit snake_case `#[Name('...')]`, `#[Title('...')]`, `#[Description('...')]` and accurate annotations (`#[IsDestructive(true)]` on `delete_note` and `delete_folder`; `#[IsDestructive(false)]` on others; `#[IsOpenWorld(false)]` on all).
    - `UpdateNoteTool` requires `file_hash` (64-char hex) and includes `current_hash` + `reason` in error message on `McpKosException::conflict`.
    - `RenameNoteTool`, `MoveNoteTool`, `DeleteNoteTool`, `CreateFolderTool`, `DeleteFolderTool` default `vault` to `'AI'` and enforce `AI`-only permission via `McpKosService`.
    - `InitAiProjectTool` scaffolds `AI/<name>/`.
    - `SaveSkillTool` delegates to `McpKosService::saveSkill()` (requiring `file_hash` when overwriting an existing skill).
  - Covers: FR-02, FR-03, FR-04, FR-08, FR-09, FR-10, FR-11, FR-12, FR-13, FR-15

- [ ] **T9 — Implement MCP Resources & Starter Prompts**
  - Files:
    - `app/Mcp/Resources/AboutMeResource.php` (`#[Uri('mdvault://memory/about-me')]`, `#[MimeType('text/markdown')]`, `#[Priority(1.0)]`, `#[Audience([Role::Assistant, Role::User])]`)
    - `app/Mcp/Resources/SkillResource.php` (`HasUriTemplate`: `new UriTemplate('mdvault://skills/{vault}/{skill}')`, `#[MimeType('text/markdown')]`)
    - `app/Mcp/Resources/ProjectSkillResource.php` (`HasUriTemplate`: `new UriTemplate('mdvault://projects/{project}/skills/{skill}')`, `#[MimeType('text/markdown')]`)
    - `app/Mcp/Resources/NoteResource.php` (`HasUriTemplate`: `new UriTemplate('mdvault://notes/{vault}/{uuid}')`, `#[MimeType('text/markdown')]`)
    - `app/Mcp/Prompts/SessionBootstrapPrompt.php` (`#[Name('session_bootstrap')]`)
    - `app/Mcp/Prompts/ProjectKickoffPrompt.php` (`#[Name('project_kickoff')]`)
    - `app/Mcp/Prompts/SynthesizeNotesPrompt.php` (`#[Name('synthesize_notes')]`)
    - `app/Mcp/Prompts/RecordReceiptPrompt.php` (`#[Name('record_receipt')]`)
  - Commands: `php artisan make:mcp-resource <Class> --no-interaction` and `php artisan make:mcp-prompt <Class> --no-interaction`.
  - Details:
    - All resources catch `McpKosException` and return `Response::error($e->getMessage())` (so error messages stay descriptive even when `app.debug` is false).
    - `AboutMeResource` calls `McpKosService::getKosContext()`; if missing, returns clean Markdown onboarding instructions for `AI/about-me.md`.
    - All 4 prompts define typed `Argument` lists, validate arguments via `$request->validate(...)`, and return crisp `Response::text(...)` instructions referencing the exact MDVault tool/resource names.
  - Covers: FR-14, FR-15

- [ ] **T10 — Build `Settings → AI & MCP` Inertia Page & Controller**
  - Files:
    - `app/Http/Controllers/Settings/McpController.php` (create)
    - `routes/settings.php` (modify)
    - `resources/js/types/settings.ts` (modify)
    - `resources/js/layouts/settings/Layout.vue` (modify)
    - `resources/js/pages/settings/Mcp.vue` (create)
  - Command: `php artisan make:controller Settings/McpController --no-interaction`
  - Details:
    - `McpController::edit(McpClientConfigService $configs)`: renders `'settings/Mcp'` with `config` (JSON string, TOML string, CLI command, DB path note) and `workspace` (AI vault status, `about-me.md` existence, accessible vs excluded vaults summary, registered primitives overview).
    - `McpController::provision(McpKosService $kos)`: calls `$kos->provisionAiWorkspace(createStarterMemory: true)`, flashes Inertia `toast` (`success` or `error` on `McpKosException`), returns `back()`.
    - Register `settings/mcp` (`settings.mcp.edit`) and `settings/mcp/provision` (`settings.mcp.provision`) in `routes/settings.php`.
    - Add `'AI & MCP'` item to `sidebarNavItems` in `resources/js/layouts/settings/Layout.vue` using `editMcp()` from `@/routes/settings/mcp` and a `@lucide/vue` icon (`Bot` or `Cpu`).
    - Implement `resources/js/pages/settings/Mcp.vue` (single root `<div>`, breadcrumb `AI & MCP settings`, `Head`, client preset tabs/buttons for Claude Desktop/Cursor/Antigravity JSON vs Codex TOML vs CLI command, copy-to-clipboard button with visual confirmation & `vue-sonner` toast, `Provision AI Workspace` button via Inertia `router.post(provision.url())`, and vault access/primitives summary).
    - Run `php artisan wayfinder:generate --no-interaction` to regenerate typed Wayfinder routes.
  - Covers: FR-16

- [ ] **T11 — Write Comprehensive Tests & Run Quality Gates**
  - Files:
    - `tests/Feature/Mcp/MdVaultServerDiscoveryTest.php`
    - `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php`
    - `tests/Feature/Mcp/McpNoteOperationsTest.php`
    - `tests/Feature/Mcp/McpSearchAndKosTest.php`
    - `tests/Feature/Settings/McpSettingsTest.php`
  - Commands:
    - `php artisan make:test --pest Mcp/MdVaultServerDiscoveryTest --no-interaction`
    - `php artisan make:test --pest Mcp/McpPermissionsAndVaultsTest --no-interaction`
    - `php artisan make:test --pest Mcp/McpNoteOperationsTest --no-interaction`
    - `php artisan make:test --pest Mcp/McpSearchAndKosTest --no-interaction`
    - `php artisan make:test --pest Settings/McpSettingsTest --no-interaction`
  - Details: Test all FRs, failure/conflict modes, encrypted vault exclusion, `AI/.trash` CLI deletion fallback, `NoteService::createWithContent()`, Artisan command `mdvault:mcp-config`, Settings Inertia props & provision endpoint, and `tests/Unit/ArchitectureTest.php`. Run Pint, PHPStan, and `npm run types:check`.
  - Covers: FR-01 through FR-16

---

## 4. Test Plan

| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Mcp/MdVaultServerDiscoveryTest.php` | Local server registered at `'mdvault'`, lists all 16 tools, 4 resources/templates, and 4 prompts; `mdvault:mcp-config` command outputs valid JSON and Codex TOML including `mcp:start mdvault` | FR-01, FR-16 |
| `tests/Feature/Mcp/McpPermissionsAndVaultsTest.php` | `list_vaults` shows `AI` (`full_crud`), Personal (`read_safe_write`), Encrypted (`none`, `accessible: false`, null `note_count`), Missing (`none`); encrypted vault targeted by any tool/resource fails cleanly; `AI` auto-provisions on first write (and fails if `AI` is encrypted); `rename_note`, `move_note`, `delete_note`, `create_folder`, `delete_folder` succeed in `AI` and fail on Personal vaults | FR-02, FR-03, FR-04, FR-11, FR-12 |
| `tests/Feature/Mcp/McpNoteOperationsTest.php` | `list_notes` reconciles externally added `.md` files; `read_note` returns `file_hash` and reconciles stale hash; `create_note` creates atomically (with/without content & template) and fails if already exists; `update_note` succeeds with matching `file_hash`, returns conflict + `current_hash` on stale hash, and preserves EOL/BOM; `append_note` appends cleanly (with and without `file_hash`); `delete_note` uses OS trash when `fakeTrash(true)` and moves to `AI/.trash/` when `canTrash()` is false; `delete_folder` refuses non-empty folders; path traversal (`..`, `\`, `.git`, symlinks) rejected | FR-05, FR-07, FR-08, FR-09, FR-10, FR-11, FR-12 |
| `tests/Feature/Mcp/McpSearchAndKosTest.php` | `search_notes` combines SQLite `Note` title/path + on-demand content grep with 1-based line numbers & snippets across unencrypted vaults while skipping encrypted vaults; `init_ai_project` scaffolds `overview.md`, `tasks.md`, `receipts.md`, `skills/` idempotently without overwriting edits; `get_kos_context` & `mdvault://memory/about-me` read & truncate `AI/about-me.md` at 2,000 chars and gracefully handle missing `about-me.md`; `list_skills`, `save_skill` (new vs existing requiring `file_hash`, personal vault requires existing `skills/` folder), `SkillResource`, `ProjectSkillResource`, `NoteResource`, and all 4 prompts (`session_bootstrap`, `project_kickoff`, `synthesize_notes`, `record_receipt`) | FR-06, FR-13, FR-14, FR-15 |
| `tests/Feature/Settings/McpSettingsTest.php` | `GET settings/mcp` renders `settings/Mcp` with copyable JSON/TOML snippets and vault summary; `POST settings/mcp/provision` creates `AI` vault + starter `about-me.md` idempotently and flashes error if `AI` is encrypted | FR-16 |
| `tests/Unit/ArchitectureTest.php` | Verifies no `Vault`/`Note` model imports in `App\Mcp\*`, all `App\Services` are `final` + `*Service`, and no raw filesystem/`File` facade violations | NFR (Architecture) |

**Test scope for QA**:
- `php artisan test --compact tests/Feature/Mcp tests/Feature/Settings/McpSettingsTest.php tests/Feature/Backups/BackupSettingsTest.php tests/Unit/ArchitectureTest.php`
- `vendor/bin/phpstan analyse`
- `npm run types:check`

---

## 5. Risks & Mitigations
- **Risk**: `NoteService::delete()` fails outside Electron (`canTrash() === false`). — **Mitigation**: `McpKosService::deleteAiNote()` moves `AI` notes to `AI/.trash/` via `FileStorageService::renameFile()` when `canTrash()` is false, preserving recoverability and keeping `.trash` dot-ignored.
- **Risk**: NativePHP desktop app stores SQLite in a different file than `database/mdvault.sqlite`. — **Mitigation**: `McpClientConfigService` inspects the active DB connection filename and emits `"env": { "DB_DATABASE": "..." }` when it differs from `database_path('mdvault.sqlite')`.
- **Risk**: Concurrent desktop UI `ExternalChangeService` poll + MCP write triggers `SQLITE_BUSY`. — **Mitigation**: `MdVaultServer::boot()` applies `PRAGMA busy_timeout = 5000;` on SQLite connections.
- **Risk**: Adding a `SettingGroup` / `SettingKey` breaks `tests/Feature/Backups/BackupSettingsTest.php` exhaustive assertions. — **Mitigation**: No `SettingKey` or `SettingGroup` changes; Core Memory uses the `AI/about-me.md` convention.
- **Risk**: `App\Mcp\*` classes violate `tests/Unit/ArchitectureTest.php` model/filesystem restrictions. — **Mitigation**: All Eloquent and filesystem access stays in `App\Services\McpKosService` and `App\Services\McpSearchService`.

---

## 6. Open Questions
- None (all requirements and design choices are resolved).

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-18 | Initial plan | — |