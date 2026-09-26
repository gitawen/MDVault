# Requirements: [Feature Name]

## Metadata
- **Feature Name**: [e.g. Note Versioning & History]
- **Feature ID**: [e.g. feat-001]
- **Author**: System Analyst
- **Created Date**: [YYYY-MM-DD]
- **Task Complexity**: [Level 3 — Complex Development | Level 4 — Architectural]
- **Status**: [DRAFT | UNDER REVIEW | APPROVED]

---

## 1. Problem Statement
*Describe the problem being solved, who is affected, and why this work is needed now.*

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- [Goal 1]
- [Goal 2]

### Out of Scope (Non-Goals)
- [Explicitly excluded item 1]
- [Explicitly excluded item 2]

---

## 3. User Personas & Stories
- **As a** [user persona],
  **I want to** [action / capability],
  **So that** [value / benefit].

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | [Short Title] | [Detailed explanation of behavior] | Given [condition], When [action], Then [expected result] |
| **FR-02** | [Short Title] | [Detailed explanation of behavior] | Given [condition], When [action], Then [expected result] |
| **FR-03** | [Short Title] | [Detailed explanation of behavior] | Given [condition], When [action], Then [expected result] |

---

## 5. Non-Functional Requirements
- **Security & Authorization**: [Authentication requirements, Fortify guards, Policies/Gates]
- **Performance**: [Query efficiency, indexing, pagination, response times]
- **Accessibility & UX**: [Keyboard navigation, ARIA, single-root Vue components, responsive UI]
- **Reliability & Data Integrity**: [DB transactions, rollback safety, foreign key constraints]

---

## 6. Technical Constraints & Context
- Framework: Laravel (PHP 8.4)
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`)
- Testing: Pest 5
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`)
- Database: SQLite (or configured DB)

---

## 7. Risks & Assumptions
- **Assumption 1**: [...]
- **Risk 1 & Mitigation**: [...]

---

## 8. Requirements Approval
- [ ] Requirements fully defined
- [ ] Edge cases identified
- [ ] Approved to proceed to Planning (`plan.md`)
