<?php

/*
Plugin Name: Post Expiration
Description: A post expiration plugin
Version:     1.0
Author:      NewClarity Consulting LLC
License:     GPL2
*/

/**
 * Class Post_Expiration
 */
class Post_Expiration {

	const DATE_FORMAT = 'M j, Y';
	const DATE_FORMAT_JS = 'M d, yy';


	/**
	 * @var self
	 */
	private static $_instance;

	/**
	 * @var string
	 */
	private $_plugin_url;

	/**
	 * @var array|null
	 */
	private $_settings = null;

	static function on_load() {

		self::$_instance = new self();

		add_action( 'plugins_loaded', array( self::$_instance, '_plugins_loaded' ) );

	}

	/**
	 * 
	 */
	function _plugins_loaded() {

		do {

			$this->_plugin_url = plugin_dir_url( __FILE__ );

			register_post_status( 'expired', array(
				'label'       => _x( 'Expired', 'post status' ),
				'public'      => false,
				'show_in_admin_all_list' => false,
				'show_in_admin_status_list' => true,
				'publicly_queryable' => false,
				'label_count' => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>' ),
			));

			/**
			 * Set up expiration for cron
			 */
			add_action( 'post_expiration_expire_posts', array( $this, '_expire_posts' ) );
			if( ! wp_next_scheduled( 'post_expiration_expire_posts' ) ) {
				wp_schedule_single_event( time(), 'post_expiration_expire_posts' );
			}

			if ( ! current_user_can( 'edit_posts' ) ) {
				break;
			}

			if ( ! $this->is_post_edit_page() ) {
				break;
			}

			add_action( 'admin_enqueue_scripts',        array( $this, '_admin_enqueue_scripts' ) );
			add_action( 'post_submitbox_minor_actions', array( $this, '_post_submitbox_minor_actions' ) );
			add_action( 'post_submitbox_misc_actions',  array( $this, '_post_submitbox_misc_actions' ) );
			add_action( 'save_post',                    array( $this, '_save_post' ) );
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

		global $wpdb;
		$sql =<<<SQL
SELECT
	post_id
FROM
	{$wpdb->postmeta}
WHERE 1=1
	AND meta_key = '_post_expiration_date'	
	AND meta_value < '%s'
SQL;
		$sql = $wpdb->prepare( $sql, date( 'Y-m-d' ) );
		$post_ids = $wpdb->get_col( $sql );
		foreach( $post_ids as $post_id ) {
			$this->expire_post( $post_id );
		}

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

			switch ( $this->get_expires_action( $post_id ) ) {

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
			$this->delete_expires_action( $post_id );

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
#post-expiration-field-group label { display: inline-block; width:5em; }
#post-expiration-field-group input, #post-expiration-field-group select { width:12em; }
</style>
HTML;
			echo $html;
		}
	}

	/**
	 *
	 */
	function _admin_enqueue_scripts() {

		if ( $this->is_post_edit_page() ) {
			global $post;

			wp_enqueue_style( 'jquery-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css');
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_enqueue_script( 'post-expiration-js', "{$this->_plugin_url}/post-expiration.js", array( 'jquery-ui-datepicker' ) );
			wp_localize_script( 'post-expiration-js', 'postExpiration', array(
				'dateFormat' => self::DATE_FORMAT_JS,
				'postStatus' => isset( $post->post_status ) ? $post->post_status : null,
				'postStatusText' => __( 'Expired', 'post-expiration' ),
			));
		}

	}


	/**
	 * @return bool
	 */
	function is_post_edit_page() {
		global $pagenow;
		return preg_match( '#^post(-new)?\.php$#', $pagenow );
	}


	/**
	 * @return array
	 */
	function post_types() {
		global $wp_post_types;
		$post_types = array();
		foreach( $wp_post_types as $post_type => $post_type_obj ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			if ( $post_type_obj->show_ui ) {
				$post_types[] = $post_type;
			}
		}
		return $post_types;
	}


	/**
	 * @return array
	 */
	function default_post_settings() {

		return array(
			'expires_label'   => $this->get_expiration_label(),
			'expiration_date' => null,
			'expires_action' => null,
		);

	}

	/**
	 * @param int $post_id
	 * @return array
	 */
	function get_post_settings( $post_id ) {

		return array(
			'expires_label'   => $this->get_expiration_label( $post_id ),
			'expiration_date' => $this->get_expiration_date( $post_id ),
			'expires_action'  => $this->get_expires_action( $post_id ),
		);

	}

	/**
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

		/**
		 * Hacky way to add post status.
		 */
		echo $this->_add_expired_to_status_selector( ob_get_clean() );

		do {

			global $post;

			if ( ! in_array( $post->post_type, $this->post_types() ) ) {
				break;
			}

			/*
			 * Set $expiration to be used in expiration-control.php.
			 */
			$expiration = ! empty( $post->ID )
				? array(
					'expires_label'   => $this->get_expiration_label( $post->ID ),
					'expiration_date' => $this->get_expiration_date( $post->ID ),
					'expires_action'  => $this->get_expires_action( $post->ID ),
				)
				: $this->default_post_settings();

			require __DIR__ . '/expiration-control.php';

		} while ( false );

	}

