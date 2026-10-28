<?php
/**
 * Unit tests. No WordPress, no database: `tests/bootstrap.php` is the world.
 *
 * Run with: php tests/run.php
 *
 * @package Duplikate
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/Duplikate/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/Duplikate/includes/class-duplicator.php';

use Duplikate\Duplicator;
use Duplikate\Settings;

$GLOBALS['dup_pass'] = 0;
$GLOBALS['dup_fail'] = 0;

function dup_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		++$GLOBALS['dup_pass'];
		return;
	}

	++$GLOBALS['dup_fail'];
	echo "FAIL: {$message}\n";
}

function dup_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['dup_pass'];
		return;
	}

	++$GLOBALS['dup_fail'];
	echo 'FAIL: ' . $message . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function dup_grants( bool $allowed = true ): void {
	$GLOBALS['dup_can'] = array( '__default__' => $allowed, 'edit_post' => $allowed, 'manage_options' => $allowed );
}

function dup_settings( array $overrides = array() ): Settings {
	update_option( Settings::OPTION, array_merge( Settings::defaults(), $overrides ), false );

	return new Settings();
}

function dup_count_posts(): int {
	return count( $GLOBALS['dup_posts'] );
}

/* -------------------------------------------------------------------------
 * Settings.
 * ---------------------------------------------------------------------- */

$defaults = Settings::defaults();

dup_same( true, $defaults['enabled'], 'the plugin is on out of the box' );
dup_same( 'draft', $defaults['status'], 'the default status of a copy is draft' );
dup_same( array( 'post', 'page' ), $defaults['post_types'], 'posts and pages are enabled by default' );
dup_same( ' (copy)', $defaults['title_suffix'], 'the default title suffix' );
dup_same( '-copy', $defaults['slug_suffix'], 'the default slug suffix' );
dup_same( true, $defaults['delete_data'], 'uninstalling removes the plugin data by default' );
dup_assert( ! str_contains( (string) $defaults['title_suffix'], 'copy 2' ), 'defaults contain no translated string' );

$clean = Settings::sanitize(
	array(
		'enabled'      => '1',
		'status'       => 'publish-sneaky',
		'redirect'     => 'elsewhere',
		'post_types'   => array( 'post', 'ghost', 'page', 'post', array( 'x' ) ),
		'title_suffix' => "  (copie)\n ",
		'slug_suffix'  => ' Copie! ',
		'max_children' => '9999',
		'row_action'   => '0',
	)
);

dup_same( true, $clean['enabled'], 'a flag is stored as a boolean' );
dup_same( 'draft', $clean['status'], 'an unknown status falls back to draft' );
dup_same( 'edit', $clean['redirect'], 'an unknown redirect falls back to the editor' );
dup_same( array( 'post', 'page' ), $clean['post_types'], 'unregistered types are dropped and duplicates removed' );
dup_same( ' (copie)', $clean['title_suffix'], 'the title suffix is flattening to one line and keeps its leading space' );
dup_same( ' (copy)', Settings::sanitize( array( 'title_suffix' => ' (copy)' ) )['title_suffix'], 'a leading space typed by the administrator is preserved' );
dup_same( ' — copie', Settings::sanitize( array( 'title_suffix' => ' — copie ' ) )['title_suffix'], 'the leading space of a dash suffix survives, the trailing one does not' );
dup_same( ' (copy)', Settings::sanitize( array( 'title_suffix' => '   ' ) )['title_suffix'], 'an empty suffix falls back to the default' );
dup_same( '(copy)', Settings::sanitize( array( 'title_suffix' => '(copy)' ) )['title_suffix'], 'a suffix typed without a space stays without a space' );dup_same( 'copie', $clean['slug_suffix'], 'the slug suffix is sanitised' );
dup_same( '-copie', Settings::sanitize( array( 'slug_suffix' => ' -copie ' ) )['slug_suffix'], 'the leading dash of the slug suffix survives' );
dup_same( '_copie', Settings::sanitize( array( 'slug_suffix' => '_copie' ) )['slug_suffix'], 'an underscore separator survives' );
dup_same( '-copy', Settings::sanitize( array( 'slug_suffix' => '-' ) )['slug_suffix'], 'a separator on its own falls back to the default' );
dup_same( 200, $clean['max_children'], 'the child budget is capped' );
dup_same( false, $clean['row_action'], 'an unchecked switch is false' );
dup_same( array( 'post', 'page' ), Settings::sanitize( array( 'post_types' => 'not-an-array' ) )['post_types'], 'a malformed type list falls back to the default' );

