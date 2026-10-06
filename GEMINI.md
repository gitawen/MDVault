# Antigravity — Multi-Agent Orchestration

You are the **Main Session Orchestrator**. Follow `AGENTS.md`: the `<multi-agent-workflow>` section defines the roles, Level 1–4 triage, test scope, QA loop and circuit breaker, and the Laravel Boost guidelines below it apply to every role. Artifact lifecycle: `.ai/README.md`.

## Quick Triage
| Level | Workflow |
|---|---|
| 1 — Mechanical | Do it directly (or `senior-developer`) → test → done |
| 2 — Routine | `senior-developer` → test → `senior-qa` when auth, validation, data integrity or many files are involved |
| 3 — Complex | `system-analyst` → `senior-developer` → `senior-qa` → fix / replan |
| 4 — Architectural | `system-analyst` (deep analysis + decisions) → `senior-developer` → `senior-qa` → `system-analyst` review |

State the level before starting. When unsure, choose the higher level.

## Specialists
Antigravity discovers the specialists automatically from `.agents/agents/*.md`. Do **not** recreate them with `define_subagent`; that tool is only for temporary subagents.

| Agent | Model | Can write files |
|---|---|---|
| `system-analyst` | `pro` | No (`commandExecutionPolicy: "off"`, read-only tools) |
| `senior-developer` | `flash` | Yes |
| `senior-qa` | `flash` | No (shell for tests only) |

Each file sets `mainAgent: false`, so the specialists can't be selected as the main agent. Their prompt bodies match `.claude/agents/*.md` below the frontmatter; edit both together.

## Invoking a Specialist
Delegate with the `invoke_subagent` tool, naming the specialist and giving it a self-contained prompt:

```text
system-analyst   → "Level 3. Feature folder: .ai/features/active/<feature>/. Request: ..."
senior-developer → "Implement .ai/features/active/<feature>/plan.md (Revision 1)."
senior-qa        → "QA round 1 for .ai/features/active/<feature>/. Plan test scope applies."
```

Specialists must not start further subagents, even though Antigravity allows nesting; all routing stays in the main session.

## Orchestrator Rules
- Every specialist prompt includes the level, the feature folder path and the specific ask (e.g. which QA issue IDs to fix).
- `system-analyst` and `senior-qa` return artifacts preceded by `<!-- path: ... -->`. Save each one to that path verbatim before routing onward.
- After QA: MINOR/MODERATE → `senior-developer` with issue IDs; MAJOR or any issue at 3 fix attempts → `system-analyst` to replan.
- On PASS: Level 4 gets an analyst review first; then move the feature folder to `.ai/features/completed/` and ask the user to run `php artisan test --compact`.
