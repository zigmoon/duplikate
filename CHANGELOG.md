# Changelog

All notable changes to Duplikate are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

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

[1.0.0]: https://github.com/zigmoon/duplikate/releases/tag/v1.0.0
