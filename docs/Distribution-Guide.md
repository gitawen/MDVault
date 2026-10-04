# MDVault — Desktop Distribution & Packaging Guide (Windows)

This document provides a step-by-step manual setup and distribution guide for packaging **MDVault** as a standalone, production-hardened desktop application for Windows using NativePHP and Electron.

---

## 1. Overview & Architecture

MDVault's desktop runtime is powered by **NativePHP Desktop** and **Electron**:
- **Application Core**: Embedded PHP 8.4 runtime packaged with the local Laravel application.
- **Frontend SPA**: Vue 3 + Inertia v3 + Tiptap compiled to static bundles in `public/build/`.
- **Database**: Embedded SQLite running locally inside the user's OS application data directory (`%APPDATA%\com.mdvault.app\database.sqlite`).
- **Filesystem**: Direct local filesystem access for Markdown notes and vaults.
- **Installer**: Nullsoft Scriptable Install System (NSIS) creating a standard Windows `.exe` setup installer.

---

## 2. Prerequisites

Before building the distribution package, verify your local environment:
1. **PHP**: PHP 8.4+ with `sodium`, `zip`, `pdo_sqlite`, and `fileinfo` extensions enabled.
2. **Node.js**: Node.js v22.0.0 or higher, with `npm`.
3. **Composer**: Composer 2.x.
4. **Git**: Installed and available in PATH.

---

## 3. Configuration & Identity

MDVault's distribution settings are governed by `config/nativephp.php`:

| Setting | Value | Description |
|---|---|---|
| `version` | `1.0.0` | Release version of the desktop application |
| `app_id` | `com.mdvault.app` | Unique bundle identifier defining the `%APPDATA%` data directory |
| `author` | `MDVault` | Application author metadata |
| `copyright` | `Copyright © 2026 MDVault` | Copyright string embedded in executable properties |
| `updater.enabled` | `false` | Disabled by default for strictly local-first, offline execution |
| `updater.default` | `github` | Configured provider for GitHub Releases |
| `nsis.delete_app_data_on_uninstall` | `false` | Preserves vaults and databases if the app is reinstalled |
| `prebuild` | `['npm run build']` | Compiles latest frontend assets automatically before packaging |

---

## 4. Step-by-Step Distribution Build

Follow these exact steps in PowerShell or your terminal from the repository root (`c:\Users\cherw\Herd\MDVault`):

### Step 1: Clean Application Caches
Ensure stale cached configurations or dev settings do not leak into the build:

```powershell
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Step 2: Validate the Test Suite
Ensure code quality, formatting, and all unit/feature tests pass:

```powershell
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/PackagingConfigurationTest.php
```

### Step 3: Compile Frontend Assets (Optional if using prebuild hook)
The `config/nativephp.php` prebuild hook executes this automatically, but you can run it manually to confirm clean compilation:

```powershell
npm run build
```

Expected output:
- `public/build/manifest.json` and bundled assets generated in `public/build/assets/`.

### Step 4: Run the NativePHP Windows Build Command
Execute the distribution build targeting Windows 64-bit architecture:

```powershell
php artisan native:build win x64 --no-interaction
```

#### What happens during the build:
1. **Prebuild Hook**: Executes `npm run build` to compile production Vue/CSS assets.
2. **Dependencies**: Prunes dev dependencies and synchronizes Electron packages.
3. **PHP Binary**: Extracts the pre-packaged, audited PHP 8.4 binary (`php-8.4.zip`) into the build folder.
4. **Bundle Sanitization**: Strips development files (`tests/`, `docs/`, `.ai/`, `.agents/`, `.gemini/`, dev dotfiles) and sensitive `.env` keys.
5. **Electron Builder & NSIS**: Packages the Electron shell, sets application icons, and compiles the NSIS installer executable.

---

## 5. Locating Output Artifacts

Upon completion, all distribution packages are placed in:

```text
nativephp/electron/dist/
```

Key output files:
- **`mdvault-1.0.0-setup.exe`** (or `MDVault-1.0.0-setup.exe`): The standalone Windows installer. Distribute this file to end users.
- **`win-unpacked/`**: The unpacked portable application folder containing `MDVault.exe`. Useful for fast developer testing without running the installer.

---

## 6. Publishing Releases via GitHub Releases

MDVault is configured to use **GitHub Releases** for binary distribution (no S3 / AWS account required):

### Option A: Manual GitHub Release (Recommended)
1. Run the build command locally:
   ```powershell
   php artisan native:build win x64 --no-interaction
   ```
2. Navigate to your GitHub repository in your browser (e.g. `https://github.com/<owner>/<repo>/releases`).
3. Click **"Draft a new release"**.
4. Create a tag matching the version (e.g. `v1.0.0`).
5. Title the release (e.g. `MDVault v1.0.0 — Official Windows Release`).
6. Attach the generated installer and updater manifest from `nativephp/electron/dist/`:
   - `MDVault-1.0.0-setup.exe` (or `mdvault-1.0.0-setup.exe`)
   - `latest.yml` (used by electron-updater if updater is enabled)
   - `MDVault-1.0.0-setup.exe.blockmap`
