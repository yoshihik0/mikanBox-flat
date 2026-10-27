# 🍊mikanBox flat

[日本語 README →](README.md)

**No database. No build step. No Node.**

🍊mikanBox flat is a free, file-based CMS for small and mid-sized sites. Upload it to any PHP host and edit in the browser — no database, no build tooling, no Node runtime.

**AI can build it; you can still read it.** An MCP server is built in, so agents read your site's structure and design conventions and write pages directly. Your pages stay Markdown and your data stays JSON in plain folders, so you can open them afterwards and see exactly what the agent did. The instructions you give the AI are a document on the site, not hidden state.

**No admin panel on the internet, if you want it that way.** Run mikanBox on your own machine and publish static files instead — the same product, a different operating style.

**[Try the live demo →](https://yoshihiko.com/mikanbox/demo/)**

> 🍊 *Mikan* is the Japanese word for a small, easy-to-peel mandarin orange — the fruit that gave mikanBox its name.

### Two ways to run it

|  | Where the admin lives | Attack surface |
| :--- | :--- | :--- |
| **Hosted** | On your server, in the browser | PHP, no database |
| **Local + static publish** | On your own machine | None — you upload generated files only |

The second style is what makes mikanBox unusual: the admin panel never faces the internet, yet you still edit in a browser rather than in a text editor. Build to a folder and upload it anywhere that serves static files — Cloudflare Pages, a CDN, or plain shared hosting.

### Two editions

**🍊mikanBox flat** (this repository) stores content as JSON files and suits sites up to roughly a hundred pages. **🍊mikanBox** uses SQLite and stays comfortable as the page count grows, adding revision history, an approval workflow and admin search. Content migrates between the two, so starting here does not lock you in.

[Compare the two editions →](#flat) · [SQLite edition 🍊mikanBox →](https://github.com/yoshihik0/mikanBox)

---

## Features

- **File-based (JSON)** — No database required. Just place on a PHP-enabled server
- **Your content stays readable, and yours** — Pages are Markdown and JSON in plain folders: grep it, diff it, back it up with `cp -r`, walk away with it. No database export, no API, no lock-in — while the admin panel still handles the things files cannot, like updating every reference to a renamed image
- **Modeless admin** — Almost everything happens on one screen, with no page reloads
- **Markdown Support** — Easy to edit content, and also well-suited as a content archive
- **Filter by Category** — Narrow down pages and images by category; use categories as workspaces
- **Reference images by filename** — No file paths to think about
- **Component Structure** — Page templates and parts you can combine and reuse
- **Per-component Scoped CSS** — Write CSS in small scopes without worrying about interference
- **Paste AI-generated code as-is** — HTML, CSS and JS go straight in; no manual design pass needed
- **AI Agent Integration (MCP)** — AI understands the site structure and design conventions, and reads/writes files directly
- **DESIGN.md Management** — Manage instructions for AI right on the site, and hand them to AI
- **Multimodal AI Support** — AI generates images and sends/places them directly in the media folder
- **Static, dynamic, or mixed** — Choose per page; publish a fully static site or keep some pages dynamic
- **DB Less DB** — A page entry doubles as a data table: write rows in the page, read them anywhere, serve them as JSON. Works as a headless CMS
- **Podcast** — Auto-generate RSS for podcast distribution

---

<a name="flat"></a>

## JSON Version (flat, this repo) vs. SQLite Version

| | 📂 JSON version (flat, this repo) | 🗄️ SQLite version |
| :--- | :--- | :--- |
| Best for | Sites with fewer pages (up to ~100 or so) | Sites that will keep growing in page count |
| Storage | JSON files | (single DB file) |
| Requirements | PHP 8.0+ only | PHP 8.0+ + SQLite3 extension (standard on most hosting providers) |
| Approval workflow & preview share URL | − | ○ |
| Revision history & restore to a previous version | − | ○ |
| Sign-in | Password | Registered devices (no password) |
| Keyword search box in the admin panel | − | ○ |

> [!TIP]
> For campaign or event sites, shop sites — sites with few pages but that need regular updates — use **this 🍊mikanBox flat**. For sites you'll run long-term and expect to keep growing, use **🍊mikanBox**. Data can be migrated between the two, so you can switch later.

[SQLite version 🍊mikanBox →](https://github.com/yoshihik0/mikanBox)

---

## Requirements

- A web server with PHP 8.0 or later (no database required)
- Locally, you don't even need a server: just open the folder containing 🍊mikanBox flat with an AI coding tool like Claude Code, and the AI can build the site and manage content directly. Export as static files and upload via FTP.

---

## Installation

1. Upload the `mikanBox` folder and `index.php` to your server
2. For security, we recommend renaming the `mikanBox` folder to a name of your choice (if renamed, also update the `$core_dir` variable at the top of `index.php` to match)
3. Access `{your-admin-folder}/admin.php` to set your admin password

Site-specific data is created on first access in `mikanData` beside `index.php`. Existing `mikanBox/data` sites are migrated automatically only when the directory can be moved safely.

That's all.

### Running it locally

You can run it on your own machine instead of a server. PHP is the only requirement.

```bash
php -v                      # 8.0 or later (on macOS: brew install php)
cd /path/to/mikanbox        # the folder containing index.php
php -S localhost:8000
```

Open `http://localhost:8000/mikanBox/admin.php`, set a password, and you are done. `mikanData` and `media` are created for you.

Open this folder in an AI coding tool and the agent can read and write your pages' Markdown and JSON directly. To publish, upload the folder that static generation produces — **no PHP, no data and no admin panel go to the public side**.

---

## Intended Use & Scale

**Ideal for:** Personal sites, small business/corporate sites, event pages, and portfolios (Guideline: up to ~100 pages)

---

## Basic Usage

### Two Operating Styles

**Continuous Content** (blogs, service pages, etc.)

- Write in Markdown and reference images by filename only
- Well-suited for ongoing updates and content reuse

**Short-term Pages** (landing pages, event pages, etc.)

- Paste AI-generated HTML/CSS/JS directly to publish
- No manual design work needed

### Design (Components)

- **Page Components** — Wrappers that define the overall layout of a page
- **Parts Components** — Reusable parts embedded in pages or other components
- **AI-Instruction Components** — Things like DESIGN.md, managing instructions read by AI as components too

Components contain HTML and scoped CSS, and can be nested.

### Static Site Generation (SSG)

Export all pages as static HTML with a single click. You can also mix static and dynamic pages. Choose between generating pages in the current server or creating an upload-ready folder containing portable relative links and a copied media folder.

### Page Publish Status

| Status | Behavior |
| :------- | :------- |
| Draft | Private (admin only) |
| Public (Dynamic) | Served dynamically via PHP |
| Public (Static) | Exported as static HTML |
| DB | Page itself is private; exposes data as an API |

---

## AI Integration

🍊mikanBox is designed with a strong focus on compatibility with AI tools.

- AI-generated HTML pages can be pasted directly into the content field for instant publishing
- The codebase is compact and simply structured, making it easy for AI to understand the specifications
- MCP support allows AI agents to understand the site structure and directly edit/update content or components
- Multimodal input (like images) from AI can be received directly, automating uploads to the media folder and placement on pages
- AI instructions are managed as components too — hand DESIGN.md or BRAND.md to AI, or have AI generate them for you
- Simple specifications mean it's easy to have AI add new functionality

### MCP Support

mikanBox supports the Model Context Protocol (MCP), providing a native connection for AI agents to safely operate on server files. This lets page creation, design changes, and component building all happen through conversation with AI alone, without the user ever touching the admin panel.

The administration MCP supports MCP 2026-07-28 and initialize-based MCP 2025-11-25, 2025-06-18, and 2025-03-26. Send the administration API key in an `Authorization: Bearer` or `X-API-Key` header. Claude custom connector OAuth Client ID and Client Secret fields are not API-key fields, so the administration MCP cannot be registered there until mikanBox supports OAuth.

When several mikanBox sites are connected at once, the `get_site_info` tool returns the site's immutable ID, name, public URL, and environment (production, staging, development, or local). AI can verify the target before write operations, reducing the risk of editing the wrong site.

Starting with 2.5.1, AI calls `get_ai_context` after connecting to load DESIGN.md, BRAND.md, CONTENTS.md, and every other AI-instruction component in one response. Instructions remain independently manageable while reaching AI efficiently as site-specific project guidance.

---

## Data Embedding & API

### Loading Other Pages or External Markdown

You can load other pages or Markdown files from external sites like GitHub. This is handy for embedding frequently updated sections (like news) inside otherwise complex pages.

### Embed Data in a Page

```
{{DATA:price:GHOST}}4800{{/DATA}}
```

Reference from the same page: `{{POST_MD::price}}`
Reference from another page: `{{POST_MD:pageID:price}}`

### Table-style Data (DB Less DB)

A page entry doubles as a data table — a database without a database. Write rows in the page body, then read any single field from another page, or serve the whole set as JSON.

```
{{DATAROW:row1}}
  {{DATA:name}}Product A{{/DATA}}
  {{DATA:price}}4800{{/DATA}}
{{/DATAROW}}
```

Reference: `{{POST_MD:pageID#row1:name}}`

### Publish as an API

Set a page's status to **DB** to expose its data externally as a JSON API. It can also be used as a headless CMS.

```
https://yoursite.com/api/pageID
```

### CSV Import

The site menu includes a built-in feature to bulk-convert CSV files (from Excel, etc.) into `{{DATAROW}}` format.

---

## Podcast Distribution

Set a page's category to `podcast` and embed an audio file to auto-generate an RSS feed at:

```
https://yoursite.com/podcast.xml
```

Then simply submit that feed to Apple Podcasts, Spotify, Amazon Music, or other platforms.

---

## Sitemap · RSS

| URL | Content |
| :---| :---|
| `/sitemap.xml` | XML Sitemap |
| `/rss.xml`     | RSS Feed |
| `/podcast.xml` | Podcast RSS (podcast category only) |

---

## Security

- Since no database is used, there is no attack surface for SQL injection
- Small codebase with no dependency on plugins
- **Running locally and uploading static files leaves nothing to attack.** No PHP, no JSON, and no admin panel are published; the site is generated files, and the originals stay on your machine
- Renaming the admin directory makes the URL harder to guess
- `.htaccess` restricts direct access to management files

---

## Documentation

- [日本語ヘルプ →](https://yoshihiko.com/mikanbox/help_ja.html)
- [English Help →](https://yoshihiko.com/mikanbox/help_en.html)

---

## License

MIT License — Copyright (c) 2026 [yoshihiko.com](http://yoshihiko.com)