$GLOBALS['dup_post_types'][] = 'wp_block';
dup_same(
	array( 'post' ),
	Settings::sanitize( array( 'post_types' => array( 'wp_block', 'post' ) ) )['post_types'],
	'a type the engine never duplicates cannot be stored'
);
$GLOBALS['dup_post_types'] = array( 'post', 'page', 'revision' );
dup_same(
	array( 'post', 'page' ),
	Settings::sanitize( array( 'post_types' => array( 'revision', 'post', 'page' ) ) )['post_types'],
	'a revision cannot be stored as a duplicable type'
);
$GLOBALS['dup_post_types'] = array( 'post', 'page' );
dup_same( 'draft', Settings::sanitize( array( 'status' => array( 'draft' ) ) )['status'], 'an array is never a status' );
dup_same( 'pending', Settings::sanitize( array( 'status' => 'pending' ) )['status'], 'a known status is kept' );

$settings = dup_settings( array( 'post_types' => array( 'post', 'ghost' ) ) );
dup_same( array( 'post' ), $settings->post_types(), 'the stored list is intersected with the registered types on read' );
dup_assert( $settings->allows_post_type( 'post' ), 'a registered type is allowed' );
dup_assert( ! $settings->allows_post_type( 'ghost' ), 'an unregistered type is refused' );

$filtered = dup_settings();
add_filter(
	'duplikate_post_types',
	static function ( array $types ): array {
		$types[] = 'product';

		return $types;
	}
);
dup_assert( $filtered->allows_post_type( 'product' ), 'the post type list is filterable' );
remove_all_filters( 'duplikate_post_types' );

$off = dup_settings( array( 'enabled' => false ) );
dup_assert( ! $off->allows_post_type( 'post' ), 'a disabled plugin allows no type at all' );

/* -------------------------------------------------------------------------
 * Guard rails.
 * ---------------------------------------------------------------------- */

dup_reset_store();
dup_grants( true );

$source = dup_make_post(
	array(
		'post_title'   => 'Winter landing',
		'post_content' => 'Body',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_name'    => 'winter-landing',
		'post_author'  => 3,
	)
);

$duplicator = new Duplicator( dup_settings() );

$missing = $duplicator->check( 999999 );
dup_assert( $missing instanceof WP_Error, 'a missing id is refused' );
dup_same( 'duplikate_missing', $missing->get_error_code(), 'the error code says the post is gone' );

$duplicator_off = new Duplicator( dup_settings( array( 'enabled' => false ) ) );
$off_check      = $duplicator_off->check( $source );
dup_assert( $off_check instanceof WP_Error, 'a disabled plugin refuses every duplicate' );
dup_same( 'duplikate_disabled', $off_check->get_error_code(), 'the error code says the plugin is off' );

$duplicator_type = new Duplicator( dup_settings( array( 'post_types' => array( 'post' ) ) ) );
$type_check      = $duplicator_type->check( $source );
dup_assert( $type_check instanceof WP_Error, 'a post type left out of the settings is refused' );
dup_same( 'duplikate_type_off', $type_check->get_error_code(), 'the error code names the type' );

$trashed = dup_make_post( array( 'post_status' => 'trash', 'post_type' => 'post' ) );
$trash_check = $duplicator->check( $trashed );
dup_assert( $trash_check instanceof WP_Error, 'a trashed post is refused' );
dup_same( 'duplikate_status', $trash_check->get_error_code(), 'the error code names the status' );

$revision = dup_make_post( array( 'post_type' => 'revision' ) );
$rev_check = $duplicator->check( $revision );
dup_assert( $rev_check instanceof WP_Error, 'a revision is refused' );
dup_same( 'duplikate_type', $rev_check->get_error_code(), 'the error code names the type' );

foreach ( array( 'attachment', 'nav_menu_item', 'wp_block', 'oembed_cache' ) as $never ) {
	$GLOBALS['dup_post_types'][] = $never;
	$made                        = dup_make_post( array( 'post_type' => $never ) );
	$check                       = ( new Duplicator( dup_settings( array( 'post_types' => array( $never ) ) ) ) )->check( $made );
	dup_assert( $check instanceof WP_Error, $never . ' is never duplicated' );
}

