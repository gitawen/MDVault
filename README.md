# MDVault

> **Local-first, privacy-focused Markdown knowledge vault for the desktop.**

MDVault is a modern desktop note-taking and knowledge management application built with **Laravel 13**, **Vue 3**, **Inertia.js v3**, **Tailwind CSS v4**, and **NativePHP**. It bridges the convenience of a rich visual editor with the longevity, ownership, and simplicity of plain `.md` files on your local drive.

---

## Core Principles

- **Local-First & Authoritative Filesystem**: The filesystem owns your `.md` files. SQLite manages application indexes, search metadata, and UI state. Your notes remain plain Markdown files you can open in any text editor.
- **100% Offline**: Operates fully offline without cloud accounts, remote servers, or background telemetry.
- **Rich Visual Editor**: Powered by Tiptap with bi-directional Markdown serialization—bold, italic, task lists, tables, code blocks, and blockquotes with formatting preservation.
- **Strong Vault Encryption**: Optional end-to-end vault encryption powered by `libsodium` and `Argon2id`. Encrypted vaults conceal note filenames, folder structures, and contents on disk.
- **Filesystem Intelligence**: Detects external file changes, renames, additions, and deletions without silently overwriting external modifications.
- **Portable Backups**: Export and restore entire vaults or individual notes as standard ZIP archives with SHA-256 integrity verification.

---

## Releases & Installation

Standalone desktop installers for Windows are distributed directly via **GitHub Releases**:

1. Go to the [**Releases**](https://github.com/gitawen/MDVault/releases) page.
2. Download the latest installer (`MDVault-1.0.0-setup.exe`).
3. Run the installer to install MDVault on your system.

---

## Getting Started (Development)

### Prerequisites
- **PHP**: 8.4 or higher (with `sodium`, `zip`, `pdo_sqlite`, and `fileinfo` extensions)
- **Node.js**: 22.0.0 or higher
- **Composer**: 2.x
- **Git**

### Installation
```bash
# Clone the repository
git clone https://github.com/gitawen/MDVault.git
cd MDVault

# Install PHP and Node dependencies
composer install
npm install

# Setup environment configuration
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Compile frontend assets
npm run build
```

### Running Locally
```bash
# Native desktop development mode (Electron + Vite HMR):
composer native:dev

# Or web browser development mode:
composer dev
```

### Testing
```bash
# Run the complete test suite:
php artisan test --compact

# Code style checking:
vendor/bin/pint --test
```

---

## Desktop Packaging & Distribution

To package a production-ready Windows NSIS installer and unpacked binary:

```bash
php artisan native:build win x64 --no-interaction
```

The resulting distribution artifacts will be located in:
```text
nativephp/electron/dist/
├── MDVault-1.0.0-setup.exe   # Standalone Windows NSIS Setup Installer
└── win-unpacked/             # Portable unpacked application folder
```

For complete packaging instructions, updater configuration, and GitHub release publishing steps, see the [**Desktop Distribution & Packaging Guide**](docs/Distribution-Guide.md).

---

## Documentation

- [Master Plan](docs/Masterplan.md) — Comprehensive architecture, core rules, and implementation phase specifications.
- [Distribution Guide](docs/Distribution-Guide.md) — Step-by-step Windows packaging and GitHub Releases guide.
- [Architectural Decisions](.ai/decisions/) — Architectural Decision Records (ADRs) across all completed phases.

---

## License

MDVault is open-sourced software licensed under the [MIT license](LICENSE).
