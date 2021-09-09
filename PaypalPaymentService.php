<?php

namespace ArtistRepublik\AROrders\App\Services;

use App\User;
use ArtistRepublik\AROrders\ARPayment;
use ArtistRepublik\AROrders\Models\Payment;
use ArtistRepublik\AROrders\Models\PaypalOrder;
use ArtistRepublik\AROrders\Models\PaypalPayout;
use ArtistRepublik\AROrders\Models\PaypalWebhookEvent;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\App;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Payments\CapturesRefundRequest;
use PaypalPayoutsSDK\Payouts\PayoutsPostRequest;
use Illuminate\Support\Facades\Log;


class PaypalPaymentService
{

    const PAYPAL_FEE_PERCENTAGE = .029;
    const PAYPAL_ADDITIONAL_FEE = .30;
    
    private $_client = null;
    private $_user = null;
    private $_seller_user = null;

    /**
     * Sets the user and paypal client.
     *
     * @param User $user - The user which is being charged
     */
    public function __construct($user = null, $seller_user = null)
    {
        $this->_user = $user;
        if (! $user && auth()->check()) {
            $this->_user = auth()->user();
        }
        $this->_seller_user = $seller_user;
        if (App::environment() !== 'production') {
            $environment = new SandboxEnvironment(config('arorders.paypal_client'), config('arorders.paypal_secret'));
        } else {
            $environment = new ProductionEnvironment(config('arorders.paypal_client'), config('arorders.paypal_secret'));
        }
        $this->_client = new PayPalHttpClient($environment);
    }

    /**
     * Create a paypal payment order.
     *
     * @param float $amount - Amount in dollars
     * @param int $product_type_id - Product type id
     * @param int $seller_id - Seller user id
     * @param float $fee - Fee in dollars
     * @param string $return_url - Redirect URL
     * @param string $cancel_url - Cancel URL
     * @return PaypalOrder|null
     */
    public function create(float $amount, float $fee = null, string $return_url, string $cancel_url): ?PaypalOrder
    {
        $seller_merchant_id = null;
        if ($this->_seller_user) {
            $this->_sellerHasPaypalSetup($this->_seller_user);
            $seller_merchant_id = $this->_seller_user->{config('arorders.seller_paypal_id_column')};
        }

        $amount = ceil($amount * 100) / 100;
        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');
        $request->body = $this->_createOrderData($this->_user, $seller_merchant_id, $amount, $fee, $return_url, $cancel_url);
        $response = $this->_client->execute($request);

        return PaypalOrder::create([
            'buyer_user_id' => $this->_user->id,
            'seller_user_id' => $this->_seller_user ? $this->_seller_user->id : null,
            'order_id' => $response->result->id,
            'status' => $response->result->status,
            'amount' => ARPayment::convertDollarsToCents($amount),
            'fee' => $fee ? ARPayment::convertDollarsToCents($fee) : 0,
            'payment_link' => array_values(array_filter($response->result->links, function ($val) {
                return $val->rel === 'approve';
            }))[0]->href, //TODO seperate this line out
        ]);
    }

    /**
     * Updates the status oof the paypal order.
     *
     * @param PaypalOrder $paypal_order
     * @return string [paid, declined]
     */
    public function updatePaymentStatus(PaypalOrder $paypal_order): string
    {
        $request = new OrdersCaptureRequest($paypal_order->order_id);

        try {
            $response = $this->_client->execute($request);
            $status = $response->result->purchase_units[0]->payments->captures[0]->status;
            $paypal_order->status = $status;
            $paypal_order->capture_id = $response->result->purchase_units[0]->payments->captures[0]->id;
            $paypal_order->save();
            return $status === 'COMPLETED' ? Payment::STATUS_PAID : Payment::STATUS_DECLINED;
        } catch (Exception $e) {
            Log::error($e->message());
            return Payment::STATUS_DECLINED;
        }

    }

    /**
     * Updates the paypal payment data.
     *
     * @param PaypalOrder $paypal_order
     * @param array $data
     * @return void
     */
    public function updateData(PaypalOrder $paypal_order, array $data): void
    {
        foreach ($data as $column => $value) {
            $paypal_order->$column = $value;
        }
        $paypal_order->save();
    }

    public function refund(PaypalOrder $paypal_order, int $amount = null): void
    {
        $request = new CapturesRefundRequest($paypal_order->capture_id);
        $request->body = [
            'amount' => [
                'value' => $amount ? ARPayment::convertCentsToDollars($amount) : ARPayment::convertCentsToDollars($paypal_order->amount),
                'currency_code' => 'USD',
            ],
        ];
        $response = $this->_client->execute($request);
        $paypal_order->refund_id = $response->result->id;
        $paypal_order->save();
    }

