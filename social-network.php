<?php

use Rdlv\WordPress\Networks\NetworkApi;

/*
Plugin Name: Advanced Custom Fields: Facebook Connection
Plugin URI: PLUGIN_URL
Description: SHORT_DESCRIPTION
Version: 1.0.0
Author: Jérôme Mulsant <jerome@rue-de-la-vieille.fr>
Author URI: https://rue-de-la-vieille.fr
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// exit if accessed directly
if (!defined('ABSPATH')) exit;

// check if class already exists
if (!class_exists('SocialNetwork')) :

    class SocialNetwork
    {

        /*
        *  __construct
        *
        *  This function will setup the class functionality
        *
        *  @type	function
        *  @date	17/02/2016
        *  @since	1.0.0
        *
        *  @param	n/a
        *  @return	n/a
        */

        /**
         * Networks constructor.
         */
        function __construct()
        {
            // vars
            $this->settings = array(
                'version' => '1.0.0',
                'url'     => plugin_dir_url(__FILE__),
                'path'    => plugin_dir_path(__FILE__),
            );
        }

        public function init()
        {
            // set text domain
            // https://codex.wordpress.org/Function_Reference/load_plugin_textdomain
            load_plugin_textdomain('acf-social_connection', false, plugin_basename(dirname(__FILE__) . '/..') . '/lang');

            // include field
            add_action('acf/include_field_types', array($this, 'include_field_types')); // v5
            add_action('acf/register_fields', array($this, 'include_field_types')); // v4

            NetworkApi::init();
        }

        /*
        *  include_field_types
        *
        *  This function will include the field type class
        *
        *  @type	function
        *  @date	17/02/2016
        *  @since	1.0.0
        *
        *  @param	$version (int) major ACF version. Defaults to false
        *  @return	n/a
        */

        function include_field_types($version = false)
        {
            // support empty $version
            if (!$version) $version = 4;

            // include
            include_once('fields/acf-social_connection-v' . $version . '.php');
        }
    }

    // initialize
    (new SocialNetwork())->init();

// class_exists check
endif;

?>
