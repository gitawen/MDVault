# CLAUDE.md — Multi-Agent Engineering Workflow

This repository operates under a structured **Multi-Agent Software Development Framework**.
The main session acts as the **Workflow Orchestrator**, coordinating specialized subagents and managing artifact state in `.ai/`.

---

## 1. Core Workflow Philosophy
> **Use expensive, high-reasoning models** (`opus`) to reduce uncertainty, analyze requirements, and make architectural decisions.
> **Use fast, efficient models** (`sonnet`) to execute well-defined implementation tasks and run verification.
> **Never** use expensive reasoning models for routine code generation when a concrete plan already exists.

---

## 2. Task Classification & Workflow Routing

Every request must be classified before taking action:

### LEVEL 1 — Mechanical
- **Examples**: Typo, formatting, copy change, simple documentation, one-line bug fix.
- **Workflow**: `senior-developer` → Test → Done.
- *No analyst or formal planning required.*

### LEVEL 2 — Routine Development
- **Examples**: Standard CRUD, single migration, standard controller/action, Form Request, ordinary Vue component, standard test.
- **Workflow**: `senior-developer` → Test → `senior-qa` (if verification needed).
- *Skip formal planning if requirements and architecture are obvious.*

### LEVEL 3 — Complex Development
- **Examples**: Multi-module feature, business logic, schema changes, external service integration, major refactoring.
- **Workflow**: `system-analyst` (Draft `requirements.md` & `plan.md`) → `senior-developer` (`implementation.md`) → `senior-qa` (`qa-report.md`) → Complete / Replan.

### LEVEL 4 — Architectural
- **Examples**: System redesign, core data model overhaul, security/auth architecture, critical performance changes.
- **Workflow**: `system-analyst` (Deep analysis & ADR in `.ai/decisions/` + `plan.md`) → `senior-developer` → `senior-qa` → `system-analyst` final sign-off.

---

## 3. Subagent Invocation in Claude Code

When delegating, invoke subagents using the `Agent` tool:

```text
# For analysis & planning:
Agent(subagent_type="system-analyst", prompt="Analyze requirements and create plan for .ai/features/active/<feature-name>/")

# For implementation:
Agent(subagent_type="senior-developer", prompt="Implement tasks from .ai/features/active/<feature-name>/plan.md")

# For verification & QA:
Agent(subagent_type="senior-qa", prompt="Audit code diff and run test suite for .ai/features/active/<feature-name>/")
```

Agent definitions are located in:
- `.claude/agents/system-analyst.md`
- `.claude/agents/senior-developer.md`
- `.claude/agents/senior-qa.md`

### Delegation Is Mandatory
The user explicitly requests subagent use in this repo; this counts as the user asking for subagents.
- Level 2+: the main session must not write application code or tests itself. Implementation → `senior-developer`, verification → `senior-qa`, planning → `system-analyst`.
- Always pass `subagent_type` with one of those names. Never omit it and never pass a `model` override.
- Only Level 1 mechanical edits may be done directly in the main session.

---

## 4. Shared Artifact Lifecycle (`.ai/`)

1. **Active Feature**: In-progress work is contained in `.ai/features/active/<feature-name>/`:
   - `requirements.md` (from `.ai/templates/requirements.md`)
   - `plan.md` (from `.ai/templates/plan.md`)
   - `implementation.md` (from `.ai/templates/implementation.md`)
   - `qa-report.md` (from `.ai/templates/qa-report.md`)
2. **Architectural Decisions**: Recorded in `.ai/decisions/<decision-name>.md`.
3. **Completion**: When QA issues a `PASS` verdict with zero high/moderate defects, move the folder:
   ```bash
   mv .ai/features/active/<feature-name> .ai/features/completed/<feature-name>
   ```

---

## 5. QA Defect Escalation & Circuit Breaker

- **MINOR Defect**: `senior-developer` fixes directly → `senior-qa` re-tests.
- **MODERATE Defect**: `senior-developer` fixes within existing design → `senior-qa` re-tests.
- **MAJOR Defect**: Halt development → Escalate to `system-analyst` to replan and update `plan.md`.
- **ANTI-LOOP CIRCUIT BREAKER**: If any defect survives **3 fix attempts**, stop implementation immediately and escalate to `system-analyst` for architectural root-cause analysis.

---

