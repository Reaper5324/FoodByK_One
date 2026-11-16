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

test('every targeted menu item displays its applicable promotion', async ({ page }) => {
  await mockAnonymousSession(page);
  await page.route(/\/categories$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [{ id: 1, name: 'Meals' }] }),
  }));
  await page.route(/\/promotions\/active$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [{
      id: 9, code: 'DELIVERY', discount_type: 'free_delivery', discount_value: 0,
      target_product_ids: [1, 2], target_product_names: ['Burger', 'Kota'],
    }] }),
  }));
  await page.route(/\/products$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [
      { id: 1, name: 'Burger', description: 'Burger', price: 50 },
      { id: 2, name: 'Kota', description: 'Kota', price: 60 },
      { id: 3, name: 'Chips', description: 'Chips', price: 20 },
    ] }),
  }));

  await page.goto('/pages/customer/menu.html');
  await expect(page.locator('.menu-card')).toHaveCount(3);
  await expect(page.locator('.menu-card').nth(0).locator('.menu-promotion-badge')).toContainText('Free delivery');
  await expect(page.locator('.menu-card').nth(1).locator('.menu-promotion-badge')).toContainText('Free delivery');
  await expect(page.locator('.menu-card').nth(2).locator('.menu-promotion-badge')).toHaveCount(0);
});

test('customer promotions show the matching targeted product image', async ({ page }) => {
  await mockAnonymousSession(page);
  await page.route(/\/promotions\/active$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [{
      id: 10, code: 'KOTA10', discount_type: 'percentage', discount_value: 10,
      target_product_ids: [12], target_product_names: ['Chicken Kota'],
    }] }),
  }));
  await page.route(/\/products$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [
      { id: 12, name: 'Chicken Kota', price: 75, image_url: 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=' },
    ] }),
  }));

  await page.goto('/pages/customer/promotions.html');
  const promotionProduct = page.locator('.promotion-product');
  await expect(promotionProduct).toHaveCount(1);
  await expect(promotionProduct).toContainText('Chicken Kota');
  await expect(promotionProduct.locator('img')).toHaveAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=');
  await expect(promotionProduct).toHaveAttribute('href', 'menu-item.html?id=12');
});

test('customization selection updates the price and sends selected option IDs', async ({ page }) => {
  let submittedCartItem;
  await mockAnonymousSession(page);
  await page.route(/\/products\/1$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: {
      id: 1, name: 'Classic Burger', description: 'Fresh burger', price: 50,
      options: [
        { id: 41, name: 'Extra cheese', price_delta: 8, is_available: true },
        { id: 42, name: 'Free sauce', price_delta: 0, is_available: true },
      ],
    } }),
  }));
  await page.route(/\/promotions\/active$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [] }),
  }));
  await page.route(/\/cart\/items$/, async (route) => {
    submittedCartItem = route.request().postDataJSON();
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
  });
  await page.route(/\/auth\/me$/, (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ success: false, data: null, error: 'Authentication required.' }),
  }));

  await page.goto('/pages/customer/menu-item.html?id=1');
  await page.getByLabel('Extra cheese').check();
  await page.getByLabel('Free sauce').check();
  await expect(page.locator('.menu-item-price')).toContainText('R58.00');
  await page.getByRole('button', { name: 'Add to cart' }).click();
  await expect.poll(() => submittedCartItem).toEqual({ product_id: 1, quantity: 1, option_ids: [41, 42] });
});

test('menu cards keep the price and add button separate on a phone viewport', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await mockAnonymousSession(page);
  await page.route(/\/categories$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [] }),
  }));
  await page.route(/\/promotions\/active$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [] }),
  }));
  await page.route(/\/products$/, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [
      { id: 1, name: 'A long product name for mobile layout', price: 149.99 },
    ] }),
  }));

  await page.goto('/pages/customer/menu.html');
  const card = page.locator('.menu-card').first();
  await expect(card).toBeVisible();
  const price = await card.locator('.menu-price-wrap').boundingBox();
  const button = await card.locator('.add-to-cart').boundingBox();
  expect(price).not.toBeNull();
  expect(button).not.toBeNull();
  expect(price.x + price.width).toBeLessThanOrEqual(button.x + 1);
});

test('admin optional extras editor adds and removes paired name/price rows', async ({ page }) => {
  let submittedOptions;

  await page.route(/^https?:\/\/(?!127\.0\.0\.1|localhost)/, (route) => route.abort());

await page.route(/pwa\.js$/, (route) => route.abort());

  await page.route(/\/auth\/me$/, (route) => route.fulfill({
    headers: { 'X-CSRF-Token': 'test-csrf-token' },
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { id: 7, full_name: 'Test Admin', email: 'admin@example.test', role: 'admin' } }),
  }));
  await page.route(/\/(admin\/products|admin\/categories|push\/public-key|staff\/orders\/unacknowledged)$/, (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/admin/products') && route.request().method() === 'POST') {
      submittedOptions = route.request().postDataJSON().options;
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { id: 90 } }) });
    }
    const data = path.endsWith('/admin/categories')
      ? [{ id: 1, name: 'Meals' }]
      : path.endsWith('/push/public-key')
        ? { enabled: false }
        : [];
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  });

  await page.goto('/admin/menu-items.html', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#adminLoadingState')).toHaveCount(0);
  await page.locator('#addMenuBtn').click();
  const rows = page.locator('#itemOptions .optional-extra-row');
  await expect(rows).toHaveCount(1);
  await rows.first().locator('.item-option-name').fill('Extra cheese');
  await rows.first().locator('.item-option-price').fill('8.00');
  await page.locator('#addItemOptionBtn').click();
  await expect(rows).toHaveCount(2);
  await rows.nth(1).locator('.item-option-name').fill('Free sauce');
  await rows.nth(1).locator('.item-option-price').fill('0.00');
  await page.locator('#addItemOptionBtn').click();
  await expect(rows).toHaveCount(3);
  await rows.nth(2).locator('.item-option-name').fill('Removed extra');
  await rows.nth(2).locator('button[data-remove-item-option]').click();
  await expect(rows).toHaveCount(2);
  await rows.nth(1).locator('button[data-remove-item-option]').click();
  await expect(rows).toHaveCount(1);
  await expect(rows.first().locator('.item-option-name')).toHaveValue('Extra cheese');
  await page.locator('#addItemOptionBtn').click();
  await expect(rows).toHaveCount(2);
  await rows.nth(1).locator('.item-option-name').fill('Free sauce');
  await rows.nth(1).locator('.item-option-price').fill('0.00');

  await page.locator('#itemName').fill('Test meal');
  await page.locator('#itemCategory').selectOption('1');
  await page.locator('#itemPrice').fill('79.00');
  await page.locator('#itemDescription').fill('A test menu item');
  await page.getByRole('button', { name: /save menu item/i }).click();
  await expect.poll(() => submittedOptions).toEqual([
    { name: 'Extra cheese', price_delta: 8 },
    { name: 'Free sauce', price_delta: 0 },
  ]);
});
