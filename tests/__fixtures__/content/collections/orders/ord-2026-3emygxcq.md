---
id: b22e8d8b-e0e4-4f9c-9ce2-f716ce779dfd
blueprint: order
title: ORD-2026-3EMYGXCQ
order_number: ORD-2026-3EMYGXCQ
reference: ORD-2026-3EMYGXCQ
customer: 4c2f430e-5bc4-4478-91ce-4e92166eaa8a
customer_email: john@example.com
customer_name: 'John Doe'
status: pending
payment_status: paid
gateway: stripe
shipping_method: standard
date: '2026-05-19'
use_shipping_address_for_billing: false
billing_name: 'John Doe'
billing_address: '123 Main St'
billing_city: 'New York'
billing_postal_code: '10001'
billing_region: NY
billing_country: USA
shipping_name: 'John Doe'
shipping_address: '123 Main St'
shipping_city: 'New York'
shipping_postal_code: '10001'
shipping_region: NY
shipping_country: USA
items:
  -
    id: test_key
    product: test-product-id
    product_id: test-product-id
    name: 'Test Product'
    sku: TEST-SKU
    variant: null
    quantity: 2
    price: 50.0
    total: 100.0
    tax: 0
    metadata: {  }
items_total: 100.0
coupon_total: 0
shipping_total: 0.0
tax_total: 10.0
grand_total: 110.0
status_log: 'Order placed 2026-05-19 12:21:49'
subtotal: 100.0
tax: 10.0
shipping: 0.0
discount: 0
total: 110.0
billing_address_data:
  street: '123 Main St'
  line2: ''
  city: 'New York'
  state: NY
  postal_code: '10001'
  country: USA
shipping_address_data:
  street: '123 Main St'
  line2: ''
  city: 'New York'
  state: NY
  postal_code: '10001'
  country: USA
payment_method: stripe
is_paid: true
---
