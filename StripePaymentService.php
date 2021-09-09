<?php

namespace ArtistRepublik\AROrders\App\Services;

use ArtistRepublik\AROrders\ARPayment;
use ArtistRepublik\AROrders\Models\Payment;
use ArtistRepublik\AROrders\Models\PaymentIntent;
use ArtistRepublik\AROrders\Models\PaymentIntentRefund;
use ArtistRepublik\AROrders\Models\SubscriptionPlan;
use Exception;
use Stripe\Customer;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\Stripe;

class StripePaymentService
{
    private $_user = null;
    private $_seller_user = null;
    private $_meta = null;

    /**
     * Sets the user and stripe api key.
     *
     * @param $user - The user which is being charged
     */
    public function __construct($user = null, $seller_user = null, $meta = null)
    {
        $this->_user = $user;
        if (!$user && auth()->check()) {
            $this->_user = auth()->user();
        }
        $this->_seller_user = $seller_user;
        $this->_meta = $meta;
        Stripe::setApiKey(config('arorders.stripe_secret'));
    }

    /**
     * Creates a stripe payment intent as well as a payment intent model.
     *
     * @param float $amount - Amount in dollars
     * @param string $type - The payment type
     * @param int|null $seller_id - The seller id
     * @param float|null $fee - The fee to be charged on behalf of AR in dollars
     * @return PaymentIntent|null - A payment intent model
     */
    public function create(float $amount, float $fee = null, bool $remember_card = false): ?PaymentIntent
    {

       if ($this->_seller_user) {
           $this->_sellerHasStripeSetup($this->_seller_user->seller_stripe_id);
       }

        $customer = self::createCustomerFromUser($this->_user);
        $this->_user = $this->_user->fresh();
        $intent_data = $this->_createIntentData($customer, $amount, $this->_seller_user, $fee, $remember_card);

        $stripe_intent = $this->_createPaymentIntent($intent_data);

        return PaymentIntent::create([
            'buyer_id' => $this->_user->id,
            'seller_id' => $this->_seller_user ? $this->_seller_user->id : null,
            'intent_id' => $stripe_intent->id,
            'client_secret' => $stripe_intent->client_secret,
            'amount' => $intent_data['amount'],
            'fee' => isset($intent_data['application_fee_amount']) ? $intent_data['application_fee_amount'] : 0,
            'customer' => $stripe_intent->customer,
            'status' => $stripe_intent->status,
        ]);
    }

    public function createSetupIntent(SubscriptionPlan $subscription_plan): PaymentIntent
    {
        $stripe_intent = $this->_user->createSetupIntent();

        return PaymentIntent::create([
            'buyer_id' => $this->_user->id,
            'seller_id' => null,
            'intent_id' => $stripe_intent->id,
            'client_secret' => $stripe_intent->client_secret,
            'amount' => ARPayment::convertDollarsToCents($subscription_plan->planable->price),
            'fee' => 0,
            'customer' => $stripe_intent->customer,
            'status' => $stripe_intent->status,
        ]);
    }

    /**
     * Takes in a payment intent and charges it.
     *
     * @param PaymentIntent $intent - The payment intent model
     * @param string $payment_id - The payment method id
     * @return string - The status of the charge
     */
    public function chargePaymentIntent(PaymentIntent $intent, string $payment_id): string
    {
        $payment_method = PaymentMethod::retrieve($payment_id);
        $payment_method->attach([
            'customer' => $this->_user->{config('arorders.user_stripe_customer_id_column')},
        ]);
        $payment_intent = StripePaymentIntent::retrieve($intent->intent_id);
        $payment_intent = $payment_intent->confirm([
            'payment_method' => $payment_id,
        ]);
        $intent->status = $payment_intent->status;
        $intent->save();

        return $payment_intent->status;
    }

    /**
     * Updates the payment status.
     *
     * @param PaymentIntent $intent
     * @return string [paid, declined]
     */
    public function updatePaymentStatus(PaymentIntent $intent): string
    {
        if (preg_match('/seti_(.*)/', $intent->intent_id)) {
            $stripe_intent = SetupIntent::retrieve($intent->intent_id);
        } else {
            $stripe_intent = StripePaymentIntent::retrieve($intent->intent_id);
        }
        $intent->status = $stripe_intent->status;
        $intent->save();

        return $stripe_intent->status === 'succeeded' ? Payment::STATUS_PAID : Payment::STATUS_DECLINED;
    }

    /**
     * Refund a given payment intent.
     *
     * @param PaymentIntent $intent
     * @return PaymentIntentRefund
     */
    public function refund(PaymentIntent $intent, int $amount = null): PaymentIntentRefund
    {
        $refund_data = [
            'payment_intent' => $intent->intent_id,
        ];
        if ($amount) {
            $refund_data['amount'] = $amount;
        }
        $refund = Refund::create($refund_data);

        return PaymentIntentRefund::create([
            'payment_intent_id' => $intent->id,
            'refund_id' => $refund->id,
            'reason' => $refund->reason ? $refund->reason : '',
            'status' => $refund->status,
            'amount' => $refund->amount,
        ]);
    }

