# Universal Multi-Agent Software Development Framework — Bootstrap Prompt

You are going to configure this repository with a structured, multi-agent software development workflow.

The goal is to create a reusable engineering process where specialized agents perform distinct responsibilities:

1. **System Analyst** — requirements analysis, architecture, planning
2. **Senior Developer** — implementation
3. **Senior QA Engineer** — testing, verification, review
4. **Main Session Orchestrator** — workflow routing and orchestration

The framework is **platform-agnostic** and can be generated dynamically for any target agent ecosystem:
- **Claude Code** (`.claude/`, `CLAUDE.md`)
- **Gemini** (Gemini Antigravity & Gemini CLI: `.gemini/`, `GEMINI.md`)
- **Cursor** (`.cursor/`, `.cursorrules`)
- **Codex / Universal Agent** (`AGENTS.md`)
- **All / Unified** (all supported platforms configured in harmony)

The workflow prioritizes high-quality reasoning where it matters while minimizing unnecessary use of expensive models.

---

# 1. Target Agent Ecosystem Selection

When running this bootstrap prompt, determine or ask the user which target agent ecosystem to configure:

| Target Option | Supported Tools & Platforms | Generated Config Files |
|---|---|---|
| **1. Claude Code** | Anthropic Claude Code CLI | `.claude/agents/*.md`, `CLAUDE.md` |
| **2. Gemini** | Google Antigravity & Gemini CLI | `.gemini/agents/*.md`, `GEMINI.md`, Antigravity runtime definitions |
| **2. Gemini** | Google Antigravity & Gemini CLI | `.agents/agents/*.md`, `GEMINI.md`, Antigravity runtime definitions |
| **3. Cursor** | Cursor Composer & Cursor Agent | `.cursor/rules/multi-agent.mdc`, `.cursorrules` |
| **4. Codex** | OpenAI Codex, ChatGPT Operator, `AGENTS.md` standard | `AGENTS.md` |
| **5. Unified (Default)** | All platforms above sharing `.ai/` artifacts | All respective configurations in sync |

---

# 2. Core Development Philosophy

Follow this principle:

> Use expensive/high-reasoning models to reduce uncertainty and make important architectural decisions. Use cheaper/faster models to execute well-defined implementation tasks.

Do NOT use the expensive reasoning model for routine code generation when a concrete plan already exists.

The preferred workflow is:

```text
USER REQUEST
    ↓
SYSTEM ANALYST
    ↓
PLAN
    ↓
SENIOR DEVELOPER
    ↓
IMPLEMENTATION
    ↓
SENIOR QA
    ↓
TEST / REVIEW
    ↓
┌───────────────┬──────────────────┐
│ PASS          │ FAIL             │
│               │                  │
▼               ▼                  │
DONE        CLASSIFY ISSUE         │
                │                  │
        ┌───────┴────────┐         │
        │                │         │
      MINOR            MAJOR       │
        │                │         │
        ▼                ▼         │
   DEVELOPER         ANALYST       │
        │                │          │
        │             REPLAN        │
        │                │          │
        └───────┬────────┘          │
                ▼                   │
           IMPLEMENT               │
                ↓                   │
               QA ─────────────────┘
```

---

# 3. First: Inspect the Repository

Before creating or modifying anything:

1. Inspect the repository structure.
2. Identify the application framework (e.g. Laravel, Rails, Next.js, Django).
3. Identify the backend language & version.
4. Identify the frontend framework & UI library.
5. Identify the database.
6. Inspect existing instruction files (`CLAUDE.md`, `GEMINI.md`, `AGENTS.md`, `.cursorrules`).
7. Inspect existing tests and tooling (Pest, PHPUnit, Jest, Vitest, Playwright).
8. Identify existing development conventions.

Do NOT immediately overwrite existing configuration.
Preserve useful existing instructions, rules, and guidelines.

---

# 4. Target Directory Structure

The shared artifact structure is:

```text
.ai/
├── README.md
├── features/
│   ├── active/
│   └── completed/
├── decisions/
└── templates/
    ├── requirements.md
    ├── plan.md
    ├── implementation.md
    └── qa-report.md
```

Depending on the chosen target ecosystem(s), generate:

