<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;

/**
 * Provides the interface for the Cybersource REST / Microform payment gateway.
 */
interface CybersourceRestInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface, SupportsVoidsInterface {

  /**
   * Generate a Flex Microform capture context (client-init JWT).
   *
   * The capture context is scoped to the request origin and the gateway's
   * allowed card networks, not to a specific order.
   *
   * @return string
   *   The capture-context JWT to hand to the Microform JS.
   */
  public function generateCaptureContext(): string;

  /**
   * Whether 3-D Secure (payer authentication) is enabled on this gateway.
   */
  public function isPayerAuthEnabled(): bool;

  /**
   * Set up payer authentication for a tokenised card (3-D Secure step 1).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid.
   * @param string $transient_token
   *   The Microform transient-token JWT.
   *
   * @return array{accessToken: string, deviceDataCollectionUrl: string, referenceId: string}
   *   The device-data-collection parameters for the browser.
   *
   * @throws \Drupal\cybersource_rest\Exception\CybersourceApiException
   */
  public function setupPayerAuthentication(OrderInterface $order, string $transient_token): array;

  /**
   * Run the payer-authentication enrollment check (3-D Secure lookup).
   *
   * Amount, currency and billing data are taken from the ORDER server-side;
   * only the device fingerprint fields come from the browser. On success the
   * authentication result is stashed server-side (session-bound) for
   * createPayment() to consume — the browser never carries CAVV/ECI values.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid.
   * @param string $transient_token
   *   The Microform transient-token JWT.
   * @param string $reference_id
   *   The referenceId from setupPayerAuthentication().
   * @param array<string, mixed> $browser
   *   The browser fingerprint fields collected client-side.
   * @param array<string, mixed> $billing
   *   The customer-typed billing fields from the checkout form (the order's
   *   saved profile, when present, takes precedence server-side).
   *
   * @return array<string, mixed>
   *   One of:
   *   - ['status' => 'authenticated'] (frictionless success),
   *   - ['status' => 'unavailable'] (proceed, no liability shift),
   *   - ['status' => 'challenge', 'stepUpUrl' => ..., 'accessToken' => ...,
   *     'width' => ..., 'height' => ...],
   *   - ['status' => 'failed'] (authentication failed — do not pay).
   *
   * @throws \Drupal\cybersource_rest\Exception\CybersourceApiException
   */
  public function enrollPayerAuthentication(OrderInterface $order, string $transient_token, string $reference_id, array $browser, array $billing = []): array;

}