    /**
     * Creates a stripe customer from a user
     * Saves the customer id to user stripe_customer_id field.
     *
     * @param $user - The user
     * @return Customer - A stripe customer instance
     */
    public static function createCustomerFromUser($user): Customer
    {
        Stripe::setApiKey(config('arorders.stripe_secret'));

        if ($user->{config('arorders.user_stripe_customer_id_column')}) {
            $customer = null;
            try {
                $customer = Customer::retrieve($user->{config('arorders.user_stripe_customer_id_column')});
            } catch (Exception $e) {
            }
            if ($customer) {
                return $customer;
            }
        }
        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->fname.' '.$user->lname,
        ]);
        $user->{config('arorders.user_stripe_customer_id_column')} = $customer->id;
        $user->save();

        return $customer;
    }

    /**
     * Verifies that the payment intent has succeeded.
     *
     * @param PaymentIntent $intent - The payment intent
     * @param bool $update_intent - If we should update the status of the payment intent
     * @return bool
     */
    public static function verifyPaymentForIntent(PaymentIntent $intent, bool $update_intent = true): bool
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $stripe_intent = StripePaymentIntent::retrieve($intent->intent_id);
        if ($update_intent) {
            $intent->status = $stripe_intent->status;
            $intent->save();
        }

        return $stripe_intent->status === 'succeeded';
    }

    /**
     * Attach a customer to a payment method.
     *
     * @param string $customer_id - The stripe customer id
     * @param string $payment_id - The stripe payment id
     * @return void
     */
    public static function attachCustomerToPaymentMethod(string $customer_id, string $payment_id): void
    {
        Stripe::setApiKey(config('arorders.stripe_secret'));
        $payment_method = PaymentMethod::retrieve($payment_id);
        $payment_method->attach([
            'customer' => $customer_id,
        ]);
    }

    /**
     * Checks if a given seller (user) has stripe set up.
     *
     * @param $seller - The seller's User model
     * @return bool
     */
    private function _sellerHasStripeSetup($seller): bool
    {
        if (!$seller->seller_stripe_id) {
            $error_msg = strtr('Seller with id: {id} does not have stripe setup', ['{id}' => $seller->id]);
            throw new Exception($error_msg);
        }

        return true;
    }

    /**
     * Creates the stripe payment intent data.
     *
     * @param Customer $customer - A stripe customer object
     * @param float $amount - Amount in dollars
     * @param User|null $seller - The seller User model
     * @param float|null $fee - The fee in dollars
     * @return array - The information to be passed when creating a stripe payment intent
     */
    private function _createIntentData(Customer $customer, float $amount, $seller = null, float $fee = null, bool $remember_card = false): array
    {
        $amount = ARPayment::convertDollarsToCents($amount);
        if ($fee !== null) {
            $fee = ARPayment::convertDollarsToCents($fee);
        }
        $intent_data = [
            'payment_method_types' => ['card'],
            'amount' => $amount,
            'currency' => 'usd',
            'customer' => $customer['id'],
        ];

        if ($remember_card) {
            $intent_data['setup_future_usage'] = 'off_session';
        }

        if ($seller) {
            $seller_fee = $seller->fee;
            $seller_fee_amount = $seller_fee * $amount;
            $api_client_fee = $amount - $seller_fee_amount;
            $intent_data['application_fee_amount'] = $api_client_fee;
            $intent_data['on_behalf_of'] = $seller->seller_stripe_id;
            $intent_data['transfer_data'] = ['destination' => $seller->seller_stripe_id];
        }
        if ($this->_meta) {
            foreach($this->_meta as $key=>$value) {
                $intent_data["metadata[$key]"] = $value;
            }
        }
        return $intent_data;
    }

    /**
     * Creates the actual stripe payment intent.
     *
     * @param array $intent_data - The data for the stripe payment intent
     * @return StripePaymentIntent - The stripe payment intent
     */
    private function _createPaymentIntent(array $intent_data): StripePaymentIntent
    {
        $error_msg = '';

        try {
            $payment_intent = StripePaymentIntent::create($intent_data);


        } catch (CardException | RateLimitException | InvalidRequestException | AuthenticationException | ApiConnectionException | ApiErrorException $e) {
            $error_msg = strtr('Creating payment intent failed with error code: {error_code} and message: {error_message}',
                                ['{error_code}' => $e->getStripeCode(),
                                '{error_message}' => $e->getMessage(), ]);
        } catch (Exception $e) {
            $error_msg = strtr('Creating payment intent failed with error code: {error_code} and message: {error_message}',
                                ['{error_code}' => $e->getCode(),
                                '{error_message}' => $e->getMessage(), ]);
        }
        if ($error_msg !== '') {
            throw new Exception($error_msg);
        }

        return $payment_intent;
    }
}
