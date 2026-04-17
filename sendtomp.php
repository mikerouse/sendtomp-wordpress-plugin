<?php
/*
Plugin Name: Gravity Forms Send to MP Addon
Plugin URI: http://www.bluetorch.co.uk
Description: An add-on to the Gravity Forms plugin that sends form data to the MP API so that it can be used to send messages to UK Members of Parliament, such as petition signatures or copies of manifestos that constituents want the MP to read and agree to.
Version: 0.1
Author: Mike Rouse for Bluetorch Consulting Ltd
Author URI: http://www.bluetorch.co.uk

------------------------------------------------------------------------
Copyright 2024 Bluetorch Consulting Ltd

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
------------------------------------------------------------------------

*/
define( 'GF_SENDTOMP_VERSION', '0.1' );

add_action( 'gform_loaded', array( 'GF_SendToMP_Bootstrap', 'load' ), 5 );

class GF_SendToMP_Bootstrap {

    public static function load() {

        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
            return;
        }

        require_once( 'class-sendtomp.php' );

        GFAddOn::register( 'GFSendToMP' );
    }

}

function gf_sendtomp() {
    return GFSimpleAddOn::get_instance();
}



