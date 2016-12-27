<?php

/*
Plugin Name: Post Expiration
Description: A post expiration plugin designed for WordPress sitebuilders to love.
Version:     1.1
Author:      NewClarity Consulting LLC
License:     GPL2
*/

/**
 * Class Post_Expiration
 */
class Post_Expiration {

	/**
	 * PHP date/time format for how dates will be displayed for the user
	 */
	const DISPLAY_FORMAT = 'M j, Y';

	/**
	 * jQuery UI date/time format for how dates will be displayed for the user
	 */
	const DISPLAY_FORMAT_JS = 'M d, yy';

	/**
	 * option_name for storage of plugin settings in wp_options
	 */
	const SETTINGS_NAME = 'post_expiration';

	/**
	 * PHP date/time format for storing last_expired post meta.
	 */
	const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s';

	/**
	 * PHP date/time format for storing expiration date in post meta and for searching for expired posts.
	 */
	const STORAGE_FORMAT = 'Y-m-d';

	/**
	 * Action hook name for expiration cron task
	 */
	const EXPIRE_ACTION = 'post_expiration_expire_posts';

	/**
	 * @var Post_Expiration Store single instance of self.
	 */
	private static $_instance;

	/**
	 * @var string Store URL for this plugin.
	 */
	private $_plugin_url;

	/**
	 * Capture singleton instance
	 */
	static function on_load() {

		self::$_instance = new self();

	}

	/**
	 * Post_Expiration constructor
	 *
	 * Making it private enforces only one instance of this class.
	 */
	private function __construct() {

		add_action( 'plugins_loaded', array( $this, '_plugins_loaded_9' ), 9 );

	}

	/**
	 * Allow access to singleton instance for remove_action() or remove_filter(), if needed.
	 *
	 * @return Post_Expiration
	 */
	static function instance() {

		return self::$_instance;

	}

	/**
	 * Initialize the plugin's "constant(s)", post status, cron task and hooks.
	 *
	 * Run at priority 9 so that a regular priority 10 `plugins_loaded` hook can modify, if needed.
	 */
	function _plugins_loaded_9() {

		do {

			if ( ! defined( 'POST_EXPIRATION_TEST_MODE' ) ) {
				define( 'POST_EXPIRATION_TEST_MODE', true );
			}

			$this->_plugin_url = plugin_dir_url( __FILE__ );

			register_post_status( 'expired', array(
				'label'                     => _x( 'Expired', 'post status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				'publicly_queryable'        => false,
				'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>' ),
			));

			/**
			 * Set up expiration for cron
			 */
			add_action( self::EXPIRE_ACTION, array( $this, '_expire_posts' ) );

			if ( POST_EXPIRATION_TEST_MODE ) {
				wp_clear_scheduled_hook( self::EXPIRE_ACTION );
			}

			if ( ! wp_next_scheduled( self::EXPIRE_ACTION ) ) {

				$utc_timestamp = POST_EXPIRATION_TEST_MODE
					? $this->next_midnights_utc_timestamp()
					:  strtotime( gmdate( 'DATE_ATOM' ) );


				wp_schedule_single_event( $utc_timestamp, self::EXPIRE_ACTION );

			}

			if ( ! current_user_can( 'edit_posts' ) ) {

				break;

			}

			add_action( 'save_post',                    array( $this, '_save_post' ) );

			if ( ! $this->is_post_edit_page() ) {

				break;

			}

			add_action( 'admin_enqueue_scripts',        array( $this, '_admin_enqueue_scripts' ) );
			add_action( 'post_submitbox_minor_actions', array( $this, '_post_submitbox_minor_actions' ) );
			add_action( 'post_submitbox_misc_actions',  array( $this, '_post_submitbox_misc_actions' ) );
			add_action( 'admin_head',                   array( $this, '_admin_head' ) );

		} while ( false );

	}

	/**
	 * Start queuing HTML so we can add Expired as a status in 'post_submitbox_misc_actions'
	 */
	function _post_submitbox_minor_actions() {

		ob_start();

	}

	/**
	 * Cron task to expire posts
	 */
	function _expire_posts() {

		$expire_date = $this->storage_formatted_current_date();

		/**
		 * Using raw SQL here because WordPress does not have an API that does
		 * what we need.  We could have used WP_Meta_Query but it would have
		 * added complexity and yet we'd still need raw SQL so better to
		 * stick with clear raw SQL.
		 */

		global $wpdb;
		$sql      = <<<SQL
SELECT
	post_id
FROM
	{$wpdb->postmeta}
WHERE 1=1
	AND meta_key = '_post_expiration_date'	
	AND meta_value IS NOT NULL 
	AND meta_value < '%s'
SQL;
		$sql      = $wpdb->prepare( $sql, $expire_date );
		$post_ids = $wpdb->get_col( $sql );
		$post_ids = apply_filters( 'pre_post_expiration_posts', $post_ids, $expire_date );
		foreach ( $post_ids as $post_id ) {
			if ( ! apply_filters( 'do_post_expiration', true, $post_id, $expire_date ) ) {
				continue;
			}
			$this->expire_post( $post_id );
			do_action( 'post_expiration_post_expired', $post_id, $expire_date );
		}
		do_action( 'post_expiration_posts_expired', $post_ids, $expire_date );

	}

