# QA Report: [Feature Name]

## Metadata
- **Feature Name**: [e.g. Note Versioning & History]
- **Feature ID**: [e.g. feat-001]
- **Author**: Senior QA Engineer
- **QA Round**: [1, 2, 3 ...]
- **Date**: [YYYY-MM-DD]
- **Verdict**: [PASS | FAIL]

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 | [yes / partial / no] | `tests/Feature/...Test.php` | [✅ / ❌] |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact [scope]` | [N passed, M failed] |
| `vendor/bin/phpstan analyse` | [pass / N errors] |
| `npm run types:check` | [pass / N errors / not applicable] |

*Paste failure output verbatim.*

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | [Critical / High / Medium / Low] | [MINOR / MODERATE / MAJOR] | `path/to/file:line` | [what is wrong] | [correct behaviour] | [0–3] |

**Severity**
- **Critical**: security hole, data loss/corruption, app crash, core requirement missing.
- **High**: requirement not met, broken behaviour on a main path, missing authorization/validation.
- **Medium**: edge case bug, missing test coverage, convention violation with real impact.
- **Low**: style, naming, minor clean-up.

**Classification (routing)**
- **MINOR** → Developer fixes directly (localized, obvious fix).
- **MODERATE** → Developer fixes within the existing design (non-trivial, but the plan still holds).
- **MAJOR** → System Analyst replans (requirement, design or data model is wrong or incomplete).

**Circuit breaker**: any issue reaching **3 fix attempts** is escalated to the System Analyst regardless of classification.

---

## 4. Code Review Notes
- **Security & Authorization**: [...]
- **Validation & Data Integrity**: [...]
- **Performance (N+1, indexes)**: [...]
- **Conventions (AGENTS.md, `.ai/rules/`)**: [...]
- **Frontend (Inertia/Vue/Wayfinder)**: [...]

---

## 5. Routing Recommendation
- [ ] **PASS** → move feature to `.ai/features/completed/`
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: [issue IDs]
- [ ] **FAIL — MAJOR** → System Analyst: [issue IDs and why the plan must change]
