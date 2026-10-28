<?php
/**
 * Uninstall routine.
 *
 * Duplikate stores two options and nothing about the content, so the cleanup
 * is short on purpose: a duplicate is a normal post and stays where the author
 * put it.
 *
 * @package Duplikate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$duplikate_options = array(
	'duplikate_settings',
	'duplikate_version',
	'duplikate_welcome',
);

$duplikate_cleanup = static function () use ( $duplikate_options ): void {
	$settings = get_option( 'duplikate_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	foreach ( $duplikate_options as $option ) {
		delete_option( $option );
	}
};

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$duplikate_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $duplikate_sites as $duplikate_site_id ) {
		switch_to_blog( (int) $duplikate_site_id );
		$duplikate_cleanup();
		restore_current_blog();
	}
} else {
	$duplikate_cleanup();
}
