<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Subscription;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

class PaymentController extends Controller
{
    public function createCheckoutSession(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'padre', 403, 'La suscripción debe pertenecer a una cuenta de padre o tutor.');
        $request->validate([
            'plan_key' => ['required', 'in:max'],
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = Session::create([
            'mode' => 'subscription',
            'customer_email' => $request->user()->email,
            'line_items' => [[
                'price' => config('services.stripe.price_max'),
                'quantity' => 1,
            ]],
            'metadata' => [
                'user_id' => (string) $request->user()->id,
                'plan_key' => 'max',
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $request->user()->id,
                    'plan_key' => 'max',
                ],
            ],
            'success_url' => config('app.frontend_url') . '/?payment=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.frontend_url') . '/?payment=cancelled',
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }

    public function confirmCheckoutSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'starts_with:cs_'],
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));
        $session = Session::retrieve($data['session_id']);
        $user = $request->user();
        abort_unless($user->role === 'padre', 403, 'La suscripción debe pertenecer a una cuenta de padre o tutor.');

        if ((string) ($session->metadata->user_id ?? '') !== (string) $user->id
            || ($session->metadata->plan_key ?? null) !== 'max') {
            return response()->json(['message' => 'La sesión de pago no pertenece a esta cuenta.'], 403);
        }

        if ($session->status !== 'complete'
            || !in_array($session->payment_status, ['paid', 'no_payment_required'], true)
            || !$session->subscription) {
            return response()->json(['status' => 'pending']);
        }

        $subscriptionId = is_string($session->subscription)
            ? $session->subscription
            : $session->subscription->id;
        $subscription = Subscription::retrieve($subscriptionId);
        if (!in_array($subscription->status, ['active', 'trialing'], true)) {
            return response()->json(['status' => 'pending']);
        }

        $this->activateUser($user, $session, $subscription);

        return response()->json([
            'status' => 'active',
            'user' => [
                ...$user->fresh()->only([
                'id', 'name', 'email', 'role', 'avatar', 'total_stars', 'streak', 'level',
                'equipped_sticker', 'equipped_costume',
                'plan_key', 'energy', 'energy_reset_at',
                'subscription_status', 'subscription_ends_at',
                ]),
                'has_premium' => true,
            ],
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret')
            );
        } catch (UnexpectedValueException|SignatureVerificationException $exception) {
            report($exception);
            return response()->json(['message' => 'Webhook inválido.'], 400);
        }

        if (DB::table('stripe_events')->where('stripe_event_id', $event->id)->exists()) {
            return response()->json(['received' => true]);
        }

        if (in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ], true)) {
            $session = $event->data->object;
            $user = User::find($session->metadata->user_id ?? null);

            if ($user && $user->role === 'padre' && ($session->payment_status ?? null) === 'paid' && $session->subscription) {
                Stripe::setApiKey(config('services.stripe.secret'));
                $subscriptionId = is_string($session->subscription)
                    ? $session->subscription
                    : $session->subscription->id;
                $subscription = Subscription::retrieve($subscriptionId);
                if (in_array($subscription->status, ['active', 'trialing'], true)) {
                    $this->activateUser($user, $session, $subscription);
                }
            }
        }

        if ($event->type === 'customer.subscription.updated') {
            $subscription = $event->data->object;
            $currentPeriodEnd = $this->subscriptionPeriodEnd($subscription);
            User::where('stripe_subscription_id', $subscription->id)->update([
                'subscription_status' => $subscription->status === 'active' ? 'active' : $subscription->status,
                'subscription_ends_at' => $currentPeriodEnd
                    ? \Illuminate\Support\Carbon::createFromTimestamp($currentPeriodEnd)
                    : null,
            ]);
        }

        if (in_array($event->type, ['customer.subscription.deleted', 'invoice.payment_failed'], true)) {
            $subscriptionId = $event->data->object->subscription
                ?? $event->data->object->id
                ?? null;

            User::where('stripe_subscription_id', $subscriptionId)->update([
                'subscription_status' => 'inactive',
                'plan_key' => 'free',
                'subscription_ends_at' => now(),
            ]);
        }

        DB::table('stripe_events')->insert([
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['received' => true]);
    }

    private function activateUser(User $user, object $session, object $subscription): void
    {
        $periodEnd = $this->subscriptionPeriodEnd($subscription);
        $user->forceFill([
            'plan_key' => 'max',
            'subscription_status' => $subscription->status,
            'stripe_customer_id' => $session->customer,
            'stripe_subscription_id' => $subscription->id,
            'subscription_ends_at' => $periodEnd
                ? \Illuminate\Support\Carbon::createFromTimestamp($periodEnd)
                : null,
        ])->save();
    }

    private function subscriptionPeriodEnd(object $subscription): ?int
    {
        $periodEnd = $subscription->current_period_end ?? null;
        if (!$periodEnd && isset($subscription->items->data[0])) {
            $periodEnd = $subscription->items->data[0]->current_period_end ?? null;
        }

        return $periodEnd ? (int) $periodEnd : null;
    }
}
