<?php
/**
 * Stripe Subscription Gateway
 *
 * Provides a Stripe Payment Gateway for Subscriptions.
 *
 * @class       S4WC_Subscriptions_Gateway
 * @extends     S4WC_Gateway
 * @version     1.31
 * @package     WooCommerce/Classes/Payment
 * @author      Stephen Zuniga
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class S4WC_Subscriptions_Gateway extends S4WC_Gateway {

    public function __construct() {
        parent::__construct();

        // Hooks
        add_action( 'scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 3 );
    }

    /**
     * Process the payment and return the result
     *
     * @access      public
     * @param       int $order_id
     * @return      array
     */
    public function process_payment( $order_id ) {

        if ( WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {
            $this->order = new WC_Order( $order_id );

            if ( $this->send_to_stripe() ) {
                $this->order_complete();

                WC_Subscriptions_Manager::activate_subscriptions_for_order( $this->order );

                $result = array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url( $this->order )
                );

                return $result;
            } else {
                $this->payment_failed();

                // Add a generic error message if we don't currently have any others
                if ( wc_notice_count( 'error' ) == 0 ) {
                    wc_add_notice( __( 'Transaction Error: Could not complete your subscription payment.', 'stripe-for-woocommerce' ), 'error' );
                }
            }
        } else {
            return parent::process_payment( $order_id );
        }
    }

    /**
     * Process the subscription payment and return the result
     *
     * @access      public
     * @param       int $amount
     * @return      array
     */
    public function process_subscription_payment( $amount = 0 ) {
        global $s4wc;

        // Can't send to stripe without a value, assume it's good to go.
        if ( $amount === 0 ) {
            return true;
        }

        // Get customer id
        $customer = get_user_meta( $this->order->user_id, $s4wc->settings['stripe_db_location'], true );

        // Allow options to be set without modifying sensitive data like amount, currency, etc.
        $charge_data = apply_filters( 's4wc_subscription_charge_data', array(), $this->order );

        // Set up basics for charging
        $charge_data['amount']      = $amount * 100; // amount in cents
        $charge_data['currency']    = strtolower( get_woocommerce_currency() );
        $charge_data['customer']    = $customer['customer_id'];
        $charge_data['card']        = $customer['default_card'];
        $charge_data['description'] = $this->get_charge_description( 'subscription' );

        $charge = S4WC_API::create_charge( $charge_data );

        if ( isset( $charge->id ) ) {
            $this->order->add_order_note( sprintf( __( 'Subscription paid (%s)', 'stripe-for-woocommerce' ), $charge->id ) );

            return $charge;
        }

        return false;
    }

    /**
     * Process a scheduled payment
     *
     * @access      public
     * @param       float $amount_to_charge
     * @param       WC_Order $order
     * @param       int $product_id
     * @return      void
     */
    public function scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {
        $this->order = $order;

        $charge = $this->process_subscription_payment( $amount_to_charge );

        if ( $charge ) {
            WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
        } else {
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
        }
    }

    /**
     * Set up the charge that will be sent to Stripe
     *
     * @access      private
     * @return      void
     */
    private function charge_set_up() {
        // Add a customer or retrieve an existing one
        $customer = $this->get_customer();

        // Update default card
        if ( $form_data['chosen_card'] !== 'new' ) {
            $default_card = $this->stripe_customer_info['cards'][ (int)$form_data['chosen_card'] ]['id'];
            S4WC_DB::update_customer( $this->order->user_id, array( 'default_card' => $default_card ) );
        }

        $initial_payment = WC_Subscriptions_Order::get_total_initial_payment( $this->order );

        $charge = $this->process_subscription_payment( $initial_payment );

        $this->transaction_id = $charge->id;
    }
}
