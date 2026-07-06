<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use CyberSource\Model\PtsV2PaymentsCapturesPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsRefundPost201Response;
use CyberSource\Model\PtsV2PaymentsVoidsPost201Response;
use CyberSource\Model\RiskV1AuthenticationsPost201Response;
use CyberSource\Model\RiskV1AuthenticationSetupsPost201Response;

/**
 * Thin, testable wrapper around the Cybersource REST SDK.
 *
 * Centralises HTTP-Signature authentication, host selection (test vs live) and
 * error translation so the payment gateway plugin depends on this contract
 * rather than newing-up SDK API classes inline. Requests are built by the
 * caller
 * as nested arrays using Cybersource's own JSON field names; the SDK serialises
 * them as-is.
 *
 * Every method throws
 * \Drupal\cybersource_rest\Exception\CybersourceApiException on a transport or
 * HTTP error (never the SDK's \CyberSource\ApiException).
 */
interface CybersourceApiClientInterface {

  /**
   * Generate a Flex Microform capture context (a client-init JWT).
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The GenerateCaptureContextRequest body (targetOrigins,
   *   allowedCardNetworks,
   *   clientVersion).
   *
   * @return string
   *   The capture-context JWT to hand to the Microform JS.
   */
  public function generateCaptureContext(string $mode, array $request): string;

  /**
   * Create (authorize, optionally capture) a payment.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The CreatePaymentRequest body.
   *
   * @return \CyberSource\Model\PtsV2PaymentsPost201Response
   *   The payment response.
   */
  public function createPayment(string $mode, array $request): PtsV2PaymentsPost201Response;

  /**
   * Capture a previously authorized payment.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The CapturePaymentRequest body.
   * @param string $paymentId
   *   The Cybersource payment id to capture.
   *
   * @return \CyberSource\Model\PtsV2PaymentsCapturesPost201Response
   *   The capture response.
   */
  public function capturePayment(string $mode, array $request, string $paymentId): PtsV2PaymentsCapturesPost201Response;

  /**
   * Refund a captured payment.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The RefundCaptureRequest body.
   * @param string $paymentId
   *   The Cybersource payment id to refund.
   *
   * @return \CyberSource\Model\PtsV2PaymentsRefundPost201Response
   *   The refund response.
   */
  public function refundPayment(string $mode, array $request, string $paymentId): PtsV2PaymentsRefundPost201Response;

  /**
   * Void (reverse) an authorized, uncaptured payment.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The VoidPaymentRequest body.
   * @param string $paymentId
   *   The Cybersource payment id to void.
   *
   * @return \CyberSource\Model\PtsV2PaymentsVoidsPost201Response
   *   The void response.
   */
  public function voidPayment(string $mode, array $request, string $paymentId): PtsV2PaymentsVoidsPost201Response;

  /**
   * Set up payer authentication (3-D Secure) for a card / transient token.
   *
   * Returns the Cardinal device-data-collection parameters (access token, DDC
   * URL, reference id) the browser needs before the enrollment check.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The PayerAuthSetupRequest body (tokenInformation.transientToken).
   *
   * @return \CyberSource\Model\RiskV1AuthenticationSetupsPost201Response
   *   The setup response.
   */
  public function setupPayerAuth(string $mode, array $request): RiskV1AuthenticationSetupsPost201Response;

  /**
   * Check payer-authentication enrollment (the 3-D Secure lookup).
   *
   * Frictionless outcomes are final in this response; a challenge outcome
   * carries the step-up URL + access token for the challenge iframe.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   * @param array<string, mixed> $request
   *   The CheckPayerAuthEnrollmentRequest body.
   *
   * @return \CyberSource\Model\RiskV1AuthenticationsPost201Response
   *   The enrollment response.
   */
  public function checkPayerAuthEnrollment(string $mode, array $request): RiskV1AuthenticationsPost201Response;

}
