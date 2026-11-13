Task 4. Ensure that when the fefund button is clicked on and payfastt is in sandbox mode, show a "Refunds are not available in sandbox mode instead of erroors

Task 5. Ensure tha all the notification success and error messages pop up right in front of the customer wherever they are on a page so its visible to them&#x20;

Task 6. PWA and notification

The aim of this task is to ensure they can download the web app as a pwa and ensure that staff get new order notifications from the pwa
for staff, if possible the notifications dont stop until they open the pwa
only if possible&#x20;
for customers, a pwa notification for, Satff confirmed order and steps of order progression

all users must be prompted to install the pwa

Task 7. Admin and staff are showing me that there are no orders orders check if they retrieve orders properly

You also messed up trying to save payment token before creating an order, it redirects me to payfast, the payment is successful, but it redirects me back to checkout with a " A saved payment method is required before you can place an order, if possible, wait and confirm a token, dont mess up the return url, the previous proccess was correct it just that sometimes the payment can fail or the user can exit the payment gateway thus creating an order without a payment token. fix that