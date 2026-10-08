# Implementation: Editor Default View

## Metadata
- **Feature Name**: Editor Default View
- **Feature ID**: feat-editor-default-view
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 2
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Create `EditorDefaultView` Enum | DONE | Created `app/Enums/EditorDefaultView.php` with `Code = 'code'`, `Split = 'split'`, `Preview = 'preview'` |
| T2 — Update `SettingKey` | DONE | Added `EditorDefaultView = 'editor.default_view'` to `SettingKey`, wired to string type, editor group, and default `'code'` |
| T3 — Update Validation and Controller | DONE | Added `default_view` rule in `UpdateEditorSettingsRequest` and persisted key in `EditorController` |
| T4 — Update Frontend Types and UI | DONE | Added `EditorDefaultView` in `settings.ts` and UI Select component in `Editor.vue` under "Editor View & Layout" |
| T5 — Update Editor Initialization | DONE | Updated `MarkdownEditor.vue` to initialize `displayMode` with `preferences.default_view ?? 'code'` |
| T6 — Update Tests | DONE | Updated `EditorSettingsTest.php`, `SettingsServiceTest.php`, and `codemirror.test.ts` |
| T7 — Create `useNoteNavigation` Composable & Wire into Note Selection | DONE | Created `resources/js/composables/useNoteNavigation.ts` with `noteSelectionTick` counter and `notifyNoteSelected()` helper. Wired into `NoteTree.vue` `actions.noteSelected()`. |
| T8 — Reset View Mode on Note Selection and Preference Changes | DONE | Added `noteUuid` prop, `resetDisplayMode()`, and reactive watchers on `noteSelectionTick`, `noteUuid`, and `preferences.default_view` in `MarkdownEditor.vue`. Passed `:note-uuid="props.note.uuid"` and exposed `resetDisplayMode` in `NoteEditor.vue`. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| Created | `app/Enums/EditorDefaultView.php` | String-backed enum defining `Code = 'code'`, `Split = 'split'`, `Preview = 'preview'` |
| Modified | `app/Enums/SettingKey.php` | Registered `EditorDefaultView` enum case, `SettingType::String`, `SettingGroup::Editor`, and default `EditorDefaultView::Code->value` |
| Modified | `app/Http/Requests/Settings/UpdateEditorSettingsRequest.php` | Added `Rule::enum(EditorDefaultView::class)` for `default_view` |
| Modified | `app/Http/Controllers/Settings/EditorController.php` | Saved `SettingKey::EditorDefaultView->value` in `update()` |
| Modified | `resources/js/types/settings.ts` | Exported `EditorDefaultView` union type and added `default_view` property to `EditorPreferences` |
| Modified | `resources/js/pages/settings/Editor.vue` | Added "Default View Mode" select dropdown to "Editor View & Layout" card |
| Created | `resources/js/composables/useNoteNavigation.ts` | Module-scoped `noteSelectionTick` ref and `notifyNoteSelected` helper composable |
| Modified | `resources/js/components/notes/NoteTree.vue` | Invoked `notifyNoteSelected()` inside `actions.noteSelected()` |
| Modified | `resources/js/components/editor/NoteEditor.vue` | Passed `:note-uuid="props.note.uuid"` to `MarkdownEditor` and exposed `resetDisplayMode` |
| Modified | `resources/js/components/editor/MarkdownEditor.vue` | Added `noteUuid` prop, `resetDisplayMode()`, watchers on `noteSelectionTick`, `noteUuid`, and `preferences.default_view`, and exposed `resetDisplayMode` |
| Modified | `tests/Feature/Settings/EditorSettingsTest.php` | Updated base payload, defaults assertion, persistence test, and invalid value validation cases |
| Modified | `tests/Feature/Services/SettingsServiceTest.php` | Updated group resolution test to expect `default_view` default |
| Modified | `tests/js/editor/codemirror.test.ts` | Updated `mockPreferences` fixture to include `default_view: 'code'` |
| Created | `tests/js/editor/noteNavigation.test.ts` | Added unit test verifying `notifyNoteSelected()` increments `noteSelectionTick` |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | Passed (0 style issues) |
| `php artisan test --compact tests/Feature/Settings/EditorSettingsTest.php tests/Feature/Services/SettingsServiceTest.php` | 54 passed (113 assertions) |
| `npm run test:js tests/js/editor/codemirror.test.ts` | 8 passed (8) |
| `npm run test:js tests/js/editor/noteNavigation.test.ts` | 1 passed (1) |
| `npm run types:check` | Passed (0 TypeScript errors) |
| `vendor/bin/phpstan analyse <modified files>` | Passed (0 errors) |

---

## 4. Deviations from Plan
- None

---

## 5. Notes for QA
- Verify that setting `default_view` to `split` or `preview` in settings persists across reloads and that opening notes in the workspace initializes `displayMode` in the configured layout.
- Verify invalid options submitted to `/settings/editor` return validation errors on `default_view`.
- Verify user story FR-05: When editing a note in Editor mode (e.g. after temporarily switching from Split mode), clicking a note in the tree or re-clicking the current note resets the view mode back to the user's default view setting.

---

## 6. Fix Rounds

### Fix Round 1
- **Reported Issue**: After temporarily changing the view mode while editing a note, re-selecting or switching notes should restore the configured default view mode.
- **Implemented Changes**:
  - Implemented `useNoteNavigation.ts` with module-scoped `noteSelectionTick` and `notifyNoteSelected()`.
  - Wired `notifyNoteSelected()` into `NoteTree.vue`'s `actions.noteSelected()`.
  - Updated `MarkdownEditor.vue` to accept `noteUuid`, implement `resetDisplayMode()`, and watch `noteSelectionTick`, `noteUuid`, and `preferences.default_view`.
  - Updated `NoteEditor.vue` to pass `:note-uuid` and expose `resetDisplayMode`.
  - Created unit test `tests/js/editor/noteNavigation.test.ts`.
- **Status**: Verified passing all tests and type checks.
