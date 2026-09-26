# MDVault — Master Plan

> **Project:** MDVault
> **Version:** v1
> **Architecture:** Local-first desktop application
> **Primary Storage:** Filesystem (`.md` files)
> **Application Database:** SQLite
> **Desktop Runtime:** NativePHP
> **Backend:** Laravel
> **Frontend:** Vue + Inertia
> **Editor:** Tiptap
> **UI:** Tailwind CSS + shadcn-vue
> **Future:** Synchronization, sharing, and collaboration

---

# 1. Project Vision

MDVault is a **local-first Markdown knowledge and note management application** distributed as a desktop application.

The core philosophy is:

> **The filesystem owns the actual Markdown content. SQLite manages metadata and application state.**

Users should be able to create Vaults, organize Markdown notes, edit them through a user-friendly rich-text editor, back them up, restore them, and optionally protect Vaults through encryption.

The application should remain useful and fully functional without requiring:

* Internet connectivity
* Cloud services
* User accounts
* Remote servers
* Synchronization services

Future synchronization and sharing capabilities must be possible without restructuring the core local-first architecture.

---

# 2. Core Architectural Principle

The most important architectural rule is:

```text
SQLite manages:

- Vault registry
- Note/file registry
- File metadata
- File hashes
- Application settings
- Encryption metadata
- Backup metadata
- Application state

Filesystem manages:

- Vault directories
- Folder hierarchy
- Markdown files
- Actual Markdown content
- Encrypted file contents
```

Never make SQLite the primary storage for Markdown content.

Do NOT create:

```text
notes.content
```

as the primary representation of a note.

Instead:

```text
Tiptap
   ↓
Markdown serializer
   ↓
.md file
```

When opening:

```text
.md file
   ↓
Markdown parser
   ↓
Tiptap
```

---

# 3. High-Level Architecture

```text
┌──────────────────────────────────────────┐
│               MDVault Desktop            │
│                 NativePHP                │
├──────────────────────────────────────────┤
│                                          │
│              Vue + Inertia               │
│                                          │
│          ┌───────────────────┐           │
│          │      Tiptap       │           │
│          │  Markdown Editor  │           │
│          └─────────┬─────────┘           │
│                    │                     │
│                    ▼                     │
│          ┌───────────────────┐           │
│          │      Laravel      │           │
│          │ Application Layer │           │
│          └─────────┬─────────┘           │
│                    │                     │
│          ┌─────────┴─────────┐           │
│          ▼                   ▼           │
│     SQLite Database      Filesystem      │
│     Metadata/State       Vault/.md       │
│                                          │
└──────────────────────────────────────────┘
```

---

# 4. Technology Stack

## Required

* Laravel
* NativePHP
* Vue 3
* Inertia.js
* Tailwind CSS
* shadcn-vue
* Tiptap
* SQLite

## Recommended Supporting Libraries

Use established, maintained libraries wherever possible for:

* Markdown parsing
* Markdown serialization
* UUID generation
* Cryptography
* ZIP/archive handling
* Filesystem watching

Do not implement cryptographic primitives manually.

---

# 5. Local-First Requirements

MDVault v1 must operate locally.

The application must not require:

```text
Internet
Cloud account
Remote database
Authentication server
API server
```

for normal operation.

The application should work when:

```text
Internet = OFF
```

The only required local resources are:

```text
MDVault application
SQLite database
Filesystem
```

---

# 6. Vault Concept

A Vault represents a physical directory containing a collection of Markdown notes.

Example:

```text
Documents/
└── MDVault/
    └── Work/
        ├── HRMIS.md
        ├── Projects/
        │   ├── CrownTab.md
        │   └── FMTS.md
        └── Servers/
            ├── nginx.md
            └── mysql.md
```

The Vault database record represents:

```text
Work
```

while the filesystem represents its actual contents.

---

# 7. Vault Management

MDVault v1 must support:

* Create Vault
* Open Vault
* Close Vault
* Rename Vault
* Delete Vault
* Change Vault location
* View Vault information
* Detect missing Vault directories
* Re-index Vault contents

Creating a Vault should perform:

```text
Create database record
        ↓
Create filesystem directory
        ↓
Verify directory
        ↓
Complete Vault creation
```

Remember that SQLite transactions cannot automatically roll back filesystem operations.

Filesystem/database consistency must therefore be explicitly handled.

---

# 8. Default Storage Location

The application should provide a platform-appropriate default storage directory.

Do not hardcode Windows-specific paths.

Instead use a storage/path service.

Conceptually:

```text
StoragePathService
```

