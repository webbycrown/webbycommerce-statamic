<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Shipped</title>
</head>
<body>
    <h1>{{ config('webbycommerce.store.name') ?? 'Your Order Has Shipped' }}</h1>
    <p>Hi {{ $order['customer_name'] ?? 'Customer' }},</p>
    <p>Your order <strong>{{ $order['order_number'] ?? 'N/A' }}</strong> has been shipped.</p>
    <p>Shipping address:</p>
    <p>{{ $order['shipping_address'] ?? 'N/A' }}</p>
    <p>Thank you for shopping with us.</p>
</body>
</html>