$GLOBALS['dup_post_types'] = array( 'post', 'page' );

$denied = new Duplicator( dup_settings( array( 'post_types' => array( 'page' ) ) ) );
$GLOBALS['dup_can'] = array( 'edit_post' => false );
$forbidden          = $denied->check( $source );
dup_assert( $forbidden instanceof WP_Error, 'a user without edit_post is refused' );
dup_same( 'duplikate_forbidden', $forbidden->get_error_code(), 'the error code says forbidden' );
dup_grants( true );

add_filter( 'duplikate_can_duplicate', static fn(): bool => false );
$filtered_check = ( new Duplicator( dup_settings() ) )->check( $source );
dup_assert( $filtered_check instanceof WP_Error, 'the filter can refuse a duplicate' );
remove_all_filters( 'duplikate_can_duplicate' );

/* -------------------------------------------------------------------------
 * Titles and slugs.
 * ---------------------------------------------------------------------- */

dup_same( 'Page (copy)', Duplicator::title_variant( 'Page', ' (copy)', 1 ), 'the first copy gets the plain suffix' );
dup_same( 'Page (copy 2)', Duplicator::title_variant( 'Page', ' (copy)', 2 ), 'the second copy is numbered inside the parenthesis' );
dup_same( 'Page (copy 3)', Duplicator::title_variant( 'Page', ' (copy)', 3 ), 'the third copy is numbered too' );
dup_same( 'Page — copie 2', Duplicator::title_variant( 'Page', ' — copie', 2 ), 'a suffix without parenthesis is numbered at the end' );
dup_same( 'Page [copy 2]', Duplicator::title_variant( 'Page', ' [copy]', 2 ), 'a bracket suffix is numbered inside the bracket' );

/* -------------------------------------------------------------------------
 * Duplication.
 * ---------------------------------------------------------------------- */

dup_reset_store();
dup_grants( true );

$parent = dup_make_post(
	array(
		'post_title'    => 'Services',
		'post_content'  => 'Parent body',
		'post_excerpt'  => 'Parent excerpt',
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_name'     => 'services',
		'post_author'   => 3,
		'menu_order'    => 4,
		'comment_status' => 'closed',
		'ping_status'   => 'closed',
		'post_password' => 'secret',
	)
);

$child_a = dup_make_post(
	array(
		'post_title'  => 'Audit',
		'post_content' => 'Child A',
		'post_type'   => 'page',
		'post_parent' => $parent,
		'post_status' => 'publish',
		'post_name'   => 'audit',
	)
);

$grand_child = dup_make_post(
	array(
		'post_title'  => 'Audit detail',
		'post_type'   => 'page',
		'post_parent' => $child_a,
		'post_status' => 'publish',
	)
);

add_post_meta( $parent, '_wp_page_template', 'templates/wide.php' );
add_post_meta( $parent, '_thumbnail_id', 42 );
add_post_meta( $parent, '_edit_lock', '1700000000:7' );
add_post_meta( $parent, '_wp_old_slug', 'old-services' );
add_post_meta( $parent, 'seo_score', '92' );
add_post_meta( $parent, 'list_value', 'one' );
add_post_meta( $parent, 'list_value', 'two' );

$GLOBALS['dup_terms'][ $parent ] = array( 'category' => array( 5, 9 ) );

$duplicator = new Duplicator( dup_settings( array( 'max_children' => 10 ) ) );
$result     = $duplicator->duplicate( $parent );

dup_assert( ! $result instanceof WP_Error, 'duplicating a page succeeds' );

