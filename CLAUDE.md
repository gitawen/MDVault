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

## Delegation Is Mandatory
The user explicitly requests subagent use in this repo; this counts as the user asking for subagents.
- Level 2+: the main session must not write application code or tests itself. Implementation → `senior-developer`, verification → `senior-qa`, planning → `system-analyst`.
- Always pass `subagent_type` with one of those names. Never omit it and never pass a `model` override.
- Only Level 1 mechanical edits may be done directly in the main session.


# For implementation:
Agent(subagent_type="senior-developer", prompt="Implement tasks from .ai/features/active/<feature-name>/plan.md")

# For verification & QA:
Agent(subagent_type="senior-qa", prompt="Audit code diff and run test suite for .ai/features/active/<feature-name>/")
```

Agent definitions are located in:
- `.claude/agents/system-analyst.md`
- `.claude/agents/senior-developer.md`
- `.claude/agents/senior-qa.md`

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
- **Frontend**: Inertia.js v3 + Vue 3 (single root element per component) + Tailwind CSS v4
- **Routing**: Laravel Wayfinder (`@/actions/` and `@/routes/`)
- **Linter**: `vendor/bin/pint --dirty --format agent`
- **Testing**: Pest 5 (`php artisan test --compact`)
- **Database**: SQLite
