=== YLabs AI Site Manager with ChatGPT and Claude ===
Contributors: ylabs
Tags: mcp, chatgpt, claude, ai assistant, connector
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.16.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to WPWriter. Manage posts, SEO and media from ChatGPT or Claude. A WPWriter account is required.


== Description ==

Manage your self-hosted WordPress site from ChatGPT or Claude with WPWriter. Ask WPWriter to draft, edit or schedule posts, update SEO fields and add media to your site.

YLabs AI Site Manager is the WordPress connector for WPWriter, a service made by YLabs. The plugin pairs your site with your WPWriter account so that WPWriter can carry out the content requests you make.

*WPWriter is made by YLabs and is not affiliated with OpenAI, Anthropic or the WordPress Foundation. ChatGPT, Claude and WordPress are trademarks of their owners.*

WPWriter uses a **BYOK (Bring Your Own Key)** model: connect your OpenAI, Anthropic (Claude), or Google (Gemini) API key and pay the AI providers directly at their standard rates. You keep full control over your AI costs.

With **auto-blogging**, build a topic queue, choose your templates and AI models, schedule content runs, and control whether completed posts stay as drafts or publish only after configured quality checks pass.

A free WPWriter account includes 15 AI-generated posts and 20 AI-generated images. No credit card required.

= Connect in ChatGPT or Claude =

After you install the plugin, its Connect page shows these steps.

In ChatGPT: open Plugins in the sidebar, find WPWriter and click Install plugin. If ChatGPT offers Chat and Work, use Chat.

In Claude: open Customize → Connectors, choose + Add, then Add custom connector. Enter the name WPWriter and the URL https://mcp.wpwriter.com/mcp, go through Claude's steps and click Add, then sign in with your WPWriter account. Claude's Free plan allows one custom connector.

Then say "Connect my WordPress site" and approve the request in your WordPress admin.

= What Can You Do With WPWriter? =

* **AI Content Creation** — Generate complete, publish-ready articles and pages with a guided wizard: content settings, design settings, media selection, and generation
* **Auto-Blogging** — Schedule content from a topic queue with draft-first review or quality-gated auto-publishing controls
* **AI Image Generation** — Create images with DALL-E, Imagen, Nanobanana (Gemini), Stability AI and other models, with optimization and batch upload
* **AI SEO Optimization** — Automatically generate SEO titles, descriptions, and supported focus keywords with AI, supporting Yoast SEO, Rank Math, and All in One SEO
* **AI Content Improvement** — Improve, expand, and rewrite existing posts with AI assistance
* **AI Image Enhancement** — Upscale, enhance, or reimagine existing images
* **Site Content Health** — Monitor SEO performance, identify weak pages, and get actionable improvement recommendations
* **Content Ideas** — Get AI-powered topic suggestions tailored to your niche
* **Content & Design Templates** — Use built-in templates or create your own for consistent content style and HTML design across posts
* **Shortcode Embedding** — Embed contact forms, videos, product grids, or any WordPress shortcode into AI-generated content
* **Featured Image Management** — Upload, optimize, and set featured images with automatic resizing and quality control
* **Multi-Site Management** — Manage multiple WordPress sites from one WPWriter dashboard
* **Post Type Conversion** — Convert posts to pages or pages to posts with a single click
* **Category & Tag Management** — Create and organize taxonomies directly from WPWriter

= Content for Any Scenario =

WPWriter uses customizable prompt templates to create content for virtually any type of page:

* **Articles & Blog Posts** — Expert pieces, friendly advice, technical guides, creative storytelling, SEO-balanced articles
* **Product Pages** — Feature showcases, comparisons, quick overviews with specs
* **Pages with Forms & Media** — Contact pages, service pages with quote forms, video tutorial pages
* **Home Pages** — Landing pages with compelling copy and structured layouts
* **Custom Content** — Write your own prompt or customize existing templates

Every template is open-source and free to use. Modify them or build your own from scratch.

= Supported AI Models =

Choose a model for each task:

**Text & Content:**
* **Claude** (Anthropic) — Opus 4.6, Sonnet 4.5, Haiku 4.5, and more
* **Gemini** (Google) — 2.5 Pro, 2.5 Flash, 2.0 Flash, and more
* **GPT** (OpenAI) — GPT-4o, GPT-4.1, o3, o4-mini, and more

**Image Generation:**
* **Nanobanana** (Gemini) — Realistic images
* **Imagen 4 Ultra / Imagen 4** (Google)
* **DALL-E 3 / DALL-E 2** (OpenAI)

= Plain HTML Output =

WPWriter writes plain, semantic HTML that your theme styles. It does not need a page builder.

= What WPWriter Can and Cannot Do on Your Site =

WPWriter acts as the WordPress user you pair with, but always with the limits below, even if that user is an Administrator.

WPWriter can:

* create, edit, schedule, publish and delete posts and pages
* upload and edit media, and manage categories, tags and comments
* save full HTML in posts and pages (styles, embeds and structured data), as a WordPress Editor can
* edit menus, menu locations and widgets
* read the active theme and which SEO plugin is installed, and save SEO fields on posts
* edit WooCommerce products, if WooCommerce is installed
* read the site display settings, and change them only when you pair as an Administrator (site title, tagline, homepage settings, posts per page, date and time format, timezone, week start, site icon, site logo, default comment and ping status)

WPWriter cannot:

* install, activate, update, edit or delete plugins or themes, or update WordPress
* create, edit, delete or list users, or change anyone's role
* change any other setting, such as the admin email, membership, permalinks or search engine visibility
* read passwords, API keys or other stored secrets from your site
* edit theme or plugin files, upload unfiltered file types, or import or export site data

= Security =

