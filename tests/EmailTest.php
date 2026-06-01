<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use WebbyCrown\WebbyCommerceStatamic\Helpers\EmailHelper;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderConfirmation;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderShipped;
use WebbyCrown\WebbyCommerceStatamic\Mail\OrderCancelled;

class EmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_send_order_confirmation_sends_email_to_customer_and_admin(): void
    {
        Config::set('webbycommerce.emails.order_confirmation.enabled', true);
        Config::set('webbycommerce.emails.order_confirmation.to_customer', true);
        Config::set('webbycommerce.emails.order_confirmation.to_admin', 'admin@example.com');

        $orderData = ['order_number' => 'ORD-123', 'total' => 100.00];
        $customerEmail = 'customer@example.com';

        $result = EmailHelper::sendOrderConfirmation($orderData, $customerEmail);

        $this->assertTrue($result);

        Mail::assertSent(OrderConfirmation::class, function ($mail) use ($customerEmail) {
            return $mail->hasTo($customerEmail) && $mail->data['order_number'] === 'ORD-123';
        });

        Mail::assertSent(OrderConfirmation::class, function ($mail) {
            return $mail->hasTo('admin@example.com') && $mail->data['order_number'] === 'ORD-123';
        });
    }

    public function test_send_order_confirmation_does_not_send_if_disabled(): void
    {
        Config::set('webbycommerce.emails.order_confirmation.enabled', false);

        $orderData = ['order_number' => 'ORD-123'];
        $customerEmail = 'customer@example.com';

        $result = EmailHelper::sendOrderConfirmation($orderData, $customerEmail);

        $this->assertFalse($result);
        Mail::assertNothingSent();
    }

    public function test_send_order_shipped_sends_email_to_customer(): void
    {
        Config::set('webbycommerce.emails.order_shipped.enabled', true);

        $orderData = ['order_number' => 'ORD-123', 'status' => 'shipped'];
        $customerEmail = 'customer@example.com';

        $result = EmailHelper::sendOrderShipped($orderData, $customerEmail);

        $this->assertTrue($result);

        Mail::assertSent(OrderShipped::class, function ($mail) use ($customerEmail) {
            return $mail->hasTo($customerEmail) && $mail->data['status'] === 'shipped';
        });
    }

    public function test_send_order_cancelled_sends_email_to_customer(): void
    {
        $orderData = ['order_number' => 'ORD-123', 'status' => 'cancelled'];
        $customerEmail = 'customer@example.com';

        $result = EmailHelper::sendOrderCancelled($orderData, $customerEmail);

        $this->assertTrue($result);

        Mail::assertSent(OrderCancelled::class, function ($mail) use ($customerEmail) {
            return $mail->hasTo($customerEmail) && $mail->data['status'] === 'cancelled';
        });
    }
}
