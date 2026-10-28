<?php
/**
 * A small WordPress, enough to test Duplikate without a database.
 *
 * `wp_insert_post()`, `get_post()`, the meta table and the term relationships
 * are emulated in memory, and the emulation keeps the one behaviour that
 * matters most here: WordPress unslashes what it receives, so a backslash only
 * survives if the caller slashed its data first.
 *
 * @package Duplikate
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DUPLIKATE_VERSION', '1.0.0' );
define( 'DUPLIKATE_URL', 'https://example.test/wp-content/plugins/Duplikate/' );
define( 'DUPLIKATE_DIR', dirname( __DIR__ ) . '/Duplikate/' );
define( 'DUPLIKATE_BASENAME', 'Duplikate/duplikate.php' );

$GLOBALS['dup_options']     = array();
$GLOBALS['dup_posts']       = array();
$GLOBALS['dup_meta']        = array();
$GLOBALS['dup_terms']       = array();
$GLOBALS['dup_taxonomies']  = array( 'category' => 'category', 'post_tag' => 'post_tag' );
$GLOBALS['dup_post_types']  = array( 'post', 'page', 'product' );
$GLOBALS['dup_hierarchical'] = array( 'page' );
$GLOBALS['dup_next_id']     = 100;
$GLOBALS['dup_actions']     = array();
$GLOBALS['dup_filters']     = array();
$GLOBALS['dup_can']         = array();
$GLOBALS['dup_user_id']     = 7;
$GLOBALS['dup_die']         = null;
$GLOBALS['dup_redirect']    = null;
$GLOBALS['dup_meta_fail']   = array();
$GLOBALS['dup_term_fail']   = array();

/* -------------------------------------------------------------------------
 * Value objects.
 * ---------------------------------------------------------------------- */

class WP_Error {
	private array $errors = array();
	private array $messages = array();

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][]   = $message;
			$this->messages[ $code ][] = $data;
		}
	}

	public function get_error_code(): string {
		$codes = array_keys( $this->errors );

		return (string) ( $codes[0] ?? '' );
	}

	public function get_error_message(): string {
		$code = $this->get_error_code();

		return (string) ( $this->errors[ $code ][0] ?? '' );
	}

	public function get_error_codes(): array {
		return array_keys( $this->errors );
	}
}

class WP_Post {
	public int $ID = 0;
	public int $post_author = 0;
	public string $post_date = '';
	public string $post_date_gmt = '';
	public string $post_content = '';
	public string $post_content_filtered = '';
	public string $post_title = '';
	public string $post_excerpt = '';
	public string $post_status = 'draft';
	public string $post_type = 'post';
	public string $post_password = '';
	public string $post_name = '';
	public string $post_mime_type = '';
	public int $post_parent = 0;
	public int $menu_order = 0;
	public string $comment_status = 'open';
	public string $ping_status = 'open';

	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/* -------------------------------------------------------------------------
 * Options.
 * ---------------------------------------------------------------------- */

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['dup_options'] ) ? $GLOBALS['dup_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['dup_options'][ $name ] = $value;

	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['dup_options'] ) ) {
		return false;
	}

	$GLOBALS['dup_options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['dup_options'][ $name ] );

	return true;
}

/* -------------------------------------------------------------------------
 * Hooks.
 * ---------------------------------------------------------------------- */

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['dup_filters'][ $tag ][ $priority ][] = $callback;

	return true;
}

