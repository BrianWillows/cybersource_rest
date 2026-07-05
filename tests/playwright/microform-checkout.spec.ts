import { test, expect, Page } from '@playwright/test';

/**
 * End-to-end: an anonymous customer reaches the Cybersource REST (Flex
 * Microform v2) card form in checkout, and the Microform initialises — i.e.
 * the browser successfully fetched a valid capture context, loaded the
 * Cybersource-hosted Flex library, and mounted the hosted card-number field
 * as an iframe. That end-to-end chain (server credentials -> HTTP-Signature
 * auth -> Cybersource API -> in-browser Microform) is what this asserts.
 *
 * It deliberately does NOT type a PAN or complete a charge: the card fields are
 * cross-origin iframes hosted by Cybersource, and the server-side charge path
 * is covered by the module's PHPUnit kernel tests. This mirrors the SOP E2E,
 * which stops at the signed Secure Acceptance form.
 *
 * Runs against a site with sample Commerce products and an enabled
 * `cybersource_rest` test-mode gateway. A fresh browser context = an empty
 * anonymous cart, so no server-side reset is needed.
 */

async function setIfPresent(page: Page, selector: string, value: string): Promise<void> {
  const el = page.locator(selector).first();
  if (await el.count()) {
    await el.fill(value).catch(() => {});
  }
}

test('anonymous customer reaches an initialised Cybersource Microform card form', async ({ page }) => {
  // 1. Add a product to the cart.
  await page.goto('/product/1');
  await page.getByRole('button', { name: /Add to cart/i }).click();
  await expect(
    page.locator('[data-drupal-messages], .messages--status, .messages-list').filter({ hasText: /added to/i }).first(),
  ).toBeVisible({ timeout: 15000 });

  // 2. Cart -> Checkout.
  await page.goto('/cart');
  await page.getByRole('button', { name: /Checkout/i }).click();
  await page.waitForLoadState('domcontentloaded');

  // 3. Login step: continue as guest if offered.
  const guest = page.getByRole('button', { name: /Continue as Guest|Guest Checkout|Continue/i }).first();
  if (await guest.count()) {
    await guest.evaluate((b: HTMLButtonElement) => (b as HTMLButtonElement & { form: HTMLFormElement }).form.requestSubmit(b)).catch(() => {});
    await page.waitForTimeout(1500);
  }

  // 4. Order information: email + choose the Cybersource REST payment option.
  await page.waitForURL(/\/checkout\/\d+\/order_information/, { timeout: 20000 });
  await setIfPresent(page, 'input[name*="[email]"]', 'guest.buyer@example.com');
  await setIfPresent(page, 'input[name*="[email_confirm]"]', 'guest.buyer@example.com');

  // Onsite gateways render by payment-method label, not by gateway id.
  const rest = page.getByRole('radio', { name: /Cybersource Microform/i });
  await expect(rest).toHaveCount(1);
  // The theme hides the input under its label, which intercepts clicks; click
  // the label to select the option and fire the add-payment-method AJAX.
  await page.getByText('Credit card (Cybersource Microform)').click();
  await expect(rest).toBeChecked({ timeout: 10000 });

  // 5. The REST card form and the hosted-field mounts render.
  const form = page.locator('form').filter({ has: page.locator('.cybersource-rest-form, .cybersource-rest-token') }).first();
  const numberMount = page.locator('#cybersource-card-number');
  await expect(numberMount).toBeVisible({ timeout: 20000 });
  await expect(page.locator('.cybersource-rest-token')).toHaveCount(1);

  // 6. The Microform mounts a Cybersource-hosted iframe into the number field —
  //    proof the capture context was valid and the Flex library initialised.
  await expect(numberMount.locator('iframe')).toHaveCount(1, { timeout: 30000 });
});