should determine:

```text
Windows → User Documents/MDVault
macOS   → User Documents/MDVault
Linux   → User Documents/MDVault
```

The user must be able to change the root storage location.

---

# 9. Database Design

MDVault v1 should initially use five core tables:

```text
settings
vaults
notes
vault_encryption
backups
```

Do not prematurely create tables for:

```text
devices
sync
sharing
collaboration
versions
remote accounts
```

Those belong to future versions.

---

# 10. `settings`

Stores application configuration using a key/value structure.

Suggested schema:

```text
settings
────────────────────────
id
key
value
type
group
created_at
updated_at
```

Example settings:

```text
storage.root_path
appearance.theme
appearance.accent_color
appearance.logo
appearance.favicon
editor.font_size
editor.font_family
editor.line_height
editor.word_wrap
editor.show_line_numbers
backup.default_format
app.check_external_changes
```

Possible groups:

```text
general
storage
editor
appearance
backup
security
```

Settings should be accessible through:

```text
SettingsService
```

Do not scatter direct database queries for settings throughout the application.

---

# 11. `vaults`

Represents registered Vaults.

Suggested schema:

```text
vaults
────────────────────────
id
uuid
name
description
path
relative_path
is_encrypted
status
created_at
updated_at
```

Suggested types:

```text
id              INTEGER
uuid            UUID/string
name            VARCHAR(255)
description     TEXT NULL
path            TEXT
relative_path   TEXT NULL
is_encrypted    BOOLEAN
status          VARCHAR(30)
created_at
updated_at
```

Example:

```text
id:             1
uuid:           019a...
name:           Work
description:    Work-related documentation
path:           D:\Documents\MDVault\Work
is_encrypted:   false
status:         active
```

---

# 12. UUID Requirement

Vaults and notes should have stable UUIDs.

Do not rely exclusively on auto-increment IDs.

UUIDs will be useful for:

* Backup/import
* Future synchronization
* Future sharing
* Future multi-device support
* Stable identity across database rebuilds

---

# 13. `notes`

Each Markdown file should have a registry record.

Suggested schema:

```text
notes
────────────────────────
id
uuid
vault_id
title
filename
relative_path
extension
mime_type
file_size
file_hash
is_encrypted
created_at
updated_at
```

Example:

```text
id:             1
uuid:           019a...
vault_id:       1
title:          HRMIS
filename:       HRMIS.md
relative_path:  Projects/HRMIS.md
extension:      md
mime_type:      text/markdown
file_size:      18452
file_hash:      sha256...
is_encrypted:   false
```

Relationship:

```text
vaults
   │
   └── hasMany notes
```

---

# 14. Relative Paths

Notes should use a path relative to their Vault.

Example:

```text
Vault:

D:\Documents\MDVault\Work
```

Note:

```text
Projects/HRMIS.md
```

Actual path:

```text
D:\Documents\MDVault\Work\Projects\HRMIS.md
```

This allows a Vault to be moved without rewriting every note's path.

Example:

```text
D:\Documents\MDVault\Work
```

can become:

```text
E:\MyData\MDVault\Work
```

while:

```text
Projects/HRMIS.md
```

remains unchanged.

---

# 15. Do Not Create a `folders` Table Initially

The physical filesystem already provides folder hierarchy.

Example:

```text
Work/
├── Projects/
│   ├── HRMIS.md
│   └── CrownTab.md
├── Servers/
│   └── nginx.md
└── Meetings/
    └── September.md
```

The hierarchy can be represented by:

```text
notes.relative_path
```

Examples:

```text
Projects/HRMIS.md
Projects/CrownTab.md
Servers/nginx.md
Meetings/September.md
```

Therefore, do not create a `folders` table unless a future feature requires folder-specific metadata.

---

# 16. Markdown Content

Markdown content must remain in the filesystem.

Correct:

```text
notes
├── title
├── filename
├── relative_path
├── file_size
└── file_hash

filesystem
└── HRMIS.md
      └── actual Markdown content
```

Incorrect:

```text
notes
├── title
├── path
└── content
```

This is one of the foundational architectural decisions of MDVault.

---

# 17. File Hashing

Every indexed Markdown file should have a SHA-256 hash.

Example:

```text
HRMIS.md
   ↓
SHA-256
   ↓
e3b0c44298fc1c149...
```

The hash is used for:

* External change detection
* Backup validation
* Duplicate detection
* Corruption detection
* Future synchronization
* File integrity checks

Example:

```text
Database hash:
ABC123

Current filesystem hash:
XYZ789

Result:
File changed externally
```

