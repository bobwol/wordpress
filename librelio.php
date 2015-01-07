<?php
/*
Plugin Name: Librelio
Version: 0.0.3
Plugin URI: http://www.librelio.com/
Description: Improves WordPress search using Amazon CloudSearch
Author: Librelio
Author URI: http://www.librelio.com/
 */

require_once('lib/functions.php');

if ( !class_exists( 'Lift_Search' ) ) {

	load_plugin_textdomain('librelio', false, basename( dirname( __FILE__ ) ) . '/languages' );

  if ( version_compare( phpversion(), '5.3.0', '>=') ) {
    require_once('lift-core.php');

    register_deactivation_hook( __FILE__, '_lift_deactivate' );
  }

  function _lift_php_version_check() {
    $_ = lift_cloud_localize_func();
    if ( !class_exists( 'Lift_Search' ) ) {
	    die( '<p style="font: 12px/1.4em sans-serif;"><strong>'.sprintf($_('Librelio Search requires PHP version 5.3 or higher. Installed version is: %s'), phpversion()).'</strong></p>' );
	  } elseif ( function_exists('_lift_activation') ) {
	    _lift_activation();
	  }
  }

  // check to see if .com functions exist, if not, run php version check on activation - with .com environments we can assume PHP 5.3 or higher
  if ( !function_exists( 'wpcom_is_vip' ) ) {
    register_activation_hook( __FILE__, '_lift_php_version_check' );
  }

  function lift_wp_search_init($aws)
  {
      if ( class_exists( 'Lift_Search' ) ) {
        Lift_Search::init($aws);
      }
  }

  add_action('aws_init', 'lift_wp_search_init');

}
