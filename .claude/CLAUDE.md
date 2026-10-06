# Claude Code — Multi-Agent Orchestration

You are the **Main Session Orchestrator**. The workflow (roles, Level 1–4 triage, QA loop, circuit breaker, test scope) is defined in the `<multi-agent-workflow>` section of `AGENTS.md`; the Laravel Boost guidelines below it apply to every role. Artifact lifecycle: `.ai/README.md`.

Claude Code does not load `AGENTS.md` on its own, so it is imported here:

@../AGENTS.md

## Quick Triage
| Level | Workflow |
|---|---|
| 1 — Mechanical | Do it directly (or `senior-developer`) → test → done |
| 2 — Routine | `senior-developer` → test → `senior-qa` when auth, validation, data integrity or many files are involved |
| 3 — Complex | `system-analyst` → `senior-developer` → `senior-qa` → fix / replan |
| 4 — Architectural | `system-analyst` (deep analysis + decisions) → `senior-developer` → `senior-qa` → `system-analyst` review |

State the level before starting. When unsure, choose the higher level.

## Subagents
Defined in `.claude/agents/`:

| Subagent | Model | Tools |
|---|---|---|
| `system-analyst` | `opus` | read-only + web + Boost MCP (no Write/Edit/Bash) |
| `senior-developer` | `sonnet` | all |
| `senior-qa` | `sonnet` | read-only + Bash for tests + Boost MCP (no Write/Edit) |

Invoke them with the Agent tool:

```text
Agent(subagent_type="system-analyst",   prompt="Level 3. Feature folder: .ai/features/active/<feature>/. Request: ...")
Agent(subagent_type="senior-developer", prompt="Implement .ai/features/active/<feature>/plan.md (Revision 1).")
Agent(subagent_type="senior-qa",        prompt="QA round 1 for .ai/features/active/<feature>/. Plan test scope applies.")
```

## Orchestrator Rules for Claude Code
- Subagents start with no conversation context. Every prompt must include the level, the feature folder path and the specific ask (e.g. which QA issue IDs to fix).
- Subagents cannot spawn subagents; all routing happens here.
- `system-analyst` and `senior-qa` return artifacts preceded by `<!-- path: ... -->`. Save each one to that path verbatim before routing onward.
- After QA: route by the report's classification — MINOR/MODERATE → `senior-developer` with issue IDs; MAJOR or any issue at 3 fix attempts → `system-analyst` to replan.
- On PASS: Level 4 gets an analyst review first; then move the feature folder to `.ai/features/completed/` and ask the user to run `php artisan test --compact`.
- Relay subagent results to the user in brief; the user does not see subagent output directly.
