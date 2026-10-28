=== Duplikate ===
Contributors: zigmoon
Tags: duplicate post, duplicate page, clone, copy, content
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One click under any title: a draft copy with its hierarchy, its terms and its metadata — and nothing left half-written if something fails.

== Description ==

Duplikate adds a **Duplicate** link to the row actions of every post, page and public custom post type, a bulk action to the lists, a row in the post status panel of the block editor and a button in the classic editor. The copy is always a **draft**, so nothing goes public by accident.

**What is copied**

* Title and content, with the suffix and the slug made unique automatically: `(copy)`, `(copy 2)`, `(copy 3)` — a title that already carries the suffix is never suffixed twice.
* The page template, the featured image, the menu order, the comment and ping status.
* Every taxonomy term, by **term id**, so two terms with the same name can never be confused.
* Every custom field, with WordPress's own slashing round trip — a backslash in an ACF field or a JSON blob survives the copy.
* Child pages, recursively, reattached to the new parent. Depth and total count are capped so a deeply nested tree cannot exhaust the request.

**What is never copied** — revisions, comments, edit locks, the trash state, old-slug markers, attachments, navigation menu items and reusable blocks. A duplicate is content, not a second history.

**Never a corrupt database** — the copy is inserted first and every later step runs inside a `try`/`catch` with `Throwable`. If anything fails, the partial copy is discarded before the error is reported, so a failed duplication leaves no orphan post, no orphan row in the term relationships table and no half-copied branch.

**Built for the current stack** — WordPress 6.5 through 7.1, PHP 8.2 through 8.5. Only maintained WordPress APIs, no deprecated calls, no Composer or npm build step. The single outbound request is the update check against GitHub, once every 12 hours, and it can be switched off. The test suite runs on PHP 8.3 and PHP 8.5.

**Quality**

* Strictly escaped output, sanitised input, capability checks (`current_user_can( 'edit_post', $id )`) and nonces on every admin action.
* Small, single-responsibility classes: settings, duplicator, admin, CLI.
* 174 automated assertions ship in the `tests/` directory (unit tests plus a boot/render smoke test).
* English source strings, complete French and Spanish translations, `.pot` catalogue.
* Data cleanup on uninstall (off by default), multisite-aware; deactivation never touches your content.

**Features**

* Master switch, so every Duplicate link disappears without a single row being touched.
* Choose which post types get the link; the list is read from the registered types, so a plugin added later shows up on its own.
* Choose the status of the copy: same as the original, draft (default), pending or private.
* Title suffix and slug suffix, both editable.
* After duplicating: stay on the list or open the new draft in the editor.
* Keep the original author and the original date.
* Copy child pages, taxonomies, custom fields, the featured image and the page template, each on its own switch.
* Bulk action with a per-run limit and a clear report of what was copied, skipped or refused.
* WP-CLI: `wp duplikate duplicate <id>` and `wp duplikate settings`.
* Filters for integration with bespoke workflows: `duplikate_post_types`, `duplikate_can_duplicate`, `duplikate_post_data`, `duplikate_status`, `duplikate_taxonomies`, `duplikate_meta_skip`, `duplikate_meta_only`, and the actions `duplikate_post_duplicated` and `duplikate_duplicated`.

== Installation ==

1. Upload the `Duplikate` folder to `/wp-content/plugins/`, or install the ZIP from Plugins → Add New → Upload Plugin.
2. Activate the plugin through the **Plugins** screen.
3. Open **Settings → Duplikate** to choose the post types, the status of the copy and what is copied.
4. Go to **Posts** or **Pages**: a **Duplicate** link now sits under every title.

Nothing is configured by default beyond a sensible set of values: pages and posts, drafts, hierarchy, terms and metadata.

== Frequently Asked Questions ==

= Will the copy be published? =

No. The default status is **Draft**, and it is the safe choice: nothing appears publicly until someone publishes it. You can change it in Settings → Duplikate.

= Does it duplicate the child pages too? =

Yes, when **Copy child pages** is on. The whole branch is copied and every child is reattached to the new parent, with a depth limit of five and a configurable total cap.

= Are the categories, tags and custom taxonomies copied? =

Yes, by term id, so an existing term is reused and no duplicate term is created.

= Are the custom fields copied? =

Yes, except edit locks, old slugs and the trash markers. Two filters, `duplikate_meta_skip` and `duplikate_meta_only`, let you change the list precisely.

= Is the featured image file duplicated? =

No. The copy points at the same attachment, so the media library does not grow.

= What happens if something fails halfway? =

The partial copy is discarded and you get an error notice. Duplikate never leaves half-written content behind.

= Does it work with custom post types? =

Yes. Tick the type in Settings → Duplikate. Private and internal types (`revision`, `attachment`, `nav_menu_item`, `wp_block`, templates, fonts) are never offered.

= Can I use it on a multisite install? =

Yes. Settings are stored per site, and uninstall walks every site when data removal is enabled.

= Which PHP versions are supported? =

PHP 8.2 to 8.5, and WordPress 6.5 to 7.1.

= How do updates work? =

The plugin is published on GitHub rather than in the WordPress.org directory, so it brings its own update check: it reads its latest release from github.com and reports it on the Plugins and Updates screens, where "Update now" and automatic updates work exactly as they do for a directory plugin. The check runs every 12 hours at most and can be switched off in Settings → Duplikate → Advanced.

== Screenshots ==

1. The Duplicate link under every title.
2. Settings → Duplikate, General.
3. What is copied.
4. Advanced.
5. Bulk action report.

== Changelog ==

= 1.0.1 =
* The plugin now updates itself from its GitHub releases: the dashboard shows the new version, "Update now" installs it, and automatic updates can be enabled as for any other plugin. The check runs once every 12 hours and can be switched off in Advanced.
* Fix: the slug suffix lost its leading dash, so a copy of "atelier" became "ateliercopy" instead of "atelier-copy".
* The settings screen no longer offers post types the engine always refuses (patterns, navigation menus).

= 1.0.0 =
* First release.
* Duplicate link in the row actions, bulk action per post type, button in the classic editor.
* Hierarchy, taxonomies, metadata, featured image and page template, each optional.
* Draft-first statuses, transactional copy with rollback, depth and count caps.
* Settings screen, WP-CLI commands and filters.

== Upgrade Notice ==

= 1.0.1 =
Adds the update check, so the next release arrives without a manual upload. Nothing to reconfigure.

= 1.0.0 =
First release.
