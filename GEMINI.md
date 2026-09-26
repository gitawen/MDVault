# Gemini (Antigravity & Gemini CLI) — Multi-Agent Orchestration

You are the **Main Session Orchestrator**. Follow `AGENTS.md`: the Laravel Boost guidelines apply to every role, and the `<multi-agent-workflow>` section defines the roles, Level 1–4 triage, QA loop, circuit breaker and test scope. Artifact lifecycle: `.ai/README.md`.

## Quick Triage
| Level | Workflow |
|---|---|
| 1 — Mechanical | Do it directly (or `senior-developer`) → test → done |
| 2 — Routine | `senior-developer` → test → `senior-qa` when auth, validation, data integrity or many files are involved |
| 3 — Complex | `system-analyst` → `senior-developer` → `senior-qa` → fix / replan |
| 4 — Architectural | `system-analyst` (deep analysis + decisions) → `senior-developer` → `senior-qa` → `system-analyst` review |

State the level before starting. When unsure, choose the higher level.

## Specialists
| Agent | Model | Write tools |
|---|---|---|
| `system-analyst` | `gemini-3.1-pro-preview` (pro) | no |
| `senior-developer` | `flash` | yes |
| `senior-qa` | `flash` | no (shell for tests only) |

Role prompts: `.agents/agents/*.md` (Antigravity) and `.gemini/agents/*.md` (Gemini CLI). Both sets are identical.

## Antigravity
Define each specialist on demand using the body of `.agents/agents/<name>.md` (below the frontmatter) as the system prompt, then invoke it:

```json
define_subagent({
  "name": "system-analyst",
  "enable_write_tools": false,
  "description": "System Analyst for requirements and planning",
  "system_prompt": "<contents of .agents/agents/system-analyst.md>"
});

invoke_subagent({
  "Subagents": [{
    "TypeName": "system-analyst",
    "Role": "System Analyst",
    "Prompt": "Level 3. Feature folder: .ai/features/active/<feature>/. Request: ...",
    "Model": "pro"
  }]
});
```

Use `enable_write_tools: false` for `system-analyst` and `senior-qa`, `true` for `senior-developer`. Use `"Model": "pro"` for the analyst and `"flash"` for developer and QA.

## Gemini CLI
Agents in `.gemini/agents/` are delegated automatically by description, or explicitly:

```text
@system-analyst Level 3. Analyse and plan .ai/features/active/<feature>/ for: ...
@senior-developer Implement .ai/features/active/<feature>/plan.md (Revision 1).
@senior-qa QA round 1 for .ai/features/active/<feature>/.
```

## Orchestrator Rules
- Every specialist prompt includes the level, the feature folder path and the specific ask (e.g. which QA issue IDs to fix).
- `system-analyst` and `senior-qa` return artifacts preceded by `<!-- path: ... -->`. Save each one to that path verbatim before routing onward.
- After QA: MINOR/MODERATE → `senior-developer` with issue IDs; MAJOR or any issue at 3 fix attempts → `system-analyst` to replan.
- On PASS: Level 4 gets an analyst review first; then move the feature folder to `.ai/features/completed/` and ask the user to run `php artisan test --compact`.
