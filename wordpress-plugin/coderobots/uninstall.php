<?php
// Remove the plugin's settings when it is deleted from Plugins → Installed Plugins.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'coderobots_settings' );