7. Click **"Publish release"**. Users can now directly download the Windows installer from your GitHub Release page.

### Option B: Automated Publishing via NativePHP
If you want NativePHP to publish directly to GitHub Releases via CLI:
1. Configure your repository details in your `.env`:
   ```dotenv
   GITHUB_OWNER=your-github-username-or-org
   GITHUB_REPO=MDVault
   GITHUB_TOKEN=ghp_yourPersonalAccessTokenWithRepoScope
   ```
2. Run the publish command:
   ```powershell
   php artisan native:publish win x64
   ```
   This automatically builds the application and uploads the draft or published release assets directly to GitHub.

---

## 7. Manual Verification & QA Checklist

When testing the generated installer on a Windows machine:

1. **Clean Installation**:
   - Double-click `mdvault-1.0.0-setup.exe`.
   - Verify the NSIS setup wizard completes and creates a Desktop shortcut.
2. **First Launch**:
   - Launch MDVault from the Desktop shortcut.
   - Confirm the window opens with title `MDVault` at 1280×800 dimensions.
   - Confirm DevTools are closed by default (`APP_DEBUG=false`).
3. **Vault Creation & Markdown Editing**:
   - Create a new Vault (e.g. `My Notes`).
   - Create and edit a note with markdown formatting (headers, bold, lists).
   - Close the app and re-launch: confirm the window position is remembered (`rememberState()`) and notes are intact.
4. **Encryption & Security**:
   - Create or convert an encrypted vault.
   - Test password unlock and auto-lock (or manual lock via toolbar).
   - Check `storage/logs/laravel.log`: verify zero plaintext passwords or note contents are logged.
5. **Backup & Restore**:
   - Create a ZIP backup of a vault.
   - Test restore in a fresh vault: confirm all note files and folder structures restore accurately.
6. **Uninstall Safety**:
   - Uninstall MDVault via Windows Settings / Control Panel.
   - Verify user vault folders and `%APPDATA%\com.mdvault.app` remain intact on disk.

---

## 7. Troubleshooting & Common Questions

### Q: Windows SmartScreen shows "Windows protected your PC"?
- **Cause**: The executable is unsigned. SmartScreen warns about unfamiliar executables until they develop reputation or are signed with a trusted certificate.
- **Resolution for Testing**: Click **"More info"**, then click **"Run anyway"**.
- **Resolution for Official Release**: Configure Azure Trusted Signing or EV code signing credentials in `.env` (`NATIVEPHP_AZURE_*`).

### Q: How do I clean previous build artifacts?
Run the reset command:
```powershell
php artisan native:reset
```
This clears the `build/` and `nativephp/electron/dist/` directories.

### Q: Does the packaged app require an active Herd / Valet / Apache server?
- **No**. The packaged desktop application is completely standalone and embeds its own internal PHP server and SQLite database. No local web server or internet connection is required.
