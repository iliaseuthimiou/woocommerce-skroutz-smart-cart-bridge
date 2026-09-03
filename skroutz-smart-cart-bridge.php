<?php
/**
 * Plugin Name:       Skroutz Smart Cart Bridge for WooCommerce
 * Plugin URI:        https://github.com/iliaseuthimiou/woocommerce-skroutz-smart-cart-bridge
 * Description:       Imports Skroutz Smart Cart webhook orders into WooCommerce and keeps their state and shipment details synchronized.
 * Version:           1.0.0
 * Author:            Ilias Euthimiou
 * Author URI:        https://iliaseuthimiou.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       skroutz-smart-cart-bridge
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package SkroutzSmartCartBridge
 */

/*
 * Copyright (C) 2026 Ilias Euthimiou
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSCB_VERSION', '1.0.0' );
define( 'SSCB_PLUGIN_FILE', __FILE__ );
define( 'SSCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SSCB_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(
	SSCB_PLUGIN_FILE,
	array( 'IliasEuthimiou\\SkroutzSmartCartBridge\\Plugin', 'activate' )
);

add_action(
	'before_woocommerce_init',
	array( 'IliasEuthimiou\\SkroutzSmartCartBridge\\Plugin', 'declare_woocommerce_compatibility' )
);

IliasEuthimiou\SkroutzSmartCartBridge\Plugin::instance();
