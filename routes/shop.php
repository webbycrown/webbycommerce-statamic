<?php

use WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop\CartController;
use WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop\CheckoutController;
use WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop\ProductController as ShopProductController;
use WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('products')->group(function () {
    Route::get('/', [ShopProductController::class, 'index'])->name('products.index');
    Route::get('/{slug}', [ShopProductController::class, 'show'])->name('products.show');
});

Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('cart.index');
    Route::post('/add', [CartController::class, 'add'])->name('cart.add');
    Route::post('/update', [CartController::class, 'update'])->name('cart.update');
    Route::post('/remove/{key}', [CartController::class, 'remove'])->name('cart.remove');
});

Route::prefix('checkout')->group(function () {
    // JSON endpoints only (no Blade rendering)
    Route::get('/', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::get('/success/{orderNumber}', [CheckoutController::class, 'success'])->name('checkout.success');
    Route::post('/address', [CheckoutController::class, 'address'])->name('checkout.address');
    Route::post('/update-address', [CheckoutController::class, 'updateAddress'])->name('checkout.update-address');
    Route::post('/shipping', [CheckoutController::class, 'shipping'])->name('checkout.shipping');
    Route::post('/complete', [CheckoutController::class, 'complete'])->name('checkout.complete');

    // Synchronous form endpoints
    Route::post('/process', [CheckoutController::class, 'processCheckout'])->name('checkout.process');
    Route::post('/apply-coupon', [CheckoutController::class, 'applyCouponSync'])->name('checkout.apply-coupon');
});

Route::match(['get', 'post'], '/payment/callback', [CheckoutController::class, 'paymentCallback'])->name('checkout.payment.callback');
Route::post('/payment/webhook', [CheckoutController::class, 'paymentWebhook'])->name('checkout.payment.webhook');
Route::get('/payment/redirect', [CheckoutController::class, 'paymentRedirect'])->name('checkout.payment.redirect');

Route::get('/search', [ShopProductController::class, 'search'])->name('search');

Route::prefix('wishlist')->group(function () {
    Route::get('/', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/add', [WishlistController::class, 'add'])->name('wishlist.add');
    Route::post('/remove/{productId}', [WishlistController::class, 'remove'])->name('wishlist.remove');
    Route::post('/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
    Route::post('/move-to-cart', [WishlistController::class, 'moveToCart'])->name('wishlist.move-to-cart');
    Route::post('/move-all-to-cart', [WishlistController::class, 'moveAllToCart'])->name('wishlist.move-all-to-cart');
    Route::post('/clear', [WishlistController::class, 'clear'])->name('wishlist.clear');
});

Route::prefix('api')->group(function () {
    Route::get('/cart', [CartController::class, 'getCartJson'])->name('api.cart.get');
    Route::get('/cart/count', [CartController::class, 'count'])->name('api.cart.count');
    Route::post('/coupon/validate', [CheckoutController::class, 'validateCoupon'])->name('api.coupon.validate');
    Route::post('/coupon/apply', [CheckoutController::class, 'applyCoupon'])->name('api.coupon.apply');
    Route::get('/shipping/methods', [CheckoutController::class, 'getShippingMethods'])->name('api.shipping.methods');
    Route::get('/wishlist', [WishlistController::class, 'getWishlistJson'])->name('api.wishlist.get');
    Route::get('/wishlist/count', [WishlistController::class, 'count'])->name('api.wishlist.count');
});
