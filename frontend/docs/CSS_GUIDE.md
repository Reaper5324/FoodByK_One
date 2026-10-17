# Food by K Frontend CSS Guide

This guide explains how the website's visual rules are written in CSS. CSS (Cascading Style Sheets) controls how HTML looks: colors, spacing, layout, text, responsive behavior, and visual feedback.

## Where the styles live

Shared customer styles are in `frontend/public assets/css/`. Pages link these files in their `<head>`; a typical order is:

1. `style.css` for brand variables, resets, shared elements, buttons, and the common navigation foundation.
2. `home.css` where a page uses the home-page visual language and shared hero sections.
3. A page stylesheet such as `menu.css`, `cart.css`, `checkout.css`, `orders.css`, `account.css`, `auth.css`, `loyalty.css`, or `promotions.css` for that page's unique layout.
4. `skeletons.css` and `mobile-navigation.css` for shared loading placeholders and small-screen navigation.
5. `assistant.css` is loaded by the navigation JavaScript when the assistant UI is installed.

The home page's newer look uses `home.css`. Older or more general page rules remain in `style.css` and `customer.css`. Admin pages also use `frontend/css/dashboard.css`; some admin behavior is presented by PHP templates/components while most current admin screens are HTML.

## Design techniques used

### Brand tokens

`style.css` defines CSS custom properties in `:root`, for example `--food-red`, `--food-yellow`, `--food-black`, `--food-light`, and `--content-width`. A custom property is a named value that can be reused with `var(--food-red)`. This keeps repeated choices consistent and makes later redesigns easier.

The main palette uses red for brand emphasis, yellow as an accent, dark text and backgrounds for contrast, and light neutral surfaces. The home page adds warm cream and muted ink colors to create a softer food-focused surface while retaining the brand accents.

### Layout and sizing

The CSS uses Flexbox and CSS Grid to arrange navigation, cards, forms, menu items, summaries, and dashboard panels. These layout systems adapt when available space changes. `max-width`, `min-width: 0`, `gap`, and wrapping rules help prevent content from overflowing on narrow screens.

Responsive rules use media queries such as `@media (max-width: 768px)` and `@media (max-width: 480px)`. These change the arrangement for phones and tablets, for example turning the desktop navigation into a hamburger menu and changing grids into fewer columns.

The `clamp(minimum, preferred, maximum)` function lets a value grow with the viewport but places a lower and upper limit on it. The home hero heading, for example, uses a `clamp()` font size so the title scales between phone and desktop sizes without growing without limit.

### Reusable visual patterns

- Shared `.btn` classes give links and buttons a consistent shape and emphasis.
- Repeated page shells, cards, section headings, status labels, form controls, and empty states make related pages feel like one product.
- The home, cart, checkout, and orders pages share the bold headings, warm background surfaces, red/yellow emphasis, and card treatment.
- The `.ui-arrow` icon is drawn with CSS pseudo-elements rather than a text arrow glyph. This avoids iOS choosing an emoji-style arrow from its system font.
- Selectors are mostly named after their purpose (for example `.order-progress`, `.cart-total`, `.menu-grid`). This is a practical naming convention, not a strict CSS naming framework.

## Animation and transitions

Animation is used for short feedback, not to decorate every element:

- `skeletons.css` animates a light gradient across loading placeholders with `@keyframes skeleton-shimmer`.
- `mobile-navigation.css` uses `nav-skeleton-shimmer` while account-dependent navigation links are being resolved. The hamburger's three bars transition into a close mark when the menu opens.
- `account.css` spins the small progress indicator shown while policy acceptance is submitted.
- Page styles use brief transitions for interactive changes such as button colors, card elevation, image zoom, or moving a small arrow on hover.
- The home page and page-specific styles include `prefers-reduced-motion` rules. When a visitor's operating system asks for reduced motion, the interface disables or simplifies motion where supported.

For example, the common skeleton rule has a moving pseudo-element:

```css
.skeleton::after {
  content: "";
  transform: translateX(-100%);
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.7), transparent);
  animation: skeleton-shimmer 1.35s infinite;
}
```

The keyframe moves that highlight across the placeholder. Most transitions are controlled by adding or removing a state class such as `.is-mobile-nav-open` or `.is-visible` in JavaScript.

## Skeleton loaders

A skeleton is a temporary shape that resembles the content being fetched. It tells the visitor that the page is working and gives a preview of the content layout, instead of showing an unexplained blank area or spinner.

The shared building blocks live in `skeletons.css`:

- `.skeleton` supplies the neutral surface, clipping, and shimmer.
- `.skeleton-line` resembles a text line.
- `.skeleton-card` reserves card space.
- `.skeleton-media` reserves an image area.
- `.skeleton-pill` resembles a compact category chip.

Page JavaScript inserts only the number of placeholders appropriate while data is loading. For example, `menu.js` inserts four category pills and six product-card placeholders, then replaces them with the real categories and products. `cart.js` builds placeholders for its loading state and removes them when the API response arrives; it should not leave placeholder rows beside real cart items. The order, loyalty, account, checkout, and admin screens use similar loading states for their API-backed sections.

A typical loading cycle is:

1. Set `aria-busy="true"` on the changing region so assistive technology knows it is loading.
2. Add placeholders with `aria-hidden="true"` so decorative skeleton shapes are not announced as real content.
3. Request data from the API.
4. Replace the placeholders with actual content, or show an error/empty state.
5. Set `aria-busy="false"`.

## Fonts and type sizes

The shared base font is `Arial, Helvetica, sans-serif` in `style.css`. This is a system font stack: the browser uses Arial when present and falls back to Helvetica or a generic sans-serif. It does not require downloading a font service. Some home-page rules use very heavy weights (often 800–950) and uppercase text for a bold food-brand tone; smaller labels use letter spacing and uppercase to distinguish metadata from body copy.

There is no single fixed type scale across every legacy page. Common patterns are:

- Body copy around 14–16px with a comfortable line height, often 1.6–1.8.
- Small supporting labels around 9–13px.
- Form controls and card names commonly around 13–16px.
- Large page and hero headings use `clamp()` so they can range from roughly 2.5rem to over 6rem depending on the component and screen.

When adding a style, reuse the page's existing typography and spacing choices. Prefer `rem`, percentages, `max-width`, and `clamp()` for elements that need to adapt, and check the small-screen media query as well as the desktop rules.

## How to follow a page's styles

Open its HTML file and read its stylesheet links from top to bottom. Then inspect the page stylesheet and search for the component class names used in the HTML. Shared rules can come from `style.css`, `home.css`, or the utility stylesheets before the page's own CSS. CSS follows the cascade: when two selectors have comparable strength, the later applicable rule wins.