	/**
	 * Expires post assuming it is ready to be expired and based on pre-configured expiration action
	 *
	 * @param int $post_id
	 */
	function expire_post( $post_id ) {

		do {

			if ( ! $this->needs_expiration( $post_id ) ) {
				break;
			}

			$expired = true;

			switch ( $this->get_expire_method( $post_id ) ) {

				case 'set_to_draft':
					$this->set_to_draft( $post_id );
					break;

				case 'set_to_expired':
					$this->set_to_expired( $post_id );
					break;

				case 'trash_post':
					$post = wp_trash_post( $post_id );
					break;

				default:
					$expired = false;
					break;
			}

			if ( ! $expired ) {
				break;
			}

			$this->set_last_expired( $post_id );
			$this->delete_expiration_date( $post_id );
			$this->delete_expire_method( $post_id );

		} while ( false );

	}

	/**
	 * Add CSS for Post Expiration
	 */
	function _admin_head() {
		if ( $this->is_post_edit_page() ) {
			$html = <<<HTML
<style type="text/css">
#post-expiration-display { font-weight:bold; }
#post-expiration-field-group label { display: inline-block; width:7em; }
#post-expiration-field-group input, #post-expiration-field-group select { width:12em; font-size:12px; }
</style>
HTML;
			echo apply_filters( 'post_expiration_css', $html );
		}
	}

	/**
	 * Set up JS and CSS required for this plugin
	 */
	function _admin_enqueue_scripts() {

		if ( $this->is_post_edit_page() ) {
			global $post;

			$css_url = apply_filters( 'post_expiration_datepicker_css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css' );
			if ( $css_url ) {
				wp_enqueue_style( 'jquery-ui-css', $css_url );
			}
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_enqueue_script( 'post-expiration-js', "{$this->_plugin_url}/post-expiration.js", array( 'jquery-ui-datepicker' ) );
			wp_localize_script( 'post-expiration-js', 'postExpiration', array(
				'dateFormat'      => apply_filters( 'post_expiration_display_format_js', self::DISPLAY_FORMAT_JS ),
				'postStatus'      => isset( $post->post_status ) ? $post->post_status : null,
				'postStatusLabel' => __( 'Expired', 'post-expiration' ),
			));
		}

	}

	/**
	 * Convenience function to test if the current admin page is post.php or post-new.php.
	 *
	 * These are the only admin pages where Expiration code should be loaded.
	 *
	 * @return bool
	 */
	function is_post_edit_page() {
		global $pagenow;
		return preg_match( '#^post(-new)?\.php$#', $pagenow );
	}

	/**
	 * Get the post types that support expiration.
	 *
	 * By default all post types support expiration EXCEPT:
	 *
	 *  1. Attachments
	 *  2. Show UI => false
	 *
	 * You can filter this list with the `post_expiration_post_types` hook.
	 *
	 * @return array
	 */
	function post_types() {
		global $wp_post_types;
		$post_type_names = array();
		foreach( $wp_post_types as $post_type_name => $post_type_obj ) {
			if ( 'attachment' === $post_type_name ) {
				continue;
			}
			if ( isset( $post_type_obj->can_expire ) && ! $post_type_obj->can_expire ) {
				continue;
			}
			if ( $post_type_obj->show_ui ) {
				$post_type_names[] = $post_type_name;
			}
		}
		return apply_filters( 'post_expiration_post_types', $post_type_names );
	}

	/**
	 * Get the default post expiration related settings for a post
	 *
	 * @return array
	 */
	function default_post_settings() {

		return array(
			'expires_label'   => $this->get_expiration_label(),
			'expiration_date' => null,
			'expire_method'   => $this->preferred_expire_method(),
		);

	}

	/**
	 * Returns the default settings.
	 *
	 * @return string
	 */
	function default_settings() {
		return array(
			'expire_method'     => 'set_to_expired',
		);
	}

	/**
	 * Get the current settings
	 *
	 * @return string
	 */
	function settings() {

		/**
		 * Get settings, defaulting to an empty array
		 */
		$settings = get_option( self::SETTINGS_NAME, array() );

		/**
		 * Ensure get_option() did not load a non-array
		 */
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$default_settings = $this->default_settings();

		/**
		 * Ensure all default element exist in case $settings was loaded without them
		 */
		$settings = wp_parse_args( $settings, $default_settings );

		/**
		 * Remove any elements that are not defined in $this->default_settings()
		 */
		return array_intersect_key( $settings, $default_settings );
	}

