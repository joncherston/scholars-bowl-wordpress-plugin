<?php
if (!defined('ABSPATH')) {
    exit;
}

class SBR_Stripe_Handler {

    private $stripe_secret_key;

    public function __construct($stripe_secret_key = null) {
        // Use provided key or fall back to global option
        $this->stripe_secret_key = $stripe_secret_key ?: get_option('sbr_stripe_secret_key');

        // Include Stripe PHP library from our internal copy
        $this->load_stripe_library();
    }

    private function load_stripe_library() {
        // Check if Stripe class is already loaded
        if (class_exists('\Stripe\Stripe')) {
            error_log('SBR: Stripe library already loaded');
            return true;
        }

        // Load from our internal Stripe library
        $stripe_init_path = plugin_dir_path(__FILE__) . '../lib/stripe/init.php';
        error_log('SBR: Loading internal Stripe library from: ' . $stripe_init_path);

        if (file_exists($stripe_init_path)) {
            error_log('SBR: Found internal Stripe library, loading...');
            require_once $stripe_init_path;

            // Verify classes are properly loaded
            if (class_exists('\\Stripe\\Stripe') && class_exists('\\Stripe\\PaymentIntent') && class_exists('\\Stripe\\Refund')) {
                error_log('SBR: Internal Stripe library loaded successfully');
                return true;
            } else {
                error_log('SBR: Failed to load required Stripe classes from internal library');
                return false;
            }
        } else {
            error_log('SBR: Internal Stripe library not found at: ' . $stripe_init_path);
        }

        return false;
    }


    public function process_payment($payment_method_id, $amount_cents, $customer_email, $registration_id) {
        if (empty($this->stripe_secret_key)) {
            return array(
                'success' => false,
                'error' => 'Stripe is not configured. Please contact the administrator.'
            );
        }

        // Load Stripe library if not already loaded
        if (!class_exists('\Stripe\Stripe')) {
            if (!$this->load_stripe_library()) {
                error_log('SBR: Failed to load Stripe library');
                return array(
                    'success' => false,
                    'error' => 'Stripe PHP library is not available. Please install the Stripe PHP library or check Formidable Stripe plugin.'
                );
            }
        }

        // Verify required classes are loaded
        if (!class_exists('\Stripe\PaymentIntent')) {
            error_log('SBR: Stripe\PaymentIntent class not found after library load');
            return array(
                'success' => false,
                'error' => 'Stripe PaymentIntent class not available. Library loading failed.'
            );
        }

        try {
            // Set the API key
            \Stripe\Stripe::setApiKey($this->stripe_secret_key);

            // Create payment intent
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $amount_cents,
                'currency' => 'usd',
                'payment_method' => $payment_method_id,
                'confirmation_method' => 'manual',
                'confirm' => true,
                'return_url' => add_query_arg('stripe_return', '1', wp_get_referer() ?: home_url()),
                'description' => 'Scholars Bowl Registration #' . $registration_id,
                'receipt_email' => $customer_email,
                'metadata' => [
                    'registration_id' => $registration_id,
                    'type' => 'scholars_bowl_registration'
                ]
            ]);

            if ($payment_intent->status === 'succeeded') {
                // Detect if this is a test mode transaction
                $is_test = strpos($payment_intent->id, 'pi_test_') === 0;
                
                return array(
                    'success' => true,
                    'payment_intent_id' => $payment_intent->id,
                    'is_test_mode' => $is_test
                );
            } elseif ($payment_intent->status === 'requires_action') {
                return array(
                    'success' => false,
                    'error' => 'Payment requires additional authentication. Please try a different card.'
                );
            } else {
                $error_message = 'Payment failed';
                if (isset($payment_intent->last_payment_error->message)) {
                    $error_message .= ': ' . $payment_intent->last_payment_error->message;
                }
                return array(
                    'success' => false,
                    'error' => $error_message
                );
            }

        } catch (\Stripe\Exception\CardException $e) {
            return array(
                'success' => false,
                'error' => $e->getError()->message
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Stripe API error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Payment processing failed. Please try again.'
            );
        } catch (Exception $e) {
            error_log('Stripe payment error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Payment processing failed. Please try again.'
            );
        }
    }

    public function create_refund($payment_intent_id, $amount_cents = null, $reason = 'requested_by_customer') {
        if (empty($this->stripe_secret_key)) {
            return array(
                'success' => false,
                'error' => 'Stripe is not configured.'
            );
        }

        if (!class_exists('\Stripe\Stripe')) {
            if (!$this->load_stripe_library()) {
                return array(
                    'success' => false,
                    'error' => 'Stripe PHP library is not available.'
                );
            }
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripe_secret_key);

            $refund_data = [
                'payment_intent' => $payment_intent_id,
                'reason' => $reason
            ];

            if ($amount_cents !== null) {
                $refund_data['amount'] = $amount_cents;
            }

            $refund = \Stripe\Refund::create($refund_data);

            if ($refund->status === 'succeeded') {
                return array(
                    'success' => true,
                    'refund_id' => $refund->id
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Refund failed with status: ' . $refund->status
                );
            }

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Stripe refund error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getError()->message
            );
        } catch (Exception $e) {
            error_log('Stripe refund error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Refund processing failed.'
            );
        }
    }

    public function get_payment_details($payment_intent_id) {
        if (empty($this->stripe_secret_key)) {
            return array(
                'success' => false,
                'error' => 'Stripe is not configured.'
            );
        }

        if (!class_exists('\Stripe\Stripe')) {
            if (!$this->load_stripe_library()) {
                return array(
                    'success' => false,
                    'error' => 'Stripe PHP library is not available.'
                );
            }
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripe_secret_key);

            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

            return array(
                'success' => true,
                'payment_intent' => $payment_intent->toArray()
            );

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Stripe payment details error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getError()->message
            );
        } catch (Exception $e) {
            error_log('Stripe payment details error: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Failed to retrieve payment details.'
            );
        }
    }
}