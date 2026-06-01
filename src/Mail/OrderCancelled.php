<?php

namespace WebbyCrown\WebbyCommerceStatamic\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public array $order;

    public function __construct(array $order)
    {
        $this->order = $order;
    }

    public function build()
    {
        return $this->subject('Order Cancelled - ' . ($this->order['order_number'] ?? ''))
            ->view('webbycommerce::emails.order_cancelled')
            ->with(['order' => $this->order]);
    }
}