	/**
	 * Update the current settings
	 *
	 * @param string $settings
	 * @return string
	 */
	function update_settings( $settings ) {

		update_option( self::SETTINGS_NAME, $settings );

	}

	/**
	 * Get the preferred expiration method
	 *
	 * This is set based on the last expiration method chose
	 *
	 * @return string
	 */
	function preferred_expire_method() {

		$expire_method = null;

		$settings = $this->settings();
		$expire_method = isset( $settings[ 'expire_method' ] )
			? $settings[ 'expire_method' ]
			: 'set_to_expired';

		return apply_filters( 'post_expiration_preferred_method', $expire_method );

	}

	/**
	 * Get the current post expiration related settings for a post.
	 *
	 * @param int $post_id
	 * @return array
	 */
	function get_post_settings( $post_id ) {

		return array(
			'expires_label'   => $this->get_expiration_label( $post_id ),
			'expiration_date' => $this->get_display_formatted_date( $post_id ),
			'expire_method'   => $this->get_expire_method( $post_id ),
		);

	}

	/**
	 * Adds "Expired" as an option to drop down for an expired post.
	 *
	 * This uses fragile output buffering and regex because this ticket has stalled for 6 years:
	 * @see https://core.trac.wordpress.org/ticket/12706
	 *
	 * @param string $html
	 * @return string
	 */
	public function _add_expired_to_status_selector( $html ) {

		global $post;

		if ( 'expired' === $post->post_status ) {

			$expiredText = esc_html( __( 'Expired', 'post-expiration' ) );
			/**
			 * Add the option "Expired" to the end of the list of statuses.
			 * Set the current option to "expired"
			 */
			$html = preg_replace( '~(</select>\s+<a href="#post_status)~', "<option value='expired' selected>{$expiredText}</option>\n$1", $html );
			/**
			 * Set the display text
			 */
			$html = preg_replace( '#(<span id="post-status-display">)#', "$1\n{$expiredText}", $html );

		}

		return $html;

	}


	/**
	 *
	 */
	public function _post_submitbox_misc_actions() {

		do {

			global $post;

			if ( ! in_array( $post->post_type, $this->post_types() ) ) {
				break;
			}

			/*
			 * Set $expiration to be used in expiration-controls.php.
			 */
			$expiration = ! empty( $post->ID )
				? $this->get_post_settings( $post->ID )
				: $this->default_post_settings();

			$expiration = apply_filters( 'post_expiration_data', $expiration );

			if ( empty( $expiration ) ) {
				break;
			}

			$template_file = apply_filters( 'post_expiration_controls_template', __DIR__ . '/expiration-controls.php', $expiration );

			if ( empty( $template_file ) ) {
				break;
			}

			/**
			 * Hacky way to add post status.
			 */
			echo $this->_add_expired_to_status_selector( ob_get_clean() );

			require $template_file;

		} while ( false );

	}

	/**
	 * Sets or deletes post expiration settings for the specified post.
	 *
	 * @param int $post_id
	 */
	public function _save_post( $post_id ) {

		do {
			if ( defined( 'DOING_AUTOSAVE' ) ) {
				break;
			}
			if ( defined( 'DOING_AJAX' ) ) {
				break;
			}
			if ( ! isset( $_POST[ 'post_expiration' ] ) ) {
				break;
			}
			if ( filter_input( INPUT_GET, 'bulk_edit' ) ) {
				break;
			}
			if ( wp_is_post_revision( $post_id ) ) {
				break;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				break;
			}

			/**
			 * If "Save Draft" was clicked change it to "draft" status.
			 */
			do {
				if ( ! isset( $_POST[ 'save' ] ) ) {
					break;
				}
				if ( __( 'Save Draft' ) !== $_POST[ 'save' ] ) {
					break;
				}
				if ( 'expired' !== get_post_status( $post_id ) ) {
					break;
				}
				$this->set_to_draft( $post_id );

			} while ( false );

			if ( ! is_array( $_POST[ 'post_expiration' ] ) ) {
				$expiration = $this->default_post_settings();
			} else {
				$expiration = wp_parse_args(
					$_POST['post_expiration'],
					$this->default_post_settings()
				);
			}

			if ( $expiration[ 'expiration_date' ] && $expiration[ 'expire_method' ] ) {

				$expire_method = $expiration[ 'expire_method' ];
				$this->update_expire_method( $post_id, $expire_method );
				$this->update_expiration_date( $post_id, $expiration[ 'expiration_date' ] );

			} else {

				$expire_method = null;
				$this->delete_expire_method( $post_id );
				$this->delete_expiration_date( $post_id );

			}

			/**
			 * Now set the last selected method to be the preferred expire method
			 */
			$settings = $this->settings();
			$settings[ 'expire_method' ] = $expire_method;
			$this->update_settings( $settings );

		} while ( false );

	}