---

# 18. Filesystem as Source of Truth

For note content:

```text
Filesystem = Source of Truth
```

SQLite is an index/registry.

If SQLite and the filesystem disagree about note content:

```text
Filesystem content takes precedence.
```

The application should provide mechanisms to rebuild/re-index SQLite metadata from the filesystem.

This is important because users may edit `.md` files using:

* VS Code
* Notepad
* another Markdown editor
* file managers
* scripts
* external tools

MDVault must not assume it is the only application touching the files.

---

# 19. File Storage Service

All filesystem operations must go through application services.

Do not perform direct filesystem manipulation from Vue components.

Recommended conceptual services:

```text
VaultService
NoteService
FileStorageService
StoragePathService
SettingsService
BackupService
EncryptionService
```

Architecture:

```text
Vue
 ↓
Inertia
 ↓
Laravel Application Layer
 ↓
Service
 ↓
Filesystem / SQLite
```

Never:

```text
Vue
 ↓
Direct filesystem access
```

---

# 20. Tiptap Editor

Tiptap will provide the primary Markdown editing interface.

The editor should feel like a modern note-taking application rather than a raw code editor.

Core toolbar:

```text
Undo
Redo

Bold
Italic
Underline
Strike

Heading 1
Heading 2
Heading 3

Bullet List
Ordered List
Task List

Blockquote
Code
Code Block

Link
Horizontal Rule

Alignment

Clear Formatting
```

Potential later features:

```text
Table
Images
Callouts
Advanced blocks
```

Only implement features that have a reliable Markdown representation.

---

# 21. Markdown Round Trip

The editor must support:

```text
Markdown
   ↓
Parser
   ↓
Tiptap
   ↓
User edits
   ↓
Serializer
   ↓
Markdown
```

Round-trip integrity is critical.

Example:

```text
HRMIS.md
   ↓
Open
   ↓
Tiptap
   ↓
Edit
   ↓
Save
   ↓
HRMIS.md
```

Avoid editor features that silently introduce data that cannot be represented in Markdown.

---

# 22. Autosave / Save Strategy

The implementation should support a controlled save mechanism.

Do not write to disk on every keystroke.

Recommended conceptual flow:

```text
User edits
   ↓
Editor state changes
   ↓
Debounced save
   ↓
Serialize Markdown
   ↓
Write file
   ↓
Calculate SHA-256
   ↓
Update SQLite metadata
```

The exact debounce interval should be configurable or determined during implementation testing.

The system should also support explicit save.

---

# 23. Note Management

MDVault v1 should support:

```text
Create Note
Open Note
Rename Note
Delete Note
Move Note
Create Folder
Move Note Between Folders
```

Example:

```text
Work/
├── Projects/
│   ├── HRMIS.md
│   └── CrownTab.md
└── Servers/
    └── nginx.md
```

Moving a note:

```text
Projects/HRMIS.md
        ↓
Servers/HRMIS.md
```

must update:

```text
Filesystem path
notes.relative_path
notes.filename
```

while keeping the note UUID stable.

---

# 24. External File Changes

MDVault must eventually detect files changed outside the application.

Examples:

```text
VS Code edits HRMIS.md
        ↓
Filesystem watcher
        ↓
Change detected
        ↓
Hash recalculated
        ↓
SQLite metadata updated
        ↓
UI notified
```

Handle:

```text
Created externally
Modified externally
Renamed externally
Moved externally
Deleted externally
```

If a currently open note changes externally, the application must avoid silently overwriting the external changes.

Possible conflict handling:

```text
External change detected

[Reload from Disk]
[Keep Current Editor]
[Compare]
```

The exact UX can be refined during Phase 5.

---

# 25. Backup System

MDVault should provide:

```text
Backup All Vaults
Backup Selected Vault
Restore Backup
Import Backup
Validate Backup
```

ZIP should be the first supported backup format.

Possible later format:

```text
tar.gz
```

---

# 26. Backup Structure

A standard backup should resemble:

```text
MDVault-Backup-2026-09-26.zip
│
├── mdvault.sqlite
├── manifest.json
└── vaults/
    ├── Work/
    │   ├── HRMIS.md
    │   └── Projects/
    │       └── CrownTab.md
    │
    └── Personal/
        └── Ideas.md
```

The backup must be self-contained.

---

# 27. Backup Manifest

Every backup should contain:

```text
manifest.json
```

Example:

```json
{
    "application": "MDVault",
    "format_version": 1,
    "database_version": 1,
    "created_at": "...",
    "vaults": 4,
    "notes": 127
}
```

