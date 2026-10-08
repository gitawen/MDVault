# Requirements: Editor Default View Settings

## Metadata
- **Feature Name**: Editor Default View
- **Feature ID**: feat-editor-default-view
- **Author**: System Analyst
- **Created Date**: 2026-10-08
- **Task Complexity**: Level 2 — Routine
- **Status**: APPROVED

---

## 1. Problem Statement
Currently, the Markdown Editor in MDVault always defaults to the 'code' (Editor Only) view when opening a note. Some users may prefer to default to 'split' view (to see a live preview alongside code) or 'preview' view, but there is no setting to change this behavior.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Add a new editor preference to select the default view mode (`code`, `split`, or `preview`).
- Persist this preference in the database using the existing settings architecture.
- Provide a UI on the Editor Settings page to configure the default view mode.
- Apply the chosen default view mode automatically when initializing `MarkdownEditor.vue`.

### Out of Scope (Non-Goals)
- Remembering the view state of individual notes.
- Changing the layout or look of the view modes themselves.

---

## 3. User Personas & Stories
- **As a** user,
  **I want to** specify my preferred default view mode in the Editor settings,
  **So that** my notes open in my desired layout (like Split view) automatically, saving me a click each time.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Settings Backend | The system must define a new `editor.default_view` setting key. | Given a new installation, When accessing the default settings, Then `editor.default_view` defaults to `code`. |
| **FR-02** | Settings Form Validation | The system must validate that the default view is one of the allowed values. | Given a request to update editor settings, When the payload includes an invalid `default_view`, Then validation fails. |
| **FR-03** | Editor UI Initialization | `MarkdownEditor.vue` must initialize `displayMode` to the configured default. | Given a user with `default_view` set to `split`, When opening a note, Then the editor starts in split view. |
| **FR-04** | Settings UI | The Editor Settings page must provide a dropdown or select control to choose the default view. | Given the Editor settings page, When I update the "Default View Mode", Then the change is persisted and applied to future editor loads. |
| **FR-05** | View Reset on Note Selection | Switching to Editor mode while editing a note is temporary. When a user clicks a note in the tree/sidebar (including re-clicking the current note) or navigates between notes, the editor's view mode must reset to the configured `default_view`. | Given a note currently switched to Editor mode, When the user clicks a note in the sidebar or opens a note, Then the view mode resets to `preferences.default_view`. |

---

## 5. Non-Functional Requirements
- **Reliability & Data Integrity**: Fits into the existing settings service seamlessly. Types must be strict (`enum`).

---

## 6. Technical Constraints & Context
- Framework: Laravel (PHP 8.4)
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`)

---

## 7. Risks & Assumptions
- **Assumption 1**: The current valid view modes are strictly `code`, `split`, and `preview`.
- **Risk 1**: Typescript types diverge from PHP enums. **Mitigation**: Create an exact typescript union type corresponding to the PHP cases.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
