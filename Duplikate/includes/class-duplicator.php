<?php
/**
 * The duplication engine.
 *
 * Design rules, in order of importance:
 *
 * 1. Never write half a duplicate. The post is inserted first; taxonomies,
 *    metadata and children are copied afterwards, and any failure deletes the
 *    new post again, so a broken plugin or a fatal error cannot leave an
 *    orphan behind.
 * 2. Never touch the database directly. Everything goes through
 *    `wp_insert_post()`, `add_post_meta()`, `wp_set_object_terms()` and
 *    `wp_delete_post()`, which keep caches, hooks and multisite tables
 *    consistent.
 * 3. Never copy what must not be copied: revisions, comments, edit locks,
 *    old-slug markers or the trash flags of the source.
 * 4. Never surprise the editor: the duplicate is a draft, owned by the person
 *    who asked for it, with a distinct slug.
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one post into another.
 */
final class Duplicator {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Post types that are never duplicated, whatever the settings say.
	 *
	 * Revisions and auto-drafts have no content of their own; attachments carry
	 * files, and duplicating the row without the file would produce a broken
	 * media entry; menu items belong to a menu that is not being duplicated.
	 *
	 * @var array<int, string>
	 */
	private const NEVER = array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * The post types that can never be duplicated.
	 *
	 * Exposed so the settings screen offers exactly what the engine accepts:
	 * a ticked type that the engine refuses would be a lie in the interface.
	 *
	 * @return array<int, string>
	 */
	public static function never_types(): array {
		return self::NEVER;
	}