	/**
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

			$expiration = wp_parse_args(
				$_POST[ 'post_expiration' ],
				$this->default_post_settings()
			);

			if ( $expiration[ 'expiration_date' ] && $expiration[ 'expires_action' ] ) {

				$expiration_date = date( 'Y-m-d', strtotime( $expiration[ 'expiration_date' ] ) );
				$this->update_expiration_date( $post_id, $expiration_date );
				$this->update_expires_action( $post_id, $expiration[ 'expires_action' ] );

			} else {

				$this->delete_expiration_date( $post_id );
				$this->delete_expires_action( $post_id );

			}

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

			$expiration_date = $this->get_expiration_date( $post_id );

			if ( empty( $expiration_date ) ) {
				break;
			}

			if ( current_time( 'timestamp' )  < strtotime( $expiration_date ) ) {
				break;
			}

			$is_expired =  true;

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
		update_post_meta( $post_id, 'last_expired', date('Y-m-d' ) );
	}

	/**
	 * @param int $post_id
	 * @param string $expiration_date
	 */
	function update_expiration_date( $post_id, $expiration_date ) {
		update_post_meta( $post_id, '_post_expiration_date', $expiration_date );
	}

	/**
	 * @param int $post_id
	 * @param string $expires_action
	 */
	function update_expires_action( $post_id, $expires_action ) {
		update_post_meta( $post_id, '_post_expiration_action', $expires_action );
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

			$expires = $this->get_expiration_date( $post_id );

			if ( empty( $expires ) ) {
				break;
			}

			$label = $expires;

		} while ( false );

		return $label;

	}

	/**
	 * @param int|null $post_id
	 *
	 * @return string
	 */
	function get_expiration_date( $post_id = null ) {

		do {

			$expiration_date = '';

			if ( is_null( $post_id ) ) {
				break;
			}

			$value = get_post_meta( $post_id, '_post_expiration_date', true );

			if ( empty( $value ) ) {
				break;
			}

			$expiration_date = date( self::DATE_FORMAT, strtotime( $value ) );

		} while ( false );

		return $expiration_date;

	}

	/**
	 * @param int|null $post_id
	 *
	 * @return string
	 */
	function get_expires_action( $post_id = null ) {
		do {

			$expires_action = 'set_to_expired';

			if ( is_null( $post_id ) ) {
				break;
			}

			$value = get_post_meta( $post_id, '_post_expiration_action', true );

			if ( empty( $value ) ) {
				break;
			}

			$expires_action = $value;

		} while ( false );

		return $expires_action;
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
	function delete_expires_action( $post_id ) {
		return delete_post_meta( $post_id, '_post_expiration_action' );
	}

}
Post_Expiration::on_load();