if ( is_array( $result ) ) {
	$copy_id = $result['id'];
	$copy    = get_post( $copy_id );

	dup_same( 'Services (copy)', $copy->post_title, 'the title carries the suffix' );
	dup_same( 'draft', $copy->post_status, 'the copy is a draft' );
	dup_same( 'page', $copy->post_type, 'the type is preserved' );
	dup_same( 'Parent body', $copy->post_content, 'the content is copied' );
	dup_same( 'Parent excerpt', $copy->post_excerpt, 'the excerpt is copied' );
	dup_same( 'services-copy', $copy->post_name, 'the slug is suffixed' );
	dup_same( 7, $copy->post_author, 'the copy belongs to the current user by default' );
	dup_same( 4, $copy->menu_order, 'the menu order is kept' );
	dup_same( 'closed', $copy->comment_status, 'the comment status is copied' );
	dup_same( 'secret', $copy->post_password, 'the password is copied' );
	dup_same( 2, $result['children'], 'both children are duplicated' );
	dup_same( 0, $copy->post_parent, 'a root page stays at the root' );

	$meta = get_post_meta( $copy_id );
	dup_same( 'templates/wide.php', $meta['_wp_page_template'][0] ?? null, 'the page template is copied' );
	dup_same( 42, (int) ( $meta['_thumbnail_id'][0] ?? 0 ), 'the featured image is copied as a reference' );
	dup_same( '92', (string) ( $meta['seo_score'][0] ?? '' ), 'a custom field is copied' );
	dup_same( array( 'one', 'two' ), $meta['list_value'] ?? array(), 'a field holding several values keeps all of them' );
	dup_assert( ! isset( $meta['_edit_lock'] ), 'the edit lock is never copied' );
	dup_assert( ! isset( $meta['_wp_old_slug'] ), 'the old-slug marker is never copied' );

	// Pages carry no taxonomy in WordPress by default: the term test lives in
	// the post section below, where `category` exists.

	// Children.
	$copied_children = get_children( array( 'post_parent' => $copy_id, 'post_type' => 'page' ) );
	dup_same( 1, count( $copied_children ), 'the child is reattached to the copy' );

	$child_copy = reset( $copied_children );
	dup_same( 'Audit (copy)', $child_copy->post_title, 'the child title is suffixed' );
	dup_same( 'draft', $child_copy->post_status, 'the child is a draft too' );

	$grand_children = get_children( array( 'post_parent' => $child_copy->ID, 'post_type' => 'page' ) );
	dup_same( 1, count( $grand_children ), 'the grandchild follows its parent' );
}

/* A second duplication must not collide. */
$second = $duplicator->duplicate( $parent );
dup_assert( is_array( $second ) && 'Services (copy 2)' === get_post( $second['id'] )->post_title, 'the second copy is numbered' );

/* Duplicating a copy must not stack the suffix. */
$third = $duplicator->duplicate( (int) $second['id'] );
dup_assert( is_array( $third ) && 'Services (copy 3)' === get_post( $third['id'] )->post_title, 'a copy of a copy is not suffixed twice' );

/* -------------------------------------------------------------------------
 * What the settings switch off.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$plain = dup_make_post( array( 'post_title' => 'Plain', 'post_type' => 'post', 'post_status' => 'publish' ) );
add_post_meta( $plain, '_thumbnail_id', 11 );
add_post_meta( $plain, '_wp_page_template', 'x.php' );
add_post_meta( $plain, 'keep_me', 'yes' );
$GLOBALS['dup_terms'][ $plain ] = array( 'category' => array( 3 ), 'post_tag' => array( 8 ) );

$no_extras = new Duplicator(
	dup_settings(
		array(
			'copy_taxonomies' => false,
			'copy_meta'       => false,
		)
	)
);
$plain_copy = $no_extras->duplicate( $plain );
$plain_id   = $plain_copy['id'];

dup_same( array(), get_post_meta( $plain_id ), 'with meta copying off, no metadata travels' );
dup_assert( ! isset( $GLOBALS['dup_terms'][ $plain_id ] ), 'with taxonomy copying off, no term travels' );

/* With everything on, a post does carry its terms. */
$with_terms = ( new Duplicator( dup_settings() ) )->duplicate( $plain );
dup_same( array( 3 ), $GLOBALS['dup_terms'][ $with_terms['id'] ]['category'] ?? array(), 'the categories are copied by id' );
dup_same( array( 8 ), $GLOBALS['dup_terms'][ $with_terms['id'] ]['post_tag'] ?? array(), 'the tags are copied too' );

$no_thumb = new Duplicator( dup_settings( array( 'copy_thumbnail' => false, 'copy_template' => false ) ) );
$thumb_id = $no_thumb->duplicate( $plain )['id'];
$thumb_meta = get_post_meta( $thumb_id );

