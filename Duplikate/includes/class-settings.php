<?php
/**
 * Options, defaults and sanitisation.
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the single option array.
 */
final class Settings {

	/**
	 * Option holding every setting.
	 */
	public const OPTION = 'duplikate_settings';

	/**
	 * Option holding the plugin version, used by the upgrade routine.
	 */
	public const VERSION_OPTION = 'duplikate_version';

	/**
	 * Option holding the "first run" flag for the welcome notice.
	 */
	public const WELCOME_OPTION = 'duplikate_welcome';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Defaults.
	 *
	 * Deliberately free of `__()`: a translated default would be written to the
	 * database in whichever language the administrator happened to use, and
	 * WordPress 6.7+ reports a notice for a text domain used too early.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => true,
			'post_types'     => array( 'post', 'page' ),
			'status'         => 'draft',
			'copy_children'  => true,
			'copy_taxonomies' => true,
			'copy_meta'      => true,
			'copy_thumbnail' => true,
			'copy_template'  => true,
			'keep_author'    => false,
			'keep_date'      => false,
			'title_suffix'   => ' (copy)',
			'slug_suffix'    => '-copy',
			'row_action'     => true,
			'bulk_action'    => true,
			'redirect'       => 'edit',
			'max_children'   => 50,
			'delete_data'    => true,
			'check_updates'  => true,
		);
	}

	/**
	 * Post statuses a duplicate may be created in.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array( 'draft', 'pending', 'private', 'same' );
	}

	/**
	 * Every setting merged with the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$this->cache = array_merge( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether the plugin is switched on.
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled' );
	}

	/**
	 * Post types the administrator allowed.
	 *
	 * Every name is checked against the registered types on read, so a type
	 * removed by a deactivated plugin simply stops appearing.
	 *
	 * @return array<int, string>
	 */
	public function post_types(): array {
		$configured = (array) $this->get( 'post_types', array() );
		$registered = array_values( (array) get_post_types( array(), 'names' ) );
		$allowed    = array_values( array_intersect( $configured, $registered ) );

		/**
		 * Filters the post types Duplikate may duplicate.
		 *
		 * @param array<int, string> $allowed Post type names.
		 */
		$filtered = apply_filters( 'duplikate_post_types', $allowed );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $allowed;
	}

	/**
	 * Whether a post type may be duplicated.
	 *
	 * @param string $post_type Post type name.
	 */
	public function allows_post_type( string $post_type ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return in_array( $post_type, $this->post_types(), true );
	}

	/**
	 * Stores a partial update and returns the merged result.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$clean = self::sanitize( $input, $this->all() );

		update_option( self::OPTION, $clean, false );

		$this->cache = $clean;

		return $clean;
	}

	/**
	 * Validates raw input.
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param array<string, mixed> $fallback Values to keep when a field is absent.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $fallback = array() ): array {
		$defaults = array_merge( self::defaults(), $fallback );
		$clean    = array();

		foreach ( array( 'enabled', 'copy_children', 'copy_taxonomies', 'copy_meta', 'copy_thumbnail', 'copy_template', 'keep_author', 'keep_date', 'row_action', 'bulk_action', 'delete_data', 'check_updates' ) as $flag ) {
			$value        = $input[ $flag ] ?? null;
			$clean[ $flag ] = is_scalar( $value ) ? ! empty( $value ) : (bool) $defaults[ $flag ];
		}

		$status                = isset( $input['status'] ) && is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		$clean['status']       = in_array( $status, self::statuses(), true ) ? $status : (string) $defaults['status'];

		$redirect              = isset( $input['redirect'] ) && is_scalar( $input['redirect'] ) ? sanitize_key( (string) $input['redirect'] ) : '';
		$clean['redirect']     = in_array( $redirect, array( 'edit', 'list' ), true ) ? $redirect : (string) $defaults['redirect'];

		$clean['post_types'] = self::sanitize_post_types( $input['post_types'] ?? $defaults['post_types'] );
		$clean['title_suffix'] = self::sanitize_suffix( $input['title_suffix'] ?? '', (string) $defaults['title_suffix'] );
		$clean['slug_suffix']  = self::sanitize_slug_suffix( $input['slug_suffix'] ?? '', (string) $defaults['slug_suffix'] );

		$children              = isset( $input['max_children'] ) && is_scalar( $input['max_children'] ) ? absint( (string) $input['max_children'] ) : 0;
		$clean['max_children'] = min( 200, max( 1, $children ?: (int) $defaults['max_children'] ) );

		return $clean;
	}

	/**
	 * Keeps only the post type names that are registered and public enough to
	 * be edited, in a stable order.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private static function sanitize_post_types( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return self::defaults()['post_types'];
		}

		$registered = (array) get_post_types( array(), 'names' );
		$refused    = Duplicator::never_types();
		$clean      = array();

		foreach ( $value as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$name = sanitize_key( $name );

			if ( '' !== $name
				&& in_array( $name, $registered, true )
				&& ! in_array( $name, $refused, true )
				&& ! in_array( $name, $clean, true )
			) {
				$clean[] = $name;
			}
		}

		return array() === $clean ? self::defaults()['post_types'] : $clean;
	}

	/**
	 * The title suffix may not contain markup or a newline.
	 *
	 * The leading space is kept when the administrator typed one: the suffix is
	 * glued to a title, and "Page(copy)" is not what anyone means. Trimming it
	 * away — which is what `sanitize_text_field()` alone does — would quietly
	 * break the default " (copy)".
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value to use when empty.
	 */
	private static function sanitize_suffix( mixed $value, string $fallback ): string {
		$raw     = is_scalar( $value ) ? (string) $value : '';
		$leading = '' !== $raw && ( ' ' === $raw[0] || "\t" === $raw[0] );

		$value = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $raw ) ) );

		if ( '' === $value ) {
			return $fallback;
		}

		return ( $leading ? ' ' : '' ) . self::truncate( $value, 40 );
	}

	/**
	 * The slug suffix is restricted to what a slug accepts.
	 *
	 * `sanitize_title()` strips the dashes at both ends, which would turn the
	 * default `-copy` into `copy` and glue it to the original slug. The two
	 * separators are therefore rebuilt around the sanitised body.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value to use when empty.
	 */
	private static function sanitize_slug_suffix( mixed $value, string $fallback ): string {
		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $raw ) {
			return $fallback;
		}

		$separators = array( '-', '_' );
		$leading    = in_array( $raw[0], $separators, true ) ? $raw[0] : '';
		$trailing   = strlen( $raw ) > 1 && in_array( substr( $raw, -1 ), $separators, true ) ? substr( $raw, -1 ) : '';
		$body       = sanitize_title( trim( $raw, '-_' ) );

		if ( '' === $body ) {
			return $fallback;
		}

		return $leading . self::truncate( $body, 20 ) . $trailing;
	}

	/**
	 * Cuts a string without relying on mbstring.
	 *
	 * `mb_substr()` is polyfilled by WordPress, but a host can also disable the
	 * extension for a request, so the fallback stays byte-safe rather than
	 * producing a broken character at the edge.
	 *
	 * @param string $value  Input.
	 * @param int    $length Maximum number of characters.
	 */
	private static function truncate( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		$cut = substr( $value, 0, $length );

		// Drop a trailing partial UTF-8 sequence, if any.
		return (string) preg_replace( '/[\x80-\xBF]+$/', '', (string) preg_replace( '/[\x80-\xBF][\x80-\xBF]+$/u', '', $cut ) );
	}
}
