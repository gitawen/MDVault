# Plan: Editor Default View Settings

## Metadata
- **Feature Name**: Editor Default View
- **Feature ID**: feat-editor-default-view
- **Author**: System Analyst
- **Created Date**: 2026-10-08
- **Task Complexity**: Level 2 — Routine
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
We will introduce a new setting `editor.default_view` mapped to a new PHP enum `App\Enums\EditorDefaultView`. The setting will be editable via the Editor Settings page and loaded into `MarkdownEditor.vue` to set the initial `displayMode`.

---

## 2. Architecture & Design
- **Approach**: Extend the existing `SettingsService` logic. Add the field to `SettingKey`, `UpdateEditorSettingsRequest`, and `EditorController`. On the frontend, update `EditorPreferences` type, add a `Select` component in `Editor.vue`, and initialize `displayMode` using `preferences.default_view` in `MarkdownEditor.vue`.
- **Alternatives Considered**: None, as this perfectly aligns with the current settings architecture.
- **Decision Records**: None

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Enum | `app/Enums/EditorDefaultView.php` | Defines allowed values (`code`, `split`, `preview`). |
| Enum | `app/Enums/SettingKey.php` | Registers `EditorDefaultView` with string type, editor group, and default value. |
| Form Request | `app/Http/Requests/Settings/UpdateEditorSettingsRequest.php` | Validates `default_view` via `Rule::enum()`. |
| Controller | `app/Http/Controllers/Settings/EditorController.php` | Persists the new setting. |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types | `resources/js/types/settings.ts` | Extends `EditorPreferences` with `default_view: 'code' | 'split' | 'preview'`. |
| Page | `resources/js/pages/settings/Editor.vue` | UI select component to change default view mode. |
| Component | `resources/js/components/editor/MarkdownEditor.vue` | Initializes `displayMode` to `preferences.default_view`. |

---

## 3. Implementation Tasks
*Ordered. Each task leaves the application in a working state.*

- [x] **T1 — Create `EditorDefaultView` Enum**
  - Files: `app/Enums/EditorDefaultView.php`
  - Command: `php artisan make:enum Enums/EditorDefaultView --type string` (or manually create if command not available)
  - Details: Create string enum with cases `Code = 'code'`, `Split = 'split'`, `Preview = 'preview'`.
  - Covers: [FR-01]

- [x] **T2 — Update `SettingKey`**
  - Files: `app/Enums/SettingKey.php`
  - Details: Add `case EditorDefaultView = 'editor.default_view';`. Add it to `type()` (returns `SettingType::String`), `group()` (returns `SettingGroup::Editor`), and `default()` (returns `EditorDefaultView::Code->value`).
  - Covers: [FR-01]

- [x] **T3 — Update Validation and Controller**
  - Files: `app/Http/Requests/Settings/UpdateEditorSettingsRequest.php`, `app/Http/Controllers/Settings/EditorController.php`
  - Details: 
    - In Request: Add `'default_view' => ['required', Rule::enum(EditorDefaultView::class)]`.
    - In Controller: Add `SettingKey::EditorDefaultView->value => $request->validated('default_view')` to the `$values` array.
  - Covers: [FR-02]

- [x] **T4 — Update Frontend Types and UI**
  - Files: `resources/js/types/settings.ts`, `resources/js/pages/settings/Editor.vue`
  - Details:
    - In Types: Add `export type EditorDefaultView = 'code' | 'split' | 'preview';` and include `default_view: EditorDefaultView;` in `EditorPreferences`.
    - In Page: Add a `Select` dropdown in the "Editor View & Layout" card to change `form.default_view`. Provide options for "Editor Only" (`code`), "Split View" (`split`), and "Preview Only" (`preview`).
  - Covers: [FR-04]

- [x] **T5 — Update Editor Initialization**
  - Files: `resources/js/components/editor/MarkdownEditor.vue`
  - Details: Change `const displayMode = ref<DisplayMode>('code');` to `const displayMode = ref<DisplayMode>(preferences.default_view ?? 'code');` (ensure `DisplayMode` matches the `EditorDefaultView` union).
  - Covers: [FR-03]

- [x] **T6 — Update Tests**
  - Files: `tests/Feature/Settings/EditorSettingsTest.php`, `tests/Feature/Services/SettingsServiceTest.php`, `tests/js/editor/codemirror.test.ts`
  - Details:
    - Update `baseEditorSettingsPayload()` in `EditorSettingsTest.php` to include `'default_view' => 'code'`.
    - Assert `default_view` updates correctly and rejects invalid values.
    - Update `SettingsServiceTest.php` `group returns every field...` test to expect `default_view` as well.
    - Update `mockPreferences` in `codemirror.test.ts`.

- [ ] **T7 — Create `useNoteNavigation` Composable & Wire into Note Selection**
  - Files: `resources/js/composables/useNoteNavigation.ts`, `resources/js/components/notes/NoteTree.vue`
  - Details:
    - Create `useNoteNavigation.ts` exposing `noteSelectionTick` and `notifyNoteSelected()`.
    - In `NoteTree.vue`, invoke `notifyNoteSelected()` inside `actions.noteSelected()`.
  - Covers: [FR-05]

- [ ] **T8 — Reset View Mode on Note Selection and Preference Changes**
  - Files: `resources/js/components/editor/MarkdownEditor.vue`, `resources/js/components/editor/NoteEditor.vue`
  - Details:
    - In `MarkdownEditor.vue`:
      - Accept `noteUuid?: string` prop.
      - Implement `resetDisplayMode(): void` setting `displayMode.value = preferences.default_view ?? 'code'`.
      - Watch `noteSelectionTick` (from `useNoteNavigation`), `() => noteUuid`, and `() => preferences.default_view` to call `resetDisplayMode()`.
      - Expose `resetDisplayMode` in `defineExpose`.
    - In `NoteEditor.vue`:
      - Pass `:note-uuid="props.note.uuid"` to `<MarkdownEditor>`.
      - Expose `resetDisplayMode: () => editorRef.value?.resetDisplayMode()` in `defineExpose`.
  - Covers: [FR-05]

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Settings/EditorSettingsTest.php` | Update valid/invalid `default_view` setting | [FR-01], [FR-02] |
| `tests/Feature/Services/SettingsServiceTest.php` | Setting key resolution & persistence | [FR-01] |
| `tests/js/editor/codemirror.test.ts` | Mock preference updates | [FR-03] |

**Test scope for QA**: `php artisan test --compact tests/Feature/Settings/EditorSettingsTest.php tests/Feature/Services/SettingsServiceTest.php tests/js/editor/codemirror.test.ts`, `vendor/bin/phpstan analyse`, and `npm run types:check`.

---

## 5. Risks & Mitigations
- **Risk**: Setting key name clashes. — **Mitigation**: Using `editor.default_view` is clean and scoped.

---

## 6. Open Questions
- None.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-08 | Initial plan | Tasks T1–T6 |
| 2 | 2026-10-08 | Reset view on note selection | Added T7 and T8 to reset view mode back to default on note re-selection / switching |