dup_assert( ! isset( $thumb_meta['_thumbnail_id'] ), 'the featured image can be left behind' );
dup_assert( ! isset( $thumb_meta['_wp_page_template'] ), 'the template can be left behind' );
dup_same( 'yes', $thumb_meta['keep_me'][0] ?? null, 'the other fields still travel' );

/* -------------------------------------------------------------------------
 * Sloppy input and backslashes.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$sloppy = dup_make_post(
	array(
		'post_title'   => 'Paths',
		'post_content' => 'C:\\Users\\me\\file.txt and it\'s fine',
		'post_type'    => 'post',
		'post_status'  => 'publish',
	)
);

// Core expects slashed input to `add_post_meta()`; a caller that forgets it
// loses a backslash, which is exactly what the duplicator must avoid.
$json = '{"a":"it\'s","b":"C:\\\\tmp"}';
add_post_meta( $sloppy, 'json', wp_slash( $json ) );

$sloppy_copy = ( new Duplicator( dup_settings() ) )->duplicate( $sloppy );
$sloppy_new  = get_post( $sloppy_copy['id'] );

dup_same( 'C:\\Users\\me\\file.txt and it\'s fine', $sloppy_new->post_content, 'backslashes and quotes survive the round trip' );
dup_same( $json, get_post_meta( $sloppy_copy['id'], 'json', true ), 'a backslash inside a custom field survives' );

/* -------------------------------------------------------------------------
 * Status, author and date.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$future = dup_make_post( array( 'post_title' => 'Soon', 'post_status' => 'future', 'post_type' => 'post', 'post_author' => 2 ) );

$same_status = ( new Duplicator( dup_settings( array( 'status' => 'same' ) ) ) )->duplicate( $future );

dup_same( 'draft', get_post( $same_status['id'] )->post_status, 'a scheduled post is copied as a draft even in "same" mode' );

$published = dup_make_post( array( 'post_title' => 'Out', 'post_status' => 'publish', 'post_type' => 'post' ) );
$same_pub  = ( new Duplicator( dup_settings( array( 'status' => 'same' ) ) ) )->duplicate( $published );
dup_same( 'publish', get_post( $same_pub['id'] )->post_status, '"same" keeps a published status' );

$pending = ( new Duplicator( dup_settings( array( 'status' => 'pending' ) ) ) )->duplicate( $published );
dup_same( 'pending', get_post( $pending['id'] )->post_status, 'the status can be forced' );

$override = ( new Duplicator( dup_settings() ) )->duplicate( $published, array( 'status' => 'private' ) );
dup_same( 'private', get_post( $override['id'] )->post_status, 'the caller can override the status' );

$keep_author = ( new Duplicator( dup_settings( array( 'keep_author' => true ) ) ) )->duplicate( $future );
dup_same( 2, get_post( $keep_author['id'] )->post_author, 'the original author can be kept' );

$keep_date = ( new Duplicator( dup_settings( array( 'keep_date' => true ) ) ) )->duplicate( $future );
dup_same( '2026-01-02 03:04:05', get_post( $keep_date['id'] )->post_date, 'the original date can be kept' );

$fresh = ( new Duplicator( dup_settings() ) )->duplicate( $future );
dup_assert( '2026-01-02 03:04:05' !== get_post( $fresh['id'] )->post_date, 'by default the copy is dated now' );

/* -------------------------------------------------------------------------
 * Rollback: a failure must leave nothing behind.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$fragile = dup_make_post( array( 'post_title' => 'Fragile', 'post_type' => 'post', 'post_status' => 'publish' ) );
$GLOBALS['dup_terms'][ $fragile ] = array( 'category' => array( 1 ), 'post_tag' => array( 2 ) );

$before = dup_count_posts();
$GLOBALS['dup_term_fail'] = array( 'post_tag' );

$failure = ( new Duplicator( dup_settings() ) )->duplicate( $fragile );

dup_assert( $failure instanceof WP_Error, 'a term that cannot be written fails the duplication' );
dup_same( 'duplikate_copy', $failure->get_error_code(), 'the error code says the copy was cancelled' );
dup_same( $before, dup_count_posts(), 'the half-written copy was deleted, so the post count is unchanged' );

$GLOBALS['dup_term_fail'] = array();
$GLOBALS['dup_meta_fail'] = array();

/* A meta key that already exists on the source but cannot be written to the
   copy must cancel the whole thing, not leave a copy missing its fields. */