    /**
     * Payout to user.
     *
     * @param float $amount - The payout amount
     * @param string $email_subject - The email subject
     * @param string $note - The note to go with the payout
     * @return PaypalPayout
     */
    public function payoutWithAmount(float $amount, string $email_subject, string $note)
    {
        $request = new PayoutsPostRequest();
        $amount = round($amount - (($amount * self::PAYPAL_FEE_PERCENTAGE) + self::PAYPAL_ADDITIONAL_FEE), 2);
        $request->body = $this->_createPayoutData($amount, $this->_user, $email_subject, $note);
        try {
            $response = $this->_client->execute($request);
            return PaypalPayout::create([
                'user_id' => $this->_user->id,
                'paypal_email' => $this->_user->paypal_email,
                'amount' => ARPayment::convertDollarsToCents($amount),
                'payout_batch_id' => $response->result->batch_header->payout_batch_id,
                'status' => $response->result->batch_header->batch_status,
                'email_subject' => $email_subject,
                'note' => $note,
            ]);
        } catch (Exception $e) {
            Log::error("Paypal Error Message: {$e->getMessage()} \n");
            return false;
        }
    }

    /**
     * Checks if a given seller (user) has paypal set up.
     *
     * @param User $seller - The seller's User model
     * @return bool
     */
    private function _sellerHasPaypalSetup($seller): bool
    {
        if (!$seller->{config('arorders.seller_paypal_id_column')}) {
            $error_msg = strtr('Seller with id: {id} does not have paypal setup', ['{id}' => $seller->id]);
            throw new Exception($error_msg);
        }

        return true;
    }

    /**
     * Creaetes the paypal order data.
     *
     * @param User $payer - The user buying
     * @param string $seller_merchant_id - Sellers paypal merchant id
     * @param float $amount - Amount in dollars
     * @param float $fee = Fee in Dollars
     * @param string $return_url - Return URL
     * @param string $cancel_url - Cancel URL
     * @return array
     */
    private function _createOrderData($payer, string $seller_merchant_id = null, float $amount, float $fee = null, string $return_url, string $cancel_url): array
    {
        $purchase_unit = [
            'amount' => [
                'value' => $amount,
                'currency_code' => 'USD',
            ],
        ];
        if ($seller_merchant_id) {
            $purchase_unit['payee'] = ['merchant_id' => $seller_merchant_id];
            $purchase_unit['payment_instruction'] = [
                'platform_fees' => [
                    [
                        'amount' => [
                            'value' => $fee,
                            'currency_code' => 'USD',
                        ],
                    ],
                ],
            ];
        }

        return [
            'intent' => 'CAPTURE',
            'payer' => [
                'name' => [
                    'given_name' => $payer->fname,
                    'surname' => $payer->lname,
                ],
                'email_address' => $payer->email,
            ],
            'purchase_units' => [
                $purchase_unit,
            ],
            'application_context' => [
                'brand_name' => 'Artist Republik',
                'return_url' => config('app.front_end_url').$return_url,
                'cancel_url' => config('app.front_end_url').$cancel_url,
            ],
        ];
    }

    private function _createPayoutData(float $amount, $reciever, string $email_subject, string $note)
    {
        return [
            'sender_batch_header' => [
                'email_subject' => $email_subject,
            ],
            'items' => [
                [
                    'recipient_type' => 'EMAIL',
                    'receiver' => $reciever->paypal_email,
                    'note' => $note,
                    'amount' => [
                        'currency' => 'USD',
                        'value' => $amount,
                    ],
                ],
            ],
        ];
    }

    public static function handleWebhookEvent(array $data): void
    {
        $paypal_webhook_event = PaypalWebhookEvent::create([
            'event_id' => $data['id'],
            'event_time' => Carbon::parse($data['create_time']),
            'resource_type' => $data['resource_type'],
            'event_type' => $data['event_type'],
            'summary' => $data['summary'],
            'event' => json_encode($data),
        ]);
        switch ($data['resource_type']) {
            case 'payouts_item':
                try {
                    $payout = PaypalPayout::where('payout_batch_id', $data['resource']['payout_batch_id'])->first();
                    $payout->status = $data['resource']['transaction_status'];
                    $payout->save();
                    $paypal_webhook_event->resource_id = $data['resource']['payout_batch_id'];
                    $paypal_webhook_event->save();
                } catch (Exception $e) {
                    Log::error("Payout Status Update Failed: {$e->getMessage()} \n");
                    return;
                }
            break;
            default:
                return;
        }
    }
}