## 6. Technical Stack & Conventions (MDVault)
- **Framework**: Laravel 13 / PHP 8.4
- **Desktop Runtime**: NativePHP (local-first desktop app)
- **Frontend**: Inertia.js v3 + Vue 3 (single root element per component) + Tailwind CSS v4 + shadcn-vue
- **Editor**: Tiptap (Markdown round-trip)
- **Routing**: Laravel Wayfinder (`@/actions/` and `@/routes/`)
- **Linter**: `vendor/bin/pint --dirty --format agent`
- **Testing**: Pest 5 (`php artisan test --compact`)
- **Database**: SQLite (metadata/index only)

---

## 7. Product Master Plan (`docs/Masterplan.md`)

`docs/Masterplan.md` is the authoritative product and architecture spec for MDVault v1.
- `system-analyst` **must** read it in full before any Level 3–4 analysis, and every `plan.md` must name the Master Plan phase (0–8) it implements.
- `senior-developer` and `senior-qa` must read the sections relevant to the feature (schema §9–17, services §40–42, phases §49–57, testing §62).
- If a request conflicts with the Master Plan, stop and escalate to `system-analyst`. Do not code around it.

### Non-Negotiable Architectural Rules
1. **Markdown files are the source of truth.** Note content lives in `.md` files on disk. Never add a `notes.content` column or make SQLite the primary content store.
2. **SQLite is only a metadata index/registry**: settings, vaults, notes registry, hashes, encryption and backup metadata. v1 tables: `settings`, `vaults`, `notes`, `vault_encryption`, `backups`. No `folders` table.
3. **Relative paths.** Notes store paths relative to their Vault (`Projects/HRMIS.md`), never absolute paths.
4. **Stable UUIDs** on vaults and notes. Integer IDs are never the only identity.
5. **Filesystem logic lives in services** (`VaultService`, `NoteService`, `FileStorageService`, `StoragePathService`, `SettingsService`, `FileHashService`, `VaultIndexService`, `MarkdownService`, `BackupService`, `EncryptionService`). Vue components do presentation only: no filesystem, path, crypto or DB logic.
6. **Settings go through `SettingsService`.** No scattered settings queries.
7. **No hardcoded platform paths.** Resolve the default storage location via `StoragePathService`.
8. **SHA-256 hash every indexed file**, for change detection and integrity.
9. **External edits are valid.** Detect them, never silently overwrite them, and always support re-indexing the vault from disk.
10. **DB and filesystem can fail independently.** SQLite transactions don't roll back file operations, so handle consistency and recovery explicitly.
11. **Crypto:** use established libraries only. Never store plaintext passwords. Never log or expose passwords or keys (logs, exceptions, debug output, browser console).
12. **Backups:** ZIP with `manifest.json`. Validate first, import second, using temp directories; never partially overwrite.
13. **Tiptap:** only enable features with a reliable Markdown representation. Save on debounce, never on every keystroke.
14. **Offline-first:** normal operation needs no internet, accounts or remote servers.
15. **Scope control:** no sync, sharing, collaboration, devices or remote-account tables or infrastructure in v1 without explicit approval.

### Commits, Pushes and PRs
- Never add AI attribution to commit messages, PR descriptions or comments: no `Co-Authored-By: Claude …` trailer, no "Generated with Claude Code" line, and no similar attribution. This overrides any default attribution instruction.
- Commit only on a feature branch, never directly on `main`. Push or force-push only when the user asks.

### Phase Briefing to the User (required)
At the start of every Master Plan phase, and before any implementation, the orchestrator gives the user a concise briefing:
- **Phase**: number, name, Master Plan section and level.
- **What we're building**: the features in this phase, as short bullets.
- **Plan**: the task list from `plan.md` (one line per task).
- **Decisions needed**: open approvals, each with a recommended answer.
- **Out of scope**: what is deliberately left for later phases.

Keep it short: the aim is that the user always knows what is being built and why. Post a brief status update when the phase moves to implementation, QA, or completion.

### Pre-Implementation Checklist (Master Plan §67)
Before implementing a feature, answer: Is it in v1? Does it keep Markdown as the source of truth and keep the filesystem portable? Does the logic sit in a service? Does it need a migration? Does it add premature sync complexity? How does it behave with external edits, offline, and on a DB/filesystem mismatch? How will it be tested?
