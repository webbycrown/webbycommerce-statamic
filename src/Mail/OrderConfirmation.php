<?php

namespace WebbyCrown\WebbyCommerceStatamic\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public array $order;

    public function __construct(array $order)
    {
        $this->order = $order;
    }

    public function build()
    {
        return $this->subject('Order Confirmation - ' . ($this->order['order_number'] ?? ''))
            ->view('webbycommerce::emails.order_confirmation')
            ->with(['order' => $this->order]);
    }
}
