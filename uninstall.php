<?php
/**
 * Remove plugin settings on uninstall.
 *
 * Order metadata is intentionally retained with historical orders.
 *
 * @package SkroutzSmartCartBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$sscb_options = array(
	'sscb_webhook_secret',
	'sscb_product_match_mode',
	'sscb_product_meta_key',
	'sscb_variation_match_mode',
	'sscb_variation_meta_key',
	'sscb_debug_logging',
);

foreach ( $sscb_options as $sscb_option ) {
	delete_option( $sscb_option );
}
