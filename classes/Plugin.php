<?php

namespace JoelMelon\Plugins\NostrPostr;

/**
 * Plugin Class
 *
 * Use elements in the front- and backend area with class .nostr-postr to trigger the Nostr Postr window.
 * The Nostr Postr window is a react component. All the postring works with javascript and the nostr_tools package.
 * There is a trigger script which is enqueued in the front- and backend to open a new window, where the react
 * component get initialized. The window is openend with necessary query vars to determine it on init.
 * Example of a Nostr Postr window url: https://nostr-postr.local/?action=nostr-postr&post_id=1&post_type=page
 *
 * @author Joel Stüdle <joel.stuedle@gmail.com>
 * @since 1.0.0
 */

// https://www.php.net/manual/en/class.allowdynamicproperties.php
#[\AllowDynamicProperties]

class Plugin {

	private static $instance;
	public $plugin_header = '';
	public $domain_path   = '';
	public $name          = '';
	public $prefix        = '';
	public $version       = '';
	public $file          = '';
	public $plugin_url    = '';
	public $plugin_dir    = '';
	public $base_path     = '';
	public $text_domain   = '';
	public $debug         = '';
	public $post_types    = '';
	public $no_cache      = '';

	/**
	 * Creates an instance if one isn't already available,
	 * then return the current instance.
	 *
	 * @param string $file The file from which the class is being instantiated.
	 * @return object The class instance.
	 * @since 1.0.0
	 */
	public static function get_instance( $file ) {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Plugin ) ) {
			self::$instance = new Plugin();

			if ( ! function_exists( 'get_plugin_data' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			self::$instance->plugin_header = get_plugin_data( $file );
			self::$instance->name          = self::$instance->plugin_header['Name'];
			self::$instance->domain_path   = basename( dirname( __DIR__ ) ) . self::$instance->plugin_header['DomainPath'];
			self::$instance->prefix        = 'nostr-postr';
			self::$instance->version       = self::$instance->plugin_header['Version'];
			self::$instance->file          = $file;
			self::$instance->plugin_url    = plugins_url( '', __DIR__ );
			self::$instance->plugin_dir    = dirname( __DIR__ );
			self::$instance->base_path     = self::$instance->prefix;
			self::$instance->text_domain   = self::$instance->plugin_header['TextDomain'];
			self::$instance->debug         = true;
			self::$instance->post_types    = array();
			self::$instance->no_cache      = isset( $_SERVER['HTTP_CACHE_CONTROL'] ) && 'no-cache' === $_SERVER['HTTP_CACHE_CONTROL'] ? true : false;

			if ( ! isset( $_SERVER['HTTP_HOST'] ) || strpos( $_SERVER['HTTP_HOST'], '.local' ) === false && ! in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) ) {
				self::$instance->debug = false;
			}

			self::$instance->run();
		}

		return self::$instance;
	}

	/**
	 * Execution function which is called after the class has been initialized.
	 * This contains hook and filter assignments, etc.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		// Load classes
		$this->load_classes(
			array(
				\JoelMelon\Plugins\NostrPostr\Plugin\Assets::class,
			)
		);

		// load the textdomain
		add_action( 'plugins_loaded', array( $this, 'load_text_domain' ) );

		// set post types with low priority (over 9000!) to hopefully catch all registerd post types
		add_action( 'init', array( $this, 'set_post_types' ), 9001 );

		// the action to detect if current call is for nostr-postr, set priority bigger than set_post_types
		add_action( 'init', array( $this, 'nostr_postr_initialize' ), 9002 );

		// add a button to trigger nostr-postr to WordPress post columns
		add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
	}


	/**
	 * Loads and initializes the plugin classes.
	 *
	 * @param array of classes
	 * @since 1.0.0
	 */
	private function load_classes( $classes ) {
		foreach ( $classes as $class ) {
			$class_parts = explode( '\\', $class );
			$class_short = end( $class_parts );
			$class_set   = $class_parts[ count( $class_parts ) - 2 ];

			if ( ! isset( nostr_postr()->{$class_set} ) || ! is_object( nostr_postr()->{$class_set} ) ) {
				nostr_postr()->{$class_set} = new \stdClass();
			}

			if ( property_exists( nostr_postr()->{$class_set}, $class_short ) ) {
				/* translators: %1$s = already used class name, %2$s = plugin class */
				wp_die( sprintf( esc_html( _x( 'There was a problem with the Plugin. Only one class with name “%1$s” can be use used in “%2$s”.', 'Theme instance load_classes() error message', 'nostr-postr' ) ), $class_short, $class_set ), 500 );
			}

			nostr_postr()->{$class_set}->{$class_short} = new $class();

			if ( method_exists( nostr_postr()->{$class_set}->{$class_short}, 'run' ) ) {
				nostr_postr()->{$class_set}->{$class_short}->run();
			}
		}
	}

	/**
	 * Load the plugins textdomain
	 *
	 * @since 1.0.0
	 */
	public function load_text_domain() {
		load_plugin_textdomain( nostr_postr()->text_domain, false, nostr_postr()->domain_path );
	}

	/**
	 * Set post types on which nostr-postr should be available
	 * Per default, 'post', 'page' and all registered custom post types will be included
	 * The post type list is filterable with a filter hook:
	 * add_filter( 'nostr_postr_post_types', function( $post_types ) { unset('post_type'); return $post_types; }, 10, 1 );
	 *
	 * @since 1.0.0
	 */
	public function set_post_types() {
		$post              = get_post_type_object( 'post' );
		$page              = get_post_type_object( 'page' );
		$custom_post_types = get_post_types( array( '_builtin' => false ), 'object' );

		$this->post_types['post'] = array(
			'singular' => $post->labels->name,
			'plural'   => $post->labels->singular_name,
		);
		$this->post_types['page'] = array(
			'singular' => $page->labels->name,
			'plural'   => $page->labels->singular_name,
		);

		foreach ( $custom_post_types as $custom_post_type ) {
			$this->post_types[ $custom_post_type->name ] = array(
				'singular' => $custom_post_type->labels->name,
				'plural'   => $custom_post_type->labels->singular_name,
			);
		}

		$this->post_types = apply_filters( 'nostr_postr_post_types', $this->post_types );
	}

	/**
	 * On WordPress init, we check if the query var 'action' is set to 'nostr-postr'
     * If so, we output the app container and enqueue the plugin scripts and styles.
	 *
	 * @since 1.0.0
	 */
	public function nostr_postr_initialize() {
		if ( isset( $_GET ) && isset( $_GET['action'] ) && 'nostr-postr' === $_GET['action'] ) {
			echo '<title>' . _x( 'Nostr Postr', 'Nostr Postr window meta title', 'nostr-postr' ) . '</title>';
            // enqueue nostr-postr assets and styles
			nostr_postr()->Plugin->Assets->enqueue_scripts_styles();
			do_action( 'wp_head' );
			echo '<body class="nostr-postr-app nostr-postr-app--initializing">';
			echo '<div class="nostr-postr-app__head">';
			echo '<img src="' . nostr_postr()->plugin_url . '/assets/media/nostr-postr-app-head.png' . '" alt="Nostr Postr Brand"/>';
			echo '</div>';
			echo '<div class="nostr-postr-app__content" id="nostr-postr-app">';
			echo '</div>';
			echo '</body>';
			do_action( 'wp_footer' );
			die;
		}
	}

	/**
	 * This function adds a "Post to Nostr" button in the admin post column actions
     * This provides a quick way to give access to the Nostr Postr window if logged in.
	 *
	 * @since 1.0.0
	 */
	public function filter_row_actions( $actions, $post ) {
		if ( isset( nostr_postr()->post_types[ $post->post_type ] ) && 'publish' === $post->post_status ) {
			$actions['nostr_postr'] = '<button type="button" class="button-link nostr-postr" data-post-id="' . $post->ID . '" data-post-type="' . $post->post_type . '" aria-label="' . esc_html( _x( 'Post to Nostr', 'Post Column Action', 'nostr-postr' ) ) . '" aria-expanded="false">' . esc_html( _x( 'Post to Nostr', 'Post Column Action', 'nostr-postr' ) ) . '</button>';
		}
		return $actions;
	}
}