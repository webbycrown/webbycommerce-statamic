<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Cancelled</title>
</head>
<body>
    <h1>{{ config('webbycommerce.store.name') ?? 'Order Cancelled' }}</h1>
    <p>Hi {{ $order['customer_name'] ?? 'Customer' }},</p>
    <p>Your order <strong>{{ $order['order_number'] ?? 'N/A' }}</strong> has been cancelled.</p>
    <p>If you have questions, contact us at {{ config('webbycommerce.store.email') ?? 'support@example.com' }}.</p>
</body>
</html>
