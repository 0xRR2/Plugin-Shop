<?php

namespace Azuriom\Plugin\Shop\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\User;
use Azuriom\Plugin\Shop\Models\Gateway;
use Azuriom\Plugin\Shop\Models\Package;
use Azuriom\Plugin\Shop\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function selectGateway(Request $request, Package $package)
    {
        abort_if(! $package->isSubscription(), 403);

        $user = $request->user();

        if (use_site_money()) {
            $this->createInternalSubscription($user, $package);

            return to_route('shop.home')->with('success', trans('shop::messages.cart.success'));
        }

        $gateways = Gateway::enabled()
            ->get()
            ->filter(fn (Gateway $gateway) => $gateway->isSupported())
            ->filter(fn (Gateway $gateway) => $gateway->paymentMethod()->supportsSubscriptions());

        // If there is only one payment gateway, redirect to it directly
        if ($gateways->count() === 1) {
            $gateway = $gateways->first();

            return $gateway->paymentMethod()->startSubscription($user, $package);
        }

        return view('shop::payments.pay', [
            'gateways' => $gateways,
            'route' => fn (string $gateway) => route('shop.subscriptions.subscribe', [
                'package' => $package,
                'gateway' => $gateway,
            ]),
        ]);
    }

    public function subscribe(Request $request, Package $package, Gateway $gateway)
    {
        abort_if(! $gateway->is_enabled || ! $package->isSubscription(), 403);

        $user = $request->user();

        return $gateway->paymentMethod()->startSubscription($user, $package);
    }

    public function cancel(Request $request, Subscription $subscription)
    {
        abort_if(! $subscription->user->is($request->user()), 403);

        $subscription->cancel();

        return redirect()->route('shop.profile');
    }

    protected function createInternalSubscription(User $user, Package $package): void
    {
        $subscription = $package->subscriptions()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'gateway_type' => 'azuriom',
            'status' => 'active',
            'price' => $package->getPrice(),
            'currency' => 'XXX',
        ]);

        $subscription->addRenewalPayment();
    }
}
