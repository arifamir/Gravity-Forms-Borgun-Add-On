<?php
/*
* Plugin Name: Gravity Forms Borgun Add-On
* Plugin URI: https://github.com/arifamir/Gravity-Forms-Borgun-Add-On
* Description: Integrates Gravity Forms with Borgun payments enabling end users to purchase goods and services through Gravity Forms.
* Version: 1.0.0
* Author: Muhammad Arif Amir
* Author URI: https://profiles.wordpress.org/marifamir/
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Text Domain: gravityformsborgun
* Domain Path: /languages
*/


define( 'GF_KORTA_VERSION', '1.0.0' );
add_action( 'gform_loaded', array( 'GF_Borgun_Bootstrap', 'load' ), 5 );

class GF_Borgun_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-borgun.php' );

		GFAddOn::register( 'GFBorgun' );
	}
}

function gf_borgun() {
	return GFBorgun::get_instance();
}

if ( ! function_exists( 'add_borgun_currency' ) ) {

	add_filter( 'gform_currencies', 'add_borgun_currency' );
	function add_borgun_currency( $currencies ) {
		$currencies['ISK'] = array(
			'name'               => __( 'Icelandic Króna', 'gravityforms' ),
			'symbol_left'        => 'Kr.',
			'symbol_right'       => '',
			'symbol_padding'     => ' ',
			'thousand_separator' => ',',
			'decimal_separator'  => '.',
			'decimals'           => 2
		);

		return $currencies;
	}
}
