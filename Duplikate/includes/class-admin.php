<?php
/**
 * Admin: row actions, bulk action, submit box, settings screen, notices.
 *
 * Visual language: the same white Swiss system as the rest of the suite —
 * hairline rules, 2 px corners, one accent, Space Grotesk headings and
 * JetBrains Mono micro-labels, all embedded locally.
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that runs inside wp-admin.
 */
final class Admin {

	/**
	 * Where the plugin comes from.
	 */
	private const AUTHOR_SITE = 'https://2points13.fr';
	private const DEV_SITE    = 'https://zigmoon.com';
	private const DOCS_SITE   = 'https://github.com/zigmoon/duplikate#readme';

	/**
	 * How many posts one bulk run may duplicate.
	 */
	private const BULK_LIMIT = 25;

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
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_duplikate_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_duplikate_dismiss_welcome', array( $this, 'handle_dismiss_welcome' ) );
		add_action( 'admin_action_duplikate_duplicate', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'submit_box' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'plugin_action_links_' . DUPLIKATE_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		foreach ( $this->settings->post_types() as $post_type ) {
			add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'handle_bulk' ), 10, 3 );
		}
	}

	/**
	 * Settings page under Settings.
	 */
	public function menu(): void {
		add_options_page(
			__( 'Duplikate', 'duplikate' ),
			__( 'Duplikate', 'duplikate' ),
			'manage_options',
			'duplikate',
			array( $this, 'render' )
		);
	}

	/**
	 * Assets, on our screen only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_duplikate' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'duplikate-admin', DUPLIKATE_URL . 'assets/css/admin.css', array(), DUPLIKATE_VERSION );
		wp_enqueue_script( 'duplikate-admin', DUPLIKATE_URL . 'assets/js/admin.js', array(), DUPLIKATE_VERSION, true );
	}

	/**
	 * Script for the block editor: the same action, inside the post status panel.
	 *
	 * The nonce is per post and is built here, so the script only ever has to
	 * follow a URL — no REST round trip, no second way in.
	 */
	public function editor_assets(): void {
		$post = get_post();

		if ( ! $post instanceof \WP_Post || $post->ID <= 0 || ! $this->settings->get( 'row_action' ) ) {
			return;
		}

		if ( true !== $this->duplicator->check( (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			'duplikate-editor',
			DUPLIKATE_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element' ),
			DUPLIKATE_VERSION,
			true
		);

		wp_enqueue_style(
			'duplikate-editor',
			DUPLIKATE_URL . 'assets/css/editor.css',
			array(),
			DUPLIKATE_VERSION
		);

		wp_localize_script(
			'duplikate-editor',
			'duplikateEditor',
			array(
				'url'   => wp_nonce_url(
					admin_url( 'admin.php?action=duplikate_duplicate&post=' . (int) $post->ID ),
					'duplikate_duplicate_' . (int) $post->ID
				),
				'label' => __( 'Duplicate', 'duplikate' ),
				'hint'  => __( 'creates a draft copy', 'duplikate' ),
			)
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=duplikate' ) ) . '">' . esc_html__( 'Settings', 'duplikate' ) . '</a>';

		return $links;
	}

	/**
	 * Origin links on the Plugins row.
	 *
	 * @param array<int, string> $meta Existing meta.
	 * @param string             $file Plugin basename.
	 * @return array<int, string>
	 */
	public function row_meta( array $meta, string $file = '' ): array {
		if ( '' !== $file && DUPLIKATE_BASENAME !== $file ) {
			return $meta;
		}

		$meta[] = $this->external_link( self::AUTHOR_SITE, '2.13' );
		$meta[] = $this->external_link( self::DEV_SITE, __( 'dev by zigmoon', 'duplikate' ) );
		$meta[] = $this->external_link( self::DOCS_SITE, __( 'Documentation', 'duplikate' ) );

		return $meta;
	}

	/**
	 * "Duplicate" under the title of every eligible row.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @param \WP_Post              $post    Post of the row.
	 * @return array<string, string>
	 */
	public function row_action( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post || ! $this->settings->get( 'row_action' ) ) {
			return $actions;
		}

		if ( true !== $this->duplicator->check( (int) $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=duplikate_duplicate&post=' . (int) $post->ID ),
			'duplikate_duplicate_' . (int) $post->ID
		);

		$actions['duplikate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr(
				sprintf(
					/* translators: %s: post title. */
					__( 'Duplicate “%s”', 'duplikate' ),
					wp_strip_all_tags( get_the_title( $post ) )
				)
			),
			esc_html__( 'Duplicate', 'duplikate' )
		);

		return $actions;
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @return array<string, string>
	 */
	public function bulk_action( array $actions ): array {
		if ( $this->settings->get( 'bulk_action' ) && $this->settings->is_enabled() ) {
			$actions['duplikate'] = __( 'Duplicate', 'duplikate' );
		}

		return $actions;
	}

	/**
	 * Runs the bulk action and redirects with a summary.
	 *
	 * @param string             $redirect_to Redirect target, untouched.
	 * @param string             $action      Action key.
	 * @param array<int, string> $post_ids    Selected ids.
	 */
	public function handle_bulk( $redirect_to, $action, $post_ids ) {
		if ( 'duplikate' !== $action ) {
			return $redirect_to;
		}

		$ids     = is_array( $post_ids ) ? array_map( 'absint', $post_ids ) : array();
		$ids     = array_values( array_filter( $ids ) );
		$done    = 0;
		$failed  = 0;
		$skipped = max( 0, count( $ids ) - self::BULK_LIMIT );

		foreach ( array_slice( $ids, 0, self::BULK_LIMIT ) as $id ) {
			$result = $this->duplicator->duplicate( $id );

			if ( $result instanceof \WP_Error ) {
				++$failed;
				continue;
			}

			++$done;
		}

		$args = array( 'duplikate_done' => $done );

		if ( $failed > 0 ) {
			$args['duplikate_failed'] = $failed;
		}

		if ( $skipped > 0 ) {
			$args['duplikate_skipped'] = $skipped;
		}

		return add_query_arg( $args, $redirect_to );
	}

	/**
	 * Handles the row action.
	 */
	public function handle_duplicate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'No content was selected.', 'duplikate' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'duplikate_duplicate_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this content.', 'duplikate' ), '', array( 'response' => 403 ) );
		}

		$result = $this->duplicator->duplicate( $post_id );

		if ( $result instanceof \WP_Error ) {
			$this->redirect_back( array( 'duplikate_error' => $result->get_error_code() ), $post_id );
		}

		$target = 'edit' === $this->settings->get( 'redirect' )
			? get_edit_post_link( $result['id'], 'raw' )
			: wp_get_referer();

		if ( ! is_string( $target ) || '' === $target ) {
			$target = admin_url( 'edit.php' );
		}

		$this->redirect_trusted(
			add_query_arg(
				array(
					'duplikate_done' => 1,
				),
				$target
			)
		);
	}

	/**
	 * Sends the visitor back to the list with an error code.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @param int                  $post Post id, used when there is no referer.
	 */
	private function redirect_back( array $args, int $post = 0 ): void {
		$target = wp_get_referer();

		if ( ! is_string( $target ) || '' === $target ) {
			$target = $post > 0 ? (string) get_edit_post_link( $post, 'raw' ) : '';
		}

		if ( '' === $target ) {
			$target = admin_url( 'edit.php' );
		}

		$this->redirect_trusted( add_query_arg( $args, $target ) );
	}

	/**
	 * Redirects to a URL that belongs to this site, then stops.
	 *
	 * `wp_safe_redirect()` is used everywhere so that a crafted referer cannot
	 * turn the duplicate link into an open redirect.
	 *
	 * @param string $url Target.
	 */
	private function redirect_trusted( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Adds a duplicate button to the publish box.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function submit_box( $post ): void {
		if ( ! $post instanceof \WP_Post || $post->ID <= 0 || ! $this->settings->get( 'row_action' ) ) {
			return;
		}

		if ( true !== $this->duplicator->check( (int) $post->ID ) ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=duplikate_duplicate&post=' . (int) $post->ID ),
			'duplikate_duplicate_' . (int) $post->ID
		);
		?>
		<div class="misc-pub-section duplikate-action">
			<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Duplicate', 'duplikate' ); ?></a>
			<span class="duplikate-action-hint"><?php esc_html_e( 'creates a draft copy', 'duplikate' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Saves the settings form.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'duplikate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'duplikate_save' );

		$raw = isset( $_POST['duplikate'] ) && is_array( $_POST['duplikate'] ) ? wp_unslash( $_POST['duplikate'] ) : array();

		$this->settings->update( $raw );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'duplikate',
					'duplikate_saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Dismisses the welcome notice.
	 */
	public function handle_dismiss_welcome(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'duplikate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'duplikate_dismiss_welcome' );

		delete_option( Settings::WELCOME_OPTION );

		$referer = function_exists( 'wp_get_referer' ) ? (string) wp_get_referer() : '';

		wp_safe_redirect( '' !== $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Admin notices: result of a duplication, result of a save, first run.
	 */
	public function notices(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$done    = isset( $_GET['duplikate_done'] ) ? absint( wp_unslash( $_GET['duplikate_done'] ) ) : 0;
		$failed  = isset( $_GET['duplikate_failed'] ) ? absint( wp_unslash( $_GET['duplikate_failed'] ) ) : 0;
		$skipped = isset( $_GET['duplikate_skipped'] ) ? absint( wp_unslash( $_GET['duplikate_skipped'] ) ) : 0;
		$error   = isset( $_GET['duplikate_error'] ) && is_string( $_GET['duplikate_error'] ) ? sanitize_key( wp_unslash( $_GET['duplikate_error'] ) ) : '';
		$saved   = isset( $_GET['duplikate_saved'] ) ? (string) sanitize_key( wp_unslash( $_GET['duplikate_saved'] ) ) : '';

		if ( 'duplikate' !== $page ) {
			$this->maybe_welcome_notice();
		} else {
			// The settings screen is open: the welcome notice has done its job.
			if ( false !== get_option( Settings::WELCOME_OPTION, false ) ) {
				delete_option( Settings::WELCOME_OPTION );
			}
		}

		if ( $done > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of duplicates. */
						_n( '%d draft copy created.', '%d draft copies created.', $done, 'duplikate' ),
						$done
					)
				)
			);
		}

		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of failures. */
						_n( '%d item could not be duplicated.', '%d items could not be duplicated.', $failed, 'duplikate' ),
						$failed
					)
				)
			);
		}

		if ( $skipped > 0 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: skipped items, 2: limit. */
						__( '%1$d item(s) were left alone: one run duplicates at most %2$d items.', 'duplikate' ),
						$skipped,
						self::BULK_LIMIT
					)
				)
			);
		}

		if ( '' !== $error ) {
			$messages = array(
				'duplikate_disabled'  => __( 'Duplikate is switched off.', 'duplikate' ),
				'duplikate_missing'   => __( 'That content no longer exists.', 'duplikate' ),
				'duplikate_type'      => __( 'This kind of content cannot be duplicated.', 'duplikate' ),
				'duplikate_type_off'  => __( 'Duplicating this post type is disabled in the settings.', 'duplikate' ),
				'duplikate_status'    => __( 'This content is not in a state that can be duplicated.', 'duplikate' ),
				'duplikate_forbidden' => __( 'You are not allowed to duplicate this content.', 'duplikate' ),
				'duplikate_insert'    => __( 'The copy could not be created.', 'duplikate' ),
				'duplikate_copy'      => __( 'The copy was cancelled and removed, so nothing was left half-written.', 'duplikate' ),
			);

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $messages[ $error ] ?? __( 'The copy could not be created.', 'duplikate' ) )
			);
		}

		if ( '1' === $saved && 'duplikate' === $page ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'duplikate' )
			);
		}
	}

	/**
	 * The one-time notice shown after activation.
	 */
	private function maybe_welcome_notice(): void {
		if ( false === get_option( Settings::WELCOME_OPTION, false ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=duplikate' );
		$dismiss_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=duplikate_dismiss_welcome' ),
			'duplikate_dismiss_welcome'
		);

		printf(
			'<div class="notice notice-info duplikate-welcome"><p><strong>%s</strong> %s</p><p class="duplikate-welcome-actions"><a class="button button-primary" href="%s">%s</a> <a class="button button-secondary" href="%s">%s</a></p><p class="duplikate-welcome-credit">%s <span aria-hidden="true">·</span> %s</p></div>',
			esc_html__( 'Duplikate is active.', 'duplikate' ),
			esc_html__( 'Open a post or a page list: a “Duplicate” link now sits under every title.', 'duplikate' ),
			esc_url( $settings_url ),
			esc_html__( 'Choose what is copied', 'duplikate' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'duplikate' ),
			$this->external_link( self::AUTHOR_SITE, '2points13.fr' ),
			$this->external_link( self::DEV_SITE, 'zigmoon.com' )
		);
	}

	/**
	 * The credits line: version, author, developer.
	 */
	private function credit_line(): string {
		return sprintf(
			/* translators: 1: plugin version, 2: author link, 3: developer link. */
			esc_html__( 'Duplikate %1$s — a plugin by %2$s, dev by %3$s.', 'duplikate' ),
			esc_html( DUPLIKATE_VERSION ),
			$this->external_link( self::AUTHOR_SITE, '2.13' ),
			$this->external_link( self::DEV_SITE, 'zigmoon' )
		);
	}

	/**
	 * A link that leaves the dashboard.
	 *
	 * @param string $url   Target.
	 * @param string $label Visible label.
	 */
	private function external_link( string $url, string $label ): string {
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->all();
		$types    = $this->selectable_post_types();
		$chosen   = (array) $settings['post_types'];

		$statuses = array(
			'draft'   => __( 'Draft', 'duplikate' ),
			'pending' => __( 'Pending review', 'duplikate' ),
			'private' => __( 'Private', 'duplikate' ),
			'same'    => __( 'Same as the original', 'duplikate' ),
		);
		?>
		<div class="wrap dup-admin" data-dup-admin>
			<header class="dup-admin-head">
				<div class="dup-brand">
					<span class="dup-brand-mark" aria-hidden="true">
						<svg viewBox="0 0 64 64" width="34" height="34" focusable="false" aria-hidden="true">
							<rect width="64" height="64" rx="8" fill="#f7f8fa"/>
							<g fill="none" stroke="#0b0d10" stroke-width="4.2" stroke-linecap="square">
								<path d="M20 46V30a9 9 0 0 1 18 0v16"/>
								<path d="M15 46h28"/>
							</g>
							<circle cx="29" cy="31" r="2.7" fill="#0b0d10"/>
							<path d="M27.6 33h2.8v5.4h-2.8Z" fill="#0b0d10"/>
							<path d="M42 20h7v7" fill="none" stroke="#0a66ff" stroke-width="3" stroke-linecap="square"/>
							<path d="M49 20 38 31" fill="none" stroke="#0a66ff" stroke-width="3" stroke-linecap="square"/>
						</svg>
					</span>
					<div>
						<h1>
							Duplikate
							<span class="dup-state <?php echo $settings['enabled'] ? 'is-on' : 'is-off'; ?>">
								<?php echo $settings['enabled'] ? esc_html__( 'Active', 'duplikate' ) : esc_html__( 'Off', 'duplikate' ); ?>
							</span>
						</h1>
						<p class="dup-lead">
							<?php esc_html_e( 'One click under any title: a draft copy with its hierarchy, its terms and its metadata — and nothing left half-written if something fails.', 'duplikate' ); ?>
						</p>
					</div>
				</div>
				<p class="dup-meta">
					<?php
					printf(
						/* translators: 1: version, 2: PHP version, 3: WordPress version. */
						esc_html__( 'v%s · PHP %s+ · WordPress %s+', 'duplikate' ),
						esc_html( DUPLIKATE_VERSION ),
						'8.2',
						'6.5'
					);
					?>
				</p>
			</header>

			<nav class="dup-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Duplikate sections', 'duplikate' ); ?>">
				<button type="button" class="dup-tab" role="tab" id="dup-tab-general" aria-controls="dup-panel-general" aria-selected="true" data-dup-tab="general"><?php esc_html_e( 'General', 'duplikate' ); ?></button>
				<button type="button" class="dup-tab" role="tab" id="dup-tab-content" aria-controls="dup-panel-content" aria-selected="false" tabindex="-1" data-dup-tab="content"><?php esc_html_e( 'What is copied', 'duplikate' ); ?></button>
				<button type="button" class="dup-tab" role="tab" id="dup-tab-advanced" aria-controls="dup-panel-advanced" aria-selected="false" tabindex="-1" data-dup-tab="advanced"><?php esc_html_e( 'Advanced', 'duplikate' ); ?></button>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dup-form">
				<input type="hidden" name="action" value="duplikate_save">
				<?php wp_nonce_field( 'duplikate_save' ); ?>

				<div class="dup-panel" id="dup-panel-general" role="tabpanel" aria-labelledby="dup-tab-general" data-dup-panel="general">
					<?php
					$this->field_switch(
						'enabled',
						__( 'Enable Duplikate', 'duplikate' ),
						(bool) $settings['enabled'],
						__( 'Switching this off removes every Duplicate link without touching a single post.', 'duplikate' )
					);

					$this->section( __( 'Post types', 'duplikate' ) );
					?>
					<p class="dup-help"><?php esc_html_e( 'The Duplicate link appears in the list and the editor of the types you tick.', 'duplikate' ); ?></p>
					<div class="dup-checks">
						<?php foreach ( $types as $name => $label ) : ?>
							<label class="dup-check">
								<input type="checkbox" name="duplikate[post_types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $chosen, true ) ); ?>>
								<span><?php echo esc_html( $label ); ?></span>
								<code><?php echo esc_html( $name ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>

					<?php
					$this->section( __( 'The copy', 'duplikate' ) );
					$this->field_select( 'status', __( 'Status of the copy', 'duplikate' ), (string) $settings['status'], $statuses, __( 'Draft is the safe default: nothing appears publicly until someone publishes it.', 'duplikate' ) );
					$this->field_select(
						'redirect',
						__( 'After duplicating', 'duplikate' ),
						(string) $settings['redirect'],
						array(
							'edit' => __( 'Open the new draft in the editor', 'duplikate' ),
							'list' => __( 'Stay on the list', 'duplikate' ),
						),
						__( 'With the list, the copy is marked as a draft and you can keep working.', 'duplikate' )
					);
					?>
				</div>

				<div class="dup-panel" id="dup-panel-content" role="tabpanel" aria-labelledby="dup-tab-content" data-dup-panel="content" hidden>
					<?php
					$this->field_switch( 'copy_children', __( 'Copy child pages', 'duplikate' ), (bool) $settings['copy_children'], __( 'A page that has children is copied with its whole branch, and every child is reattached to the new parent.', 'duplikate' ) );
					$this->field_switch( 'copy_taxonomies', __( 'Copy categories and tags', 'duplikate' ), (bool) $settings['copy_taxonomies'], __( 'Every term of every taxonomy attached to the type is copied, by id, so two terms with the same name cannot be confused.', 'duplikate' ) );
					$this->field_switch( 'copy_meta', __( 'Copy custom fields', 'duplikate' ), (bool) $settings['copy_meta'], __( 'Shop settings, layout options, SEO fields: everything except edit locks, old slugs and the trash markers.', 'duplikate' ) );
					$this->field_switch( 'copy_thumbnail', __( 'Copy the featured image', 'duplikate' ), (bool) $settings['copy_thumbnail'], __( 'The copy points at the same image; the file itself is not duplicated.', 'duplikate' ) );
					$this->field_switch( 'copy_template', __( 'Copy the page template', 'duplikate' ), (bool) $settings['copy_template'], __( 'Keeps the template chosen for the original.', 'duplikate' ) );

					$this->section( __( 'Names', 'duplikate' ) );
					$this->field_text( 'title_suffix', __( 'Title suffix', 'duplikate' ), (string) $settings['title_suffix'], __( 'Added to the title. The second copy becomes “(copy 2)”, the third “(copy 3)”, and a title that already carries the suffix is not suffixed twice.', 'duplikate' ), ' (copy)' );
					$this->field_text( 'slug_suffix', __( 'Slug suffix', 'duplikate' ), (string) $settings['slug_suffix'], __( 'Added to the permalink. WordPress makes it unique on its own, so it never collides with an existing page.', 'duplikate' ), '-copy' );
					?>
				</div>

				<div class="dup-panel" id="dup-panel-advanced" role="tabpanel" aria-labelledby="dup-tab-advanced" data-dup-panel="advanced" hidden>
					<?php
					$this->field_switch( 'keep_author', __( 'Keep the original author', 'duplikate' ), (bool) $settings['keep_author'], __( 'Off, the copy belongs to whoever duplicated it — the usual choice when a team works on the same site.', 'duplikate' ) );
					$this->field_switch( 'keep_date', __( 'Keep the original date', 'duplikate' ), (bool) $settings['keep_date'], __( 'Off, the copy is dated now, which keeps the list in a sensible order.', 'duplikate' ) );
					$this->field_switch( 'row_action', __( 'Show the link under each title', 'duplikate' ), (bool) $settings['row_action'], __( 'The “Duplicate” link in the list and the button in the editor.', 'duplikate' ) );
					$this->field_switch( 'bulk_action', __( 'Offer the bulk action', 'duplikate' ), (bool) $settings['bulk_action'], __( 'Adds “Duplicate” to the bulk actions menu of each list.', 'duplikate' ) );

					$this->section( __( 'Limits and cleanup', 'duplikate' ) );
					$this->field_number( 'max_children', __( 'Maximum children per duplication', 'duplikate' ), (int) $settings['max_children'], 1, 200, __( 'A budget, so that a deeply nested tree cannot exhaust the request. Once reached, the remaining children are skipped rather than half-copied.', 'duplikate' ) );
					$this->field_switch( 'delete_data', __( 'Delete all plugin data when the plugin is deleted', 'duplikate' ), (bool) $settings['delete_data'], __( 'Only the settings and the version marker: Duplikate never stores anything about your content.', 'duplikate' ) );
					?>

					<div class="dup-note">
						<strong><?php esc_html_e( 'What is never copied', 'duplikate' ); ?></strong>
						<p><?php esc_html_e( 'Revisions, comments, edit locks, the trash status, the old-slug markers, attachments and menu items. A duplicate is content, not a second history.', 'duplikate' ); ?></p>
					</div>
				</div>

				<div class="dup-savebar">
					<span class="dup-savebar-hint"><?php esc_html_e( 'Changes apply immediately.', 'duplikate' ); ?></span>
					<?php submit_button( __( 'Save changes', 'duplikate' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<footer class="dup-credits">
				<?php // Both helpers escape url and label; the markup is fixed. ?>
				<p class="dup-credits-line"><?php echo $this->credit_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p class="dup-credits-links">
					<?php echo $this->external_link( self::AUTHOR_SITE, '2points13.fr' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span aria-hidden="true">·</span>
					<?php echo $this->external_link( self::DEV_SITE, 'zigmoon.com' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</footer>
		</div>
		<?php
	}

	/**
	 * Post types that can be offered, with a readable label.
	 *
	 * @return array<string, string>
	 */
	private function selectable_post_types(): array {
		$types  = get_post_types( array( 'show_ui' => true ), 'objects' );
		$skip   = Duplicator::never_types();
		$labels = array();

		foreach ( $types as $name => $object ) {
			if ( in_array( $name, $skip, true ) || empty( $object->label ) ) {
				continue;
			}

			$labels[ (string) $name ] = (string) $object->labels->singular_name;
		}

		return $labels;
	}

	/**
	 * Section heading with a hairline rule.
	 *
	 * @param string $label Heading text.
	 */
	private function section( string $label ): void {
		printf( '<h2 class="dup-section"><span>%s</span></h2>', esc_html( $label ) );
	}

	/**
	 * Boolean field rendered as a switch.
	 *
	 * The control is a native checkbox: it submits, works without JavaScript,
	 * keeps its keyboard behaviour, and is exposed as `role="switch"`.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param bool   $checked     Current state.
	 * @param string $description Help text.
	 */
	private function field_switch( string $key, string $label, bool $checked, string $description = '' ): void {
		$id = 'dup-' . $key;
		?>
		<div class="dup-switch-field">
			<input type="hidden" name="duplikate[<?php echo esc_attr( $key ); ?>]" value="0">
			<?php // Nested input: the label needs no `for`, or a click would toggle twice. ?>
			<label>
				<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="duplikate[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?><?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( '' !== $description ) : ?>
				<p class="dup-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Text field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $description Help text.
	 * @param string $placeholder Placeholder.
	 */
	private function field_text( string $key, string $label, string $value, string $description = '', string $placeholder = '' ): void {
		$id = 'dup-' . $key;
		?>
		<div class="dup-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="duplikate[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
			<?php if ( '' !== $description ) : ?>
				<p class="dup-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Number field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param int    $value       Current value.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 * @param string $description Help text.
	 */
	private function field_number( string $key, string $label, int $value, int $min, int $max, string $description = '' ): void {
		$id = 'dup-' . $key;
		?>
		<div class="dup-field dup-field--narrow">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>" name="duplikate[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
			<?php if ( '' !== $description ) : ?>
				<p class="dup-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Select field.
	 *
	 * @param string                $key         Setting key.
	 * @param string                $label       Label.
	 * @param string                $value       Current value.
	 * @param array<string, string> $options     Options.
	 * @param string                $description Help text.
	 */
	private function field_select( string $key, string $label, string $value, array $options, string $description = '' ): void {
		$id = 'dup-' . $key;
		?>
		<div class="dup-field dup-field--narrow">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>" name="duplikate[<?php echo esc_attr( $key ); ?>]"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
				<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $description ) : ?>
				<p class="dup-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