add_post_meta( $fragile, 'brand_new', 'value' );
$GLOBALS['dup_meta_fail'] = array( 'brand_new' );

$hard_before = dup_count_posts();
$hard_fail   = ( new Duplicator( dup_settings() ) )->duplicate( $fragile );

dup_assert( $hard_fail instanceof WP_Error, 'an unwritable custom field cancels the copy' );
dup_same( 'duplikate_copy', $hard_fail->get_error_code(), 'the error code says the copy was cancelled' );
dup_same( $hard_before, dup_count_posts(), 'the cancelled copy left nothing behind' );

$GLOBALS['dup_meta_fail'] = array();

/* Once the obstacle is gone, the same post duplicates cleanly again. */
$recovered = ( new Duplicator( dup_settings() ) )->duplicate( $fragile );
dup_assert( ! $recovered instanceof WP_Error, 'the same post duplicates again once the obstacle is gone' );
dup_same( 'value', (string) get_post_meta( $recovered['id'], 'brand_new', true ), 'the field that failed is there this time' );

/* -------------------------------------------------------------------------
 * Budgets and depth.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$root = dup_make_post( array( 'post_title' => 'Root', 'post_type' => 'page', 'post_status' => 'publish' ) );

for ( $i = 1; $i <= 6; $i++ ) {
	dup_make_post(
		array(
			'post_title'  => 'Branch ' . $i,
			'post_type'   => 'page',
			'post_parent' => $root,
			'post_status' => 'publish',
		)
	);
}

$budgeted = ( new Duplicator( dup_settings( array( 'max_children' => 3 ) ) ) )->duplicate( $root );
dup_same( 3, $budgeted['children'], 'the child budget is respected' );

$no_children = ( new Duplicator( dup_settings( array( 'copy_children' => false ) ) ) )->duplicate( $root );
dup_same( 0, $no_children['children'], 'children can be left out entirely' );

$skipped = ( new Duplicator( dup_settings() ) )->duplicate( $root, array( 'skip_children' => true ) );
dup_same( 0, $skipped['children'], 'the caller can skip the children' );

/* A non-hierarchical type never reports children. */
$post_root = dup_make_post( array( 'post_title' => 'Flat', 'post_type' => 'post', 'post_status' => 'publish' ) );
dup_make_post( array( 'post_title' => 'Orphan', 'post_type' => 'post', 'post_parent' => $post_root ) );
$flat = ( new Duplicator( dup_settings() ) )->duplicate( $post_root );
dup_same( 0, $flat['children'], 'a flat post type has no children to copy' );

/* -------------------------------------------------------------------------
 * Filters.
 * ---------------------------------------------------------------------- */

dup_reset_store();

$hooked = dup_make_post( array( 'post_title' => 'Hook', 'post_type' => 'post', 'post_status' => 'publish' ) );

add_filter(
	'duplikate_post_data',
	static function ( array $data ): array {
		$data['post_title'] = 'Hooked title';

		return $data;
	}
);

$hooked_copy = ( new Duplicator( dup_settings() ) )->duplicate( $hooked );
dup_same( 'Hooked title', get_post( $hooked_copy['id'] )->post_title, 'the post data is filterable' );
remove_all_filters( 'duplikate_post_data' );

add_filter( 'duplikate_status', static fn(): string => 'pending' );
$status_copy = ( new Duplicator( dup_settings() ) )->duplicate( $hooked );
dup_same( 'pending', get_post( $status_copy['id'] )->post_status, 'the status is filterable' );
remove_all_filters( 'duplikate_status' );

$events = array();
add_action(
	'duplikate_duplicated',
	static function ( int $new_id ) use ( &$events ): void {
		$events[] = $new_id;
	}
);
$fired = ( new Duplicator( dup_settings() ) )->duplicate( $hooked );
dup_assert( in_array( $fired['id'], $events, true ), 'the action fires with the new id' );

/* -------------------------------------------------------------------------
 * Report.
 * ---------------------------------------------------------------------- */

echo "\n" . $GLOBALS['dup_pass'] . ' passed, ' . $GLOBALS['dup_fail'] . " failed\n";

exit( $GLOBALS['dup_fail'] > 0 ? 1 : 0 );