The manifest should allow MDVault to determine:

* What created the backup
* Backup format version
* Database version
* Number of Vaults
* Number of notes
* Creation date
* Future compatibility information

---

# 28. Backup Validation

Before importing:

```text
Backup ZIP
   ↓
Read manifest
   ↓
Validate format
   ↓
Validate database
   ↓
Validate files
   ↓
Check integrity
   ↓
Import
```

A corrupted or incompatible backup must not partially overwrite an existing MDVault installation.

Prefer:

```text
Validate first
Import second
```

---

# 29. Import / Restore

Expected workflow:

```text
Import Backup
      ↓
Select ZIP
      ↓
Validate manifest
      ↓
Validate database
      ↓
Validate files
      ↓
Prepare temporary extraction
      ↓
Import
      ↓
Rebuild file index
      ↓
Verify
      ↓
Complete
```

Use temporary directories where appropriate to prevent partially imported data.

---

# 30. Encryption

Encryption is a major feature and must be treated as a security-sensitive subsystem.

The encryption design should use established cryptographic libraries.

Never implement encryption algorithms manually.

Do not store the user's plaintext password.

---

# 31. `vault_encryption`

Suggested schema:

```text
vault_encryption
────────────────────────
id
vault_id
algorithm
key_version
salt
nonce
encrypted_key
created_at
updated_at
```

Example:

```text
vault_id:       1
algorithm:      AES-256-GCM
key_version:    1
salt:           ...
nonce:          ...
encrypted_key:  ...
```

The exact cryptographic design must be reviewed and tested before implementation.

---

# 32. Encryption Requirements

Encrypted Vaults should protect:

```text
Note content
Folder/file names
Sensitive metadata where practical
```

Example plaintext filesystem:

```text
My Credentials/
└── Bank Accounts.md
```

Encrypted filesystem:

```text
7e9c31a4/
└── 8af29c11
```

Inside MDVault:

```text
My Credentials
└── Bank Accounts.md
```

The user should not need to understand the underlying encrypted filesystem representation.

---

# 33. Encryption Flow

Opening an encrypted Vault:

```text
Open Vault
   ↓
Request password
   ↓
Derive/unlock encryption key
   ↓
Validate key
   ↓
Unlock Vault
   ↓
Decrypt metadata/content as required
   ↓
Display Vault
```

Saving:

```text
Tiptap
   ↓
Markdown
   ↓
Encrypt
   ↓
Write encrypted file
   ↓
Update hash/metadata
```

Locking:

```text
Lock Vault
   ↓
Close sensitive resources
   ↓
Clear decrypted state where possible
   ↓
Return Vault to locked state
```

---

# 34. Encryption Security Principles

Never:

```text
Store plaintext password
Log passwords
Log encryption keys
Expose keys to Vue unnecessarily
Implement custom cryptographic algorithms
```

Avoid storing decrypted content longer than necessary.

Sensitive values must not appear in:

```text
Laravel logs
Debug output
Exception messages
Browser console
```

---

# 35. Appearance System

The settings interface should follow a modern application settings structure.

```text
Settings
│
├── General
├── Storage
├── Editor
├── Appearance
├── Backup
└── Security
```

Appearance options may include:

```text
Theme

○ Light
○ Dark
● System

Accent Color

Application Logo
Application Favicon

Sidebar
☑ Show file tree
☑ Show icons

Editor
☑ Show line numbers
☑ Word wrap
```

Use the existing UI component conventions consistently throughout the application.

---

# 36. Main Application Layout

The initial application layout should follow a modern knowledge-management application.

Conceptually:

```text
┌─────────────────────────────────────────────────────────────┐
│ MDVault                                      Search   ⚙     │
├───────────────┬───────────────────────────────┬─────────────┤
│               │                               │             │
│ Vaults        │           Editor              │             │
│               │                               │             │
│ Work          │ # HRMIS                       │             │
│ ├ Projects    │                               │             │
│ │ ├ HRMIS     │ Markdown content...           │             │
│ │ └ CrownTab  │                               │             │
│ └ Servers     │                               │             │
│               │                               │             │
│ Personal      │                               │             │
│               │                               │             │
├───────────────┴───────────────────────────────┴─────────────┤
│ Status / Save state                                         │
└─────────────────────────────────────────────────────────────┘
```

The exact UI can evolve.

The architecture should not depend on a particular visual layout.

---

# 37. Search

Basic local search should be considered part of the core note experience.

Initial search can use the filesystem/database index.

Future versions may introduce:

```text
SQLite FTS5
```

or another dedicated local search index.

