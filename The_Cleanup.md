Our objective in this session is to wire up whats left of the website, Check and ensure that guardrails safeguards are in place, and consolidate the final website

Critically assess your implementations. use official sources if you're not sure
Prefereably use existing elements, components and routes unless new code is really needed. Use the system's existing design 
use names, concepts, conventions and variables used by the exixting system

One task at a time, signal me in order to continue to the next task


Task 1: Promotions

The backend has promotion and loyalty rewards
craete a customer promotion page where customers can view promotions and add them to their next order automatically (add to their checkout)
The Promotion page should be accesible via the nav bar
The promotion page should list the promotions in card form with the card containing The promotion code, The discount and an add to checkout button which adds to the customers next checkout

Task 2: Loyalty rewards
The Loyalty Rewards Mechanism and page

The loyalty reward system should work like this: For every R100 the customer spends, they get 1 point so if they spend R175 they get  1.75 points
These points can be redeemed at checkout (add that to checkout)

A Loyalty  rewards page is needed for user visibility
-it should show the total points acummulated by the customer
-List past orders (does not have to be detailed) and the points accumulated from that order 

Task 2.1: Checkout repeated lookup problem
The current code fetches products individually while claculating the subtotal, validating promotion and when creating an order. recommened fix: bulk-load the products once then reuse
be midful of  safegurads and existing code
check that every requested product ID exixts in the result
preserve the active/available checks
continue calculating prices on the server
preserve historical product names and unit prices
ensure promotion calculations use  exactly the same validated product prices as the subtotal calculation

This solves fewer database round trips

Task 2.2: Order-detail retrieval has an N+1  query pattern
locations OrderService::getOrderByID() and OrderService::getOrderForStaff()
Low risk fix: add a bulk product lookup and reuse it in both methods. alternatively retrieve item and product fields through a joined query while retaining the order-item snapshot fields
Task 3: Detailed Menu Item page

When a customer clicks on a menu item image, they get to see a detailed view of the menu item. The detailed view include the menu item image, the name, description, a back button to go back to the menu and an add to cart button
implement this.

Task 4: Nav bar mobile devices only

as a customer on a mobile device, turn the Nav bar into a hamburger menu for mobile device screens only
 sub task: use the logo on the login page and add a smaller version  in the nav bar before or after the "FOODBY K" in the nav bar.

Task 5:
The staff Order Management pages has a bunch of hardcoded metrics/ at the top of the page, remove them and replace them with analytics the system can actuallly provide.

Task 6: 
In the same staff Order Management page, I need you to split the All Orders table
All orders should be shown on a different tab where staff can view all orders and their dates
The All Orders on the staff page should only change Today's Orders
It will be an interface where it displays orders that are due and placed today only as well as the manage/view button beside those orders so staff can manage orders

Task 6.1: Staff Adjust order functionality shows time slot however it says "Unable to load TimeSlots" ensure that the adjust order functionality can change time slots and implement guardrails and safeguards


Task 7:

Staff should not be able to view the Admin dashborad, it is a security risk, remove the dashboard nav link in the staff nav panel


Task 8: Safegaurd and race condition checks
Im not sure if all safe guards and race conditions have been check

limits - 3 Delivery addresses per customer, 1 promotion per checkout, Limit email change because it is tied to customer account and log in, Customers cant order on Sundays (off day), Staff cant adjust orders to sunday.
Find more of these type of limits and ensure that they are implemented properly on customer, staff and admin pages.

