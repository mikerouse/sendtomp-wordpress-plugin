<?php
/**
 * Plugin Name: SendToMP
 * Plugin URI: https://www.bluetorch.co.uk/sendtomp
 * Description: Send verified constituent messages to UK Members of Parliament and Peers. Supports Gravity Forms, WPForms, Contact Form 7, and webhook integrations. Built by a former parliamentary assistant.
 * Version: 1.4.11
 * Author: Bluetorch Consulting Ltd
 * Author URI: https://www.bluetorch.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sendtomp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SENDTOMP_VERSION', '1.4.11' );
define( 'SENDTOMP_PLUGIN_FILE', __FILE__ );
define( 'SENDTOMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENDTOMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SENDTOMP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SENDTOMP_API_BASE', 'https://www.bluetorch.co.uk' );

/**
 * Autoloader for SendToMP classes.
 *
 * Maps class names like SendToMP_API_Client to includes/class-sendtomp-api-client.php,
 * except Admin/Settings classes which live in admin/.
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'SendToMP' ) !== 0 ) {
		return;
	}

	// Interface and abstract adapter classes.
	$adapter_map = [
		'SendToMP_Form_Adapter_Interface' => 'adapters/interface-sendtomp-form-adapter.php',
		'SendToMP_Form_Adapter_Abstract'  => 'adapters/abstract-sendtomp-form-adapter.php',
	];

	if ( isset( $adapter_map[ $class ] ) ) {
		$path = SENDTOMP_PLUGIN_DIR . $adapter_map[ $class ];
		if ( file_exists( $path ) ) {
			require_once $path;
		}
		return;
	}

	$file = strtolower( $class );
	$file = str_replace( '_', '-', $file );
	$file = 'class-' . $file . '.php';

	// Admin and Settings classes live in admin/
	if ( strpos( $class, 'SendToMP_Admin' ) === 0 || strpos( $class, 'SendToMP_Settings' ) === 0 ) {
		$path = SENDTOMP_PLUGIN_DIR . 'admin/' . $file;
	} else {
		$path = SENDTOMP_PLUGIN_DIR . 'includes/' . $file;
	}

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

function sendtomp() {
	return SendToMP::get_instance();
}

add_action( 'plugins_loaded', function () {
	sendtomp()->init();
}, 20 );

register_activation_hook( __FILE__, array( 'SendToMP', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SendToMP', 'deactivate' ) );
