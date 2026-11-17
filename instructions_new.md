
Retrieve the last Admin settings page 
re implement the sytling and structure used
Then implement atlest additional 60% of the settings there including choosing trading hour days and hours


The current admin reports page is vague and does not really report much business value to the business. rewrite the reports page to give it more meaningful insights into the business and customers,

Show a preview of cart items in checkout, also Buy one get one free does not add an additional quantity of an item to the users order fix that

Delivery fee is not waiverd when the free delivery is applied
Check if all promotions affect the correct values

Both frontend and backend 

Add a settings limit on quantity of 1 cart item and cart items 
Default 6 of 1 item max
And Default 10 cart items max 
Quantity of cart item included 
Connect admin settings as well to the backend endpoints 

Recommend meals when the cart is empty and user is on the cart page

Promotions Add to checkout if users have items in their cart

Add a rate limit 
Not strict on the amount of queries only after 4 minutes can they query it again
Show the necessary error


Add order lookup and 
Menu item lookup on support 
Customer side so they can choose a menu item, product

Ensure customers can submit a cancel order request which triggers an auto refund of all confirmed funds back into the users bank via payfast
Staff must be notified of this change as well as the system 
Search official payfast docs to do that
Keep it implemented but 
Ensure to state that the feature is available 
When payfast is switched from sandbox to production

Ensure that minimum order amount is the last thing applied and doesn’t stop checkout 

Ensure that buy one get one free is calculated properly according to all official standards
Ensure all the logic behaves sanely and properly
