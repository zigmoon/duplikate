# Changelog

All notable changes to Duplikate are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.0.1] — 28 October 2026

### Added

- **The plugin updates itself from its GitHub releases.** WordPress only ever asks `api.wordpress.org` for updates, so a plugin distributed through GitHub was invisible to the dashboard: every release had to be downloaded and uploaded by hand. The plugin now declares `Update URI` — the mechanism WordPress added in 5.8 for exactly this — so core skips the directory for it and asks us instead. The new version appears on the Plugins and Updates screens, "Update now" installs it, and automatic updates can be enabled like any other plugin.
- The check runs at most once every 12 hours, the answer is cached in a site transient, and a failed check is cached for an hour so an offline site never pays a 10 second timeout on every page load. The cache is dropped when the plugin is updated.
- **A switch to turn it off**, in Settings → Duplikate → Advanced. Off means no request at all.
- The "View details" modal is filled from the release: version, changelog, download link.

### Fixed

- **The slug suffix lost its leading dash.** `sanitize_title( '-copy' )` strips the separators, so a copy of `atelier` became `ateliercopy` instead of `atelier-copy`. The separators are now rebuilt around the sanitised body.
- **The settings screen no longer offers post types the engine refuses.** Patterns (`wp_block`) and navigation menus (`wp_navigation`) were tickable although `Duplicator::check()` always refuses them; the screen and the sanitiser now share the engine's list.

### Changed

- **The privacy claim is now accurate.** The README said "no outbound request", which stops being true the moment an update check exists. It now says what actually happens: one request to `github.com` every 12 hours, switchable off.

### Tests

- 22 new assertions over a recorded GitHub payload: tag normalisation, draft and pre-release rejection, archive choice, caching, the switch and the failure path. 132 unit assertions in total.

## [1.0.0] — 2026-10-28

First release.

### Added

- **Duplicate** link in the row actions of every post, page and public custom
  post type, gated by `current_user_can( 'edit_post', $id )`.
- **Bulk action** on each eligible list, with a per-run limit of 25 items and a
  report of what was copied, skipped or refused.
- **Block editor integration**: the same action as a row in the post status
  panel, built from the per-post nonce and shipped without a build step.
- **Classic editor button** in the publish box, for sites still using it.
- **Settings screen** under Settings → Duplikate, in three tabs: General, What
  is copied, Advanced. Every boolean is a real switch.
- Copies the page hierarchy (children reattached to the new parent), the
  taxonomy terms by id, the metadata with WordPress's slashing round trip, the
  featured image, the page template, the menu order and the comment status.
- Unique titles (`(copy)`, `(copy 2)`, …) and unique slugs.
- Transactional copy: the post is inserted first, and any failure discards it,
  so a duplication never leaves a half-written row behind.
- Depth (5) and child-count budget so a deep tree cannot exhaust the request.
- Author and date can be kept from the original or reset to the current user.
- Status of the copy: same as the original, draft (default), pending, private.
- WP-CLI: `wp duplikate duplicate <id>` and `wp duplikate settings`.
- Filters `duplikate_post_types`, `duplikate_can_duplicate`,
  `duplikate_post_data`, `duplikate_status`, `duplikate_taxonomies`,
  `duplikate_meta_skip`, `duplikate_meta_only`; actions
  `duplikate_post_duplicated` and `duplikate_duplicated`.
- English source strings with complete French and Spanish catalogues, built
  from the source by `tools/build-translations.php`.
- 174 assertions: 110 unit assertions with no WordPress required, and 64 over a
  mocked WordPress (boot, hooks, rendering).
- Multisite-aware uninstall, off by default, that removes only the plugin's own
  options and transients.

[1.0.1]: https://github.com/zigmoon/duplikate/releases/tag/v1.0.1
[1.0.0]: https://github.com/zigmoon/duplikate/releases/tag/v1.0.0
