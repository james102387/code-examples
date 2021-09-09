<?php

namespace ArtistRepublik\AROrders\App\Http\Controllers;

use ArtistRepublik\AROrders\App\Http\Requests\PaymentUpdateRequest;
use ArtistRepublik\AROrders\App\Http\Resources\PaymentResource;
use ArtistRepublik\AROrders\App\Services\CAPIService;
use ArtistRepublik\AROrders\Facades\ARPayment;
use ArtistRepublik\AROrders\Models\Payment;
use ArtistRepublik\AROrders\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    public function update(Payment $payment, PaymentUpdateRequest $request)
    {
        if ($request->recurring) {
            ARPayment::createSubscription($payment, $request->all());
        } else {
            ARPayment::update($payment, $request->except(['recurring']));
        }

        if ($payment->status === Payment::STATUS_PAID) {
            $capi_service = new CAPIService();
            $capi_service->call(null, $request->ip(), $request->headers->get('X-USER-AGENT'), $request->headers->get('referer'), config('arorders.user')::resolveUser(), $request->_fbp, $request->_fbc, CAPIService::EVENT_PURCHASE, 'USD', 1, $payment->order);
        }

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function refund(Payment $payment, Request $request)
    {
        $amount = $request->query('amount', null);
        ARPayment::refund($payment, $amount);

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function payout()
    {
        $user = config('arorders.user')::resolveUser();
        if (!$user) {
            return $this->regularResponse([], false, 'USER_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $error = null;
        $model = $user->{config('arorders.payout_model_relationship')};
        $amount = $model->payout_amount;
        $error = call_user_func(config('arorders.payout_rules') . '::checkPayoutRules', $user, $amount);
        $email_subject = config('arorders.payout_email_subject');
        $note = config('arorders.payout_note');

        if ($error) {
            return $this->regularResponse([], false, $error[0], 200, $error[1]);
        }

        $model->paid_out_amount = $model->paid_out_amount + $amount;
        $model->last_payout = now();
        $model->save();
        ARPayment::payout($user, ARPayment::convertCentsToDollars($amount), $email_subject, $note);

        return $this->regularResponse([]);
    }

    public function cancelSubscription(Request $request)
    {
        $user = config('arorders.user')::resolveUser();

        if (!$user) {
            return $this->regularResponse([], false, 'USER_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$subscription_plan_id = $request->query('subscription_plan_id')) {
            return $this->regularResponse([], false, 'ERR_NO_TYPE', 400);
        }
        $subscription_plan = SubscriptionPlan::find($subscription_plan_id);

        $res = ARPayment::cancelSubscription($user, $subscription_plan->type, $subscription_plan->stripe_plan);

        return response($res)
            ->setStatusCode(Response::HTTP_OK);
    }
}
