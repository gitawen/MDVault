# Multi-Agent Workflow Artifacts (.ai)

This directory maintains persistent state, requirements, implementation plans, and verification reports for multi-agent software development in this repository.

## Directory Structure

```text
.ai/
├── README.md               # This file: architecture & lifecycle guide
├── features/
│   ├── active/             # In-progress features undergoing planning, implementation, or QA
│   │   └── <feature-name>/
│   │       ├── requirements.md
│   │       ├── plan.md
│   │       ├── implementation.md
│   │       └── qa-report.md
│   └── completed/          # Features that have passed QA and were approved
│       └── <feature-name>/
├── decisions/              # Architectural Decision Records (ADRs)
└── templates/              # Standard templates used by agents
    ├── requirements.md     # Authored by System Analyst
    ├── plan.md             # Authored by System Analyst
    ├── implementation.md   # Authored by Senior Developer
    └── qa-report.md        # Authored by Senior QA Engineer
```

---

## 4-Level Task Classification

Every task must be triaged by the Main Session Orchestrator:

| Level | Type | Criteria | Typical Workflow |
|---|---|---|---|
| **Level 1** | Mechanical | Typo, formatting, copy, one-line bug fix | Developer → Test → Done |
| **Level 2** | Routine Development | Standard CRUD, single migration, controller, standard Vue component | Developer → Test → QA (as needed) |
| **Level 3** | Complex Development | Multi-module feature, business logic, DB migration, external APIs | Analyst (Plan) → Developer → QA → Complete/Replan |
| **Level 4** | Architectural | Core model overhaul, auth/security rework, performance architecture | Analyst (Deep Analysis) → Plan → Developer → QA → Analyst Review |

---

## Model & Cost Optimization Principle

> **Use high-reasoning models** (Claude Code `opus`, Antigravity `pro`) to reduce uncertainty and make architectural decisions.
> **Use fast models** (Claude Code `sonnet`, Antigravity `flash`) to execute well-defined implementation tasks and run verification.
> **Never** waste high-reasoning models on routine code generation when a concrete plan already exists.

---

## Lifecycle of a Feature

1. **Initiation**: The orchestrator receives a Level 3 or 4 request, creates `.ai/features/active/<feature-name>/`.
2. **Analysis & Planning**:
   - System Analyst produces `requirements.md` and `plan.md`.
   - System Analyst records architectural decisions in `.ai/decisions/<decision-name>.md`.
   - The Analyst and QA roles have no write tools: they return each artifact preceded by `<!-- path: ... -->` and the orchestrator saves it.
3. **Implementation**:
   - Senior Developer follows `plan.md` step-by-step.
   - Senior Developer records progress, tests, and file changes in `implementation.md`.
   - Senior Developer runs Pint (`vendor/bin/pint --dirty --format agent`) and local tests.
4. **Verification**:
   - Senior QA Engineer reviews code diffs against `requirements.md` and `plan.md`.
   - Senior QA Engineer runs the scoped tests, `vendor/bin/phpstan analyse` and `npm run types:check` (see Test Scope in `AGENTS.md`).
   - Senior QA Engineer returns `qa-report.md`; the orchestrator saves it.
5. **Feedback Loop**:
   - **PASS** (no open Critical/High issues): Level 4 first receives an Analyst review; then the folder is moved from `.ai/features/active/<feature-name>` to `.ai/features/completed/<feature-name>` and the user is asked to run the full suite (`php artisan test --compact`).
   - **MINOR**: Developer fixes code directly; QA re-tests.
   - **MODERATE**: Developer clarifies within existing design; QA re-tests.
   - **MAJOR**: Escalate to System Analyst to replan and update `plan.md`.
   - **CIRCUIT BREAKER**: If the same defect survives **3 fix attempts**, stop implementation immediately and escalate to System Analyst for root-cause architectural review.

---

## Related Files

- **Workflow source of truth**: the `<multi-agent-workflow>` section of `AGENTS.md` (outside the Boost block, so `php artisan boost:update` preserves it).
- **Claude Code**: `CLAUDE.md`, `.claude/CLAUDE.md` (imports `AGENTS.md`), `.claude/agents/*.md`, `.mcp.json`
- **Antigravity**: `AGENTS.md`, `GEMINI.md`, `.agents/agents/*.md`, `.agents/mcp_config.json`

The role prompt bodies in `.claude/agents/` and `.agents/agents/` are identical below their frontmatter — edit both together.

`.ai/rules/` (if present) holds Laravel Boost project rules and is separate from feature artifacts.
