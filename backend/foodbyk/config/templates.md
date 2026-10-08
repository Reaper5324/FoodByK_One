| Template name | Content SID | Language | Body | Backend variables |
|---|---|---|---|---|
| `staff_new_order` | `HXaeaedd0960686315d51cfbd7f93d37ec` | English | `Food by K: New order #{{1}} needs review. Fulfilment: {{2}}.` | Order ID, then fulfillment type |
| `customer_order_confirmed` | `HX31f9253509eaca779b851e85092ab949` | English (US) | `Food by K: Your order #{{1}} is confirmed for {{2}}. Thank you for ordering.` | Order ID, then one date/time string |
| `customer_order_declined` | `HXd98e8c167375186cb63cc9068c7a9ad2` | English (US) | `Food by K: We couldn't accept order #{{1}}. Reason: {{2}}. Please contact us if you need help.` | Order ID, then reason |
| `customer_payment_received` | `HXb06acfc4effd0d2830c56b207d4b9169` | English | `Food by K: Payment received for order #{{1}}. Thank you.` | Order ID |
| `customer_payment_issue` | `HXaf8a815da6fdcfc85ac4401faa08c366` | English | `Food by K: We couldn't confirm payment for order #{{1}}. Please contact Food by K for help.` | Order ID |
