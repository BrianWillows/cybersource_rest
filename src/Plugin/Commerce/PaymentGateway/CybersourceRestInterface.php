<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway;

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

}