function has_filter( $tag, $callback = false ) {
	if ( empty( $GLOBALS['dup_filters'][ $tag ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['dup_filters'][ $tag ] as $callbacks ) {
		if ( in_array( $callback, $callbacks, true ) ) {
			return true;
		}
	}

	return false;
}

function do_action( $tag, ...$args ) {
	if ( empty( $GLOBALS['dup_filters'][ $tag ] ) ) {
		return;
	}

	ksort( $GLOBALS['dup_filters'][ $tag ] );

	foreach ( $GLOBALS['dup_filters'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['dup_filters'][ $tag ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['dup_filters'][ $tag ] );

	foreach ( $GLOBALS['dup_filters'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function remove_all_filters( $tag ) {
	unset( $GLOBALS['dup_filters'][ $tag ] );
}

/* -------------------------------------------------------------------------
 * Posts: an in-memory table.
 * ---------------------------------------------------------------------- */

function dup_reset_store(): void {
	$GLOBALS['dup_posts']      = array();
	$GLOBALS['dup_meta']       = array();
	$GLOBALS['dup_terms']      = array();
	$GLOBALS['dup_next_id']    = 100;
	$GLOBALS['dup_meta_fail']  = array();
	$GLOBALS['dup_term_fail']  = array();
}

function dup_make_post( array $data ): int {
	$id = $GLOBALS['dup_next_id']++;

	$defaults = array(
		'ID'                    => $id,
		'post_author'           => 1,
		'post_date'             => '2026-01-02 03:04:05',
		'post_date_gmt'         => '2026-01-02 03:04:05',
		'post_content'          => '',
		'post_content_filtered' => '',
		'post_title'            => '',
		'post_excerpt'          => '',
		'post_status'           => 'draft',
		'post_type'             => 'post',
		'post_password'         => '',
		'post_name'             => '',
		'post_mime_type'        => '',
		'post_parent'           => 0,
		'menu_order'            => 0,
		'comment_status'        => 'open',
		'ping_status'           => 'open',
	);

	$GLOBALS['dup_posts'][ $id ] = new WP_Post( array_merge( $defaults, $data, array( 'ID' => $id ) ) );

	return $id;
}

function get_post( $post = null, $output = 'OBJECT', $filter = 'raw' ) {
	if ( $post instanceof WP_Post ) {
		return $post;
	}

	$id = (int) $post;

	return $GLOBALS['dup_posts'][ $id ] ?? null;
}

function get_post_field( $field, $post = null ) {
	$object = get_post( $post );

	if ( ! $object instanceof WP_Post ) {
		return '';
	}

	return $object->{$field} ?? '';
}

function get_the_title( $post = null ) {
	return get_post_field( 'post_title', $post );
}

function get_edit_post_link( $post = null, $context = 'display' ) {
	$id = $post instanceof WP_Post ? $post->ID : (int) $post;

	return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
}

/**
 * Mirrors core: input is unslashed, post_name is uniquified.
 */
function wp_insert_post( $postarr, $wp_error = false, $fire_after_hooks = true ) {
	$data = wp_unslash( $postarr );

	if ( ! is_array( $data ) ) {
		return $wp_error ? new WP_Error( 'invalid', 'invalid data' ) : 0;
	}

	$id = dup_make_post(
		array(
			'post_author'           => (int) ( $data['post_author'] ?? 1 ),
			'post_date'             => (string) ( $data['post_date'] ?? '2026-10-28 00:00:00' ),
			'post_date_gmt'         => (string) ( $data['post_date_gmt'] ?? '2026-10-28 00:00:00' ),
			'post_content'          => (string) ( $data['post_content'] ?? '' ),
			'post_content_filtered' => (string) ( $data['post_content_filtered'] ?? '' ),
			'post_title'            => (string) ( $data['post_title'] ?? '' ),
			'post_excerpt'          => (string) ( $data['post_excerpt'] ?? '' ),
			'post_status'           => (string) ( $data['post_status'] ?? 'draft' ),
			'post_type'             => (string) ( $data['post_type'] ?? 'post' ),
			'post_password'         => (string) ( $data['post_password'] ?? '' ),
			'post_name'             => dup_unique_slug( (string) ( $data['post_name'] ?? '' ), (string) ( $data['post_title'] ?? '' ) ),
			'post_mime_type'        => (string) ( $data['post_mime_type'] ?? '' ),
			'post_parent'           => (int) ( $data['post_parent'] ?? 0 ),
			'menu_order'            => (int) ( $data['menu_order'] ?? 0 ),
			'comment_status'        => (string) ( $data['comment_status'] ?? 'open' ),
			'ping_status'           => (string) ( $data['ping_status'] ?? 'open' ),
		)
	);

	return $id;
}

function dup_unique_slug( string $slug, string $title ): string {
	if ( '' === $slug ) {
		$slug = sanitize_title( $title );
	}

	$base   = $slug;
	$suffix = 1;

	while ( dup_slug_taken( $slug ) ) {
		++$suffix;
		$slug = $base . '-' . $suffix;
	}

	return $slug;
}

function dup_slug_taken( string $slug ): bool {
	foreach ( $GLOBALS['dup_posts'] as $post ) {
		if ( $post->post_name === $slug && '' !== $slug ) {
			return true;
		}
	}

	return false;
}

function wp_delete_post( $id, $force_delete = false ) {
	$id = (int) $id;

	if ( ! isset( $GLOBALS['dup_posts'][ $id ] ) ) {
		return false;
	}

	unset( $GLOBALS['dup_posts'][ $id ], $GLOBALS['dup_meta'][ $id ], $GLOBALS['dup_terms'][ $id ] );

	return true;
}

function get_posts( $args = array() ) {
	$args = (array) $args;
	$out  = array();

	foreach ( $GLOBALS['dup_posts'] as $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}

		if ( isset( $args['post_parent'] ) && (int) $post->post_parent !== (int) $args['post_parent'] ) {
			continue;
		}

		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] ) {
			$statuses = (array) $args['post_status'];

			if ( ! in_array( $post->post_status, $statuses, true ) ) {
				continue;
			}
		}

		if ( isset( $args['title'] ) && $post->post_title !== $args['title'] ) {
			continue;
		}

		$out[] = 'ids' === ( $args['fields'] ?? '' ) ? $post->ID : $post;
	}

	$limit = (int) ( $args['posts_per_page'] ?? -1 );

	return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
}

function get_children( $args = array(), $output = 'OBJECT' ) {
	$args = (array) $args;
	$out  = array();

	foreach ( $GLOBALS['dup_posts'] as $post ) {
		if ( (int) $post->post_parent !== (int) ( $args['post_parent'] ?? 0 ) ) {
			continue;
		}

		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}

		if ( isset( $args['post_status'] ) && ! in_array( $post->post_status, (array) $args['post_status'], true ) ) {
			continue;
		}

		$out[ $post->ID ] = $post;
	}

	$limit = (int) ( $args['numberposts'] ?? -1 );

	return $limit > 0 ? array_slice( $out, 0, $limit, true ) : $out;
}

/* -------------------------------------------------------------------------
 * Meta: an in-memory table, slashed on the way in.
 * ---------------------------------------------------------------------- */

function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
	$post_id = (int) $post_id;
	$key     = (string) wp_unslash( $meta_key );

	if ( in_array( $key, $GLOBALS['dup_meta_fail'], true ) ) {
		return false;
	}

	$value = maybe_unserialize( wp_unslash( $meta_value ) );

	if ( $unique && isset( $GLOBALS['dup_meta'][ $post_id ][ $key ] ) ) {
		return false;
	}

	$GLOBALS['dup_meta'][ $post_id ][ $key ][] = $value;

	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$post_id = (int) $post_id;
	$meta    = $GLOBALS['dup_meta'][ $post_id ] ?? array();

	if ( '' !== $key ) {
		$values = $meta[ $key ] ?? array();

		return $single ? ( $values[0] ?? '' ) : $values;
	}

	return $meta;
}

function metadata_exists( $type, $object_id, $meta_key ) {
	return isset( $GLOBALS['dup_meta'][ (int) $object_id ][ (string) $meta_key ] );
}

function is_protected_meta( $meta_key, $meta_type = '' ) {
	return is_string( $meta_key ) && '' !== $meta_key && '_' === $meta_key[0];
}

function maybe_unserialize( $data ) {
	return $data;
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	if ( is_object( $value ) ) {
		$copy = clone $value;

		foreach ( get_object_vars( $copy ) as $key => $var ) {
			$copy->{$key} = wp_slash( $var );
		}

		return $copy;
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	if ( is_object( $value ) ) {
		$copy = clone $value;

		foreach ( get_object_vars( $copy ) as $key => $var ) {
			$copy->{$key} = wp_unslash( $var );
		}

		return $copy;
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/* -------------------------------------------------------------------------
 * Taxonomies.
 * ---------------------------------------------------------------------- */

function get_object_taxonomies( $object_type, $output = 'names' ) {
	if ( 'page' === $object_type ) {
		return array();
	}

	return array_values( $GLOBALS['dup_taxonomies'] );
}

function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['dup_taxonomies'][ $taxonomy ] );
}

function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
	return $GLOBALS['dup_terms'][ (int) $object_id ][ $taxonomy ] ?? array();
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	if ( in_array( $taxonomy, $GLOBALS['dup_term_fail'], true ) ) {
		return new WP_Error( 'term_error', 'term failure' );
	}

	$GLOBALS['dup_terms'][ (int) $object_id ][ $taxonomy ] = array_map( 'intval', (array) $terms );

	return array_map( 'intval', (array) $terms );
}

/* -------------------------------------------------------------------------
 * Types and capabilities.
 * ---------------------------------------------------------------------- */

function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
	$types = $GLOBALS['dup_post_types'];

	if ( ! empty( $args['show_ui'] ) ) {
		$types = array_values( array_diff( $types, array( 'revision', 'attachment' ) ) );
	}

	$out = array();

	foreach ( $types as $type ) {
		$out[ $type ] = (object) array(
			'name'  => $type,
			'label' => ucfirst( $type ),
			'labels' => (object) array( 'singular_name' => ucfirst( $type ) ),
		);
	}

	return 'names' === $output ? array_keys( $out ) : $out;
}

function is_post_type_hierarchical( $post_type ) {
	return in_array( (string) $post_type, $GLOBALS['dup_hierarchical'], true );
}

function post_type_supports( $post_type, $feature ) {
	return 'page-attributes' === $feature && is_post_type_hierarchical( $post_type );
}

function current_user_can( $capability, ...$args ) {
	if ( isset( $GLOBALS['dup_can'][ $capability ] ) ) {
		return (bool) $GLOBALS['dup_can'][ $capability ];
	}

	return ! empty( $GLOBALS['dup_can']['__default__'] );
}

function get_current_user_id() {
	return (int) $GLOBALS['dup_user_id'];
}

function is_admin() {
	return true;
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz', 3 ), 0, (int) $length );
}

/* -------------------------------------------------------------------------
 * Escaping, i18n and URLs.
 * ---------------------------------------------------------------------- */

function __( $text, $domain = null ) {
	return $text;
}

function _e( $text, $domain = null ) {
	echo $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}

function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

	return trim( (string) $title, '-' );
}

