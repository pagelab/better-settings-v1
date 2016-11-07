<?php
/**
 * Example Code: Settings Page - Better Implementation v1.
 *
 * This code is part of the article "Using A Config To Write Reusable Code"
 * as published on https://www.alainschlesser.com/.
 *
 * @see       https://www.alainschlesser.com/config-files-for-reusable-code/
 *
 * @package   AlainSchlesser\BetterSettings1
 * @author    Alain Schlesser <alain.schlesser@gmail.com>
 * @license   GPL-2.0+
 * @link      https://www.alainschlesser.com/
 * @copyright 2016 Alain Schlesser
 */

namespace AlainSchlesser\BetterSettings1;

use InvalidArgumentException;

/**
 * Class SettingsPage.
 *
 * This class registers a settings page via the WordPress Settings API.
 *
 * It enables you an entire collection of settings pages and options fields as
 * as hierarchical text representation in your Config file. In this way, you
 * don't have to deal with all the confusing callback code that the WordPress
 * Settings API forces you to use.
 *
 * @package AlainSchlesser\BetterSettings1
 * @author  Alain Schlesser <alain.schlesser@gmail.com>
 */
class SettingsPage {

	use FunctionInvokerTrait;

	/*
	 * Configuration keys to use for the different Config sections.
	 */
	const CONFIG_KEY_PAGES    = 'pages';
	const CONFIG_KEY_SETTINGS = 'settings';

	/**
	 * Config instance.
	 *
	 * @since 0.1.0
	 *
	 * @var ConfigInterface;
	 */
	protected $config;

	/**
	 * Hooks to the settings pages that have been registered.
	 *
	 * @since 0.1.0
	 *
	 * @var array
	 */
	protected $page_hooks = array();

	/**
	 * Instantiate Settings object.
	 *
	 * @since 0.1.0
	 *
	 * @param ConfigInterface $config Config object that contains Settings
	 *                                configuration.
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;
	}

	/**
	 * Register necessary hooks.
	 *
	 * @since 0.1.0
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_init', [ $this, 'init_settings' ] );
	}

	/**
	 * Add the pages from the configuration settings to the WordPress admin
	 * backend.
	 *
	 * @since 0.1.0
	 */
	public function add_pages() {
		$this->iterate( static::CONFIG_KEY_PAGES );
	}

	/**
	 * Initialize the settings persistence.
	 *
	 * @since 0.1.0
	 */
	public function init_settings() {
		$this->iterate( static::CONFIG_KEY_SETTINGS );
	}

	/**
	 * Iterate over a given collection of Config entries.
	 *
	 * @since 0.1.2
	 *
	 * @param string $type Type of entries to iterate over.
	 */
	protected function iterate( $type ) {
		if ( ! $this->config->has_key( "${type}" ) ) {
			return;
		}

		$entries = $this->config->get_key( "${type}" );
		array_walk( $entries, [ $this, "add_${type}_entry" ] );
	}

	/**
	 * Add a single page to the WordPress admin backend.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $data              Arguments for page creation function.
	 * @param string $key               Current page name.
	 * @throws InvalidArgumentException If the page addition function could not
	 *                                  be invoked.
	 */
	protected function add_pages_entry( $data, $name ) {
		// Skip page creation if it already exists. This allows reuse of 1 page
		// for several plugins.
		if ( ! empty( $GLOBALS['admin_page_hooks'][ $name ] ) ) {
			return;
		}

		// If we have a parent slug, add as a submenu instead of a menu.
		$function = array_key_exists( 'parent_slug', $data )
			? 'add_submenu_page'
			: 'add_menu_page';

		// Add the page name as manue slug.
		$data['menu_slug'] = $name;

		// Prepare rendering callback.
		$data['function'] = function () use ( $data ) {
			if ( array_key_exists( 'view', $data ) ) {
				$view = new View( $data['view'] );
				echo $view->render();
			}
		};

		$page_hook          = $this->invoke_function( $function, $data );
		$this->page_hooks[] = $page_hook;
	}

	/**
	 * Add option groups.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $data Arguments for the register_setting WP function.
	 * @param string $name Name of the option group.
	 */
	protected function add_settings_entry( $data, $name ) {
		// Default to using the same option group name as the settings name.
		$option_group = isset( $data['option_group'] )
			? $data['option_group']
			: $name;

		register_setting(
			$option_group,
			$name,
			// Optionally use a sanitization callback.
			isset( $data['sanitize_callback'] )
				? $data['sanitize_callback']
				: null
		);

		// Prepare array to pass to array_walk as third parameter.
		$args['setting_name'] = $name;
		$args['page']         = $option_group;

		array_walk( $data['sections'], [ $this, 'add_section' ], $args );
	}

	/**
	 * Add options section.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $data Arguments for the add_settings_section WP function.
	 * @param string $name Name of the option section.
	 * @param string $args Additional arguments to pass on.
	 */
	protected function add_section( $data, $name, $args ) {
		// prepare the rendering callback.
		$render_callback = function () use ( $data ) {
			if ( array_key_exists( 'view', $data ) ) {
				$view = new View( $data['view'] );
				echo $view->render();
			}
		};

		add_settings_section(
			$name,
			$data['title'],
			$render_callback,
			$args['page']
		);

		// Extend array to pass to array_walk as third parameter.
		$args['section'] = $name;

		array_walk( $data['fields'], [ $this, 'add_field' ], $args );
	}

	/**
	 * Add options field.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $data Arguments for the add_settings_field WP function.
	 * @param string $name Name of the settings field.
	 * @param array  $args Contains both page and section name.
	 */
	protected function add_field( $data, $name, $args ) {
		// Prepare the rendering callback.
		$render_callback = function () use ( $data, $args ) {
			// Fetch $options to pass into view.
			$options = get_option( $args['setting_name'] );
			if ( array_key_exists( 'view', $data ) ) {
				$view = new View( $data['view'] );
				echo $view->render( [
					'options' => $options,
				] );
			}
		};

		add_settings_field(
			$name,
			$data['title'],
			$render_callback,
			$args['page'],
			$args['section']
		);
	}
}
