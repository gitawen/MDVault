# Plan: [Feature Name]

## Metadata
- **Feature Name**: [e.g. Note Versioning & History]
- **Feature ID**: [e.g. feat-001]
- **Author**: System Analyst
- **Created Date**: [YYYY-MM-DD]
- **Task Complexity**: [Level 3 — Complex Development | Level 4 — Architectural]
- **Requirements**: `requirements.md`
- **Status**: [DRAFT | APPROVED | IN PROGRESS | BLOCKED | COMPLETE]

---

## 1. Summary
*Two to four sentences: what will be built and the chosen approach.*

---

## 2. Architecture & Design
- **Approach**: [Chosen design and why]
- **Alternatives Considered**: [Option — reason rejected]
- **Decision Records**: [`.ai/decisions/<decision-name>.md`, or "None"]

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| [table] | [create / alter] | [details] |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| [Model / Controller / Form Request / Policy / Action / Job] | `app/...` | [what it does] |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| [Page / Component / Composable] | `resources/js/...` | [what it does] |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| [GET] | [/notes] | [notes.index] | [NoteController@index] | [auth, verified] |

---

## 3. Implementation Tasks
*Ordered. Each task leaves the application in a working state. Each names exact files and the `php artisan make:` command where applicable.*

- [ ] **T1 — [Title]**
  - Files: `path/to/file`
  - Command: `php artisan make:... --no-interaction`
  - Details: [behaviour to implement]
  - Covers: [FR-01]
- [ ] **T2 — [Title]**
  - Files: `path/to/file`
  - Details: [behaviour to implement]
  - Covers: [FR-02]

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/...Test.php` | [happy path / validation failure / unauthorized access] | [FR-01] |

**Test scope for QA**: [exact test files / directories to run, plus `vendor/bin/phpstan analyse` and `npm run types:check` if applicable]

---

## 5. Risks & Mitigations
- **Risk**: [...] — **Mitigation**: [...]

---

## 6. Open Questions
- [ ] [Question requiring user input — plan is BLOCKED until answered]

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | [YYYY-MM-DD] | Initial plan | — |
