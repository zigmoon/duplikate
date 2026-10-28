<?php
/**
 * Plugin bootstrap.
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Duplicator.
	 *
	 * @var Duplicator|null
	 */
	private ?Duplicator $duplicator = null;

	/**
	 * Returns the singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the runtime hooks once.
	 */
	public function boot(): void {
		if ( null !== $this->settings ) {
			return;
		}

		$this->settings   = new Settings();
		$this->duplicator = new Duplicator( $this->settings );

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			( new Admin( $this->settings, $this->duplicator ) )->hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register( $this->settings, $this->duplicator );
		}
	}

	/**
	 * Settings instance, for code that needs it after boot.
	 */
	public function settings(): ?Settings {
		return $this->settings;
	}

	/**
	 * Duplicator instance.
	 */
	public function duplicator(): ?Duplicator {
		return $this->duplicator;
	}

	/**
	 * Activation stores the version and arms the welcome notice. Nothing is
	 * written about the content: Duplikate has no table and no per-post data.
	 */
	public static function activate(): void {
		update_option( Settings::VERSION_OPTION, DUPLIKATE_VERSION, false );
		update_option( Settings::WELCOME_OPTION, time(), false );
	}

	/**
	 * Deactivation only removes the transient welcome flag.
	 */
	public static function deactivate(): void {
		delete_option( Settings::WELCOME_OPTION );
	}
}