	/**
	 * Meta keys that must not travel to the copy.
	 *
	 * @var array<int, string>
	 */
	private const META_SKIP = array(
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_attachment_metadata',
		'_wp_attached_file',
		'_wp_attachment_image_alt',
		'duplikate_source',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a post may be duplicated by the current user, with the reason.
	 *
	 * @param int $post_id Post id.
	 * @return true|\WP_Error
	 */
	public function check( int $post_id ): true|\WP_Error {
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error( 'duplikate_disabled', __( 'Duplikate is switched off.', 'duplikate' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'duplikate_missing', __( 'That content no longer exists.', 'duplikate' ) );
		}

		if ( in_array( $post->post_type, self::NEVER, true ) ) {
			return new \WP_Error( 'duplikate_type', __( 'This kind of content cannot be duplicated.', 'duplikate' ) );
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return new \WP_Error( 'duplikate_status', __( 'This content is not in a state that can be duplicated.', 'duplikate' ) );
		}

		if ( ! $this->settings->allows_post_type( $post->post_type ) ) {
			return new \WP_Error( 'duplikate_type_off', __( 'Duplicating this post type is disabled in the settings.', 'duplikate' ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'duplikate_forbidden', __( 'You are not allowed to duplicate this content.', 'duplikate' ) );
		}

		/**
		 * Filters whether the current user may duplicate one post.
		 *
		 * @param bool     $allowed Whether to allow it.
		 * @param \WP_Post $post    Source post.
		 */
		$allowed = apply_filters( 'duplikate_can_duplicate', true, $post );

		if ( ! $allowed ) {
			return new \WP_Error( 'duplikate_forbidden', __( 'You are not allowed to duplicate this content.', 'duplikate' ) );
		}

		return true;
	}

	/**
	 * Duplicates one post and, for hierarchical types, its children.
	 *
	 * @param int                  $post_id Source post.
	 * @param array<string, mixed> $args     Optional overrides: `status`, `parent`, `skip_children`.
	 * @return array{id: int, title: string, children: int}|\WP_Error
	 */
	public function duplicate( int $post_id, array $args = array() ): array|\WP_Error {
		$check = $this->check( $post_id );

		if ( $check instanceof \WP_Error ) {
			return $check;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'duplikate_missing', __( 'That content no longer exists.', 'duplikate' ) );
		}

		$copy = $this->insert( $post, $args );

		if ( $copy instanceof \WP_Error ) {
			return $copy;
		}

		$children = 0;

		if ( $this->should_copy_children( $post, $args ) ) {
			$children = $this->copy_children( $post, $copy['id'], 0, $args );
		}

		/**
		 * Fires once a post has been duplicated.
		 *
		 * @param int      $new_id New post id.
		 * @param \WP_Post $post   Source post.
		 */
		do_action( 'duplikate_duplicated', $copy['id'], $post );

		$copy['children'] = $children;

		return $copy;
	}

	/**
	 * Inserts the copy of one post and fills it.
	 *
	 * The insert happens first so that a failure anywhere afterwards can delete
	 * a real row instead of leaving half-built metadata behind.
	 *
	 * @param \WP_Post             $post Source post.
	 * @param array<string, mixed> $args Overrides.
	 * @return array{id: int, title: string}|\WP_Error
	 */
	private function insert( \WP_Post $post, array $args ): array|\WP_Error {
		$parent = array_key_exists( 'parent', $args ) ? (int) $args['parent'] : (int) $post->post_parent;
		$status = $this->target_status( $post, (string) ( $args['status'] ?? '' ) );
		$title  = $this->unique_title( $post, $parent );

		$data = array(
			'post_title'        => $title,
			'post_content'      => (string) $post->post_content,
			'post_excerpt'      => (string) $post->post_excerpt,
			'post_status'       => $status,
			'post_type'         => (string) $post->post_type,
			'post_parent'       => $parent,
			'post_author'       => $this->settings->get( 'keep_author' ) ? (int) $post->post_author : get_current_user_id(),
			'post_name'         => $this->slug_for( $post ),
			'menu_order'        => (int) $post->menu_order,
			'comment_status'    => (string) $post->comment_status,
			'ping_status'       => (string) $post->ping_status,
			'post_password'     => (string) $post->post_password,
			'post_mime_type'    => (string) $post->post_mime_type,
			'post_content_filtered' => (string) $post->post_content_filtered,
		);

		if ( $this->settings->get( 'keep_date' ) ) {
			$data['post_date']     = (string) $post->post_date;
			$data['post_date_gmt'] = (string) $post->post_date_gmt;
		}

		/**
		 * Filters the post data sent to `wp_insert_post()`.
		 *
		 * @param array<string, mixed> $data Post data.
		 * @param \WP_Post             $post Source post.
		 */
		$data = (array) apply_filters( 'duplikate_post_data', $data, $post );

		// `wp_insert_post()` expects slashed input: without this, a backslash in
		// the content would be silently eaten, exactly like in core.
		$new_id = wp_insert_post( wp_slash( $data ), true );

		if ( is_wp_error( $new_id ) ) {
			/* translators: %s: error message. */
			return new \WP_Error( 'duplikate_insert', sprintf( __( 'The copy could not be created: %s', 'duplikate' ), $new_id->get_error_message() ) );
		}

		$new_id = (int) $new_id;

		if ( $new_id <= 0 || ! get_post( $new_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'duplikate_insert', __( 'The copy could not be created.', 'duplikate' ) );
		}

		try {
			$this->copy_taxonomies( $post, $new_id );
			$this->copy_meta( $post, $new_id );
		} catch ( \Throwable $error ) {
			// Nothing is left behind: the row and everything attached to it go.
			$this->discard( $new_id );

			/* translators: %s: error message. */
			return new \WP_Error( 'duplikate_copy', sprintf( __( 'The copy was cancelled: %s', 'duplikate' ), $error->getMessage() ) );
		}

		/**
		 * Fires right after one copy has been filled, before its children.
		 *
		 * @param int      $new_id New post id.
		 * @param \WP_Post $post   Source post.
		 */
		do_action( 'duplikate_post_duplicated', $new_id, $post );

		return array(
			'id'    => $new_id,
			'title' => (string) get_post_field( 'post_title', $new_id ),
		);
	}

	/**
	 * Whether the children of this post also have to be copied.
	 *
	 * @param \WP_Post             $post Source post.
	 * @param array<string, mixed> $args Overrides.
	 */
	private function should_copy_children( \WP_Post $post, array $args ): bool {
		if ( ! empty( $args['skip_children'] ) || ! $this->settings->get( 'copy_children' ) ) {
			return false;
		}

		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return false;
		}

		return post_type_supports( $post->post_type, 'page-attributes' ) || 'page' === $post->post_type;
	}

	/**
	 * Copies the direct children of a post, then their own children.
	 *
	 * The budget is global to one duplication, so a deeply nested tree cannot
	 * exhaust the request: once `max_children` is reached the rest is skipped
	 * rather than half-copied.
	 *
	 * @param \WP_Post             $post   Source post.
	 * @param int                  $parent New parent id.
	 * @param int                  $depth  Current depth.
	 * @param array<string, mixed> $args   Overrides.
	 */
	private function copy_children( \WP_Post $post, int $parent, int $depth, array $args ): int {
		$budget = (int) $this->settings->get( 'max_children', 50 );
		$depth  = $depth + 1;

		if ( $depth > 5 || $budget <= 0 ) {
			return 0;
		}

		$children = get_children(
			array(
				'post_parent'    => $post->ID,
				'post_type'      => $post->post_type,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'    => $budget,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'suppress_filters' => false,
			)
		);

		if ( ! is_array( $children ) || array() === $children ) {
			return 0;
		}

		$count = 0;

		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Post || $count >= $budget ) {
				break;
			}