* Token-based authentication — no WordPress passwords stored or transmitted
* Pairing codes expire after 10 minutes
* Each connection can be individually revoked
* All API requests are authenticated and validated
* Supports multiple simultaneous WPWriter account connections
* Every WPWriter request is limited to content work, even for an Administrator: the plugin removes plugin, theme, update, user, file, import/export, unfiltered upload and general-settings capabilities for the duration of each request
* No stored secret ever leaves the site: there is no endpoint that reads API keys or passwords
* The plugin respects a host or security plugin that turns off WordPress Application Passwords

= External Service Disclosure =

This plugin connects to WPWriter (wpwriter.com) to enable content management features. When connected:

* Your site URL and connector authentication details are associated with your WPWriter account so authenticated site-management requests can be made
* Content you create, sync, publish, or schedule through auto-blogging is transmitted between WPWriter and your WordPress site as required to perform those actions
* The plugin never sends passwords, API keys or other stored secrets from your site to WPWriter

[WPWriter Terms of Service](https://www.wpwriter.com/terms)
[WPWriter Privacy Policy](https://www.wpwriter.com/privacy)

== Installation ==

**Setup takes about 2 minutes:**

1. Create a free account at [wpwriter.com](https://www.wpwriter.com).
2. Install the plugin: upload the `ylabs-connector-for-wpwriter` folder to `/wp-content/plugins/`, or install directly through **Plugins > Add New** in WordPress.
3. Activate the plugin through the **Plugins** screen.
4. Go to **WPWriter** in your WordPress admin menu.
5. Click **Generate Pairing Code** and enter a name for this connection (e.g., "My Laptop").
6. Copy the pairing code and paste it into your WPWriter dashboard to connect.

== Frequently Asked Questions ==

= Is WPWriter free? =

Yes. The free plan includes 15 AI-generated posts and 20 AI-generated images for one WordPress site. No credit card required. Paid plans unlock more sites, higher word counts, and additional features.

= Do I need my own AI API key? =

Yes. WPWriter uses a BYOK (Bring Your Own Key) model — you connect your own API keys for OpenAI, Anthropic (Claude), or Google (Gemini). This means zero markup on AI costs. You pay the AI providers directly at their standard rates.

= Can WPWriter publish blog posts automatically? =

Yes. Auto-blogging lets you build a topic queue and schedule AI-generated posts. You control publication behavior: keep posts as drafts for review or allow publishing after your configured quality checks pass.

= Can I import an AI key saved in WordPress Settings → Connectors? =

No. Since version 1.15.0 the plugin never sends a stored key or other secret to WPWriter. Enter your AI key in WPWriter directly.

= Can WPWriter install plugins or themes, or change my permalinks? =

No. The plugin has no endpoints for plugins, themes or permalinks, and since version 1.15.0 every WPWriter request runs without those capabilities even when you pair as an Administrator. Make those changes in your WordPress admin. Of the general settings, WPWriter can change only 14 display settings, such as the site title, tagline and homepage, and only when you pair as an Administrator.

= Is my WordPress password shared with WPWriter? =

No. This plugin uses secure token-based authentication. Your WordPress password is never transmitted or stored by WPWriter.

= Will this slow down my WordPress site? =

AI processing runs on WPWriter's servers, not on your WordPress server.

= Which SEO plugins are supported? =

WPWriter can automatically generate and set SEO titles, descriptions, and supported focus keywords for:
* Yoast SEO
* Rank Math
* All in One SEO

= Are there any recommended companion plugins? =

These plugins work well with WPWriter content. Install them yourself from the plugin directory if you want them:

* **[Classic Editor](https://wordpress.org/plugins/classic-editor/)** — WPWriter generates HTML optimized for the classic editor
* **A Lightbox plugin** (e.g., [Simple Lightbox](https://wordpress.org/plugins/simple-lightbox/) or [Easy FancyBox](https://wordpress.org/plugins/easy-fancybox/)) — For full-screen image viewing in galleries
* **An SEO plugin** (Yoast SEO, Rank Math, or All in One SEO) — For AI-generated SEO metadata

= Can I connect multiple WPWriter accounts? =

Yes. Generate multiple pairing codes to connect different WPWriter accounts or devices to the same WordPress site.

= How do I disconnect? =

Go to **WPWriter** in your WordPress admin menu. You'll see connected accounts with a **Disconnect** button next to each one.

= What happens if I deactivate the plugin? =

Your connections are preserved. Reactivate anytime and existing connections still work.

= What happens if I delete the plugin? =

All connection data and settings are removed from your WordPress database.

== Screenshots ==

1. Multi-site dashboard — manage all your WordPress sites from one place
2. Post list with SEO scores — see content quality and SEO grades at a glance
3. AI Editor: Content Settings — configure topic, guidelines, and content templates
4. AI Editor: Design Settings — control visual layout with design templates
5. AI Editor: Media & Images — select and manage images for your content
6. AI Editor: Generate — choose your AI model and generate complete content
7. Analytics dashboard — track AI usage, costs, and content performance
8. The Connect page: how to connect your site in ChatGPT and Claude.

== Changelog ==

= 1.16.0 =
* Added a Connect page that shows how to connect your site in ChatGPT and Claude, with an optional check that this site's REST API answers.

Earlier versions are listed in changelog.txt.

== Upgrade Notice ==

= 1.16.0 =
Adds a Connect page with the steps for ChatGPT and Claude.

= 1.15.0 =
WPWriter now works with content-level permissions only, cannot read stored API keys, and can no longer list your plugins. Changing site display settings needs pairing as an Administrator.

= 1.14.0 =
WPWriter can no longer install or change plugins, themes, permalinks, search engine visibility or custom CSS on your site. Make those changes in your WordPress admin.

= 1.13.1 =
Security hardening. Please update.
