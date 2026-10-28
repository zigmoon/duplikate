<?php
/**
 * Integration smoke test: boots the plugin, renders the admin screen, walks the
 * row action and the notices.
 *
 * Run with: php tests/smoke.php
 *
 * @package Duplikate
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/Duplikate/duplikate.php';

$GLOBALS['dup_smoke_pass'] = 0;
$GLOBALS['dup_smoke_fail'] = 0;

function dup_smoke( bool $condition, string $message ): void {
	if ( $condition ) {
		++$GLOBALS['dup_smoke_pass'];
		return;
	}

	++$GLOBALS['dup_smoke_fail'];
	echo "FAIL: {$message}\n";
}

function dup_grants( bool $allowed = true ): void {
	$GLOBALS['dup_can'] = array( '__default__' => $allowed, 'edit_post' => $allowed, 'manage_options' => $allowed );
}

use Duplikate\Admin;
use Duplikate\Duplicator;
use Duplikate\Plugin;
use Duplikate\Settings;

dup_grants( true );
dup_reset_store();

do_action( 'plugins_loaded' );
do_action( 'init' );

dup_smoke( true, 'the plugin boots without a fatal error' );
dup_smoke( isset( $GLOBALS['dup_textdomain'] ) && 'duplikate' === $GLOBALS['dup_textdomain'][0], 'the text domain is loaded on init' );

$settings   = new Settings();
$duplicator = new Duplicator( $settings );

dup_smoke( $settings->is_enabled(), 'the plugin is enabled by default' );

/* ── Admin wiring ───────────────────────────────────────────────────────── */

$admin = new Admin( $settings, $duplicator );
$admin->hooks();

dup_smoke( has_filter( 'post_row_actions' ), 'row actions are hooked for flat types' );
dup_smoke( has_filter( 'page_row_actions' ), 'row actions are hooked for hierarchical types' );
dup_smoke( has_filter( 'bulk_actions-edit-post' ), 'the bulk action is hooked for posts' );
dup_smoke( has_filter( 'admin_action_duplikate_duplicate' ), 'the duplicate handler is hooked' );
dup_smoke( has_filter( 'post_submitbox_misc_actions' ), 'the editor button is hooked' );

/* ── Row action ─────────────────────────────────────────────────────────── */

$page = dup_make_post( array( 'post_title' => 'Landing', 'post_type' => 'page', 'post_status' => 'publish' ) );

$actions = $admin->row_action( array( 'edit' => '<a>Edit</a>' ), get_post( $page ) );

dup_smoke( isset( $actions['duplikate'] ), 'the row gains a Duplicate action' );
dup_smoke( str_contains( (string) $actions['duplikate'], 'action=duplikate_duplicate' ), 'the action points at the handler' );
dup_smoke( str_contains( (string) $actions['duplikate'], '_wpnonce=test-nonce' ), 'the action carries a nonce' );
dup_smoke( str_contains( (string) $actions['duplikate'], '>Duplicate<' ), 'the visible label is translated' );
dup_smoke( isset( $actions['edit'] ), 'the other actions are left alone' );

$revision   = dup_make_post( array( 'post_type' => 'revision' ) );
$unchanged  = $admin->row_action( array( 'edit' => 'x' ), get_post( $revision ) );
dup_smoke( ! isset( $unchanged['duplikate'] ), 'a revision gets no Duplicate action' );

$trashed = dup_make_post( array( 'post_type' => 'page', 'post_status' => 'trash' ) );
dup_smoke( ! isset( $admin->row_action( array(), get_post( $trashed ) )['duplikate'] ), 'a trashed page gets no Duplicate action' );

$GLOBALS['dup_can'] = array( 'edit_post' => false );
dup_smoke( ! isset( $admin->row_action( array(), get_post( $page ) )['duplikate'] ), 'a row the user cannot edit gets no action' );
dup_grants( true );

$disabled = new Settings();
$disabled->update( array( 'row_action' => false ) );
$quiet = new Admin( $disabled, new Duplicator( $disabled ) );
dup_smoke( ! isset( $quiet->row_action( array(), get_post( $page ) )['duplikate'] ), 'the link can be switched off' );
$disabled->update( array( 'row_action' => true ) );

/* ── Bulk action ────────────────────────────────────────────────────────── */

$bulk = $admin->bulk_action( array( 'trash' => 'Move to Trash' ) );