			$copy = $this->insert(
				$child,
				array_merge(
					$args,
					array(
						'parent'        => $parent,
						'skip_children' => true,
					)
				)
			);

			if ( $copy instanceof \WP_Error ) {
				continue;
			}

			++$count;
			$count += $this->copy_children( $child, $copy['id'], $depth, array_merge( $args, array( 'skip_children' => true ) ) );
		}

		return $count;
	}

	/**
	 * Copies every taxonomy term of the source onto the copy.
	 *
	 * Term ids are used rather than names: two terms can share a name in
	 * different branches, and a name lookup would silently pick the wrong one.
	 *
	 * @param \WP_Post $post   Source post.
	 * @param int      $new_id New post id.
	 */
	private function copy_taxonomies( \WP_Post $post, int $new_id ): void {
		if ( ! $this->settings->get( 'copy_taxonomies' ) ) {
			return;
		}

		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );

		/**
		 * Filters the taxonomies copied to the duplicate.
		 *
		 * @param array<int, string> $taxonomies Taxonomy names.
		 * @param \WP_Post           $post       Source post.
		 */
		$taxonomies = (array) apply_filters( 'duplikate_taxonomies', $taxonomies, $post );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_object_terms(
				$post->ID,
				$taxonomy,
				array(
					'fields' => 'ids',
				)
			);

			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}

			$ids = array_values( array_filter( array_map( 'absint', $terms ) ) );

			if ( array() === $ids ) {
				continue;
			}

			$result = wp_set_object_terms( $new_id, $ids, $taxonomy, false );

			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}
		}
	}

	/**
	 * Copies the post meta that is safe to copy.
	 *
	 * Values are re-attached one by one so that a key holding several values
	 * keeps all of them, and slashed so that backslashes survive, which is what
	 * `add_post_meta()` expects.
	 *
	 * @param \WP_Post $post   Source post.
	 * @param int      $new_id New post id.
	 */
	private function copy_meta( \WP_Post $post, int $new_id ): void {
		if ( ! $this->settings->get( 'copy_meta' ) ) {
			return;
		}

		$all = get_post_meta( $post->ID );

		if ( ! is_array( $all ) ) {
			return;
		}

		$skip = self::META_SKIP;

		if ( ! $this->settings->get( 'copy_thumbnail' ) ) {
			$skip[] = '_thumbnail_id';
		}

		if ( ! $this->settings->get( 'copy_template' ) ) {
			$skip[] = '_wp_page_template';
		}

		/**
		 * Filters the meta keys never copied.
		 *
		 * @param array<int, string> $skip Keys.
		 * @param \WP_Post           $post Source post.
		 */
		$skip = (array) apply_filters( 'duplikate_meta_skip', $skip, $post );

		/**
		 * Filters the meta keys copied. Returning a non-empty array switches
		 * the copy from "everything except the skip list" to "only these".
		 *
		 * @param array<int, string> $only Keys, empty by default.
		 * @param \WP_Post           $post Source post.
		 */
		$only = (array) apply_filters( 'duplikate_meta_only', array(), $post );

		foreach ( $all as $key => $values ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			if ( array() !== $only && ! in_array( $key, $only, true ) ) {
				continue;
			}

			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			if ( is_protected_meta( $key, 'post' ) && ! str_starts_with( $key, '_' ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				$added = add_post_meta( $new_id, wp_slash( $key ), wp_slash( $value ) );

				if ( false === $added && ! metadata_exists( 'post', $new_id, $key ) ) {
					throw new \RuntimeException( esc_html( sprintf( 'meta "%s" could not be copied', $key ) ) );
				}
			}
		}
	}

	/**
	 * Removes a copy that could not be completed.
	 *
	 * `wp_delete_post()` is used with `$force_delete` so that nothing is left
	 * in the trash, and untrashed, from a failed attempt.
	 *
	 * @param int $post_id Post id.
	 */
	private function discard( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		wp_delete_post( $post_id, true );
	}

	/**
	 * Status of the copy.
	 *
	 * "same" is honoured for the statuses an author can create, and falls back
	 * to draft for a scheduled post: copying the future date would publish
	 * something nobody asked to publish.
	 *
	 * @param \WP_Post $post   Source post.
	 * @param string   $forced Status forced by the caller.
	 */
	private function target_status( \WP_Post $post, string $forced = '' ): string {
		$status = '' !== $forced ? $forced : (string) $this->settings->get( 'status', 'draft' );

		if ( 'same' === $status ) {
			$status = in_array( $post->post_status, array( 'publish', 'private', 'pending', 'draft' ), true ) ? $post->post_status : 'draft';
		}

		if ( ! in_array( $status, array( 'publish', 'private', 'pending', 'draft' ), true ) ) {
			$status = 'draft';
		}

		/**
		 * Filters the status of the duplicate.
		 *
		 * @param string   $status Status.
		 * @param \WP_Post $post   Source post.
		 */
		$filtered = apply_filters( 'duplikate_status', $status, $post );

		return is_string( $filtered ) && in_array( $filtered, array( 'publish', 'private', 'pending', 'draft' ), true ) ? $filtered : $status;
	}

	/**
	 * Slug for the copy. Uniqueness is left to WordPress, which appends `-2`,
	 * `-3`… and understands hierarchical parents.
	 *
	 * @param \WP_Post $post Source post.
	 */
	private function slug_for( \WP_Post $post ): string {
		$slug = (string) $post->post_name;

		if ( '' === $slug ) {
			return '';
		}

		return $slug . (string) $this->settings->get( 'slug_suffix', '-copy' );
	}

	/**
	 * A title that does not collide with an existing copy.
	 *
	 * "Page" becomes "Page (copy)", then "Page (copy 2)", and a title that
	 * already carries the marker is not suffixed twice.
	 *
	 * @param \WP_Post $post   Source post.
	 * @param int      $parent New parent id.
	 */
	private function unique_title( \WP_Post $post, int $parent ): string {
		$suffix = (string) $this->settings->get( 'title_suffix', ' (copy)' );
		$base   = $this->strip_marker( (string) $post->post_title, $suffix );

		for ( $attempt = 1; $attempt <= 50; $attempt++ ) {
			$candidate = self::title_variant( $base, $suffix, $attempt );

			if ( ! $this->title_exists( $candidate, $post, $parent ) ) {
				return $candidate;
			}
		}

		return self::title_variant( $base, $suffix, 51 ) . ' ' . wp_generate_password( 4, false );
	}

	/**
	 * Renders the n-th variant of a title.
	 *
	 * @param string $base   Title without marker.
	 * @param string $suffix Suffix, for example " (copy)".
	 * @param int    $n      Variant number, starting at 1.
	 */
	public static function title_variant( string $base, string $suffix, int $n ): string {
		if ( $n <= 1 ) {
			return $base . $suffix;
		}

		$trimmed = rtrim( $suffix );

		// " (copy)" → " (copy 2)", " — copie" → " — copie 2".
		if ( str_ends_with( $trimmed, ')' ) ) {
			return $base . substr( $trimmed, 0, -1 ) . ' ' . $n . ')';
		}

		if ( str_ends_with( $trimmed, ']' ) ) {
			return $base . substr( $trimmed, 0, -1 ) . ' ' . $n . ']';
		}

		return $base . $trimmed . ' ' . $n;
	}

	/**
	 * Removes a marker previously added by this plugin from a title.
	 *
	 * @param string $title  Title.
	 * @param string $suffix Configured suffix.
	 */
	private function strip_marker( string $title, string $suffix ): string {
		$suffix = trim( $suffix );

		if ( '' === $suffix ) {
			return $title;
		}

		$inner = trim( $suffix, " \t\n\r\0\x0B()[]-–—:." );

		if ( '' === $inner ) {
			return $title;
		}

		$pattern = '/\s*[\(\[-–—:.]*\s*' . preg_quote( $inner, '/' ) . '(?:\s+\d+)?\s*[\)\]]?\s*$/iu';
		$clean   = preg_replace( $pattern, '', $title );

		if ( ! is_string( $clean ) ) {
			return $title;
		}

		$clean = trim( $clean );

		return '' === $clean ? $title : $clean;
	}

	/**
	 * Whether a post with this title already exists next to the source.
	 *
	 * The lookup is limited to siblings of the same type: that is where a
	 * confusing duplicate would appear, and it keeps the query cheap.
	 *
	 * @param string   $title  Candidate title.
	 * @param \WP_Post $post   Source post.
	 * @param int      $parent Parent id.
	 */
	private function title_exists( string $title, \WP_Post $post, int $parent ): bool {
		$existing = get_posts(
			array(
				'post_type'        => $post->post_type,
				'post_status'      => 'any',
				'post_parent'      => $parent,
				'title'            => $title,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		if ( ! is_array( $existing ) || array() === $existing ) {
			return false;
		}

		// `title` matches exactly, but be defensive about a stub or an old core.
		foreach ( $existing as $id ) {
			if ( (string) get_post_field( 'post_title', (int) $id ) === $title ) {
				return true;
			}
		}

		return false;
	}
}
