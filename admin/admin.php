<?php

class Lift_Admin {

  const MENU_PAGE_SLUG = "librelio";
  const SUBMENU_TEMPLATES_SLUG = "librelio_templates";
  const SUBMENU_CLOUDSEARCH_SLUG = "librelio_cloudsearch";
  const SUBMENU_PUBLICATIONS_SLUG = "librelio_publications";

  private static function _($s) { return lift_cloud_localize($s); }

	public function init() {

		add_action( 'admin_menu', array( $this, 'action__admin_menu' ) );
		add_action( 'admin_init', array( $this, 'action__admin_init' ) );

		//setup AJAX handlers
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && current_user_can( $this->get_manage_capability() ) ) {
			add_action( 'wp_ajax_lift_domains', array( $this, 'action__wp_ajax_lift_domains' ) );
			add_action( 'wp_ajax_lift_domain', array( $this, 'action__wp_ajax_lift_domain' ) );
			add_action( 'wp_ajax_lift_settings', array( $this, 'action__wp_ajax_lift_settings' ) );
			add_action( 'wp_ajax_lift_setting', array( $this, 'action__wp_ajax_lift_setting' ) );
			add_action( 'wp_ajax_lift_update_queue', array( $this, 'action__wp_ajax_lift_update_queue' ) );
			add_action( 'wp_ajax_lift_error_log', array( $this, 'action__wp_ajax_lift_error_log' ) );
			add_action( 'wp_ajax_librelio_get_wp_roles', array( $this, 'get_wp_roles' ) );
			add_action( 'wp_ajax_librelio_set_allowed_roles_for', array( $this, 'set_allowed_roles_for' ) );
			add_action( 'wp_ajax_librelio_get_settings', array( $this, 'get_settings' ) );
			add_action( 'wp_ajax_librelio_set_settings', array( $this, 'set_settings' ) );
		}

		if ( !Lift_Search::get_search_domain_name() ) {
			if ( !isset( $_GET['page'] ) || (isset( $_GET['page'] ) && $_GET['page'] !== self::SUBMENU_CLOUDSEARCH_SLUG ) ) {
				if ( current_user_can('manage_options') ) {
					add_action( 'admin_enqueue_scripts', array( $this, '__admin_enqueue_style' ) );
					add_action( 'user_admin_notices', array( $this, '_print_configuration_nag' ) );
					add_action( 'admin_notices', array( $this, '_print_configuration_nag' ) );
				}
			}
		}

    // redirect for librelio templates
    if ( isset( $_GET['page'] ) && (self::SUBMENU_TEMPLATES_SLUG == $_GET['page']) ) {
      wp_redirect( admin_url( 'edit.php?post_type=' . Lift_Search::TEMPLATE_TYPE ) );
      die();
    }
    
