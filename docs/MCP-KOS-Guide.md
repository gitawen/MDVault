# MDVault MCP KOS — Quick Start Guide

Use **MDVault** as a local Knowledge Operating System (KOS) for AI agents (Claude Desktop, Cursor, Antigravity, Codex, Hermes, etc.) via Laravel MCP (`php artisan mcp:start mdvault`).

---

## Step 1: Set Up Your `AI` Workspace (One Click)

1. Open **MDVault** and go to **Settings → AI & MCP**.
2. Click **Provision AI Workspace**.
   - This creates your **`AI`** vault and a starter **`AI/about-me.md`** note.
3. Open `AI/about-me.md` in MDVault and fill in:
   - Who you are and your tech stack
   - Active projects
   - Your standing coding rules (e.g., *"Use the simplest thing that works; don't overcomplicate; always verify tests"*)
   - Keep it short (~2,000 characters) so agents load it fast at the start of every session.

---

## Step 2: Connect Your AI Client

1. On **Settings → AI & MCP** (or by running `php artisan mdvault:mcp-config` in your terminal), copy the **JSON** (or **Codex TOML**) config snippet.
2. Paste it into your AI client's MCP configuration file:
   - **Claude Desktop**: `claude_desktop_config.json`
   - **Cursor**: `.cursor/mcp.json`
   - **Codex**: `config.toml`
   - **Antigravity / other MCP clients**: your client's `mcpServers` config
3. Restart or reload your AI client so it connects to the `mdvault` MCP server.
   - The server runs locally via `php artisan mcp:start mdvault`, so it works offline even when the MDVault desktop window is closed.

---

## Step 3: Start a Session & Work on a Project

1. **Load your context first**:
   > *"Load my MDVault context (`get_kos_context`) and check my skills."*

2. **Start a new project** (e.g., `HRIS`):
   > *"Initialize an AI project called `HRIS` in MDVault."*
   - The agent calls `init_ai_project`, which scaffolds `AI/HRIS/` with `overview.md`, `tasks.md`, `receipts.md`, and `skills/`.

3. **Use your personal vaults safely**:
   - **Personal (unencrypted) vaults**: Agents can **search, read, create, append, and update** notes (using `file_hash` optimistic locking so open edits in MDVault are never silently overwritten), but **cannot delete or reorganize** notes or folders.
   - **`AI` vault**: Agents have full CRUD over notes and folders inside `AI/`.
   - **Encrypted vaults**: Excluded and inaccessible in v1.

4. **Save reusable skills & receipts**:
   - When a workflow works (e.g., deployment, debugging, project setup), tell the agent:
     > *"Save this as a skill in `AI/HRIS/skills/` using `save_skill`."*
   - After completing work, tell it:
     > *"Log the verified test/command results in `AI/HRIS/receipts.md`."*

---

## Step 4: Test & Verify Your Connection

Once your AI client is connected to the `mdvault` MCP server, run these quick smoke-test prompts in a chat session:

1. **Verify Vault Discovery & Core Memory**:
   > *"List my MDVault vaults with `list_vaults` and load my context with `get_kos_context`."*
   - Confirms `AI` has `full_crud` (`ai_workspace`), personal unencrypted vaults have `read_safe_write`, encrypted vaults are excluded, and `AI/about-me.md` loads cleanly.

2. **Test Search Across Personal Vaults**:
   > *"Search my MDVault notes for `git` or `mysql` using `search_notes`."*

3. **Test Project Scaffolding in `AI/`**:
   > *"Initialize an AI project called `HRIS` in MDVault using `init_ai_project`."*
   - Open the `AI` vault in MDVault afterward to verify `HRIS/overview.md`, `tasks.md`, `receipts.md`, and `skills/` were created.

4. **Test `AI/about-me.md` Reload**:
   - Edit `AI/about-me.md` in MDVault with your developer profile and standing rules, then ask:
     > *"Reload my MDVault context with `get_kos_context` and summarize my rules."*