function sanitize_hex_color( $color ) {
	return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $color ) ? $color : null;
}

function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? " checked='checked'" : '';

	if ( $echo ) {
		echo $out;
	}

	return $out;
}

function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? " selected='selected'" : '';

	if ( $echo ) {
		echo $out;
	}

	return $out;
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	printf( '<input type="submit" name="%s" value="%s" class="button button-%s">', esc_attr( $name ), esc_attr( (string) $text ), esc_attr( $type ) );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_query_arg( ...$args ) {
	$url   = '';
	$pairs = array();

	if ( is_array( $args[0] ) ) {
		$pairs = $args[0];
		$url   = isset( $args[1] ) ? (string) $args[1] : '';
	} else {
		$pairs[ (string) $args[0] ] = $args[1] ?? '';
		$url = isset( $args[2] ) ? (string) $args[2] : '';
	}

	$query = array();

	foreach ( $pairs as $key => $value ) {
		$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	if ( array() === $query ) {
		return $url;
	}

	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $query );
}

function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	return $actionurl . ( str_contains( (string) $actionurl, '?' ) ? '&' : '?' ) . $name . '=test-nonce';
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce">';

	if ( $echo ) {
		echo $html;
	}

	return $html;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	return true;
}

function is_multisite() {
	return false;
}

