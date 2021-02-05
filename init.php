<?php
/**
 * Plugin Name: 			EshieldBR para WooCommerce
 * Plugin URI: 				https://www.eshieldbr.com.br
 * Description: 			Este plugin é um complemento para o plugin WooCommerce que te ajuda a filtrar seus pedidos, como transações de cartão de crédito, contra fraudes online.
 * Author: 					EshieldBR
 * Author URI: 				https://www.eshieldbr.com.br/
 * Version: 				1.0.0
 * License:              	GPLv3 or later
 * Text Domain:          	eshieldbr-for-woocommerce
 * Domain Path:          	/languages
 * WC requires at least: 	3.0
 * WC tested up to:      	4.4
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

 if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if ( !defined( 'WC_FLP_DIR' ) ) {
    define( 'WC_FLP_DIR', __FILE__ );
}

if ( ! function_exists( 'wc_eshieldbr' ) ) :

add_action( 'plugins_loaded', 'wc_eshieldbr' );

function wc_eshieldbr() {

	require_once plugin_dir_path( __FILE__ ) . 'includes' . DIRECTORY_SEPARATOR . 'class.wc-eshieldbr.php';
	WC_EshieldBR::get_instance();

}

endif;
