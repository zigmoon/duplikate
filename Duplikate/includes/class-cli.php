<?php
/**
 * WP-CLI commands.
 *
 * There is deliberately no `__invoke()` here: an object that is callable is
 * registered by WP-CLI as a single command, and every other public method is
 * then ignored, so `wp duplikate settings` would be parsed as an id.
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

/**
 * `wp duplikate …`
 */
final class Cli {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Duplicator.
	 *
	 * @var Duplicator
	 */
	private Duplicator $duplicator;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Duplicator $duplicator Duplicator.
	 */
	public function __construct( Settings $settings, Duplicator $duplicator ) {
		$this->settings   = $settings;
		$this->duplicator = $duplicator;
	}

	/**
	 * Registers the commands.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Duplicator $duplicator Duplicator.
	 */
	public static function register( Settings $settings, Duplicator $duplicator ): void {
		\WP_CLI::add_command( 'duplikate', new self( $settings, $duplicator ) );
	}

	/**
	 * Duplicates one post and prints the id of the copy.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The post to duplicate.
	 *
	 * [--status=<status>]
	 * : draft, pending, private or same. Defaults to the stored setting.
	 *
	 * [--porcelain]
	 * : Print only the new id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplikate duplicate 42
	 *     wp duplikate duplicate 42 --status=pending --porcelain
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function duplicate( array $args, array $assoc_args ): void {
		$post_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( $post_id <= 0 ) {
			\WP_CLI::error( __( 'Provide the id of the post to duplicate.', 'duplikate' ) );
		}

		$overrides = array();

		if ( isset( $assoc_args['status'] ) ) {
			$status = sanitize_key( (string) $assoc_args['status'] );

			if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'same' ), true ) ) {
				\WP_CLI::error( __( 'Unknown status.', 'duplikate' ) );
			}

			$overrides['status'] = $status;
		}

		$result = $this->duplicator->duplicate( $post_id, $overrides );

		if ( $result instanceof \WP_Error ) {
			$message = $result->get_error_message();

			// WP-CLI has no logged-in user by default, and the capability check
			// is the same one the dashboard uses, so explain rather than fail
			// with a bare "not allowed".
			if ( 'duplikate_forbidden' === $result->get_error_code() && 0 === get_current_user_id() ) {
				$message .= ' ' . __( 'No user is set: add --user=<login>.', 'duplikate' );
			}

			\WP_CLI::error( $message );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result['id'] );

			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: new id, 2: title, 3: children count. */
				__( 'Created #%1$d “%2$s” (%3$d child copies).', 'duplikate' ),
				$result['id'],
				$result['title'],
				$result['children']
			)
		);
	}

	/**
	 * Prints the current settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplikate settings
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function settings( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rows = array();

		foreach ( $this->settings->all() as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? __( 'yes', 'duplikate' ) : __( 'no', 'duplikate' );
			}

			$rows[] = array(
				'setting' => (string) $key,
				'value'   => (string) $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );
	}
}