Do not prematurely build a complicated search engine.

Potential search targets:

```text
Note title
Filename
Path
Markdown content
```

---

# 38. Re-indexing

MDVault must be able to scan a Vault and rebuild its note registry.

Conceptually:

```text
Vault
 ↓
Scan filesystem
 ↓
Find .md files
 ↓
Read metadata
 ↓
Calculate hashes
 ↓
Compare SQLite
 ↓
Create/update/delete registry entries
```

This provides recovery when:

```text
Database metadata becomes stale
Files are modified externally
Vault is copied manually
Vault is moved
Files are restored from backup
```

---

# 39. Database as an Index

The SQLite database should be treated as:

```text
Application metadata/index
```

not the only representation of the Vault.

Therefore, MDVault should eventually support:

```text
Rebuild Index
```

which reconstructs the `notes` table from the filesystem.

This is a major resilience feature.

---

# 40. Service Architecture

Recommended application services:

```text
app/Services/
├── VaultService.php
├── NoteService.php
├── FileStorageService.php
├── StoragePathService.php
├── SettingsService.php
├── BackupService.php
├── EncryptionService.php
├── FileHashService.php
├── VaultIndexService.php
└── MarkdownService.php
```

The actual directory structure can be adjusted to Laravel conventions.

---

# 41. Responsibility Boundaries

## `VaultService`

Responsible for:

```text
Create Vault
Open Vault
Close Vault
Rename Vault
Delete Vault
Move Vault
Vault validation
```

## `NoteService`

Responsible for:

```text
Create Note
Rename Note
Delete Note
Move Note
Open Note
Save Note
```

## `FileStorageService`

Responsible for:

```text
Filesystem reads
Filesystem writes
Filesystem moves
Filesystem deletes
Directory creation
Path resolution
```

## `MarkdownService`

Responsible for:

```text
Markdown → Tiptap representation
Tiptap representation → Markdown
Markdown validation
```

## `FileHashService`

Responsible for:

```text
SHA-256 calculation
File integrity checks
Change detection
```

## `VaultIndexService`

Responsible for:

```text
Filesystem scanning
Database synchronization
Re-indexing
External file reconciliation
```

## `BackupService`

Responsible for:

```text
Backup creation
Manifest generation
Backup validation
Import
Restore
```

## `EncryptionService`

Responsible for:

```text
Key derivation
Encryption
Decryption
Encrypted filename handling
Vault lock/unlock
```

---

# 42. Vue Architecture Rules

Vue components should focus on:

```text
Presentation
User interaction
UI state
Editor state
```

They should not contain:

```text
Filesystem logic
Encryption implementation
Database queries
Backup implementation
Path manipulation
```

Use Inertia requests/actions to communicate with Laravel.

---

# 43. Error Handling

Filesystem operations can fail independently of database operations.

Examples:

```text
Permission denied
File missing
Directory missing
Disk unavailable
File locked
Insufficient storage
Invalid path
Corrupted file
```

Every filesystem operation must handle failures explicitly.

Never assume:

```text
database success = filesystem success
```

or:

```text
filesystem success = database success
```

Consistency/recovery must be considered.

---

# 44. Concurrency

MDVault should initially assume:

```text
One local user
One running application instance
```

but avoid architectural decisions that prevent future concurrency.

External modifications must still be detected.

Future synchronization will introduce much more complex concurrency requirements.

Do not implement those requirements prematurely.

---

# 45. Future Synchronization

Synchronization is explicitly **out of scope for v1**.

However, v1 should prepare for it through:

```text
Stable UUIDs
Relative paths
SHA-256 hashes
Clear file metadata
Filesystem source of truth
Versioned backup format
Service-based architecture
```

Future tables may include:

```text
devices
sync_states
note_versions
sync_conflicts
```

Do not create them in v1.

---

# 46. Future Sharing

Sharing is explicitly **out of scope for v1**.

Possible future architecture:

```text
shares
share_permissions
shared_vaults
remote_accounts
```

Do not add these tables now.

---

# 47. Future Collaboration

Real-time collaboration is explicitly **out of scope for v1**.

Potential future requirements:

```text
WebSocket
Presence
Document versions
Conflict resolution
Operational transformation
CRDT
```

None of these should influence the initial implementation unnecessarily.

---

# 48. Development Phases

MDVault v1 should be developed in the following order.

```text
Phase 0 → Foundation
Phase 1 → Storage / Settings
Phase 2 → Vault Management
Phase 3 → Markdown Files
Phase 4 → Tiptap Editor
Phase 5 → Filesystem Intelligence
Phase 6 → Backup / Restore
Phase 7 → Encryption
Phase 8 → Packaging / Distribution
```