    add_action('edit_form_before_permalink', 
               array($this, 'edit_form_before_permalink'), 10, 1);
	}

  function edit_form_before_permalink($post)
  {
    if($post->post_type == Lift_Search::TEMPLATE_TYPE)
    {
?>
<div><span><?php echo self::_('Name'); ?>:</span> <span><?php 
echo $post->post_name; ?></span></div>
<?php
    }
  }

	/**
	 * Returns the capability for managing the admin
	 * @return strings
	 */
	private function get_manage_capability() {
		static $cap = null;

		if ( is_null( $cap ) )
			$cap = apply_filters( 'lift_settings_capability', 'manage_options' );
		return $cap;
	}

	/*	 * ************************   */
	/*             Callbacks          */
	/*	 * ************************   */

	/**
	 * Sets up menu pages
	 */
	public function action__admin_menu() {
    add_menu_page(self::_('Librelio'), self::_('Librelio'),
                  $this->get_manage_capability(), self::MENU_PAGE_SLUG, 
                  array($this, 'menu_librelio_page'),
                  null, 100);
    add_submenu_page(self::MENU_PAGE_SLUG, self::_('Librelio Templates'), 
                     self::_('Templates'), $this->get_manage_capability(),
                     self::SUBMENU_TEMPLATES_SLUG, 
                     array($this, 'submenu_librelio_templates_page'));

    $hook = add_submenu_page(self::MENU_PAGE_SLUG, self::_('Librelio CloudSearch'),
                     self::_('CloudSearch'), $this->get_manage_capability(),
                     self::SUBMENU_CLOUDSEARCH_SLUG,
                     array($this, 'callback__render_options_page'));
    add_action( $hook, array( $this, 'action__options_page_enqueue' ) );
    $hook = add_submenu_page(self::MENU_PAGE_SLUG, self::_('Librelio Publications'),
                     self::_('Publications'), $this->get_manage_capability(),
                     self::SUBMENU_PUBLICATIONS_SLUG,
                     array($this, 'submenu_librelio_publications_page'));
    add_action('admin_print_styles-'.$hook, 
               array($this, '__admin_enqueue_style'));

	}

  public function menu_librelio_page()
  {

  }

  public function submenu_librelio_templates_page()
  {
    
  }

  public function submenu_librelio_publications_page()
  {
    $this->frame_librelio_console('frames/publications.html');
  }

  public function frame_librelio_console($page)
  {
    $cred = Lift_Search::getAwsCredentials();
    $region = Lift_Search::get_domain_region();
    if(!$cred && $region)
      return;
    $sts = Aws\Sts\StsClient::factory(array(
      'credentials'=> $cred,
      'region' => $region,
      'endpoint' => 'https://sts.'.$region.'.amazonaws.com',
      'version' => '2011-06-15'
    ));
    $query = array(
      "autologin"=> "1",
      "credentials"=> json_encode($sts->getSessionToken()['Credentials']),
      "rootDirectory"=> Lift_Search::__get_setting('publisher'),
      "selectedApp"=> Lift_Search::__get_setting('app'),
      "redirect"=> $page
    );
    $url = plugins_url("admin/librelio-admin/login.html?".
                        http_build_query($query), __DIR__);
?>
<div class="librelio-console-frame-wrp">
  <iframe class="librelio-console-frame" src="<?php echo $url; ?>" />
</div>
<?php
  }

	public function action__options_page_enqueue() {
		if(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
			wp_enqueue_script( 'lift-admin', plugins_url( 'js/admin.js', __DIR__ ), array( 'backbone' ), '0.1', true );
		else
			wp_enqueue_script( 'lift-admin', plugins_url( 'js/admin.min.js', __DIR__ ), array( 'backbone' ), '0.1', true );

		wp_localize_script( 'lift-admin', 'liftData', array(
			'templateDir' => plugins_url( '/templates/', __FILE__ ),
			'errorLoggingEnabled' => Lift_Search::error_logging_enabled()
		) );
		$this->__admin_enqueue_style();
	}

	public function __admin_enqueue_style() {
		wp_enqueue_style( 'lift-admin', plugins_url( 'css/admin.css', __DIR__ ) );
	}

	/**
	 * Sets up all admin hooks
	 */
	public function action__admin_init() {

		//add option links
		add_filter( 'plugin_row_meta', array( __CLASS__, 'filter__plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'filter__plugin_row_meta' ), 10, 2 );
	}

	public function action__wp_ajax_lift_setting() {
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['model'] ) ) {
			$settings_data = json_decode( stripslashes( $_POST['model'] ) );

			$response = array(
				'status' => 'SUCCESS',
				'data' => array( ),
				'errors' => array( )
			);

			$setting_value = $settings_data->value;
			$response['model']['id'] = $setting_key = $settings_data->id;

			$error = new WP_Error();
			if ( isset( $_GET['nonce'] ) && wp_verify_nonce( $_GET['nonce'], 'lift_setting' ) ) {

				switch ( $setting_key ) {
					case 'batch_interval':
						$value = max( array( 1, intval( $setting_value->value ) ) );
						$unit = $setting_value->unit;
						Lift_Search::set_batch_interval_display( $value, $unit );
						$response['model']['value'] = Lift_Search::get_batch_interval_display();
						break;
					case 'domainname':
						$domain_manager = Lift_Search::get_domain_manager();
						$replacing_domain = ( Lift_Search::get_search_domain_name() != $setting_value );
						$region = ( !empty($settings_data->region) ) ? $settings_data->region : false;

						if ( $setting_value === '' ) {
							//assume that empty domain name means that we're clearing the set domain
							Lift_Search::set_search_domain_name( '' );
							Lift_Batch_Handler::_deactivation_cleanup();
							$response['model']['value'] = '';
						} elseif ( $domain_manager->domain_exists( $setting_value, $region ) ) {
							$changed_fields = array( );
							if ( !is_wp_error( $result = $domain_manager->apply_schema( $setting_value, null, $changed_fields, $region ) ) ) {
								if ( $replacing_domain ) {
									Lift_Batch_Handler::queue_all();
									Lift_Batch_Handler::enable_cron();
								}
								Lift_Search::set_search_domain_name( $setting_value );
								Lift_Search::set_domain_region( $region );
							} else {
								$error->add( 'schema_error', self::_('There was an error while applying the schema to the domain.') );
							}
						} else {
							$error->add( 'invalid_domain', self::_('The given domain does not exist.') );
						}
						$response['model']['value'] = Lift_Search::get_search_domain_name();
						break;
					case 'region':
						$response['model']['value'] = Lift_Search::get_domain_region();
						break;
					case 'next_sync':
						//for now just assume that anh post for next_sync is to fire sync immediately
						Lift_Batch_Handler::disable_cron();
						Lift_Batch_Handler::enable_cron( time() );
						break;
					case 'override_search':
						Lift_Search::set_override_search( ( bool ) $setting_value );
						$response['model']['value'] = Lift_Search::get_override_search();
						break;
					default:
						$error->add( 'invalid_setting', self::_('The name of the setting you are trying to set is invalid.') );
						break;
				}
			} else {
				$error->add( 'invalid_nonce', self::_('The request was missing required authentication data.') );
			}

			if ( count( $error->get_error_codes() ) ) {

				foreach ( $error->get_error_codes() as $code ) {
					$response['errors'][] = array( 'code' => $code, 'message' => $error->get_error_message( $code ) );
				}
				status_header( 400 );
				header( 'Content-Type: application/json' );
				$response['status'] = 'FAILURE';
			}
			die( json_encode( $response ) );
		}
	}

	public function action__wp_ajax_lift_settings() {

		$current_state = array(
			'domainname' => Lift_Search::get_search_domain_name(),
			'region' => Lift_Search::get_domain_region(),
			'last_sync' => Lift_Batch_Handler::get_last_cron_time(),
			'next_sync' => Lift_Batch_Handler::get_next_cron_time(),
			'batch_interval' => Lift_Search::get_batch_interval_display(),
			'override_search' => Lift_Search::get_override_search(),
			'nonce' => wp_create_nonce( 'lift_setting' ),
		);

		$c_state = array( );
		foreach ( $current_state as $id => $value ) {
			$c_state[] = array( 'id' => $id, 'value' => $value );
		}
		$current_state = $c_state;

		$response = json_encode( $current_state );

		header( 'Content-Type: application/json' );
		die( $response );
	}

	public function action__wp_ajax_lift_domains() {

		$response = array(
			'domains' => array( ),
			'error' => false,
			'nonce' => wp_create_nonce( 'lift_domain' )
		);
	  $dm = Lift_Search::get_domain_manager();
		$region = ( !empty($_REQUEST['region']) ) ? $_REQUEST['region'] : Lift_Search::get_domain_region();
		$domains = $dm->get_domains( $region );
    $error = $dm->get_last_error();

    $search_domain_name = Lift_Search::get_search_domain_name();
    $search_domain_region = Lift_Search::get_domain_region();
    
    if(!$domains && $error && $error['code'] == 'AccessDenied' && 
       $search_domain_name && $search_domain_region)
    {
      $domain = $dm->get_domain( $search_domain_name, 
                                 $search_domain_region );

      if($domain)
        $domains = array($domain);
    }
		if ( $domains === false && $error && $error['code'] != 'AccessDenied' ) {
			$response['error'] = $error;
		} else {
			$response['domains'] = $domains;
		}
		
		header( 'Content-Type: application/json' );
		die( json_encode( $response ) );
	}

	public function action__wp_ajax_lift_domain() {
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['model'] ) ) {
			$model = json_decode( stripslashes( $_POST['model'] ) );

			$response = array(
				'status' => 'SUCCESS',
				'data' => array( ),
				'errors' => array( ),
			);

			$error = new WP_Error();

			if ( isset( $_GET['nonce'] ) && wp_verify_nonce( $_GET['nonce'], 'lift_domain' ) ) {
				$dm = Lift_Search::get_domain_manager();
				$result = $dm->initialize_new_domain( $model->DomainName, $model->Region );
				if ( is_wp_error( $result ) ) {
					$error = $result;
				} else {
					$response['data'] = $dm->get_domain( $model->DomainName );
				}
			} else {
				$error->add( 'invalid_nonce', self::_('The request was missing required authentication data.') );
			}

			if ( count( $error->get_error_codes() ) ) {

				foreach ( $error->get_error_codes() as $code ) {
					$response['errors'][] = array( 'code' => $code, 'message' => $error->get_error_message( $code ) );
				}
				status_header( 400 );
				header( 'Content-Type: application/json' );
				$response['status'] = 'FAILURE';
			}
			die( json_encode( $response ) );
		}
	}

	public function action__wp_ajax_lift_update_queue() {

		$response = ( object ) array(
				'error' => false,
		);

		$page = max( abs( $_GET['paged'] ), 1 );
		$update_query = Lift_Document_Update_Queue::query_updates( array(
				'page' => $page,
				'per_page' => 10,
				'queue_ids' => array( Lift_Document_Update_Queue::get_active_queue_id(), Lift_Document_Update_Queue::get_closed_queue_id() )
			) );

		$response->current_page = $page;
		$response->per_page = 10;
		$response->found_rows = $update_query->found_rows;
		$response->updates = array( );

		foreach ( $update_query->meta_rows as $meta_row ) {
			$meta_value = get_post_meta( $meta_row->post_id, $meta_row->meta_key, true );
			switch ( $meta_value['document_type'] ) {
				case 'post';
					$post_id = $meta_value['document_id'];
					if ( $meta_value['action'] == 'add' ) {
						$last_user = '';
						if ( $last_id = get_post_meta( $post_id, '_edit_last', true ) ) {
							$last_user = get_userdata( $last_id );
						}
						$response->updates[] = array(
							'id' => $post_id,
							'action' => 'add',
							'title' => get_the_title( $post_id ),
							'edit_url' => esc_url( get_edit_post_link( $post_id ) ),
							'author_name' => (isset( $last_user->display_name ) ? $last_user->display_name : ''),
							'queue_date' => mysql2date( 'D. M d Y g:ia', $meta_value['update_date'] )
						);
					} else {
						$response->updates[] = array(
							'id' => $post_id,
							'action' => 'delete',
							'title' => sprintf( self::_('Post Deletion (%d)'), $post_id ),
							'edit_url' => '#',
							'author_name' => '',
							'queue_date' => mysql2date( 'D. M d Y g:ia', $meta_value['update_date'] )
						);
					}
					break;
				default:
					continue;
			}
		}

		header( 'Content-Type: application/json' );
		die( json_encode( $response ) );
	}

	public function action__wp_ajax_lift_error_log() {

		$response = ( object ) array(
				'error' => false,
				'nonce' => wp_create_nonce( 'lift_error_log' ),
		);

		if ( Lift_Search::error_logging_enabled() ) {
			$response->view_all_url = esc_url( admin_url( sprintf( 'edit.php?post_type=%s', Voce_Error_Logging::POST_TYPE ) ) );
			if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
				check_ajax_referer( 'lift_error_log', 'nonce' );
				$response = Voce_Error_Logging::delete_logs( array( 'lift-search' ) );
			} else {
				$args = array(
					'post_type' => Voce_Error_Logging::POST_TYPE,
					'posts_per_page' => 5,
					'post_status' => 'any',
					'orderby' => 'date',
					'order' => 'DESC',
					'tax_query' => array( array(
							'taxonomy' => Voce_Error_Logging::TAXONOMY,
							'field' => 'slug',
							'terms' => array( 'error', 'lift-search' ),
							'operator' => 'AND'
						) ),
				);
				$query = new WP_Query( $args );

				$response->current_page = $query->get( 'paged' );
				$response->per_page = $query->get( 'posts_per_page' );
				$response->found_rows = $query->found_posts;
				$response->errors = array( );

				foreach ( $query->posts as $post ) {
					$response->errors[] = array(
						'error_html' => sprintf( '<strong>%s</strong><pre>%s</pre>', esc_html( $post->post_title ), wpautop( $post->post_content ) ),
						'date' => mysql2date( 'D. M d Y g:ia', $post->post_date )
					);
				}
			}
		} else {
			status_header( 400 );
			$response->view_all_url = '';
			$response->error = array( 'code' => 'logging_disabled', self::_('Error Logging is Disabled') );
		}



		Header( 'Content-Type: application/json' );
		die( json_encode( $response ) );
	}

	/**
	 * Add link to access settings page on Plugin mainpage
	 * @param array $links
	 * @param string $page
	 * @return array
	 */
	public function filter__plugin_row_meta( $links, $page ) {
		if ( $page == self::SUBMENU_CLOUDSEARCH_SLUG ) {
			$links[] = '<a href="' . admin_url( 'options-general.php?page=' . self::SUBMENU_CLOUDSEARCH_SLUG ) . '">'.self::_('Settings').'</a>';
		}
		return $links;
	}

	public function callback__render_options_page() {
		?>
		<div class="wrap lift-admin" id="lift-status-page">
		</div>
		<div id="lift_modal"  class="lift_modal">
			<div class="modal_overlay">&nbsp;</div>
			<div class="modal_wrapper">
				<div class="modal_content" id="modal_content">
				</div>
			</div>
		</div>
		<?php
		foreach( glob( __DIR__ . '/templates/*.html' ) as $template_name ){
			echo '<script type="text/html" id="' . basename( $template_name, '.html' ) . '-template">';
				include_once( $template_name );
			echo '</script>';
		}
	}

	public static function _print_configuration_nag() {
		?>
		<div id="banneralert" class="lift-colorized">
			<div class="lift-message"><p><?php echo self::_('<strong>Welcome to Librelio</strong>: 	Now that you\'ve activated the Librelio plugin it\'s time to set it up. Click below to get started.'); ?></p></div>
			<div><a class="lift-btn" href="<?php echo admin_url( 'options-general.php?page=' . self::SUBMENU_CLOUDSEARCH_SLUG ) ?>"><?php echo self::_('Configure Librelio'); ?></a></div>
			<div class="clr"></div>
		</div>
		<script>
			jQuery(document).ready(function($) {
				var $bannerAlert = $('#banneralert');
				if ($bannerAlert.length) {
					$('.wrap h2').first().after($bannerAlert);
				}
			});
		</script>
		<?php
	}

  public function get_wp_roles()
  {
	  $editable_roles = array_reverse( get_editable_roles() );
    
		header( 'Content-Type: application/json' );
		die( json_encode( $editable_roles ) );
  }
  
  public function set_allowed_roles_for()
  {
    global $wp_roles;
    $allowed_caps = array( 'librelio_view_paid_external_content' );
    $res = array( 'success' => 1 );
    $req = json_decode(stripslashes(@$_GET['req']), true);
    $capability = $req['capability'];
    $roles = $req['roles'];
    $proceed = false;
    if($capability && is_array($roles))
    {
      if(!in_array($capability, $allowed_caps))
        $res = array( 'error' => 1, 'error_message' => 'Capability is not allowed!');
      else
        $proceed = true;
    }
    else
    {
      $res = array( 'error' => 1, 'error_message' => 'Invalid request!' );
    }
    if($proceed)
    {
	    $editable_roles = get_editable_roles();
      foreach($editable_roles as $role_name => $role_info)
      {
	      $wp_roles->remove_cap($role_name, $capability);
        if(in_array($role_name, $roles))
          $wp_roles->add_cap($role_name, $capability);
      }
    }
		header( 'Content-Type: application/json' );
		die( json_encode( $res ) );
  }

  public function get_settings()
  {
    $res = array( 'value' => Lift_Search::get_settings() ?: array() );
		header( 'Content-Type: application/json' );
		die( json_encode( $res ) );
  }
  
  public function set_settings()
  {
    $settings = json_decode(stripSlashes(@$_GET['settings']), true);
    if(is_array($settings))
      foreach($settings as $key=>$value)
        Lift_Search::__set_setting($key, $value);
		header( 'Content-Type: application/json' );
		die( json_encode( array( 'success' => true ) ) );
  }
}