### For Claude Code
```text
.claude/
├── CLAUDE.md
└── agents/
    ├── system-analyst.md
    ├── senior-developer.md
    └── senior-qa.md
```

### For Gemini (Antigravity & CLI)
```text
.gemini/
├── settings.json
.agents/
├── mcp_config.json
└── agents/
    ├── system-analyst.md
    ├── senior-developer.md
    └── senior-qa.md
GEMINI.md
```

### For Cursor
```text
.cursor/
└── rules/
    └── multi-agent.mdc
.cursorrules
```

### For Codex / Universal
```text
AGENTS.md
```

---

# 5. Role of the Main Session Orchestrator

The main session acts as the workflow orchestrator.

It should:
- understand the user's request
- classify the task complexity (Levels 1–4)
- decide whether planning is necessary
- invoke or adopt the appropriate specialist role
- maintain workflow state across `.ai/` artifacts
- ensure testing occurs per test-scope rules
- route issues to the correct specialist
- avoid unnecessary expensive model usage

The main session should NOT perform all work itself when a specialist is appropriate.

---

# 6. Task Classification

Every meaningful request must be classified:

## LEVEL 1 — Mechanical
- **Examples**: typo, formatting, copy, simple documentation, one-line bug fix.
- **Workflow**: Developer → Test → Done.
- No analyst or planning required.

## LEVEL 2 — Routine Development
- **Examples**: standard CRUD, single migration, controller, Form Request, Vue component, standard API endpoint, ordinary test.
- **Workflow**: Developer → Test → QA when appropriate.
- Skip formal planning if requirements and architecture are already obvious.

## LEVEL 3 — Complex Development
- **Examples**: multiple modules, complex business logic, significant DB changes, integration with external services, substantial refactoring.
- **Workflow**: Analyst → Plan → Developer → QA → Fix/Replan.

## LEVEL 4 — Architectural
- **Examples**: system redesign, major module integration, security architecture, core data model overhaul, critical performance changes.
- **Workflow**: Analyst → Deep Analysis → Plan → Developer → QA → Analyst Review → Complete.

---

# 7. Agent Roles & Specifications

## 1. System Analyst
- **Role**: Requirements analysis, architecture, planning.
- **Tools**: Read-only tools (`view_file` / `read_file`, `list_dir`, `grep_search`, `find_by_name`, web search, MCP tools). **No write tools**.
- **Model Recommendation**:
  - Claude: `opus`
  - Gemini: `gemini-3.1-pro-preview` / `pro`
  - OpenAI / Codex: `o3-mini` (high reasoning) / `gpt-4o`
  - Cursor: Claude 3.7 Sonnet (Thinking) / o3-mini

## 2. Senior Developer
- **Role**: Implementation strictly adhering to an approved plan.
- **Tools**: Read, write, replace, and terminal execution tools (`write_to_file`, `replace_file_content`, `run_command` / bash).
- **Model Recommendation**:
  - Claude: `sonnet`
  - Gemini: `gemini-3.8-flash` / `flash`
  - OpenAI / Codex: `gpt-4o` / `o3-mini`
  - Cursor: Claude 3.7 Sonnet / Claude 3.5 Sonnet

## 3. Senior QA Engineer
- **Role**: Skeptical verification against requirements, plans, and tests.
- **Tools**: Read-only inspection and test execution tools. **No production code write tools**.
- **Model Recommendation**:
  - Claude: `sonnet`
  - Gemini: `gemini-3.8-flash` / `flash`
  - OpenAI / Codex: `gpt-4o`
  - Cursor: Claude 3.7 Sonnet / Claude 3.5 Sonnet

---

# 8. Platform-Specific Invocation Mechanisms

### Claude Code
Claude Code invokes subagents using the `Agent` tool:
```bash
Agent(subagent="system-analyst", prompt="Analyze requirements for ...")
Agent(subagent="senior-developer", prompt="Implement plan at ...")
Agent(subagent="senior-qa", prompt="Verify implementation against ...")
```

### Gemini Antigravity
Gemini Antigravity orchestrates dynamic subagents via `define_subagent` and `invoke_subagent`:
```json
// Define subagent on demand
define_subagent({
  "name": "system-analyst",
  "enable_write_tools": false,
  "description": "System Analyst for requirements and planning",
  "system_prompt": "<contents from .gemini/agents/system-analyst.md>"
  "system_prompt": "<contents from .agents/agents/system-analyst.md>"
});

// Invoke subagent
invoke_subagent({
  "Subagents": [{
    "TypeName": "system-analyst",
    "Role": "System Analyst",
    "Prompt": "Analyze requirements for ...",
    "Model": "pro"
  }]
});
```

