<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Confirmation</title>
</head>
<body>
    <h1>{{ config('webbycommerce.store.name') ?? 'Order Confirmation' }}</h1>
    <p>Hi {{ $order['customer_name'] ?? 'Customer' }},</p>
    <p>Thank you for your order at {{ config('webbycommerce.store.name') ?? 'our store' }}. Your order number is <strong>{{ $order['order_number'] ?? 'N/A' }}</strong>.</p>
    <p>Order summary:</p>
    <ul>
        <li>Subtotal: {{ $order['subtotal'] ?? '0.00' }}</li>
        <li>Tax: {{ $order['tax'] ?? '0.00' }}</li>
        <li>Shipping: {{ $order['shipping'] ?? '0.00' }}</li>
        <li>Total: {{ $order['total'] ?? '0.00' }}</li>
    </ul>
    <p>We will notify you once your order has shipped.</p>
</body>
</html>