---

# 49. Phase 0 — Foundation

Complexity: Low

Set up:

```text
Laravel
NativePHP
Vue 3
Inertia
Tailwind CSS
shadcn-vue
SQLite
Tiptap
```

Establish:

```text
Database
Application layout
Routing
Basic settings
Service architecture
NativePHP runtime
```

Create foundational services/interfaces.

Do not implement major features yet.

### Acceptance Criteria

```text
Application launches as desktop application.

SQLite database works.

Vue/Inertia interface works.

Tiptap can render inside the application.

Basic Laravel service architecture exists.
```

---

# 50. Phase 1 — Storage and Settings

Complexity: Low–Medium

Implement:

```text
Settings
Storage root
Theme
Appearance
Editor preferences
```

Create:

```text
StoragePathService
SettingsService
```

### Acceptance Criteria

```text
User can view settings.

User can change storage root.

User can change theme.

Settings persist after application restart.

Default storage location is platform appropriate.
```

---

# 51. Phase 2 — Vault Management

Complexity: Medium

Implement:

```text
Create Vault
Open Vault
Close Vault
Rename Vault
Delete Vault
```

### Acceptance Criteria

```text
Creating a Vault creates a physical directory.

Vault metadata is registered in SQLite.

Vault can be opened again after restarting the application.

Vault can be renamed.

Vault can be removed safely.

Missing Vault directories are detected.
```

---

# 52. Phase 3 — Markdown Filesystem

Complexity: Medium

Implement:

```text
Create Note
Rename Note
Delete Note
Move Note
Create Folder
Move Note Between Folders
Re-index Vault
```

Implement:

```text
FileHashService
VaultIndexService
```

### Acceptance Criteria

```text
Every .md file can be indexed.

Database stores relative paths.

Markdown content remains on disk.

File hash is stored.

Vault can be rebuilt from filesystem contents.
```

---

# 53. Phase 4 — Tiptap Editor

Complexity: Medium–High

Implement:

```text
Markdown → Tiptap
Tiptap → Markdown
```

Implement toolbar:

```text
Bold
Italic
Underline
Strike
Headings
Lists
Task list
Blockquote
Code
Code block
Links
Horizontal rule
Alignment
Undo
Redo
Clear formatting
```

### Acceptance Criteria

```text
Existing Markdown files open correctly.

User can edit notes.

Saving produces valid Markdown.

Reloading the file preserves the user's formatting.

Supported Markdown round-trips without unexpected data loss.
```

---

# 54. Phase 5 — Filesystem Intelligence

Complexity: Medium–High

Implement filesystem watching.

Detect:

```text
Create
Modify
Rename
Move
Delete
```

Reconcile changes with SQLite.

Handle open-document conflicts safely.

### Acceptance Criteria

```text
Editing a .md file externally is detected.

Creating a .md file externally is detected.

Deleting a .md file externally is detected.

Moving a file externally is detected.

The application does not silently overwrite external changes.
```

---

# 55. Phase 6 — Backup and Restore

Complexity: Medium–High

Implement:

```text
Backup All
Backup Vault
Restore
Import
Validation
Manifest
```

Use ZIP first.

### Acceptance Criteria

Test:

```text
Create Vault
Create Notes
Backup
Remove local data
Fresh application state
Import backup
Verify Vaults
Verify Notes
Verify content
```

This must work reliably.

---

# 56. Phase 7 — Encryption

Complexity: High

Implement:

```text
Vault encryption
Encrypted filenames
Encrypted contents
Unlock
Lock
Password validation
Encryption metadata
```

Use established cryptographic libraries.

### Acceptance Criteria

```text
Encrypted Vault contents are not plaintext on disk.

Filenames are not exposed in plaintext.

Incorrect password cannot unlock the Vault.

Correct password unlocks the Vault.

Locking removes access to the protected content.

Backup/restore works with encrypted Vaults.

No passwords or keys appear in logs.
```

Security testing is mandatory before calling this feature complete.

---

# 57. Phase 8 — Distribution

Complexity: Medium

Build the desktop application.

Primary target:

```text
Windows
```

The application should produce a distributable NativePHP desktop application.

Test:

```text
Fresh machine
Install
Launch
Create Vault
Create Note
Edit Note
Close
Reopen
Backup
Restore
```

Later test other supported operating systems.

---

# 58. Complexity Overview