function switch_to_blog( $blog_id ) {
	return true;
}

function restore_current_blog() {
	return true;
}

function get_sites( $args = array() ) {
	return array();
}

/**
 * Records the target and stops the request.
 *
 * In WordPress a redirect is always followed by `exit`, so throwing here keeps
 * the same control flow while letting a test observe what happened.
 */
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['dup_redirect'] = $location;

	throw new RuntimeException( 'redirect: ' . $location );
}

function wp_redirect( $location, $status = 302 ) {
	return wp_safe_redirect( $location, $status );
}
function wp_get_referer() {
	return $GLOBALS['dup_referer'] ?? 'https://example.test/wp-admin/edit.php';
}

function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['dup_die'] = $message;

	throw new RuntimeException( 'wp_die: ' . $message );
}

function plugin_basename( $file ) {
	return 'Duplikate/duplikate.php';
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( $file ) {
	return DUPLIKATE_URL;
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	$GLOBALS['dup_textdomain'] = array( $domain, $plugin_rel_path );

	return true;
}

function wp_enqueue_style( ...$args ) {
	$GLOBALS['dup_styles'][] = $args;
}

function wp_enqueue_script( ...$args ) {
	$GLOBALS['dup_scripts'][] = $args;
}

function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}

function status_header( $code ) {}
function nocache_headers() {}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function language_attributes() {}
function bloginfo( $what ) {}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
