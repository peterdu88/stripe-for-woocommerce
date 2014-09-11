<?php
/*
 * Plugin Name: Stripe for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/stripe-for-woocommerce
 * Description: Use Stripe for collecting credit card payments on WooCommerce.
 * Version: 1.25
 * Author: Stephen Zuniga
 * Author URI: http://stephenzuniga.com
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Foundation built by: Sean Voss // https://github.com/seanvoss/striper
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class S4WC {

	public function __construct() {
		global $wpdb;

		// Include Stripe Methods
		include_once( 'classes/class-s4wc_api.php' );

		// Include Database Manipulation Methods
		include_once( 'classes/class-s4wc_db.php' );

		// Include Customer Profile Methods
		include_once( 'classes/class-s4wc_customer.php' );

		// Transition to new namespace
		if ( ! get_option( 'woocommerce_s4wc_settings' ) ) {

			// Update settings
			update_option( 'woocommerce_s4wc_settings', get_option( 'woocommerce_wc_stripe_settings', array() ) );
			delete_option( 'woocommerce_wc_stripe_settings' );

			// Update saved payment methods
			$wpdb->query(
				"
				UPDATE $wpdb->postmeta
					SET `meta_value` = 's4wc'
					WHERE `meta_value` = 'wc_stripe'
				"
			);
		}

		// Grab settings
		$this->settings = get_option( 'woocommerce_s4wc_settings', array() );

		// Add default values for fresh installs
		$this->settings['testmode']					= isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : 'yes';
		$this->settings['test_publishable_key']		= isset( $this->settings['test_publishable_key'] ) ? $this->settings['test_publishable_key'] : '';
		$this->settings['test_secret_key']			= isset( $this->settings['test_secret_key'] ) ? $this->settings['test_secret_key'] : '';
		$this->settings['live_publishable_key']		= isset( $this->settings['live_publishable_key'] ) ? $this->settings['live_publishable_key'] : '';
		$this->settings['live_secret_key']			= isset( $this->settings['live_secret_key'] ) ? $this->settings['live_secret_key'] : '';
		$this->settings['saved_cards']				= isset( $this->settings['saved_cards'] ) ? $this->settings['saved_cards'] : 'yes';

		// API Info
		$this->settings['api_endpoint']				= 'https://api.stripe.com/';
		$this->settings['publishable_key']			= $this->settings['testmode'] == 'yes' ? $this->settings['test_publishable_key'] : $this->settings['live_publishable_key'];
		$this->settings['secret_key']				= $this->settings['testmode'] == 'yes' ? $this->settings['test_secret_key'] : $this->settings['live_secret_key'];

		// Database info location
		$this->settings['stripe_db_location']		= $this->settings['testmode'] == 'yes' ? '_stripe_test_customer_info' : '_stripe_live_customer_info';

		// Hooks
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_stripe_gateway' ) );

		// Localization
		load_plugin_textdomain( 'stripe-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Add Stripe Gateway to WooCommerces list of Gateways
	 *
	 * @access		public
	 * @param		array $methods
	 * @return		array
	 */
	public function add_stripe_gateway( $methods ) {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Include payment gateway
		include_once( 'classes/class-s4wc_gateway.php' );

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			include_once( 'classes/class-s4wc_subscriptions_gateway.php' );

			$methods[] = 'S4WC_Subscriptions_Gateway';
		} else {
			$methods[] = 'S4WC_Gateway';
		}

		return $methods;
	}
}

$GLOBALS['s4wc'] = new S4WC();

/**
 * Process the captured payment when changing order status to completed
 *
 * @param		int $order_id
 * @return		mixed
 */
function s4wc_order_status_completed( $order_id = null ) {

	if ( ! $order_id ) {
		$order_id = $_POST['order_id'];
	}

	if ( get_post_meta( $order_id, 'capture', true ) ) {

		$params = array();
		if ( isset( $_POST['amount'] )  ) {
			$params['amount'] = round( $_POST['amount'] );
		}

		$transaction_id = get_post_meta( $order_id, 'transaction_id', true );

		$charge = S4WC_API::capture_charge( $transaction_id, $params );

		return $charge;
	}
}
add_action( 'woocommerce_order_status_processing_to_completed', 's4wc_order_status_completed' );

/**
 * Get error message for form validator given field name and type of error
 *
 * @param		string $field
 * @param		string $type
 * @return		string
 */
function s4wc_get_form_error_message( $field, $type = 'undefined' ) {

	if ( $type === 'invalid' ) {
		return sprintf( __( 'Please enter a valid %s.', 'stripe-for-woocommerce' ), "<strong>$field</strong>" );
	} else {
		return sprintf( __( '%s is a required field.', 'stripe-for-woocommerce' ), "<strong>$field</strong>" );
	}
}

/**
 * Validate credit card form fields
 *
 * @return		void
 */
function s4wc_validate_form() {
	$form = array(
		'card-number'	=> isset( $_POST['s4wc-card-number'] ) ? $_POST['s4wc-card-number'] : null,
		'card-expiry'	=> isset( $_POST['s4wc-card-expiry'] ) ? $_POST['s4wc-card-expiry'] : null,
		'card-cvc'		=> isset( $_POST['s4wc-card-cvc'] ) ? $_POST['s4wc-card-cvc'] : null,
	);

	if ( $form['card-number'] ) {
		$field = __( 'Credit Card Number', 'stripe-for-woocommerce' );

		wc_add_notice( s4wc_get_form_error_message( $field, $form['card-number'] ), 'error' );
	}
	if ( $form['card-expiry'] ) {
		$field = __( 'Credit Card Expiration', 'stripe-for-woocommerce' );

		wc_add_notice( s4wc_get_form_error_message( $field, $form['card-expiry'] ), 'error' );
	}
	if ( $form['card-cvc'] ) {
		$field = __( 'Credit Card CVC', 'stripe-for-woocommerce' );

		wc_add_notice( s4wc_get_form_error_message( $field, $form['card-cvc'] ), 'error' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 's4wc_validate_form' );

/**
 * Wrapper of wc_get_template to relate directly to s4wc
 *
 * @param		string $template_name
 * @param		array $args
 * @return		string
 */
function s4wc_get_template( $template_name, $args = array() ) {
	$template_path = WC()->template_path();
	$default_path = plugin_dir_path( __FILE__ ) . '/templates/';

	if ( wc_locate_template( $template_name, $template_path . '/s4wc', $default_path ) ) {
		$template_path .= '/s4wc';
	} else {
		$template_path .= '/woocommerce-stripe';
	}

	return wc_get_template( $template_name, $args, $template_path, $default_path );
}

/**
 * Helper function to find the key of a nested value
 *
 * @param		string $needle
 * @param		array $haystack
 * @return		mixed
 */
if ( ! function_exists( 'recursive_array_search' ) ) {
	function recursive_array_search( $needle, $haystack ) {

		foreach ( $haystack as $key => $value ) {

			if ( $needle === $value || ( is_array( $value ) && recursive_array_search( $needle, $value ) !== false ) ) {
				return $key;
			}
		}
		return false;
	}
}