	/**
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public function needs_expiration( $post_id ) {

		do {

			$is_expired = false;

			$expiration_datestamp = $this->get_expiration_datestamp( $post_id );

			if ( 0 === $expiration_datestamp ) {
				break;
			}

			$is_expired = current_time( 'timestamp' ) >= $expiration_datestamp;

		} while ( false );

		return $is_expired;

	}

	/**
	 * @param int $post_id
	 */
	function set_to_expired( $post_id ) {
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'expired'
		));
	}

	/**
	 * @param int $post_id
	 */
	function set_to_draft( $post_id ) {
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'draft'
		));
	}

	/**
	 * @param int $post_id
	 */
	function set_last_expired( $post_id ) {
		update_post_meta( $post_id, 'last_expired', date( self::TIMESTAMP_FORMAT ) );
	}

	/**
	 * @param int $post_id
	 * @param string $expiration_date
	 */
	function update_expiration_date( $post_id, $expiration_date ) {

		$expiration_date = date( self::STORAGE_FORMAT, strtotime( $expiration_date ) );
		update_post_meta( $post_id, '_post_expiration_date', $expiration_date );

	}

	/**
	 * @param int $post_id
	 * @param string $expire_method
	 */
	function update_expire_method( $post_id, $expire_method ) {
		update_post_meta( $post_id, '_post_expiration_method', $expire_method );
	}


	/**
	 * @param int|null $post_id
	 *
	 * @return string
	 */
	function get_expiration_label( $post_id = null ) {

		do {

			$label = __( 'Never', 'post-expiration' );

			if ( is_null( $post_id ) ) {
				break;
			}

			$display_date = $this->get_display_formatted_date( $post_id );

			if ( empty( $display_date ) ) {
				break;
			}

			$label = $display_date;

		} while ( false );

		return $label;

	}

	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	function get_storage_formatted_date( $post_id ) {

		return date( self::STORAGE_FORMAT, $this->get_expiration_datestamp( $post_id ) );

	}


	/**
	 * @return string
	 */
	function storage_formatted_current_date() {

		return date( self::STORAGE_FORMAT, current_time( 'timestamp' ) );

	}


	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	function get_display_formatted_date( $post_id ) {

		do {

			$display_date = '';

			$datestamp = $this->get_expiration_datestamp( $post_id );

			if ( is_null( $datestamp ) ) {
				break;
			}

			$display_format = apply_filters( 'post_expiration_display_format', self::DISPLAY_FORMAT );

			if ( empty( $display_format ) ) {
				break;
			}

			$display_date = date( $display_format, $datestamp );

		} while ( false );

		return $display_date;

	}

	/**
	 * Return a timestamp for just the date part for the specified post
	 *
	 * @param int $post_id
	 *
	 * @return int|null
	 */
	function get_expiration_datestamp( $post_id ) {

		do {

			$datestamp = null;

			if ( is_null( $post_id ) ) {
				break;
			}

			$value = get_post_meta( $post_id, '_post_expiration_date', true );

			if ( empty( $value ) ) {
				break;
			}

			$datestamp = strtotime( date( self::STORAGE_FORMAT, strtotime( $value ) ) );

		} while ( false );

		return $datestamp;

	}

	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	function get_expire_method( $post_id ) {

		do {

			$expire_method = $this->preferred_expire_method();

			if ( is_null( $post_id ) ) {
				break;
			}

			$value = get_post_meta( $post_id, '_post_expiration_method', true );

			if ( empty( $value ) ) {
				break;
			}

			$expire_method = $value;

		} while ( false );

		return $expire_method;
	}

	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	function delete_expiration_date( $post_id ) {
		return delete_post_meta( $post_id, '_post_expiration_date' );
	}

	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	function delete_expire_method( $post_id ) {
		return delete_post_meta( $post_id, '_post_expiration_method' );
	}

	/**
	 * Get the next midnight's timestamp for our timezone.
	 *
	 * @return int
	 */
	function next_midnights_utc_timestamp() {


		/*
		 * Get difference between our timezone and the server's time zone
		 *
		 * If we are 9pm EST and the server is 6pm PST then so delta = +3 hours
		 */
		$timezone_delta = current_time( 'timestamp' ) - time();

		/*
		 * Get $today's timestamp at the first second of they day.
		 */
		$today = strtotime( date( 'Y-m-d', current_time( 'timestamp' ) ) );

		/*
		 * Add one day to it and adjust for timezone.
		 *
		 *  86,400 = 24 hours * 60 minutes * 60 seconds.
		 */
		$timestamp = $today + 86400 - $timezone_delta;

		return $timestamp;

	}

}
Post_Expiration::on_load();