| Phase  | Feature             | Complexity  |
| ------ | ------------------- | ----------- |
| 0      | Foundation          | Low         |
| 1      | Settings / Storage  | Low–Medium  |
| 2      | Vault Management    | Medium      |
| 3      | Markdown Filesystem | Medium      |
| 4      | Tiptap Editor       | Medium–High |
| 5      | Filesystem Watcher  | Medium–High |
| 6      | Backup / Restore    | Medium–High |
| 7      | Encryption          | High        |
| 8      | Distribution        | Medium      |
| Future | Synchronization     | Very High   |
| Future | Sharing             | High        |
| Future | Collaboration       | Very High   |

---

# 59. Dependency Map

```text
                  ┌─────────────────┐
                  │    Phase 0      │
                  │   Foundation    │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 1      │
                  │ Storage/Settings│
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 2      │
                  │ Vault Management│
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 3      │
                  │ Markdown Files  │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 4      │
                  │ Tiptap Editor   │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 5      │
                  │ File Intelligence│
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 6      │
                  │ Backup/Restore  │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 7      │
                  │   Encryption    │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │    Phase 8      │
                  │   Distribution  │
                  └────────┬────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │   MDVault v1    │
                  └─────────────────┘

                           │
                           ▼
                     FUTURE v2
                     Synchronization
                           │
                           ▼
                     FUTURE v3
                        Sharing
                           │
                           ▼
                     FUTURE v4
                   Collaboration
```

---

# 60. Core Architectural Rules

These rules must be treated as project-level constraints.

## Rule 1 — Markdown is the source of truth

```text
.md files are the authoritative storage for note content.
```

Never make SQLite the primary note-content store.

---

## Rule 2 — SQLite stores metadata

SQLite is responsible for:

```text
Registry
Metadata
Settings
Indexes
Encryption metadata
Backup metadata
Application state
```

---

## Rule 3 — Use relative paths

Notes should store:

```text
Projects/HRMIS.md
```

not:

```text
D:\Documents\MDVault\Work\Projects\HRMIS.md
```

---

## Rule 4 — Stable UUIDs

Vaults and notes should have stable UUIDs.

Do not use integer IDs as the only identity.

---

## Rule 5 — Filesystem operations belong in services

Never perform core filesystem operations directly inside Vue components.

Use:

```text
VaultService
NoteService
FileStorageService
VaultIndexService
```

---

## Rule 6 — Never store plaintext passwords

Encryption passwords must never be persisted as plaintext.

---

## Rule 7 — Never implement custom cryptography

Use established, audited cryptographic primitives/libraries.

---

## Rule 8 — External file modifications are valid

MDVault must assume users can modify files outside the application.

---

## Rule 9 — Design for recovery

A user should be able to recover the application index from the filesystem.

---

## Rule 10 — Don't prematurely implement synchronization

Do not introduce synchronization architecture into v1 unless required by an explicitly approved future feature.

---

# 61. Agent Development Rules

When implementing MDVault, the coding agent should:

1. Read this `masterplan.md` before making architectural decisions.
2. Respect the local-first architecture.
3. Avoid introducing unnecessary dependencies.
4. Prefer existing Laravel conventions.
5. Keep filesystem logic in dedicated services.
6. Keep Vue components focused on UI.
7. Use Form Requests or equivalent validation for input validation.
8. Use policies/authorization patterns if authorization becomes necessary.
9. Use database migrations for schema changes.
10. Never store Markdown content as the primary note database field.
11. Never hardcode platform-specific storage paths.
12. Never hardcode encryption secrets.
13. Never log sensitive encryption information.
14. Write tests for filesystem operations.
15. Write tests for database/filesystem consistency.
16. Write tests for Markdown round-tripping.
17. Write tests for backup restoration.
18. Treat encryption as security-sensitive code.
19. Keep v1 scope controlled.
20. Do not add synchronization/sharing/collaboration infrastructure without explicit approval.

---

# 62. Testing Strategy

Testing should cover four major layers.

## Unit Tests

Test:

```text
Path resolution
Hash calculation
Markdown conversion
Settings
Encryption primitives through the chosen library
```

## Feature Tests

Test:

```text
Vault creation
Vault deletion
Note creation
Note editing
Note moving
Re-indexing
Backup
Restore
```

## Filesystem Tests

Test:

```text
External creation
External modification
External deletion
External rename
External move
Missing files
Missing Vaults
```

## End-to-End Tests

Test complete workflows:

```text
Create Vault
 ↓
Create Note
 ↓
Edit Note
 ↓
Restart Application
 ↓
Reopen Note
 ↓
Verify Content
```

And:

