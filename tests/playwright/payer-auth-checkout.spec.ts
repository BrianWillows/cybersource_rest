import { test, expect, Page } from '@playwright/test';

/**
 * End-to-end 3-D Secure: a full checkout through the Cybersource REST gateway
 * with payer authentication enabled, using Cybersource's FRICTIONLESS test
 * card (4000000000002701 — "3DS 2.x success frictionless authentication").
 *
 * This exercises the whole chain live against the Cybersource sandbox:
 * Microform tokenisation → payer-auth setup → Cardinal device data collection
 * → enrollment check (AUTHENTICATION_SUCCESSFUL, CAVV issued) → authorization
 * carrying the authentication data → completed order.
 *
 * Requires the dev site's cybersource_rest gateway to have payer_auth enabled
 * and Payer Authentication boarded on the sandbox merchant account.
 */

async function setIfPresent(page: Page, selector: string, value: string): Promise<void> {
  const el = page.locator(selector).first();
  if (await el.count()) {
    await el.fill(value).catch(() => {});
  }
}

/**
 * Fill and VERIFY, retrying — commerce's address widget AJAX can replace the
 * field mid-fill, silently discarding the value.
 */
async function fillVerified(page: Page, selector: string, value: string): Promise<void> {
  for (let i = 0; i < 4; i++) {
    const el = page.locator(selector).first();
    await el.fill(value).catch(() => {});
    if ((await el.inputValue().catch(() => '')) === value) {
      return;
    }
    await page.waitForTimeout(1500);
  }
  throw new Error(`Could not fill ${selector}`);
}

/**
 * Walks checkout to the payment click with a given card in the Microform.
 */
async function checkoutWithCard(page: Page, cardNumber: string): Promise<void> {
  // 1. Add a product and start checkout as a guest.
  await page.goto('/product/1');
  await page.getByRole('button', { name: /Add to cart/i }).click();
  await expect(
    page.locator('[data-drupal-messages], .messages--status, .messages-list').filter({ hasText: /added to/i }).first(),
  ).toBeVisible({ timeout: 15000 });
  await page.goto('/cart');
  await page.getByRole('button', { name: /Checkout/i }).click();
  await page.waitForLoadState('domcontentloaded');
  const guest = page.getByRole('button', { name: /Continue as Guest|Guest Checkout|Continue/i }).first();
  if (await guest.count()) {
    await guest.evaluate((b: HTMLButtonElement) => (b as HTMLButtonElement & { form: HTMLFormElement }).form.requestSubmit(b)).catch(() => {});
    await page.waitForTimeout(1500);
  }

  // 2. Order information: email, the REST gateway, billing details.
  await page.waitForURL(/\/checkout\/\d+\/order_information/, { timeout: 20000 });
  await setIfPresent(page, 'input[name*="[email]"]', 'guest.buyer@example.com');
  await setIfPresent(page, 'input[name*="[email_confirm]"]', 'guest.buyer@example.com');
  const rest = page.getByRole('radio', { name: /Cybersource Microform/i });
  await expect(rest).toHaveCount(1);
  await page.getByText('Credit card (Cybersource Microform)').click();
  await expect(rest).toBeChecked({ timeout: 10000 });

  // Billing fields — scoped to THIS gateway's add-payment-method pane (other
  // gateways' panes carry their own, hidden billing widgets).
  const pane = 'input[name*="add_payment_method"]';
  const country = page.locator('select[name*="add_payment_method"][name*="country_code"]').first();
  if (await country.count()) {
    if ((await country.inputValue().catch(() => '')) !== 'GB') {
      await country.selectOption('GB').catch(() => {});
      await page.waitForTimeout(2000);
    }
  }
  await fillVerified(page, `${pane}[name*="[given_name]"]`, 'Guest');
  await fillVerified(page, `${pane}[name*="[family_name]"]`, 'Buyer');
  await fillVerified(page, `${pane}[name*="[address_line1]"]`, '1 Test Street');
  await fillVerified(page, `${pane}[name*="[locality]"]`, 'London');
  await fillVerified(page, `${pane}[name*="[postal_code]"]`, 'EC1A 1BB');

  // 3. Type the 3DS test card into the hosted Microform iframes.
  const numberFrame = page.frameLocator('#cybersource-card-number iframe');
  await numberFrame.locator('input[name="number"]').fill(cardNumber, { timeout: 30000 });
  await page.locator('.cybersource-rest-expiry').fill('01 / 29');
  const cvvFrame = page.frameLocator('#cybersource-card-cvv iframe');
  await cvvFrame.locator('input[name="securityCode"]').fill('123');

  // 4. Pay. The JS tokenises, runs payer-auth setup + device data collection +
  //    the enrollment, then (frictionless) submits or (challenge) opens the
  //    step-up modal. Allow generous time for the Cardinal round trips.
  await page.getByRole('button', { name: /Pay and complete purchase/i }).first().click();
}

test('frictionless 3-D Secure checkout completes an order', async ({ page }) => {
  test.setTimeout(180000);
  await checkoutWithCard(page, '4000000000002701');
  await page.waitForURL(/\/checkout\/\d+\/complete/, { timeout: 120000 });
  await expect(page.getByText(/Thank you|order number|confirmation/i).first()).toBeVisible({ timeout: 15000 });
});

test('challenge 3-D Secure checkout completes after the step-up', async ({ page }) => {
  test.setTimeout(240000);
  await checkoutWithCard(page, '4000000000002503');

  // The step-up challenge opens in our modal iframe; the test ACS presents a
  // one-time-code form (the simulator accepts any/hinted code).
  const modal = page.locator('.cybersource-pa-overlay');
  await expect(modal).toBeVisible({ timeout: 60000 });
  const challenge = page.frameLocator('.cybersource-pa-frame');
  const code = challenge.locator('input:not([type="hidden"]):not([type="submit"]):not([type="button"])').first();
  await code.fill('1234', { timeout: 60000 });
  await challenge.locator('input[type="submit"], button').first().click();

  await page.waitForURL(/\/checkout\/\d+\/complete/, { timeout: 120000 });
  await expect(page.getByText(/Thank you|order number|confirmation/i).first()).toBeVisible({ timeout: 15000 });
});