dup_smoke( isset( $bulk['duplikate'] ), 'the bulk menu gains Duplicate' );
dup_smoke( isset( $bulk['trash'] ), 'the other bulk actions are kept' );

$GLOBALS['dup_posts'] = array();
$bulk_ids             = array();

for ( $i = 0; $i < 3; $i++ ) {
	$bulk_ids[] = dup_make_post( array( 'post_title' => 'Bulk ' . $i, 'post_type' => 'post', 'post_status' => 'publish' ) );
}

$redirect = $admin->handle_bulk( 'https://example.test/wp-admin/edit.php', 'duplikate', array_map( 'strval', $bulk_ids ) );

dup_smoke( str_contains( (string) $redirect, 'duplikate_done=3' ), 'the bulk run reports three copies' );
dup_smoke( 3 === count( get_posts( array( 'post_type' => 'post', 'post_status' => 'draft' ) ) ), 'three drafts exist' );

$ignored = $admin->handle_bulk( 'https://example.test/x', 'trash', $bulk_ids );
dup_smoke( 'https://example.test/x' === $ignored, 'another bulk action is left untouched' );

$many = array();
for ( $i = 0; $i < 40; $i++ ) {
	$many[] = (string) dup_make_post( array( 'post_title' => 'Many ' . $i, 'post_type' => 'post', 'post_status' => 'publish' ) );
}

$limited = $admin->handle_bulk( 'https://example.test/edit.php', 'duplikate', $many );
dup_smoke( str_contains( (string) $limited, 'duplikate_done=25' ), 'a bulk run stops at its limit' );
dup_smoke( str_contains( (string) $limited, 'duplikate_skipped=15' ), 'the items left out are counted' );

/* ── Settings screen ────────────────────────────────────────────────────── */

$GLOBALS['dup_can'] = array( 'manage_options' => true );
$_GET['page']       = 'duplikate';

ob_start();
$admin->render();
$html = (string) ob_get_clean();

dup_smoke( str_contains( $html, 'role="tablist"' ), 'the tabs render' );
dup_smoke( 3 === substr_count( $html, 'role="tab" id=' ), 'three tabs exist' );
dup_smoke( str_contains( $html, 'data-dup-panel="general"' ), 'the general panel renders' );
dup_smoke( str_contains( $html, 'data-dup-panel="content"' ), 'the content panel renders' );
dup_smoke( str_contains( $html, 'data-dup-panel="advanced"' ), 'the advanced panel renders' );
dup_smoke( str_contains( $html, 'data-dup-panel="content" hidden' ), 'the content panel is hidden by the server' );
dup_smoke( str_contains( $html, 'data-dup-panel="advanced" hidden' ), 'the advanced panel is hidden by the server' );
dup_smoke( ! str_contains( $html, 'data-dup-panel="general" hidden' ), 'the first panel is the one shown' );
dup_smoke( 10 <= substr_count( $html, 'role="switch"' ), 'every boolean is a switch' );
dup_smoke( str_contains( $html, 'name="duplikate[enabled]"' ), 'the master switch posts the right name' );
dup_smoke( str_contains( $html, 'name="duplikate[post_types][]"' ), 'the post types post as an array' );
dup_smoke( str_contains( $html, 'name="duplikate[title_suffix]"' ), 'the suffix field renders' );
dup_smoke( str_contains( $html, 'name="duplikate[status]"' ), 'the status select renders' );
dup_smoke( str_contains( $html, 'value="page"' ), 'the page type is offered' );
dup_smoke( str_contains( $html, 'data-vg' ) === false, 'no leftover markup from another plugin' );
dup_smoke( str_contains( $html, 'dup-credits' ), 'the credits block is printed once' );
dup_smoke( 1 === substr_count( $html, 'a plugin by' ), 'the credits are not duplicated' );
dup_smoke( str_contains( $html, '2points13.fr' ) && str_contains( $html, 'zigmoon.com' ), 'both credits are there' );

/* Saved settings come back in the form. */
$admin->handle_save ?? null;
$_POST['duplikate'] = array(
	'enabled'      => '0',
	'status'       => 'pending',
	'post_types'   => array( 'page' ),
	'title_suffix' => ' (bis)',
);
ob_start();
try {
	$admin->handle_save();
} catch ( Throwable $error ) {
	// `wp_safe_redirect()` is followed by `exit`, which the stub records.
}
ob_end_clean();