```text
Create
 ↓
Backup
 ↓
Destroy local data
 ↓
Restore
 ↓
Verify everything
```

---

# 63. Definition of Done for MDVault v1

MDVault v1 should be considered complete when a user can:

```text
1. Install MDVault.

2. Launch it without Internet.

3. Configure the application.

4. Create a Vault.

5. Store the Vault in a custom location.

6. Create Markdown notes.

7. Create folders.

8. Move notes between folders.

9. Rename notes.

10. Delete notes.

11. Open Markdown notes.

12. Edit notes using Tiptap.

13. Save notes as Markdown.

14. Close and reopen the application.

15. Detect external Markdown changes.

16. Re-index a Vault.

17. Create backups.

18. Restore backups.

19. Encrypt a Vault.

20. Unlock an encrypted Vault.

21. Lock an encrypted Vault.

22. Distribute MDVault as a desktop application.
```

---

# 64. Out of Scope for v1

Do not implement:

```text
Cloud synchronization
Real-time synchronization
Multi-device sync
Remote accounts
User registration
Sharing
Public links
Real-time collaboration
Collaborative editing
Cloud storage integration
Social features
Complex permissions
Team workspaces
```

These should be considered future roadmap features.

---

# 65. Future Roadmap

## v2 — Synchronization

Potential:

```text
Devices
Sync states
File versions
Conflict resolution
Remote storage
```

---

## v3 — Sharing

Potential:

```text
Vault sharing
Note sharing
Permissions
Shared workspaces
```

---

## v4 — Collaboration

Potential:

```text
Real-time editing
Presence
Comments
Document versions
Conflict resolution
CRDT/OT
```

These future features should build on the stable v1 identity model:

```text
Vault UUID
Note UUID
Relative path
File hash
```

---

# 66. Final Architecture

The final conceptual architecture for v1 is:

```text
                         MDVault
                            │
                 ┌──────────┴──────────┐
                 │                     │
              NativePHP             Laravel
                 │                     │
                 │              Application Services
                 │                     │
                 │       ┌─────────────┼─────────────┐
                 │       │             │             │
                 │   VaultService   NoteService   SettingsService
                 │       │             │             │
                 │       └─────────────┼─────────────┘
                 │                     │
                 │              ┌──────┴──────┐
                 │              │             │
                 │           SQLite      Filesystem
                 │              │             │
                 │          Metadata       Vaults
                 │          Settings       Folders
                 │          Registry       .md files
                 │          Hashes
                 │
                 └──────────────┐
                                │
                         Vue + Inertia
                                │
                              Tiptap
                                │
                         Markdown Editor
```

The fundamental relationship is:

```text
                 ┌──────────────────────┐
                 │       SQLite         │
                 │                      │
                 │ Metadata / Registry  │
                 │ Settings             │
                 │ Hashes               │
                 │ Encryption Metadata  │
                 │ Backup Metadata      │
                 └──────────┬───────────┘
                            │
                            │ references
                            ▼
                 ┌──────────────────────┐
                 │     Filesystem       │
                 │                      │
                 │ Vaults               │
                 │ Folders              │
                 │ Markdown files       │
                 │ Actual note content  │
                 └──────────────────────┘
```

**This separation is the foundation of MDVault.**

The application should remain a useful Markdown filesystem application even if the SQLite index is rebuilt.

The filesystem should remain portable.

The architecture should allow future synchronization without requiring the v1 core to be redesigned.

---

# 67. Agent Instruction

Before implementing any feature, determine:

```text
1. Does this feature belong in v1?
2. Does it preserve Markdown-as-source-of-truth?
3. Does it preserve filesystem portability?
4. Does it belong in a service rather than a Vue component?
5. Does it require a database migration?
6. Does it introduce unnecessary future/sync complexity?
7. How will it behave when files are modified externally?
8. How will it behave offline?
9. How will it recover from filesystem/database inconsistency?
10. How will it be tested?
```

If a proposed implementation conflicts with the architecture in this document, stop and reassess the design before coding.

---

# 68. First Implementation Target

The first implementation milestone should be:

```text
MDVault boots
      ↓
SQLite works
      ↓
Settings work
      ↓
User selects storage location
      ↓
User creates Vault
      ↓
Physical Vault directory is created
      ↓
User creates .md note
      ↓
Note appears in Vault tree
      ↓
User opens note
      ↓
Tiptap editor displays it
      ↓
User edits note
      ↓
Markdown is written to filesystem
      ↓
SQLite metadata is updated
```

Once this workflow works reliably, build subsequent phases on top of it.

---

# End of Master Plan
