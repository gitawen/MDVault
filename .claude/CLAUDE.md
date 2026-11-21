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
Agent(subagent_type="system-analyst",   prompt="Level 3. MDVault feature path: AI/mdvault/features/active/<feature>/. Request: ...")
Agent(subagent_type="senior-developer", prompt="Implement AI/mdvault/features/active/<feature>/plan.md (Revision 1) from MDVault (or inline plan below).")
Agent(subagent_type="senior-qa",        prompt="QA round 1 for AI/mdvault/features/active/<feature>/. Plan test scope applies.")
```

## Orchestrator Rules for Claude Code
- **MDVault Memory**: Store feature artifacts (`requirements.md`, `plan.md`, `implementation.md`, `qa-report.md`) and ADRs in MDVault (`AI` vault) via `mdvault` MCP instead of creating local `.ai/features/active/` or `.ai/features/completed/` folders. Call `read_note` for `file_hash` before `update_note`.
- Subagents start with no conversation context. Every prompt must include the level, the MDVault feature path (plus plan/requirements content if the subagent lacks `mdvault` MCP) and the specific ask (e.g. which QA issue IDs to fix).
- Subagents cannot spawn subagents; all routing happens here.
- `system-analyst` and `senior-qa` return artifacts preceded by `<!-- path: ... -->`. Save each one to MDVault (`AI/mdvault/...`) via `create_note` / `update_note` before routing onward.
- After QA: route by the report's classification — MINOR/MODERATE → `senior-developer` with issue IDs; MAJOR or any issue at 3 fix attempts → `system-analyst` to replan.
- On PASS: Level 4 gets an analyst review first; then move the feature notes from `AI/mdvault/features/active/<feature>/` to `AI/mdvault/features/completed/<feature>/` in MDVault (`move_note`) and ask the user to run `php artisan test --compact`.
- Relay subagent results to the user in brief; the user does not see subagent output directly.
