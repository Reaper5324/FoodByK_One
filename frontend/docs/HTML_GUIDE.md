# Food by K Frontend HTML Guide

HTML (HyperText Markup Language) describes the content and structure of a web page. It tells the browser which parts are headings, navigation, forms, lists, buttons, images, and dialog boxes. CSS styles that structure, and JavaScript adds behavior.

## Page types and locations

- `frontend/src/index.html` is the static customer home page.
- `frontend/pages/customer/` contains customer menu, item details, cart, checkout, orders, loyalty, and promotion pages. `payment.php` is a legacy server-rendered payment page; Netlify cannot execute PHP, so the main customer experience uses static HTML and the JSON backend API.
- `frontend/pages/auth/` contains login, registration, account, and password recovery pages.
- `frontend/pages/legal/` contains the cookie notice, privacy notice, and terms pages.
- `frontend/admin/` contains the HTML admin and staff pages.
- `frontend/components/` and `frontend/services/` contain older PHP fragments and service helpers. These are legacy server-side pieces, not JavaScript modules served by Netlify.

## Basic document structure

A page typically begins with `<!DOCTYPE html>`, then an `<html lang="en">` root, a `<head>`, and a `<body>`. The doctype asks browsers to use modern standards mode. The language attribute helps screen readers and browser translation. The head contains the character encoding, viewport setting for mobile sizing, page title, stylesheet links, and sometimes metadata. The body contains the visible page.

A customer page commonly has:

```html
<body>
  <header class="navbar"> ... </header>
  <main> ...page-specific content... </main>
  <script src=".../config.js"></script>
  <script src=".../api/api.js"></script>
  <script src=".../page.js"></script>
</body>
```

The shared navigation is authored in each page's HTML. `navigation.js` enhances it with a mobile toggle, session-aware links, and the optional assistant. This makes the page's useful links exist in the document before JavaScript finishes running.

## Semantic elements

Semantic HTML uses tags that describe their purpose:

- `<header>` introduces a page or section; `<nav>` groups navigation links.
- `<main>` identifies the page's primary content.
- `<section>` groups a related area, often with a heading.
- `<article>` represents a self-contained item such as an order card.
- `<form>`, `<label>`, `<input>`, `<select>`, and `<button>` provide standard interaction controls.
- `<footer>` holds closing information and secondary links.

Using these elements helps browsers, assistive technologies, and developers understand a page. A button should perform an action; an anchor should navigate to a URL. Labels should point to their input with matching `for` and `id` values.

## HTML, CSS, and JavaScript working together

HTML provides named containers that JavaScript can update. For example, `menu.html` has `#categoryList` and `#menuGrid`; `menu.js` fetches categories and products and creates buttons and cards inside those containers. The checkout page has fields with stable IDs, such as the fulfilment type, delivery address, delivery date/time, promotion code, and review-total region. `checkout.js` reads those fields, requests previews, and submits the order.

Dynamic regions often have `aria-live` and `aria-busy` attributes:

- `aria-live="polite"` asks screen readers to announce meaningful updates without interrupting the current task.
- `aria-busy="true"` indicates that the region is being refreshed; JavaScript changes it to false when the update is finished.
- `role="status"` is used for short messages such as save confirmation or cart feedback.
- `aria-hidden="true"` hides decorative shapes, such as skeletons, from screen readers.

The pages also use regular browser validation attributes such as `required`, `type="email"`, `maxlength`, and `autocomplete`. These improve input behavior but do not replace validation in the PHP backend; requests can be sent without the page, so the server must validate them again.

## Navigation, forms, and feedback

The navbar contains regular links to home, menu, contact, and cart. JavaScript adds or removes account links after it checks the current session. The customer “Your Orders” link is shown when an authenticated customer has an order. The mobile menu button is created by `navigation.js` and receives `aria-controls`, `aria-expanded`, and a descriptive label so it can be operated with a keyboard and understood by a screen reader.

Forms use IDs and names so their page script can read values. Submission handlers usually call `preventDefault()` to stop a full-page form post, then send JSON to the backend. The page displays success, validation, loading, or error feedback near the affected content. The registration page also uses password guidance and reveal controls; the HTML provides the input and status elements, while JavaScript updates their state.

## Dialogs and empty states

The menu page uses the native `<dialog>` element for its centered “thank you” message after a completed order. A native dialog supports browser focus behavior better than an arbitrary positioned `<div>`; JavaScript opens it only when the matching completion state is present. Pages such as orders also have deliberate empty states with an explanation and a link to browse the menu. Empty states are real content and should not be confused with skeleton loaders, which are temporary.

## Adding or editing a page

1. Start from a nearby customer or admin page so the existing navigation and style conventions are retained.
2. Use a unique page title, a viewport meta tag, and the correct stylesheet/script paths.
3. Use semantic headings and form labels; do not use a heading just to make text look large.
4. Give JavaScript-controlled regions stable IDs or data attributes.
5. Add accessible loading and feedback attributes where the content changes asynchronously.
6. Keep values from customers as text. The page scripts generally use `textContent` or DOM nodes to insert API values rather than turning arbitrary user text into HTML.
7. Keep server validation in place even when the HTML has `required` or other validation attributes.
