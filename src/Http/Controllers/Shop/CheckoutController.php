<?php

namespace WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop;

use WebbyCrown\WebbyCommerceStatamic\Cart\Cart;
use WebbyCrown\WebbyCommerceStatamic\Coupons\EntryCouponRepository;
use WebbyCrown\WebbyCommerceStatamic\Customers\EntryCustomerRepository;
use WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository;
use WebbyCrown\WebbyCommerceStatamic\Helpers\CurrencyHelper;
use WebbyCrown\WebbyCommerceStatamic\Orders\Order;
use WebbyCrown\WebbyCommerceStatamic\Payments\StripeGateway;
use WebbyCrown\WebbyCommerceStatamic\Shipping\ShippingManager;
use WebbyCrown\WebbyCommerceStatamic\Tax\TaxManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class CheckoutController
{
    protected Cart $cart;

    protected EntryCustomerRepository $customerRepository;

    protected EntryOrderRepository $orderRepository;

    protected EntryCouponRepository $couponRepository;

    protected TaxManager $taxManager;

    protected ShippingManager $shippingManager;

    protected bool $stockHeld = false;

    protected function rejectIfStockUnavailable(bool $json)
    {
        $message = $this->cart->stockError();
        if ($message === null) {
            return null;
        }

        if ($json) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        return $this->backWithCheckoutInput()->with('error', $message);
    }

    protected function releaseHeldStock(): void
    {
        if (! $this->stockHeld) {
            return;
        }

        $this->cart->releaseStock();
        $this->stockHeld = false;
    }

    /**
     * Restore only the checkout fields a visitor should see again.
     * Card numbers and security codes are never flashed into the session.
     */
    protected function backWithCheckoutInput()
    {
        return redirect()->back()->withInput(request()->only([
            'email',
            'first_name',
            'last_name',
            'phone',
            'billing_address',
            'shipping_address',
            'shipping_same_as_billing',
            'payment_method',
            'coupon_code',
            'notes',
            'shipping_method',
            'bank_sender_name',
            'bank_transaction_ref',
            'stripe_payment_method',
            'payment_intent',
        ]));
    }

    public function __construct(
        Cart $cart,
        EntryCustomerRepository $customerRepository,
        EntryOrderRepository $orderRepository,
        EntryCouponRepository $couponRepository,
        TaxManager $taxManager,
        ShippingManager $shippingManager
    ) {
        $this->cart = $cart;
        $this->customerRepository = $customerRepository;
        $this->orderRepository = $orderRepository;
        $this->couponRepository = $couponRepository;
        $this->taxManager = $taxManager;
        $this->shippingManager = $shippingManager;
    }

    public function index()
    {
        $cartData = $this->cart->toArray();

        if ($cartData['count'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'next_step' => 'shipping',
            'cart_summary' => $cartData,
        ]);
    }


    public function success(string $orderNumber)
    {
        $order = $this->orderRepository->findByOrderNumber($orderNumber);

        if (! $order) {
            return response()->json([
                'success' => false,
                'order_number' => $orderNumber,
                'message' => 'Order not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order_number' => $order->orderNumber(),
            'status' => $order->status(),
            'payment_status' => $order->paymentStatus(),
            'is_paid' => $order->isPaid(),
        ]);
    }


    public function address(Request $request)
    {
        $shippingSameAsBilling = $request->boolean('shipping_same_as_billing', true);

        $validated = $request->validate([
            'email' => 'required|email',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'billing_address' => 'required|array',
            'billing_address.city' => 'required|string|max:255',
            'billing_address.state' => 'required|string|max:255',
            'billing_address.country' => 'required|string|max:255',
            'billing_address.postal_code' => 'required|string|max:50',
            'shipping_same_as_billing' => 'boolean',
            'shipping_address' => 'required_if:shipping_same_as_billing,false|array',
            'shipping_address.city' => [Rule::requiredIf(! $shippingSameAsBilling), 'nullable', 'string', 'max:255'],
            'shipping_address.state' => [Rule::requiredIf(! $shippingSameAsBilling), 'nullable', 'string', 'max:255'],
            'shipping_address.country' => [Rule::requiredIf(! $shippingSameAsBilling), 'nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => [Rule::requiredIf(! $shippingSameAsBilling), 'nullable', 'string', 'max:50'],
        ]);

        $validated['shipping_same_as_billing'] = $shippingSameAsBilling;

        Session::put('checkout.address', $validated);

        $shippingData = $this->resolveShippingForCheckout();
        Session::put('checkout.shipping', $shippingData);

        // Reset tax engine to pick up new address
        $this->taxManager->resetEngine();

        $selectionThresholds = (array) config('webbycommerce.shipping.selection_thresholds', ['express' => 500, 'overnight' => 1500]);
        $summary = $this->getOrderSummary();

        return response()->json([
            'success' => true,
            'next_step' => 'shipping',
            'shipping' => $shippingData,
            'selection_thresholds' => $selectionThresholds,
            'subtotal' => round($summary['subtotal'], 2),
            'discount' => round($summary['discount'], 2),
            'tax' => round($summary['tax'], 2),
            'tax_rate' => $summary['tax_rate'],
            'tax_breakdown' => $summary['tax_breakdown'],
            'shipping_breakdown' => $summary['shipping_breakdown'],
            'total' => round($summary['total'], 2),
        ]);
    }

    public function updateAddress(Request $request)
    {
        $validated = $request->validate([
            'address' => 'required|array',
            'address.shipping_same_as_billing' => 'sometimes|boolean',
            'address.billing_address' => 'sometimes|array',
            'address.billing_address.city' => 'nullable|string|max:255',
            'address.billing_address.state' => 'nullable|string|max:255',
            'address.billing_address.country' => 'nullable|string|max:255',
            'address.billing_address.postal_code' => 'nullable|string|max:50',
            'address.shipping_address' => 'sometimes|array',
            'address.shipping_address.city' => 'nullable|string|max:255',
            'address.shipping_address.state' => 'nullable|string|max:255',
            'address.shipping_address.country' => 'nullable|string|max:255',
            'address.shipping_address.postal_code' => 'nullable|string|max:50',
        ]);

        $address = $validated['address'];
        $sessionAddress = Session::get('checkout.address', []);


        $sessionAddress = array_replace_recursive($sessionAddress, $address);

        Session::put('checkout.address', $sessionAddress);

        // Reset tax engine to pick up new address
        $this->taxManager->resetEngine();

        $shippingData = $this->resolveShippingForCheckout(
            Session::get('checkout.shipping.shipping_method')
        );

        Session::put('checkout.shipping', $shippingData);

        $summary = $this->getOrderSummary();
        if (! $this->hasCompleteShippingTaxAddress($sessionAddress)) {
            $summary['tax'] = 0;
            $summary['tax_rate'] = 0;
            $summary['tax_breakdown'] = ['line_items' => [], 'shipping' => 0, 'total' => 0];
            $summary['total'] = $summary['subtotal'] + $summary['shipping'] - $summary['discount'];
        }

        return response()->json([
            'success' => true,
            'shipping' => $shippingData,
            'shipping_changed' => $shippingData['location_changed'] ?? false,
            'subtotal' => round($summary['subtotal'], 2),
            'discount' => round($summary['discount'], 2),
            'tax' => round($summary['tax'], 2),
            'tax_rate' => $summary['tax_rate'],
            'tax_breakdown' => $summary['tax_breakdown'],
            'shipping_breakdown' => $summary['shipping_breakdown'],
            'total' => round($summary['total'], 2),
        ]);
    }

    public function shipping(Request $request)
    {
        $validated = $request->validate([
            'shipping_method' => 'required|string',
        ]);

        $shippingData = $this->resolveShippingForCheckout($validated['shipping_method']);
        $cost = $shippingData['shipping_cost'];

        if ($cost === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shipping method selected.',
            ], 400);
        }

        Session::put('checkout.shipping', $shippingData);
        $summary = $this->getOrderSummary();

        return response()->json([
            'success' => true,
            'next_step' => 'payment',
            'shipping_cost' => $cost,
            'shipping' => $shippingData,
            'subtotal' => round($summary['subtotal'], 2),
            'discount' => round($summary['discount'], 2),
            'tax' => round($summary['tax'], 2),
            'tax_rate' => $summary['tax_rate'],
            'tax_breakdown' => $summary['tax_breakdown'],
            'shipping_breakdown' => $summary['shipping_breakdown'],
            'total' => round($summary['total'], 2),
        ]);
    }

    public function complete(Request $request)
    {
        try {
            $checkoutData = Session::get('checkout');
            $cartData = $this->cart->toArray();

            if (!$checkoutData || $cartData['count'] === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checkout session expired or cart is empty.',
                ], 400);
            }

            if (empty($checkoutData['address']) || empty($checkoutData['shipping'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checkout address or shipping information is missing.',
                ], 400);
            }

            $rules = [
                'payment_method' => 'required|string',
                'coupon_code' => 'nullable|string',
                'notes' => 'nullable|string|max:2000',
            ];

            if (in_array($request->input('payment_method'), ['credit_card', 'stripe'])) {
                $rules['stripe_payment_method'] = 'nullable|string|max:255';
                $rules['payment_intent'] = 'nullable|string|max:255';
            } elseif ($request->input('payment_method') === 'bank_transfer') {
                $rules['bank_sender_name'] = 'required|string|max:255';
                $rules['bank_transaction_ref'] = 'nullable|string|max:255';
            }

            $paymentData = $request->validate($rules);

            if (in_array($paymentData['payment_method'] ?? '', ['credit_card', 'stripe'], true)
                && ($request->filled('card_number') || $request->filled('card_cvv'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card details must not be sent to the server. Confirm the card with Stripe.js and send a PaymentMethod or PaymentIntent only.',
                ], 422);
            }

            if (in_array($paymentData['payment_method'] ?? '', ['credit_card', 'stripe'], true)
                && empty($paymentData['stripe_payment_method'])
                && empty($paymentData['payment_intent'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'A Stripe PaymentMethod or PaymentIntent is required. Card numbers must not be posted to the server.',
                ], 422);
            }

            $checkoutData['payment'] = $paymentData;
            Session::put('checkout.payment', $paymentData);

            $subtotal = round($this->cart->subtotal(), 2);
            $resolvedCoupon = $this->resolveAppliedCoupon($paymentData['coupon_code'] ?? null, $subtotal);
            if (! $resolvedCoupon['ok']) {
                return response()->json([
                    'success' => false,
                    'message' => $resolvedCoupon['message'],
                ], 400);
            }

            $couponData = $resolvedCoupon['coupon'];
            $discountAmount = $resolvedCoupon['discount'];
            $checkoutData['coupon'] = $couponData;
            Session::put('checkout.coupon', $couponData);

            $address = $checkoutData['address'];
            $shippingSameAsBilling = $address['shipping_same_as_billing'] ?? false;
            $shippingAddress = $shippingSameAsBilling
                ? $address['billing_address']
                : $address['shipping_address'] ?? $address['billing_address'];

            $this->validateShippingTaxAddress($shippingAddress);

            $customer = $this->customerRepository->findByEmail($address['email']);

            if (!$customer) {
                $slug = Str::slug($address['email']);
                $data = [
                    'title' => $address['first_name'] . ' ' . $address['last_name'],
                    'first_name' => $address['first_name'],
                    'last_name' => $address['last_name'],
                    'email' => $address['email'],
                    'phone' => $address['phone'] ?? null,
                    'billing_address' => $address['billing_address'],
                    'shipping_address' => $shippingAddress,
                    'total_orders' => 0,
                    'total_spent' => 0,
                ];

                $entry = $this->customerRepository->make()
                    ->slug($slug);

                $entry->data($data)
                    ->published(true);

                if (!$entry->save()) {
                    throw new \Exception('Failed to create customer entry');
                }

                $customer = $this->customerRepository->find($entry->id());
            }

            if (!$customer) {
                throw new \Exception('Failed to load customer entry');
            }

            // Securely calculate and override shipping cost based on backend config/rates
            $shippingMethod = $checkoutData['shipping']['shipping_method'] ?? config('webbycommerce.shipping.default_method', 'standard');
            $resolvedShipping = $this->resolveShippingForCheckout($shippingMethod, $shippingAddress);
            if (! empty($resolvedShipping['unavailable'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'We cannot ship to this address.',
                ], 422);
            }
            $shippingAmount = round($resolvedShipping['shipping_cost'] ?? 0.0, 2);

            // Update session data with the verified shipping cost
            $checkoutData['shipping'] = $resolvedShipping;
            Session::put('checkout.shipping', $checkoutData['shipping']);
            $cartData = $this->cart->toArray();

            // Calculate tax using the current checkout address and verified shipping amount.
            $taxBreakdown = $this->cart->getTaxBreakdown();
            $lineItemsBreakdown = $taxBreakdown['line_items'] ?? [];

            $taxAmount = round($taxBreakdown['total'] ?? 0, 2);
            $total = $this->cart->chargeableTotal($subtotal, $shippingAmount, $taxAmount, $discountAmount);

            $stockResponse = $this->rejectIfStockUnavailable(true);
            if ($stockResponse) {
                return $stockResponse;
            }

            if (! $this->cart->reserveStock()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not enough stock to complete this order.',
                ], 422);
            }

            $this->stockHeld = true;

            $orderNumber = $this->orderRepository->generateOrderNumber();

            $billingAddress = $address['billing_address'];
            $billingName = trim(($billingAddress['first_name'] ?? $address['first_name']) . ' ' . ($billingAddress['last_name'] ?? $address['last_name']));
            $shippingName = trim(($shippingAddress['first_name'] ?? $address['first_name']) . ' ' . ($shippingAddress['last_name'] ?? $address['last_name']));
            $paymentMethod = $checkoutData['payment']['payment_method'] ?? 'unknown';
            $couponCode = $checkoutData['coupon']['code'] ?? null;

            $items = collect($cartData['items'])
                ->values()
                ->map(function (array $item, int $index) use ($lineItemsBreakdown) {
                    $itemTax = 0;
                    if (isset($lineItemsBreakdown[$index]) && $lineItemsBreakdown[$index]['product_id'] === ($item['product_id'] ?? null)) {
                        $itemTax = $lineItemsBreakdown[$index]['tax'] ?? 0;
                    }

                    return [
                        'id' => $item['key'] ?? md5(($item['product_id'] ?? '') . serialize($item['options'] ?? [])),
                        'product' => $item['product_id'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'sku' => $item['sku'] ?? null,
                        'variant' => $item['variant_name'] ?? ($item['options']['variant'] ?? null),
                        'quantity' => $item['quantity'] ?? 1,
                        'price' => round($item['price'] ?? 0, 2),
                        'total' => round($item['total'] ?? (($item['price'] ?? 0) * ($item['quantity'] ?? 1)), 2),
                        'shipping' => round($item['shipping_charge'] ?? 0, 2),
                        'tax' => round($itemTax, 2),
                        'metadata' => $item['options'] ?? [],
                    ];
                })
                ->all();

            $paymentResult = $this->resolveCheckoutPayment(
                $paymentMethod,
                $paymentData,
                $total,
                $orderNumber,
                $address['email']
            );

            if (! empty($paymentResult['requires_action'])) {
                $this->releaseHeldStock();

                return response()->json([
                    'success' => false,
                    'requires_action' => true,
                    'payment_intent' => $paymentResult['payment_intent'] ?? null,
                    'client_secret' => $paymentResult['client_secret'] ?? null,
                    'message' => $paymentResult['message'],
                ]);
            }

            if (! $paymentResult['ok']) {
                $this->releaseHeldStock();

                return response()->json([
                    'success' => false,
                    'message' => $paymentResult['message'],
                ], $paymentResult['http_status']);
            }

            $this->stockHeld = false;

            $isPaid = $paymentResult['is_paid'];
            $paymentStatus = $paymentResult['payment_status'];

            try {
                $orderEntry = $this->orderRepository->make();

                if (!$orderEntry) {
                    throw new \Exception('Failed to create order entry');
                }

                $orderEntry->slug(Str::slug($orderNumber))
                    ->data([
                        'title' => $orderNumber,
                        'order_number' => $orderNumber,
                        'reference' => $orderNumber,
                        'customer' => $customer->id(),
                        'customer_email' => $customer->email(),
                        'customer_name' => $customer->fullName(),
                        'coupon' => $couponCode,
                        'status' => 'pending',
                        'payment_status' => $paymentStatus,
                        'gateway' => $paymentMethod,
                        'shipping_method' => $checkoutData['shipping']['shipping_method'] ?? null,
                        'date' => now()->toDateString(),
                        'use_shipping_address_for_billing' => false,
                        'billing_name' => $billingName,
                        'billing_address' => $billingAddress['street'] ?? null,
                        'billing_address_line2' => $billingAddress['line2'] ?? null,
                        'billing_city' => $billingAddress['city'] ?? null,
                        'billing_postal_code' => $billingAddress['postal_code'] ?? null,
                        'billing_region' => $billingAddress['state'] ?? null,
                        'billing_country' => $billingAddress['country'] ?? null,
                        'shipping_name' => $shippingName,
                        'shipping_address' => $shippingAddress['street'] ?? null,
                        'shipping_address_line2' => $shippingAddress['line2'] ?? null,
                        'shipping_city' => $shippingAddress['city'] ?? null,
                        'shipping_postal_code' => $shippingAddress['postal_code'] ?? null,
                        'shipping_region' => $shippingAddress['state'] ?? null,
                        'shipping_country' => $shippingAddress['country'] ?? null,
                        'items' => $items,
                        'items_total' => $subtotal,
                        'coupon_total' => $discountAmount,
                        'shipping_total' => $shippingAmount,
                        'tax_total' => $taxAmount,
                        'grand_total' => $total,
                        'notes' => $checkoutData['payment']['notes'] ?? null,
                        'status_log' => 'Order placed ' . now()->toDateTimeString(),
                        'subtotal' => $subtotal,
                        'tax' => $taxAmount,
                        'shipping' => $shippingAmount,
                        'discount' => $discountAmount,
                        'total' => $total,
                        'billing_address_data' => $billingAddress,
                        'shipping_address_data' => $shippingAddress,
                        'payment_method' => $paymentMethod,
                        'is_paid' => $isPaid,
                        'payment_intent' => $paymentResult['payment_intent'] ?? null,
                        'charge_id' => $paymentResult['charge_id'] ?? null,
                    ])
                    ->published(true);

                if (!$orderEntry->save()) {
                    throw new \Exception('Failed to save order entry');
                }

                try {
                    \WebbyCrown\WebbyCommerceStatamic\Helpers\EmailHelper::sendOrderConfirmation($orderEntry->data()->all(), $customer->email());
                } catch (\Exception $mailEx) {
                    Log::error('Order confirmation email failed: ' . $mailEx->getMessage());
                }
            } catch (\Exception $e) {
                $this->refundUnrecordedCharge($paymentResult);
                $this->stockHeld = true;
                Log::error('Error saving order entry: ' . $e->getMessage(), [
                    'order_number' => $orderNumber,
                    'customer_id' => $customer->id(),
                    'charge_id' => $paymentResult['charge_id'] ?? null,
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            if (!empty($checkoutData['coupon']) && !empty($checkoutData['coupon']['code'])) {
                $coupon = $this->couponRepository->findByCode($checkoutData['coupon']['code']);
                if ($coupon) {
                    $data = $coupon->entry()->data()->all();
                    $newCount = $coupon->usedCount() + 1;
                    $data['redeemed_count'] = $newCount;
                    $data['used_count'] = $newCount;
                    $coupon->entry()->data($data)->save();
                }
            }

            $ordersForCustomer = $this->orderRepository->findByCustomer($customer->id()) ?: collect();
            $totalSpent = $ordersForCustomer->sum(fn($order) => $order->total());

            $customerData = $customer->entry()->data()->all();
            $customerData['total_orders'] = $ordersForCustomer->count();
            $customerData['total_spent'] = $totalSpent;
            $customerData['last_order_date'] = now()->toDateTimeString();

            $customer->entry()->data($customerData)->save();

            $this->cart->clear();
            Session::forget('checkout');

            return response()->json([
                'success' => true,
                'order' => [
                    'order_number' => $orderNumber,
                ],
                'redirect_url' => $this->resolvePaymentRedirectUrl($orderNumber, $paymentMethod),
                'callback_url' => $this->resolvePaymentCallbackUrl($orderNumber, $paymentMethod),
                'webhook_url' => $this->resolvePaymentWebhookUrl($paymentMethod),
                'gateway' => $paymentMethod,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed during checkout completion.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            $this->releaseHeldStock();
            Log::error('Checkout complete error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The checkout could not be completed. Please try again.',
            ], 500);
        }
    }

    /**
     * Synchronous single-POST checkout: validates everything, creates order, redirects.
     */
    public function processCheckout(Request $request)
    {
        try {
            $cartData = $this->cart->toArray();

            if ($cartData['count'] === 0) {
                return redirect()->back()->with('error', 'Your cart is empty.');
            }

            $shippingSameAsBilling = $request->boolean('shipping_same_as_billing', true);

            // Validate all inputs at once
            $rules = [
                'email' => 'required|email',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'billing_address.street' => 'nullable|string|max:255',
                'billing_address.city' => 'required|string|max:255',
                'billing_address.state' => 'required|string|max:255',
                'billing_address.country' => 'required|string|max:255',
                'billing_address.postal_code' => 'required|string|max:50',
                'shipping_same_as_billing' => 'nullable',
                'payment_method' => 'required|string',
                'coupon_code' => 'nullable|string',
                'notes' => 'nullable|string|max:2000',
                'shipping_method' => 'nullable|string',
            ];

            if (! $shippingSameAsBilling) {
                $rules['shipping_address.city'] = 'required|string|max:255';
                $rules['shipping_address.state'] = 'required|string|max:255';
                $rules['shipping_address.country'] = 'required|string|max:255';
                $rules['shipping_address.postal_code'] = 'required|string|max:50';
            }

            if (in_array($request->input('payment_method'), ['credit_card', 'stripe'])) {
                $rules['stripe_payment_method'] = 'nullable|string|max:255';
                $rules['payment_intent'] = 'nullable|string|max:255';
            } elseif ($request->input('payment_method') === 'bank_transfer') {
                $rules['bank_sender_name'] = 'required|string|max:255';
            }

            $validated = $request->validate($rules);

            if (in_array($validated['payment_method'] ?? '', ['credit_card', 'stripe'], true)
                && ($request->filled('card_number') || $request->filled('card_cvv'))) {
                return $this->backWithCheckoutInput()->with('error',
                    'Card details must not be sent to the server. Confirm the card with Stripe.js and send a PaymentMethod or PaymentIntent only.');
            }

            if (in_array($validated['payment_method'] ?? '', ['credit_card', 'stripe'], true)
                && empty($validated['stripe_payment_method'])
                && empty($validated['payment_intent'])) {
                return $this->backWithCheckoutInput()->with('error',
                    'A Stripe PaymentMethod or PaymentIntent is required. Card numbers must not be posted to the server.');
            }

            // Store address in session for tax engine
            $addressData = [
                'email' => $validated['email'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'billing_address' => $request->input('billing_address', []),
                'shipping_same_as_billing' => $shippingSameAsBilling,
                'shipping_address' => $request->input('shipping_address', []),
            ];
            Session::put('checkout.address', $addressData);

            // Resolve shipping address
            $shippingAddress = $shippingSameAsBilling
                ? $addressData['billing_address']
                : ($addressData['shipping_address'] ?: $addressData['billing_address']);

            // Resolve shipping cost
            $shippingMethod = $validated['shipping_method'] ?? config('webbycommerce.shipping.default_method', 'standard');
            $resolvedShipping = $this->resolveShippingForCheckout($shippingMethod, $shippingAddress);
            if (! empty($resolvedShipping['unavailable'])) {
                return $this->backWithCheckoutInput()->with('error', 'We cannot ship to this address.');
            }
            Session::put('checkout.shipping', $resolvedShipping);
            $shippingAmount = round($resolvedShipping['shipping_cost'] ?? 0.0, 2);
            $cartData = $this->cart->toArray();

            // Reset tax engine and calculate tax with new address
            $this->taxManager->resetEngine();
            $taxBreakdown = $this->cart->getTaxBreakdown();
            $lineItemsBreakdown = $taxBreakdown['line_items'] ?? [];
            $taxAmount = round($taxBreakdown['total'] ?? 0, 2);

            $subtotal = round($this->cart->subtotal(), 2);

            // Re-check any coupon against the cart as it is now. A discount stored
            // when the coupon was applied must not survive a smaller cart.
            $resolvedCoupon = $this->resolveAppliedCoupon($validated['coupon_code'] ?? null, $subtotal);
            if (! $resolvedCoupon['ok']) {
                return $this->backWithCheckoutInput()->with('error', $resolvedCoupon['message'] ?? 'Invalid coupon.');
            }

            $couponData = $resolvedCoupon['coupon'];
            $discountAmount = $resolvedCoupon['discount'];
            Session::put('checkout.coupon', $couponData);

            $total = $this->cart->chargeableTotal($subtotal, $shippingAmount, $taxAmount, $discountAmount);

            $stockResponse = $this->rejectIfStockUnavailable(false);
            if ($stockResponse) {
                return $stockResponse;
            }

            if (! $this->cart->reserveStock()) {
                return $this->backWithCheckoutInput()->with('error', 'Not enough stock to complete this order.');
            }

            $this->stockHeld = true;

            // Customer
            $customer = $this->customerRepository->findByEmail($addressData['email']);
            if (! $customer) {
                $slug = Str::slug($addressData['email']);
                $entry = $this->customerRepository->make()->slug($slug);
                $entry->data([
                    'title' => $addressData['first_name'] . ' ' . $addressData['last_name'],
                    'first_name' => $addressData['first_name'],
                    'last_name' => $addressData['last_name'],
                    'email' => $addressData['email'],
                    'phone' => $addressData['phone'],
                    'billing_address' => $addressData['billing_address'],
                    'shipping_address' => $shippingAddress,
                    'total_orders' => 0,
                    'total_spent' => 0,
                ])->published(true);

                if (! $entry->save()) {
                    throw new \Exception('Failed to create customer entry');
                }
                $customer = $this->customerRepository->find($entry->id());
            }

            if (! $customer) {
                throw new \Exception('Failed to load customer entry');
            }

            $orderNumber = $this->orderRepository->generateOrderNumber();
            $billingAddress = $addressData['billing_address'];
            $billingName = trim($addressData['first_name'] . ' ' . $addressData['last_name']);
            $shippingName = $billingName;
            $paymentMethod = $validated['payment_method'];
            $couponCode = $couponData['code'] ?? null;

            $items = collect($cartData['items'])
                ->values()
                ->map(function (array $item, int $index) use ($lineItemsBreakdown) {
                    $itemTax = 0;
                    if (isset($lineItemsBreakdown[$index]) && $lineItemsBreakdown[$index]['product_id'] === ($item['product_id'] ?? null)) {
                        $itemTax = $lineItemsBreakdown[$index]['tax'] ?? 0;
                    }

                    return [
                        'id' => $item['key'] ?? md5(($item['product_id'] ?? '') . serialize($item['options'] ?? [])),
                        'product' => $item['product_id'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'sku' => $item['sku'] ?? null,
                        'variant' => $item['variant_name'] ?? ($item['options']['variant'] ?? null),
                        'quantity' => $item['quantity'] ?? 1,
                        'price' => round($item['price'] ?? 0, 2),
                        'total' => round($item['total'] ?? (($item['price'] ?? 0) * ($item['quantity'] ?? 1)), 2),
                        'shipping' => round($item['shipping_charge'] ?? 0, 2),
                        'tax' => round($itemTax, 2),
                        'metadata' => $item['options'] ?? [],
                    ];
                })
                ->all();

            $paymentResult = $this->resolveCheckoutPayment(
                $paymentMethod,
                $validated,
                $total,
                $orderNumber,
                $addressData['email']
            );

            if (! empty($paymentResult['requires_action'])) {
                $this->releaseHeldStock();

                return $this->backWithCheckoutInput()
                    ->with('webbycommerce_requires_action', [
                        'payment_intent' => $paymentResult['payment_intent'] ?? null,
                        'client_secret' => $paymentResult['client_secret'] ?? null,
                    ])
                    ->with('error', $paymentResult['message'] ?? 'Additional authentication is required.');
            }

            if (! $paymentResult['ok']) {
                $this->releaseHeldStock();

                return $this->backWithCheckoutInput()->with('error', $paymentResult['message']);
            }

            $this->stockHeld = false;

            $isPaid = $paymentResult['is_paid'];
            $paymentStatus = $paymentResult['payment_status'];

            // Create order
            $orderEntry = $this->orderRepository->make();
            if (! $orderEntry) {
                throw new \Exception('Failed to create order entry');
            }

            $orderEntry->slug(Str::slug($orderNumber))
                ->data([
                    'title' => $orderNumber,
                    'order_number' => $orderNumber,
                    'reference' => $orderNumber,
                    'customer' => $customer->id(),
                    'customer_email' => $customer->email(),
                    'customer_name' => $customer->fullName(),
                    'coupon' => $couponCode,
                    'status' => 'pending',
                    'payment_status' => $paymentStatus,
                    'gateway' => $paymentMethod,
                    'shipping_method' => $resolvedShipping['shipping_method'] ?? null,
                    'date' => now()->toDateString(),
                    'use_shipping_address_for_billing' => false,
                    'billing_name' => $billingName,
                    'billing_address' => $billingAddress['street'] ?? null,
                    'billing_city' => $billingAddress['city'] ?? null,
                    'billing_postal_code' => $billingAddress['postal_code'] ?? null,
                    'billing_region' => $billingAddress['state'] ?? null,
                    'billing_country' => $billingAddress['country'] ?? null,
                    'shipping_name' => $shippingName,
                    'shipping_address' => $shippingAddress['street'] ?? null,
                    'shipping_city' => $shippingAddress['city'] ?? null,
                    'shipping_postal_code' => $shippingAddress['postal_code'] ?? null,
                    'shipping_region' => $shippingAddress['state'] ?? null,
                    'shipping_country' => $shippingAddress['country'] ?? null,
                    'items' => $items,
                    'items_total' => $subtotal,
                    'coupon_total' => $discountAmount,
                    'shipping_total' => $shippingAmount,
                    'tax_total' => $taxAmount,
                    'grand_total' => $total,
                    'notes' => $validated['notes'] ?? null,
                    'status_log' => 'Order placed ' . now()->toDateTimeString(),
                    'subtotal' => $subtotal,
                    'tax' => $taxAmount,
                    'shipping' => $shippingAmount,
                    'discount' => $discountAmount,
                    'total' => $total,
                    'billing_address_data' => $billingAddress,
                    'shipping_address_data' => $shippingAddress,
                    'payment_method' => $paymentMethod,
                    'is_paid' => $isPaid,
                    'payment_intent' => $paymentResult['payment_intent'] ?? null,
                    'charge_id' => $paymentResult['charge_id'] ?? null,
                ])
                ->published(true);

            try {
                if (! $orderEntry->save()) {
                    throw new \Exception('Failed to save order entry');
                }
            } catch (\Exception $e) {
                $this->refundUnrecordedCharge($paymentResult);
                $this->stockHeld = true;
                throw $e;
            }

            // Send confirmation email
            try {
                \WebbyCrown\WebbyCommerceStatamic\Helpers\EmailHelper::sendOrderConfirmation($orderEntry->data()->all(), $customer->email());
            } catch (\Exception $mailEx) {
                Log::error('Order confirmation email failed: ' . $mailEx->getMessage());
            }

            // Update coupon usage
            if (! empty($couponData['code'])) {
                $coupon = $this->couponRepository->findByCode($couponData['code']);
                if ($coupon) {
                    $cData = $coupon->entry()->data()->all();
                    $newCount = $coupon->usedCount() + 1;
                    $cData['redeemed_count'] = $newCount;
                    $cData['used_count'] = $newCount;
                    $coupon->entry()->data($cData)->save();
                }
            }

            // Update customer stats
            $ordersForCustomer = $this->orderRepository->findByCustomer($customer->id()) ?: collect();
            $totalSpent = $ordersForCustomer->sum(fn($order) => $order->total());
            $customerData = $customer->entry()->data()->all();
            $customerData['total_orders'] = $ordersForCustomer->count();
            $customerData['total_spent'] = $totalSpent;
            $customerData['last_order_date'] = now()->toDateTimeString();
            $customer->entry()->data($customerData)->save();

            // Clear cart and checkout session
            $this->cart->clear();
            Session::forget('checkout');

            return redirect($this->resolvePaymentRedirectUrl($orderNumber, $paymentMethod))
                ->with('success', 'Order placed successfully! Order number: ' . $orderNumber);

        } catch (ValidationException $e) {
            return $this->backWithCheckoutInput()->withErrors($e->errors())->with('error', 'Please correct the errors below.');
        } catch (\Exception $e) {
            $this->releaseHeldStock();
            Log::error('Checkout process error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->backWithCheckoutInput()->with('error', 'The checkout could not be completed. Please try again.');
        }
    }

    /**
     * Synchronous coupon apply: validates coupon and stores in session.
     */
    public function applyCouponSync(Request $request)
    {
        $validated = $request->validate([
            'coupon_code' => 'required|string',
        ]);

        $subtotal = $this->cart->subtotal();
        $result = $this->couponRepository->validateCoupon($validated['coupon_code'], $subtotal);

        if (! $result['valid']) {
            Session::forget('checkout.coupon');
            return $this->backWithCheckoutInput()->with('error', $result['message'] ?? 'Invalid coupon code.');
        }

        Session::put('checkout.coupon', [
            'code' => $result['coupon']->code(),
            'discount' => $result['discount'],
        ]);

        return $this->backWithCheckoutInput()->with('success', 'Coupon applied! Discount: $' . number_format($result['discount'], 2));
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $result = $this->couponRepository->validateCoupon($validated['code'], $validated['subtotal']);

        return response()->json($result);
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $subtotal = $this->cart->subtotal();
        $result = $this->couponRepository->validateCoupon($validated['code'], $subtotal);

        if (! $result['valid']) {
            Session::forget('checkout.coupon');
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Invalid coupon code.',
            ], 400);
        }

        Session::put('checkout.coupon', [
            'code' => $result['coupon']->code(),
            'discount' => $result['discount'],
        ]);

        $summary = $this->getOrderSummary();

        return response()->json([
            'success' => true,
            'message' => 'Coupon applied successfully.',
            'discount' => round($summary['discount'], 2),
            'total' => round($summary['total'], 2),
            'subtotal' => round($summary['subtotal'], 2),
            'tax' => round($summary['tax'], 2),
            'shipping_cost' => round($summary['shipping'], 2),
            'tax_rate' => $summary['tax_rate'],
            'tax_breakdown' => $summary['tax_breakdown'],
            'shipping_breakdown' => $summary['shipping_breakdown'],
            'shipping' => Session::get('checkout.shipping', []),
        ]);
    }

    public function getShippingMethods(Request $request): JsonResponse
    {
        $cartTotal = (float) $request->get('cart_total', 0);

        $address = $this->normalizeShippingAddress((array) $request->get('address', []));
        if ($this->shippingManager->allRates()->isNotEmpty() && $this->isCompleteShippingAddress($address)) {
            return response()->json($this->shippingManager->getAvailableMethods($address, $cartTotal));
        }

        $configuredMethods = config('webbycommerce.shipping.methods', []);
        $methods = [];

        foreach ($configuredMethods as $id => $details) {
            $methods[] = [
                'id' => $id,
                'name' => $details['name'] ?? ucfirst($id),
                'description' => $details['description'] ?? '',
                'cost' => $this->calculateShippingCostForMethod($id, $cartTotal),
            ];
        }

        return response()->json($methods);
    }

    protected function calculateShippingCostForMethod(string $methodId, float $cartTotal): ?float
    {
        $methods = config('webbycommerce.shipping.methods', []);

        if (!isset($methods[$methodId])) {
            return null;
        }

        $method = $methods[$methodId];
        $cost = (float) ($method['cost'] ?? 0.0);
        $threshold = $method['free_threshold'] ?? null;

        if ($threshold !== null && $cartTotal >= (float) $threshold) {
            return 0.0;
        }

        return $cost;
    }

    /**
     * Use the coupon sent with this request, or the one already stored in the session.
     * The discount is checked against the current subtotal and cannot exceed it.
     *
     * @return array{ok: bool, message: ?string, coupon: ?array, discount: float}
     */
    protected function resolveAppliedCoupon(?string $requestCode, float $subtotal): array
    {
        $couponCode = $requestCode;
        if ($couponCode === null || $couponCode === '') {
            $stored = Session::get('checkout.coupon');
            $couponCode = is_array($stored) ? ($stored['code'] ?? null) : null;
        }

        $subtotal = round(max($subtotal, 0), 2);

        if ($couponCode === null || $couponCode === '') {
            return ['ok' => true, 'message' => null, 'coupon' => null, 'discount' => 0.0];
        }

        $result = $this->couponRepository->validateCoupon($couponCode, $subtotal);

        if (! $result['valid']) {
            Session::forget('checkout.coupon');

            return [
                'ok' => false,
                'message' => $result['message'] ?? 'Invalid coupon.',
                'coupon' => null,
                'discount' => 0.0,
            ];
        }

        $discount = min(round((float) $result['discount'], 2), $subtotal);

        return [
            'ok' => true,
            'message' => null,
            'coupon' => [
                'code' => $result['coupon']->code(),
                'discount' => $discount,
            ],
            'discount' => $discount,
        ];
    }

    protected function resolveShippingForCheckout(?string $preferredMethod = null, ?array $address = null): array
    {

        $subtotal = $this->cart->subtotal();
        $address = $this->normalizeShippingAddress($address ?? $this->getSelectedShippingAddressFromSession());
        $locationSignature = $this->shippingLocationSignature($address);
        $previousShipping = Session::get('checkout.shipping', []);
        $previousSignature = $previousShipping['location_signature'] ?? null;
        $locationChanged = $previousSignature !== null && $previousSignature !== $locationSignature;

        if ($this->shippingManager->allRates()->isNotEmpty() && $this->isCompleteShippingAddress($address)) {
            $methods = $this->shippingManager->getAvailableMethods($address, $subtotal);
            $selected = $this->selectShippingMethod($methods, $preferredMethod, $previousShipping['shipping_method'] ?? null);

            if ($selected) {
                return [
                    'shipping_method' => $selected['id'],
                    'shipping_method_name' => $selected['name'],
                    'shipping_cost' => (float) $selected['cost'],
                    'shipping_methods' => $methods,
                    'location_signature' => $locationSignature,
                    'location_changed' => $locationChanged,
                ];
            }

            // Locations are configured and none match this address.
            // A missing rule is not free shipping.
            return [
                'unavailable' => true,
                'shipping_method' => null,
                'shipping_method_name' => null,
                'shipping_cost' => 0.0,
                'shipping_methods' => [],
                'location_signature' => $locationSignature,
                'location_changed' => $locationChanged,
                'free_shipping_applied' => false,
                'shipping_discount' => 0.0,
            ];
        }

        $methodId = $preferredMethod && array_key_exists($preferredMethod, config('webbycommerce.shipping.methods', []))
            ? $preferredMethod
            : $this->determineShippingMethod($subtotal);
        $methodConfig = (array) config("webbycommerce.shipping.methods.{$methodId}", []);

        return [
            'shipping_method' => $methodId,
            'shipping_method_name' => $methodConfig['name'] ?? ucfirst($methodId),
            'shipping_cost' => $this->calculateShippingCostForMethod($methodId, $subtotal) ?? 0.0,
            'shipping_methods' => null,
            'location_signature' => $locationSignature,
            'location_changed' => $locationChanged,
        ];
    }

    protected function selectShippingMethod(array $methods, ?string $preferredMethod, ?string $previousMethod): ?array
    {
        foreach ($methods as $method) {
            if (! empty($method['free_shipping_applied'])) {
                return $method;
            }
        }

        foreach ([$preferredMethod, $previousMethod] as $methodId) {
            if (! $methodId) {
                continue;
            }

            foreach ($methods as $method) {
                $methodSlug = (string) ($method['slug'] ?? '');
                $requestedSlug = Str::slug($methodId);

                if (
                    ($method['id'] ?? null) === $methodId
                    || $methodSlug === $methodId
                    || $methodSlug === $requestedSlug
                    || Str::startsWith($methodSlug, $requestedSlug . '-')
                    || Str::slug((string) ($method['name'] ?? '')) === $requestedSlug
                ) {
                    return $method;
                }
            }
        }

        foreach ($methods as $method) {
            if (! empty($method['is_default'])) {
                return $method;
            }
        }

        return $methods[0] ?? null;
    }

    protected function getSelectedShippingAddressFromSession(): array
    {
        $address = Session::get('checkout.address', []);
        $shippingAddress = ($address['shipping_same_as_billing'] ?? false)
            ? ($address['billing_address'] ?? [])
            : ($address['shipping_address'] ?? $address['billing_address'] ?? []);

        return (array) $shippingAddress;
    }

    protected function normalizeShippingAddress(array $address): array
    {
        $country = trim((string) ($address['country'] ?? ''));
        $state = trim((string) ($address['state'] ?? $address['region'] ?? ''));

        if ($country !== '' && $state !== '') {
            $state = $this->shippingManager->normalizeStateForAddress($state, $country);
        }

        return [
            'country' => $country,
            'state' => $state,
            'city' => trim((string) ($address['city'] ?? '')),
            'postal_code' => trim((string) ($address['postal_code'] ?? $address['zip_code'] ?? '')),
        ];
    }

    protected function shippingLocationSignature(array $address): string
    {

        $address = $this->normalizeShippingAddress($address);

        return implode('|', array_map(fn ($value) => strtoupper($value), [
            $address['country'],
            $address['state'],
            $address['city'],
            $address['postal_code'],
        ]));
    }

    protected function isCompleteShippingAddress(array $address): bool
    {
        $address = $this->normalizeShippingAddress($address);

        foreach (['city', 'state', 'country', 'postal_code'] as $field) {
            if ($address[$field] === '') {
                return false;
            }
        }

        return true;
    }

    protected function determineShippingMethod(float $cartTotal): string
    {
        if ($cartTotal >= 1500) {
            return 'overnight';
        }

        if ($cartTotal >= 500) {
            return 'express';
        }

        return config('webbycommerce.shipping.default_method', 'standard');
    }

    protected function getOrderSummary(): array
    {
        $checkoutData = Session::get('checkout');

        $subtotal = $this->cart->subtotal();
        $shippingAmount = $checkoutData['shipping']['shipping_cost'] ?? 0;
        $discountAmount = $checkoutData['coupon']['discount'] ?? 0;

        // Tax is calculated via the tax engine (StandardTaxEngine uses checkout.address).
        $taxBreakdown = $this->cart->getTaxBreakdown();
        $taxAmount = $taxBreakdown['total'] ?? 0;
        $taxRate = $this->cart->taxRate();

        $total = $this->cart->chargeableTotal($subtotal, $shippingAmount, $taxAmount, $discountAmount);

        return [
            'subtotal' => $subtotal,
            'shipping' => $shippingAmount,
            'shipping_breakdown' => $this->cart->shippingBreakdown(),
            'discount' => $discountAmount,
            'tax' => $taxAmount,
            'tax_rate' => $taxRate,
            'tax_breakdown' => $taxBreakdown,
            'total' => $total,
        ];
    }

    public function paymentWebhook(Request $request): JsonResponse
    {
        $gateway = new StripeGateway;
        $verified = $gateway->verifiedEvent($request->getContent(), $request->header('Stripe-Signature'));

        if (! $verified['ok']) {
            return response()->json([
                'success' => false,
                'message' => $verified['message'],
            ], $verified['http_status'] ?? 400);
        }

        $event = $verified['event'];
        $type = (string) ($event['type'] ?? '');
        $intent = $event['data']['object'] ?? null;

        if (! is_array($intent)) {
            return response()->json([
                'success' => true,
                'message' => 'Webhook received',
            ]);
        }

        if ($type === 'payment_intent.succeeded') {
            return $this->markOrderPaidFromWebhook($intent);
        }

        if ($type === 'payment_intent.payment_failed') {
            return $this->markOrderFailedFromWebhook($intent);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received',
        ]);
    }

    public function paymentCallback(Request $request)
    {
        $gateway = $request->input('gateway', config('webbycommerce.payment.default_gateway'));
        $orderNumber = $request->input('order') ?? $request->query('order');

        if ($request->isMethod('get') && $orderNumber) {
            return redirect($this->resolvePaymentRedirectUrl($orderNumber, $gateway));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment callback received',
            'gateway' => $gateway,
            'order_number' => $orderNumber,
        ]);
    }

    public function paymentRedirect(Request $request)
    {
        $orderNumber = $request->input('order') ?? $request->query('order');

        if ($orderNumber) {
            return redirect($this->resolvePaymentRedirectUrl($orderNumber));
        }

        return response()->json([
            'success' => false,
            'message' => 'Missing order number for payment redirect.',
        ], 400);
    }


    /**
     * Resolve payment for an order. Never marks an order paid without a verified capture.
     *
     * @return array{ok:bool,is_paid:bool,payment_status:string,message:?string,http_status:int}
     */
    protected function resolveCheckoutPayment(string $paymentMethod, array $paymentData, float $total, string $orderNumber, string $customerEmail): array
    {
        $pending = [
            'ok' => true,
            'is_paid' => false,
            'payment_status' => 'pending',
            'message' => null,
            'http_status' => 200,
        ];

        if ($paymentMethod === 'bank_transfer' || $paymentMethod === 'cod') {
            return $pending;
        }

        if (! in_array($paymentMethod, ['credit_card', 'stripe'], true)) {
            return [
                'ok' => false,
                'is_paid' => false,
                'payment_status' => 'pending',
                'message' => 'Unsupported payment method.',
                'http_status' => 422,
            ];
        }

        return (new StripeGateway)->resolveCard($paymentData, $total, $orderNumber, $customerEmail);
    }

    /**
     * A succeeded payment with no saved order is refunded.
     *
     * @param  array<string, mixed>  $paymentResult
     */
    protected function refundUnrecordedCharge(array $paymentResult): void
    {
        (new StripeGateway)->refund($paymentResult);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    protected function markOrderPaidFromWebhook(array $intent): JsonResponse
    {
        $order = $this->orderForIntent($intent);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'No matching order.',
            ], 404);
        }

        if ($order->isPaid() && $order->paymentStatus() === 'paid') {
            return response()->json([
                'success' => true,
                'message' => 'Order already paid.',
            ]);
        }

        $check = $this->intentMatchesOrder($order, $intent);

        if (! $check['ok']) {
            return response()->json([
                'success' => false,
                'message' => $check['message'],
            ], 422);
        }

        $this->writeOrderPayment($order, $intent, true);

        return response()->json([
            'success' => true,
            'message' => 'Order marked paid.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    protected function markOrderFailedFromWebhook(array $intent): JsonResponse
    {
        $order = $this->orderForIntent($intent);

        if (! $order || ($order->isPaid() && $order->paymentStatus() === 'paid')) {
            return response()->json([
                'success' => true,
                'message' => 'Webhook received',
            ]);
        }

        $this->writeOrderPayment($order, $intent, false);

        return response()->json([
            'success' => true,
            'message' => 'Order payment marked failed.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    protected function orderForIntent(array $intent): ?Order
    {
        $id = isset($intent['id']) ? (string) $intent['id'] : '';
        $order = $id !== '' ? $this->orderRepository->findByPaymentIntent($id) : null;

        $orderNumber = $intent['metadata']['order_number'] ?? null;

        if (! $order && is_string($orderNumber) && $orderNumber !== '') {
            $order = $this->orderRepository->findByOrderNumber($orderNumber);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{ok:bool,message:string}
     */
    protected function intentMatchesOrder(Order $order, array $intent): array
    {
        $expected = CurrencyHelper::stripeAmount((float) ($order->entry()->value('grand_total') ?? $order->total()));
        $amount = (int) ($intent['amount'] ?? -1);
        $currency = strtolower((string) ($intent['currency'] ?? ''));
        $status = (string) ($intent['status'] ?? '');

        if ($status !== 'succeeded' || $amount !== $expected || $currency !== CurrencyHelper::stripeCurrency()) {
            Log::error('Stripe webhook payment did not match the order.', [
                'order_number' => $order->orderNumber(),
                'payment_intent' => $intent['id'] ?? null,
                'status' => $status,
                'amount' => $amount,
                'expected' => $expected,
            ]);

            return [
                'ok' => false,
                'message' => 'The payment does not match this order.',
            ];
        }

        return [
            'ok' => true,
            'message' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    protected function writeOrderPayment(Order $order, array $intent, bool $paid): void
    {
        $entry = $order->entry();
        $data = $entry->data()->all();
        $data['is_paid'] = $paid;
        $data['payment_status'] = $paid ? 'paid' : 'failed';
        $data['payment_intent'] = $intent['id'] ?? ($data['payment_intent'] ?? null);

        if (! empty($intent['latest_charge']) && is_string($intent['latest_charge'])) {
            $data['charge_id'] = $intent['latest_charge'];
        }

        $note = $paid ? 'Payment confirmed ' : 'Payment failed ';
        $data['status_log'] = trim(($data['status_log'] ?? '')."\n".$note.now()->toDateTimeString());
        $entry->data($data)->save();
    }


    protected function resolvePaymentRedirectUrl(string $orderNumber, ?string $gateway = null): string
    {
        $gateway = $gateway ?: config('webbycommerce.payment.default_gateway');
        $redirectUrl = config("webbycommerce.payment.gateways.{$gateway}.redirect_url") ?? config('webbycommerce.payment.redirect_url');

        if ($redirectUrl) {
            return str_replace('{orderNumber}', urlencode($orderNumber), $redirectUrl);
        }

        return route('shop.checkout.success', ['orderNumber' => $orderNumber]);
    }

    protected function resolvePaymentCallbackUrl(?string $orderNumber = null, ?string $gateway = null): string
    {
        $gateway = $gateway ?: config('webbycommerce.payment.default_gateway');
        $callbackUrl = config("webbycommerce.payment.gateways.{$gateway}.callback_url") ?? config('webbycommerce.payment.callback_url');

        if ($callbackUrl) {
            return $orderNumber ? str_replace('{orderNumber}', urlencode($orderNumber), $callbackUrl) : $callbackUrl;
        }

        return route('shop.checkout.payment.callback', ['order' => $orderNumber]);
    }

    protected function resolvePaymentWebhookUrl(?string $gateway = null): string
    {
        $gateway = $gateway ?: config('webbycommerce.payment.default_gateway');
        $webhookUrl = config("webbycommerce.payment.gateways.{$gateway}.webhook_url") ?? config('webbycommerce.payment.webhook_url');

        if ($webhookUrl) {
            return $webhookUrl;
        }

        return route('shop.checkout.payment.webhook');
    }

    protected function validateShippingTaxAddress(array $shippingAddress): void
    {
        validator($shippingAddress, [
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'postal_code' => 'required|string|max:50',
        ])->validate();
    }

    /**
     * Returns true when there is enough location information to attempt
     * server-side tax zone matching. We consider country + state sufficient
     * for tax zone matching on-the-fly in the checkout UI.
     */
    protected function hasSufficientShippingTaxAddress(array $address): bool
    {
        $shippingAddress = ($address['shipping_same_as_billing'] ?? false)
            ? ($address['billing_address'] ?? [])
            : ($address['shipping_address'] ?? []);

        foreach (['country', 'state'] as $field) {
            if (! isset($shippingAddress[$field]) || trim((string) $shippingAddress[$field]) === '') {
                return false;
            }
        }

        return true;
    }

    protected function hasCompleteShippingTaxAddress(array $address): bool
    {
        $shippingAddress = ($address['shipping_same_as_billing'] ?? false)
            ? ($address['billing_address'] ?? [])
            : ($address['shipping_address'] ?? []);

        foreach (['city', 'state', 'country', 'postal_code'] as $field) {
            if (! isset($shippingAddress[$field]) || trim((string) $shippingAddress[$field]) === '') {
                return false;
            }
        }

        return true;
    }
}
