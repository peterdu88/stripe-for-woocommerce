<?php
/*
 * Plugin Name: Stripe for WooCommerce
 * Plugin URI: https://github.com/stezu/woocommerce-stripe/
 * Description: Use Stripe for collecting credit card payments on WooCommerce.
 * Version: 1.0
 * Author: Stephen Zuniga
 * Author URI: https://github.com/stezu* 
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Foundation built by: Sean Voss // https://github.com/seanvoss/striper
 */

class WooCommerce_Stripe {

	public function __construct() {

		// Include Stripe Methods
		include_once( 'classes/class-wc_stripe.php' );

		// Grab settings
		$this->settings = get_option( 'woocommerce_wc_stripe' . '_settings', null );

		// API Info
		$this->settings['api_endpoint']				= 'https://api.stripe.com/';
		$this->settings['publishable_key']			= $this->settings['testmode'] == 'yes' ? $this->settings['test_publishable_key'] : $this->settings['live_publishable_key'];
		$this->settings['secret_key']				= $this->settings['testmode'] == 'yes' ? $this->settings['test_secret_key'] : $this->settings['live_secret_key'];

		// Database info location
		$this->settings['stripe_db_location']		= $this->settings['testmode'] == 'yes' ? '_stripe_test_customer_info' : '_stripe_live_customer_info';

		// Hooks
		add_filter( 'woocommerce_payment_gateways', array( &$this, 'woocommerce_stripe_gateway' ) );
		add_action( 'woocommerce_after_my_account', array( &$this, 'account_saved_cards' ) );
	}

	/**
	 * Add Stripe Gateway to WooCommerces list of Gateways
	 *
	 * @access public
	 * @param array $methods
	 * @return array
	 */
	public function woocommerce_stripe_gateway( $methods ) {
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			include_once( 'classes/class-wc_stripe_gateway.php' );
		}

		if ( class_exists( 'WC_Stripe_Gateway' ) ) {
			$methods[] = 'WC_Stripe_Gateway';
		}

		return $methods;
	}

	/**
	 * Gives front-end view of saved cards in the account page
	 *
	 * @access public
	 * @return void
	 */
	public function account_saved_cards() {
		global $wc_stripe;

		// If the current user is not a stripe customer, return
		if ( ! get_user_meta( get_current_user_id(), $wc_stripe->settings['stripe_db_location'], true ) )
			return;

		// If user requested to delete a card, delete it
		if ( isset( $_POST['delete_card'] ) && wp_verify_nonce( $_POST['_wpnonce'], "stripe_del_card" ) ) {
			WC_Stripe::delete_card( get_current_user_id(), $_POST['delete_card'] );
		}

		// Get user database object
		$user_meta = get_user_meta( get_current_user_id(), $wc_stripe->settings['stripe_db_location'], true );

		// Get user credit cards
		$credit_cards = isset( $user_meta['cards'] ) ? $user_meta['cards'] : false;

		if ( $credit_cards ) :
		?>
			<h2 id="saved-cards">Saved cards</h2>
			<table class="shop_table">
				<thead>
					<tr>
						<th>Card ending in...</th>
						<th>Expires</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $credit_cards as $i => $credit_card ) : ?>
					<tr>
						<td><?php echo esc_html( $credit_card['last4'] ); ?></td>
						<td><?php echo esc_html( $credit_card['exp_month'] ) . '/' . esc_html( $credit_card['exp_year'] ); ?></td>
						<td>
							<form action="#saved-cards" method="POST">
								<?php wp_nonce_field ( 'stripe_del_card' ); ?>
								<input type="hidden" name="delete_card" value="<?php echo esc_attr( $i ); ?>">
								<input type="submit" value="Delete card">
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php
		endif;
	}
}

$GLOBALS['wc_stripe'] = new WooCommerce_Stripe();

/**
 * Process the captured payment when changing order status to completed
 *
 * @access public
 * @param int $order_id
 * @return bool
 */
function wc_stripe_order_status_completed( $order_id = null ) {
	global $woocommerce, $wc_stripe;

	if ( ! $order_id ) {
		$order_id = $_POST['order_id'];
	}

	$data = get_post_meta( $order_id );
	$total = $data['_order_total'][0] * 100;

	$params = array();
	if( isset( $_POST['amount'] ) && $amount = $_POST['amount'] ) {
		$params['amount'] = round( $amount );
	}

	if( get_post_meta( $order_id, 'capture', true ) ) {

		$transaction_id = get_post_meta( $order_id, 'transaction_id', true );

		$charge = WC_Stripe::capture_charge( $transaction_id, $params );

		return $charge;
	}
}
add_action( 'woocommerce_order_status_processing_to_completed', 'wc_stripe_order_status_completed' );

/**
 * Handles posting notifications to the user when their credit card information is invalid
 *
 * @access public
 * @return void
 */
function validation_errors() {

	foreach( $_POST['errors'] as $error ) {
		$message = '';

		$message .= '<strong>';
		switch ( $error['field'] ) {
			case 'number':
				$message .= __( 'Credit Card Number', 'wc_stripe' );
				break;
			case 'expiration':
				$message .= __( 'Credit Card Expiration', 'wc_stripe' );
				break;
			case 'cvc':
				$message .= __( 'Credit Card CVC', 'wc_stripe' );
				break;
		}
		$message .= '</strong>';

		switch ( $error['type'] ) {
			case 'undefined':
				$message .= ' ' . __( 'is a required field', 'wc_stripe' );
				break;
			case 'invalid':
				$message = __( 'Please enter a valid', 'wc_stripe' ) . ' ' . $message;
				break;
		}
		$message .= '.';

		wc_add_notice( $message, 'error' );
	}

	if ( is_ajax() ) {

		ob_start();
		wc_print_notices();
		$messages = ob_get_clean();

		echo '<!--WC_STRIPE_START-->' . json_encode(
			array(
				'result'	=> 'failure',
				'messages' 	=> $messages,
				'refresh' 	=> isset( WC()->session->refresh_totals ) ? 'true' : 'false',
				'reload'    => isset( WC()->session->reload_checkout ) ? 'true' : 'false'
			)
		) . '<!--WC_STRIPE_END-->';

		unset( WC()->session->refresh_totals, WC()->session->reload_checkout );
		exit;
	}
	die();
}
add_action( 'wp_ajax_stripe_form_validation', 'validation_errors' );
add_action( 'wp_ajax_nopriv_stripe_form_validation', 'validation_errors' );
