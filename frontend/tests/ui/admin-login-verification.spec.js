const { test, expect } = require('@playwright/test');

test('admin login requires and completes the emailed code challenge', async ({ page }) => {
  test.setTimeout(60000);
  await page.route('https://api.foodbyk.co.za/auth/login', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: {
      admin_verification_required: true,
      challenge_token: 'a'.repeat(64),
      email_hint: 'a***@example.test',
      expires_in_seconds: 600,
      clear_session_token: true,
    }, error: null }),
  }));
  await page.route('https://api.foodbyk.co.za/auth/admin-login/resend', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { message: 'A new verification code was sent.' }, error: null }),
  }));
  await page.route('https://api.foodbyk.co.za/auth/admin-login/verify', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { id: 77, role: 'admin', session_token: 'test-admin-session-token' }, error: null }),
  }));
  await page.route('https://api.foodbyk.co.za/auth/me', (route) => route.fulfill({
    contentType: 'application/json',
    headers: { 'X-CSRF-Token': 'test-csrf-token' },
    body: JSON.stringify({ success: true, data: { id: 77, role: 'admin', full_name: 'Test Admin' }, error: null }),
  }));

  await page.goto('/pages/auth/login.html');
  await page.locator('#loginEmail').fill('admin@example.test');
  await page.locator('#loginPassword').fill('not-a-real-password');
  await page.locator('#loginForm button[type="submit"]').click();

  await expect(page.locator('#adminLoginVerification')).toBeVisible();
  await expect(page.locator('#adminLoginEmailHint')).toHaveText('a***@example.test');
  await expect(page.locator('#loginForm')).toBeHidden();
  await page.getByRole('button', { name: 'Resend verification code' }).click();
  await expect(page.locator('#message')).toContainText('A new verification code was sent.');

  await page.locator('#adminLoginCode').fill('482913');
  await page.getByRole('button', { name: 'Verify code' }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard\.html$/);
});
