<?php

namespace WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop;

use WebbyCrown\WebbyCommerceStatamic\Cart\Cart;
use WebbyCrown\WebbyCommerceStatamic\Coupons\EntryCouponRepository;
use WebbyCrown\WebbyCommerceStatamic\Customers\EntryCustomerRepository;
use WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository;
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
        return response()->json([
            'success' => true,
            'order_number' => $orderNumber,
            'status' => 'completed',
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
                $rules['card_name'] = 'required|string|max:255';
                $rules['card_number'] = 'required|string|min:15|max:19';
                $rules['card_expiry'] = ['required', 'string', 'regex:/^\d{2}\/(\d{2}|\d{4})$/'];
                $rules['card_cvv'] = 'required|string|min:3|max:4';
            } elseif ($request->input('payment_method') === 'paypal') {
                $rules['paypal_email'] = 'required|email|max:255';
            } elseif ($request->input('payment_method') === 'bank_transfer') {
                $rules['bank_sender_name'] = 'required|string|max:255';
                $rules['bank_transaction_ref'] = 'nullable|string|max:255';
            }

            $paymentData = $request->validate($rules);

            $couponData = null;

            if (!empty($paymentData['coupon_code'])) {
                $result = $this->couponRepository->validateCoupon($paymentData['coupon_code'], $this->cart->subtotal());

                if (!$result['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $result['message'],
                    ], 400);
                }

                $couponData = [
                    'code' => $result['coupon']->code(),
                    'discount' => $result['discount'],
                ];
            }

            $checkoutData['payment'] = $paymentData;
            $checkoutData['coupon'] = $couponData;
            Session::put('checkout.payment', $paymentData);
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

            $subtotal = round($this->cart->subtotal(), 2);

            // Securely calculate and override shipping cost based on backend config/rates
            $shippingMethod = $checkoutData['shipping']['shipping_method'] ?? config('webbycommerce.shipping.default_method', 'standard');
            $resolvedShipping = $this->resolveShippingForCheckout($shippingMethod, $shippingAddress);
            $shippingAmount = round($resolvedShipping['shipping_cost'] ?? 0.0, 2);

            // Update session data with the verified shipping cost
            $checkoutData['shipping'] = $resolvedShipping;
            Session::put('checkout.shipping', $checkoutData['shipping']);
            $cartData = $this->cart->toArray();

            $discountAmount = round($checkoutData['coupon']['discount'] ?? 0, 2);

            // Calculate tax using the current checkout address and verified shipping amount.
            $taxBreakdown = $this->cart->getTaxBreakdown();
            $lineItemsBreakdown = $taxBreakdown['line_items'] ?? [];

            $taxAmount = round($taxBreakdown['total'] ?? 0, 2);
            $total = round($subtotal + $shippingAmount + $taxAmount - $discountAmount, 2);

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

            // Process payment via Stripe API if configured and selected
            $isPaid = in_array($paymentMethod, ['credit_card', 'paypal', 'stripe']);
            $paymentStatus = $isPaid ? 'paid' : 'pending';
            $stripeSecret = config('webbycommerce.payment.gateways.stripe.secret_key');

            if (in_array($paymentMethod, ['credit_card', 'stripe']) && $stripeSecret) {
                try {
                    // 1. Create a card token using Stripe API
                    $tokenResponse = \Illuminate\Support\Facades\Http::asForm()
                        ->withBasicAuth($stripeSecret, '')
                        ->post('https://api.stripe.com/v1/tokens', [
                            'card' => [
                                'number' => str_replace(' ', '', $paymentData['card_number'] ?? ''),
                                'exp_month' => explode('/', $paymentData['card_expiry'] ?? '')[0] ?? '',
                                'exp_year' => (function($expiry) {
                                    $year = explode('/', $expiry ?? '')[1] ?? '';
                                    return strlen($year) === 2 ? '20' . $year : $year;
                                })($paymentData['card_expiry'] ?? ''),
                                'cvc' => $paymentData['card_cvv'] ?? '',
                                'name' => $paymentData['card_name'] ?? '',
                            ]
                        ]);

                    if ($tokenResponse->failed()) {
                        $errorMsg = $tokenResponse->json()['error']['message'] ?? 'Stripe card tokenization failed.';
                        return response()->json([
                            'success' => false,
                            'message' => $errorMsg,
                        ], 400);
                    }

                    $tokenId = $tokenResponse->json()['id'];

                    // 2. Create Charge
                    $chargeResponse = \Illuminate\Support\Facades\Http::asForm()
                        ->withBasicAuth($stripeSecret, '')
                        ->post('https://api.stripe.com/v1/charges', [
                            'amount' => round($total * 100),
                            'currency' => strtolower(config('webbycommerce.currency', 'usd')),
                            'source' => $tokenId,
                            'description' => 'Order ' . $orderNumber,
                            'receipt_email' => $address['email'],
                        ]);

                    if ($chargeResponse->failed()) {
                        $errorMsg = $chargeResponse->json()['error']['message'] ?? 'Stripe charge failed.';
                        return response()->json([
                            'success' => false,
                            'message' => $errorMsg,
                        ], 400);
                    }

                    $isPaid = true;
                    $paymentStatus = 'paid';
                } catch (\Exception $stripeEx) {
                    \Illuminate\Support\Facades\Log::error('Stripe API payment failed: ' . $stripeEx->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment processor error: ' . $stripeEx->getMessage(),
                    ], 500);
                }
            } else {
                if (in_array($paymentMethod, ['credit_card', 'stripe'])) {
                    \Illuminate\Support\Facades\Log::warning('Stripe payment key not configured. Simulated success.');
                }
            }

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
                Log::error('Error saving order entry: ' . $e->getMessage(), [
                    'order_number' => $orderNumber,
                    'customer_id' => $customer->id(),
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
            Log::error('Checkout complete error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error completing checkout: ' . $e->getMessage(),
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
                $rules['card_name'] = 'required|string|max:255';
                $rules['card_number'] = 'required|string|min:15|max:19';
                $rules['card_expiry'] = ['required', 'string', 'regex:/^\d{2}\/(\d{2}|\d{4})$/'];
                $rules['card_cvv'] = 'required|string|min:3|max:4';
            } elseif ($request->input('payment_method') === 'bank_transfer') {
                $rules['bank_sender_name'] = 'required|string|max:255';
            }

            $validated = $request->validate($rules);

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
            Session::put('checkout.shipping', $resolvedShipping);
            $shippingAmount = round($resolvedShipping['shipping_cost'] ?? 0.0, 2);
            $cartData = $this->cart->toArray();

            // Reset tax engine and calculate tax with new address
            $this->taxManager->resetEngine();
            $taxBreakdown = $this->cart->getTaxBreakdown();
            $lineItemsBreakdown = $taxBreakdown['line_items'] ?? [];
            $taxAmount = round($taxBreakdown['total'] ?? 0, 2);

            $subtotal = round($this->cart->subtotal(), 2);

            // Coupon
            $couponData = null;
            $discountAmount = 0;
            if (! empty($validated['coupon_code'])) {
                $result = $this->couponRepository->validateCoupon($validated['coupon_code'], $subtotal);
                if (! $result['valid']) {
                    return redirect()->back()->withInput()->with('error', $result['message'] ?? 'Invalid coupon.');
                }
                $couponData = [
                    'code' => $result['coupon']->code(),
                    'discount' => $result['discount'],
                ];
                $discountAmount = round($result['discount'], 2);
            } elseif ($couponSession = Session::get('checkout.coupon')) {
                $couponData = $couponSession;
                $discountAmount = round($couponSession['discount'] ?? 0, 2);
            }

            Session::put('checkout.coupon', $couponData);

            $total = round($subtotal + $shippingAmount + $taxAmount - $discountAmount, 2);

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

            // Process payment
            $isPaid = in_array($paymentMethod, ['credit_card', 'paypal', 'stripe']);
            $paymentStatus = $isPaid ? 'paid' : 'pending';
            $stripeSecret = config('webbycommerce.payment.gateways.stripe.secret_key');

            if (in_array($paymentMethod, ['credit_card', 'stripe']) && $stripeSecret) {
                try {
                    $tokenResponse = \Illuminate\Support\Facades\Http::asForm()
                        ->withBasicAuth($stripeSecret, '')
                        ->post('https://api.stripe.com/v1/tokens', [
                            'card' => [
                                'number' => str_replace(' ', '', $validated['card_number'] ?? ''),
                                'exp_month' => explode('/', $validated['card_expiry'] ?? '')[0] ?? '',
                                'exp_year' => (function($expiry) {
                                    $year = explode('/', $expiry ?? '')[1] ?? '';
                                    return strlen($year) === 2 ? '20' . $year : $year;
                                })($validated['card_expiry'] ?? ''),
                                'cvc' => $validated['card_cvv'] ?? '',
                                'name' => $validated['card_name'] ?? '',
                            ]
                        ]);

                    if ($tokenResponse->failed()) {
                        $errorMsg = $tokenResponse->json()['error']['message'] ?? 'Card tokenization failed.';
                        return redirect()->back()->withInput()->with('error', $errorMsg);
                    }

                    $chargeResponse = \Illuminate\Support\Facades\Http::asForm()
                        ->withBasicAuth($stripeSecret, '')
                        ->post('https://api.stripe.com/v1/charges', [
                            'amount' => round($total * 100),
                            'currency' => strtolower(config('webbycommerce.currency', 'usd')),
                            'source' => $tokenResponse->json()['id'],
                            'description' => 'Order ' . $orderNumber,
                            'receipt_email' => $addressData['email'],
                        ]);

                    if ($chargeResponse->failed()) {
                        $errorMsg = $chargeResponse->json()['error']['message'] ?? 'Payment failed.';
                        return redirect()->back()->withInput()->with('error', $errorMsg);
                    }

                    $isPaid = true;
                    $paymentStatus = 'paid';
                } catch (\Exception $stripeEx) {
                    Log::error('Stripe payment failed: ' . $stripeEx->getMessage());
                    return redirect()->back()->withInput()->with('error', 'Payment processing error. Please try again.');
                }
            }

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
                ])
                ->published(true);

            if (! $orderEntry->save()) {
                throw new \Exception('Failed to save order entry');
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
            return redirect()->back()->withInput()->withErrors($e->errors())->with('error', 'Please correct the errors below.');
        } catch (\Exception $e) {
            Log::error('Checkout process error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->back()->withInput()->with('error', 'Error completing checkout: ' . $e->getMessage());
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
            return redirect()->back()->withInput()->with('error', $result['message'] ?? 'Invalid coupon code.');
        }

        Session::put('checkout.coupon', [
            'code' => $result['coupon']->code(),
            'discount' => $result['discount'],
        ]);

        return redirect()->back()->withInput()->with('success', 'Coupon applied! Discount: $' . number_format($result['discount'], 2));
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

            // If shipping location rules are configured and no location matched,
            // do not apply a shipping charge. Preserve checkout flow with a zero-cost response.
            $defaultEntry = $this->shippingManager->allRates()->first();
            $methodName = $defaultEntry?->value('name') ?? ucfirst($preferredMethod ?? 'shipping');
            $methodId = $preferredMethod ?: ($previousShipping['shipping_method'] ?? 'shipping_locations');

            return [
                'shipping_method' => $methodId,
                'shipping_method_name' => $methodName,
                'shipping_cost' => 0.0,
                'shipping_methods' => [],
                'location_signature' => $locationSignature,
                'location_changed' => $locationChanged,
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

        $total = $subtotal + $shippingAmount + $taxAmount - $discountAmount;

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
        $gateway = $request->input('gateway', config('webbycommerce.payment.default_gateway'));
        $payload = $request->all();

        Log::info('Payment webhook received', [
            'gateway' => $gateway,
            'payload' => $payload,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received for ' . $gateway,
            'gateway' => $gateway,
            'payload' => $payload,
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
            'payload' => $request->all(),
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