### Gemini CLI
Gemini CLI delegates automatically based on the agent description or explicitly via at-sign prefix:
```text
@system-analyst analyze requirements for ...
@senior-developer implement plan at ...
@senior-qa verify implementation for ...
```

### Cursor (Composer / Agent)
In Cursor, prefix instructions with the specialist role or switch composer models:
```text
[Role: System Analyst] Review requirements and draft .ai/features/active/<feature>/plan.md using o3-mini or Sonnet 3.7 Thinking.
[Role: Senior Developer] Implement task 1–4 from plan.md using Sonnet.
[Role: Senior QA] Audit the code diff against plan.md and run tests using Sonnet.
```
Cursor automatically enforces rules placed in `.cursor/rules/multi-agent.mdc` and `.cursorrules`.

### Codex / Universal (`AGENTS.md`)
OpenAI Codex, ChatGPT, and agents supporting the `AGENTS.md` standard read root `AGENTS.md`, which defines the orchestrator duties, the 4-level task triage, and the 3 specialist role prompts directly.

---

# 9. Artifact Workflow & Templates

All platforms share the exact same markdown artifacts in `.ai/`:

### 1. Requirements Artifact
`.ai/features/active/<feature-name>/requirements.md` (from `.ai/templates/requirements.md`)

### 2. Plan Artifact
`.ai/features/active/<feature-name>/plan.md` (from `.ai/templates/plan.md`)

### 3. Implementation Artifact
`.ai/features/active/<feature-name>/implementation.md` (from `.ai/templates/implementation.md`)

### 4. QA Report Artifact
`.ai/features/active/<feature-name>/qa-report.md` (from `.ai/templates/qa-report.md`)

### 5. Architectural Decisions
`.ai/decisions/<decision-name>.md`

When a feature passes QA with no critical or high-severity issues remaining, move the folder:
```text
.ai/features/active/<feature-name>/  ──→  .ai/features/completed/<feature-name>/
```

---

# 10. QA Feedback Loop & Escalation

```text
Implementation
     ↓
QA
     ↓
PASS ─────────────→ COMPLETE

FAIL
 ↓
Classify
 ↓
 ├── MINOR ───────→ Developer (Fix directly)
 │                     ↓
 │                    QA
 │
 ├── MODERATE ────→ Developer (Clarify within existing design)
 │                     ↓
 │                    QA
 │
 └── MAJOR ───────→ System Analyst (Replan & update plan.md)
                       ↓
                    Revised Plan
                       ↓
                  Developer
                       ↓
                      QA
```

### Preventing Infinite Loops
If the same defect survives **3 fix attempts**:
1. Stop implementation immediately.
2. Escalate to the System Analyst.
3. Re-evaluate requirements and architectural assumptions.

---

# 11. Bootstrap Task Execution Steps

When executing this bootstrap prompt for a project:

1. **Inspect**: Detect existing agent setups (`.claude`, `.gemini`, `.cursor`, `AGENTS.md`).
2. **Determine Scope**: Confirm target agent platform(s) (Claude Code, Gemini, Cursor, Codex, or All).
3. **Preserve Rules**: Retain framework guidelines (e.g. Laravel Boost, Rails conventions, React patterns).
4. **Create Shared Artifacts**: Ensure `.ai/features/{active,completed}`, `.ai/decisions`, and `.ai/templates` exist.
5. **Configure Target Platform(s)**:
   - For **Claude Code**: write `CLAUDE.md` and `.claude/agents/*.md`.
   - For **Gemini**: write `GEMINI.md` and `.gemini/agents/*.md`.
   - For **Gemini**: write `GEMINI.md` and `.agents/agents/*.md`.
   - For **Cursor**: write `.cursor/rules/multi-agent.mdc` and `.cursorrules`.
   - For **Codex**: write or update `AGENTS.md`.
6. **Verify Consistency**: Confirm all configurations adhere to the same 4-level triage, 3 roles, and model strategy without contradictions.
