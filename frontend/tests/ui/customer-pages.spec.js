const { test, expect } = require('@playwright/test');

async function mockAnonymousSession(page) {
  await page.route(/\/auth\/me$/, (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ success: false, data: null, error: 'Authentication required.' }),
  }));
}

test('menu skeletons are replaced by loaded product cards', async ({ page }) => {
  let releaseProducts;
  const productsGate = new Promise((resolve) => { releaseProducts = resolve; });
  await mockAnonymousSession(page);
  await page.route(/\/categories$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [{ id: 1, name: 'Burgers' }] }),
  }));
  await page.route(/\/products$/, async (route) => {
    await productsGate;
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: [
        { id: 1, name: 'Classic Burger', description: 'Fresh burger', price: 49.99 },
        { id: 2, name: 'Cheese Burger', description: 'Cheesy burger', price: 54.99 },
      ] }),
    });
  });

  await page.goto('/pages/customer/menu.html');
  await expect(page.locator('#menuGrid .skeleton-card')).toHaveCount(6);
  releaseProducts();
  await expect(page.locator('#menuGrid .menu-card')).toHaveCount(2);
  await expect(page.locator('#menuGrid')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('#menuGrid .skeleton')).toHaveCount(0);
});

test('cart loading placeholders do not remain beside real cart items', async ({ page }) => {
  let releaseCart;
  const cartGate = new Promise((resolve) => { releaseCart = resolve; });
  await mockAnonymousSession(page);
  await page.route(/\/cart$/, async (route) => {
    await cartGate;
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { items: [
        { id: 11, product_id: 1, quantity: 1, unit_price: 49.99 },
        { id: 12, product_id: 2, quantity: 2, unit_price: 20 },
      ] } }),
    });
  });
  await page.route(/\/products$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [
      { id: 1, name: 'Classic Burger', price: 49.99 },
      { id: 2, name: 'Fries', price: 20 },
    ] }),
  }));

  await page.goto('/pages/customer/cart.html');
  await expect(page.locator('#cartItems .cart-skeleton-row')).toHaveCount(2);
  releaseCart();
  await expect(page.locator('#cartItems .cart-row:not(.cart-skeleton-row)')).toHaveCount(2);
  await expect(page.locator('#cartItems .cart-skeleton-row')).toHaveCount(0);
  await expect(page.getByText('Classic Burger')).toBeVisible();
  await expect(page.getByText('Fries')).toBeVisible();
});

test('order tracking uses delivery-specific progress labels', async ({ page }) => {
  await mockAnonymousSession(page);
  await page.route(/\/orders\?limit=1$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { orders: [{ id: 73 }] } }),
  }));
  await page.route(/\/orders\/73$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: {
      order: {
        id: 73, status: 'ready', fulfilment_type: 'delivery', created_at: '2026-10-13 10:00:00',
        subtotal: 80, locked_discount: 0, delivery_fee: 15, requested_window_start: '2026-10-13 11:00:00',
      },
      items: [{ product_name: 'Classic Burger', quantity: 1, unit_price: 80 }],
      address: { raw_address: '1 Test Street' },
    } }),
  }));

  await page.goto('/pages/customer/orders.html');
  await expect(page.locator('.order-card')).toBeVisible();
  await expect(page.locator('.order-status-label')).toHaveText('out for delivery');
  await expect(page.locator('.order-progress')).toContainText('Out for delivery');
  await expect(page.locator('.order-progress')).toContainText('Delivered');
});