$reloaded = new Settings();
dup_smoke( false === $reloaded->is_enabled(), 'the form switches the plugin off' );
dup_smoke( 'pending' === $reloaded->get( 'status' ), 'the form stores the status' );
dup_smoke( array( 'page' ) === $reloaded->get( 'post_types' ), 'the form stores the post types' );
dup_smoke( ' (bis)' === $reloaded->get( 'title_suffix' ), 'the form stores the suffix' );
dup_smoke( isset( $GLOBALS['dup_redirect'] ) && str_contains( (string) $GLOBALS['dup_redirect'], 'page=duplikate' ), 'saving redirects to the screen' );

$reloaded->update( array( 'enabled' => true, 'status' => 'draft', 'post_types' => array( 'post', 'page' ), 'title_suffix' => ' (copy)' ) );

/* ── Notices ────────────────────────────────────────────────────────────── */

$_GET = array( 'duplikate_done' => '2' );
ob_start();
$admin->notices();
$notices = (string) ob_get_clean();
dup_smoke( str_contains( $notices, 'notice-success' ), 'a success notice is printed' );
dup_smoke( str_contains( $notices, '2 draft copies created.' ), 'the count is pluralised' );

$_GET = array( 'duplikate_done' => '1' );
ob_start();
$admin->notices();
$notices = (string) ob_get_clean();
dup_smoke( str_contains( $notices, '1 draft copy created.' ), 'a single copy is singular' );

$_GET = array( 'duplikate_failed' => '1', 'duplikate_skipped' => '5', 'duplikate_error' => 'duplikate_type' );
ob_start();
$admin->notices();
$notices = (string) ob_get_clean();
dup_smoke( str_contains( $notices, 'notice-error' ), 'a failure is reported' );
dup_smoke( str_contains( $notices, 'notice-warning' ), 'skipped items are reported' );
dup_smoke( str_contains( $notices, 'cannot be duplicated' ), 'the error code is translated into a sentence' );
dup_smoke( ! str_contains( $notices, 'duplikate_type' ), 'the raw code is never printed' );

$_GET = array( 'page' => 'duplikate', 'duplikate_saved' => '1' );
ob_start();
$admin->notices();
$notices = (string) ob_get_clean();
dup_smoke( str_contains( $notices, 'Settings saved.' ), 'saving is confirmed' );

/* ── Welcome notice ─────────────────────────────────────────────────────── */

update_option( Settings::WELCOME_OPTION, time(), false );
$_GET = array();

ob_start();
$admin->notices();
$welcome = (string) ob_get_clean();

dup_smoke( str_contains( $welcome, 'Duplikate is active.' ), 'the first run explains itself' );
dup_smoke( str_contains( $welcome, 'Dismiss' ), 'the notice can be dismissed' );

$_GET = array( 'page' => 'duplikate' );
ob_start();
$admin->notices();
ob_end_clean();

dup_smoke( false === get_option( Settings::WELCOME_OPTION, false ), 'opening the settings clears the notice' );

/* ── Activation, deactivation, uninstall ────────────────────────────────── */

Plugin::activate();
dup_smoke( '1.0.0' === (string) get_option( Settings::VERSION_OPTION, '' ), 'activation stores the version' );
dup_smoke( false !== get_option( Settings::WELCOME_OPTION, false ), 'activation arms the welcome notice' );

Plugin::deactivate();
dup_smoke( false === get_option( Settings::WELCOME_OPTION, false ), 'deactivation removes the welcome flag' );

dup_smoke( class_exists( 'Duplikate\\Cli' ), 'the CLI class loads' );

/* The uninstall routine reads the stored settings. */
$GLOBALS['dup_options']['duplikate_settings'] = array( 'delete_data' => true );
$GLOBALS['dup_options']['duplikate_version']  = '1.0.0';

define( 'WP_UNINSTALL_PLUGIN', true );
require dirname( __DIR__ ) . '/Duplikate/uninstall.php';

dup_smoke( ! isset( $GLOBALS['dup_options']['duplikate_settings'] ), 'uninstall removes the settings when asked' );
dup_smoke( ! isset( $GLOBALS['dup_options']['duplikate_version'] ), 'uninstall removes the version marker' );

echo "\n" . $GLOBALS['dup_smoke_pass'] . ' passed, ' . $GLOBALS['dup_smoke_fail'] . " failed\n";

exit( $GLOBALS['dup_smoke_fail'] > 0 ? 1 : 0 );
