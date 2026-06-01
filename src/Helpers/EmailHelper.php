<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderConfirmation;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderShipped;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderCancelled;
use WebbyCrown\WebbyCommerceStatamic\Mail\PasswordReset;

class EmailHelper
{
    public static function sendOrderConfirmation(array $orderData, string $customerEmail): bool
    {
        if (!Config::get('webbycommerce.emails.order_confirmation.enabled', true)) {
            return false;
        }

        try {
            if (Config::get('webbycommerce.emails.order_confirmation.to_customer', true)) {
                Mail::to($customerEmail)->send(new OrderConfirmation($orderData));
            }

            $sendAdmin = Config::get('webbycommerce.emails.order_confirmation.to_admin', false);
            $adminEmail = Config::get('webbycommerce.emails.order_confirmation.admin_email')
                ?? Config::get('webbycommerce.store_email');

            if ($sendAdmin && $adminEmail) {
                Mail::to($adminEmail)->send(new OrderConfirmation($orderData));
            }

            Log::info('Order confirmation email sent to: ' . $customerEmail);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send order confirmation email: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendOrderShipped(array $orderData, string $customerEmail): bool
    {
        if (!Config::get('webbycommerce.emails.order_shipped.enabled', true)) {
            return false;
        }

        try {
            Mail::to($customerEmail)->send(new OrderShipped($orderData));
            Log::info('Order shipped email sent to: ' . $customerEmail);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send order shipped email: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendOrderCancelled(array $orderData, string $customerEmail): bool
    {
        try {
            Mail::to($customerEmail)->send(new OrderCancelled($orderData));
            Log::info('Order cancelled email sent to: ' . $customerEmail);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send order cancelled email: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendPasswordReset(string $email, string $token): bool
    {
        try {
            Mail::to($email)->send(new PasswordReset($token));
            Log::info('Password reset email sent to: ' . $email);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
            return false;
        }
    }
}
