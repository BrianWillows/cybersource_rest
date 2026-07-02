<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use CyberSource\Api\CaptureApi;
use CyberSource\Api\MicroformIntegrationApi;
use CyberSource\Api\PaymentsApi;
use CyberSource\Api\RefundApi;
use CyberSource\Api\VoidApi;
use CyberSource\ApiClient;
use CyberSource\ApiException;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;
use CyberSource\Model\CapturePaymentRequest;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\Model\GenerateCaptureContextRequest;
use CyberSource\Model\PtsV2PaymentsCapturesPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsRefundPost201Response;
use CyberSource\Model\PtsV2PaymentsVoidsPost201Response;
use CyberSource\Model\Ptsv2paymentsClientReferenceInformation;
use CyberSource\Model\RefundPaymentRequest;
use CyberSource\Model\VoidPaymentRequest;
use Drupal\cybersource_rest\Exception\CybersourceApiException;
use Psr\Log\LoggerInterface;

/**
 * Default Cybersource REST client: authenticates and calls the REST SDK.
 *
 * Builds an HTTP-Signature-authenticated SDK ApiClient per mode from the
 * private credentials file, and translates the SDK's transport exceptions into
 * the module's own CybersourceApiException.
 */
final class CybersourceApiClient implements CybersourceApiClientInterface {

  /**
   * REST API hosts per mode.
   */
  private const HOSTS = [
    'test' => 'apitest.cybersource.com',
    'live' => 'api.cybersource.com',
  ];

  /**
   * Built SDK clients, keyed by mode.
   *
   * @var array<string, \CyberSource\ApiClient>
   */
  protected array $clients = [];

  public function __construct(
    protected CredentialProvider $credentials,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generateCaptureContext(string $mode, array $request): string {
    $api = new MicroformIntegrationApi($this->apiClient($mode));
    try {
      [$jwt] = $api->generateCaptureContext(new GenerateCaptureContextRequest($request));
    }
    catch (ApiException $e) {
      throw $this->translate($e, 'generate the Microform capture context');
    }
    return (string) $jwt;
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(string $mode, array $request): PtsV2PaymentsPost201Response {
    $api = new PaymentsApi($this->apiClient($mode));
    try {
      [$response] = $api->createPayment(new CreatePaymentRequest($this->normalize($request)));
    }
    catch (ApiException $e) {
      throw $this->translate($e, 'create the payment');
    }
    assert($response instanceof PtsV2PaymentsPost201Response);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(string $mode, array $request, string $paymentId): PtsV2PaymentsCapturesPost201Response {
    $api = new CaptureApi($this->apiClient($mode));
    try {
      [$response] = $api->capturePayment(new CapturePaymentRequest($request), $paymentId);
    }
    catch (ApiException $e) {
      throw $this->translate($e, 'capture the payment');
    }
    assert($response instanceof PtsV2PaymentsCapturesPost201Response);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $mode, array $request, string $paymentId): PtsV2PaymentsRefundPost201Response {
    $api = new RefundApi($this->apiClient($mode));
    try {
      [$response] = $api->refundPayment(new RefundPaymentRequest($request), $paymentId);
    }
    catch (ApiException $e) {
      throw $this->translate($e, 'refund the payment');
    }
    assert($response instanceof PtsV2PaymentsRefundPost201Response);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(string $mode, array $request, string $paymentId): PtsV2PaymentsVoidsPost201Response {
    $api = new VoidApi($this->apiClient($mode));
    try {
      [$response] = $api->voidPayment(new VoidPaymentRequest($this->normalize($request)), $paymentId);
    }
    catch (ApiException $e) {
      throw $this->translate($e, 'void the payment');
    }
    assert($response instanceof PtsV2PaymentsVoidsPost201Response);
    return $response;
  }

  /**
   * Upgrade clientReferenceInformation from a plain array to its SDK model.
   *
   * The SDK's SdkTracker calls $request->getClientReferenceInformation()->getPartner()
   * to inject a developer id before the request is sent, so this one sub-object
   * must be a real model (with getPartner()/setPartner()) rather than the plain
   * array we build everywhere else. The remaining sub-arrays are serialised
   * as-is and need no upgrading.
   *
   * @param array<string, mixed> $request
   *   The request body.
   *
   * @return array<string, mixed>
   *   The request body with clientReferenceInformation as a model object.
   */
  protected function normalize(array $request): array {
    if (isset($request['clientReferenceInformation']) && is_array($request['clientReferenceInformation'])) {
      $request['clientReferenceInformation'] = new Ptsv2paymentsClientReferenceInformation($request['clientReferenceInformation']);
    }
    return $request;
  }

  /**
   * Build (and cache) an authenticated SDK client for a mode.
   *
   * @param string $mode
   *   The gateway mode: 'test' or 'live'.
   *
   * @return \CyberSource\ApiClient
   *   The HTTP-Signature-authenticated SDK client.
   */
  protected function apiClient(string $mode): ApiClient {
    $mode = strtolower($mode) === 'live' ? 'live' : 'test';
    if (isset($this->clients[$mode])) {
      return $this->clients[$mode];
    }
    $profile = $this->credentials->getProfile($mode);
    $host = self::HOSTS[$mode];

    $merchant_config = new MerchantConfiguration();
    $merchant_config->setMerchantID($profile['merchant_id']);
    $merchant_config->setApiKeyID($profile['key_id']);
    $merchant_config->setSecretKey($profile['shared_secret']);
    $merchant_config->setAuthenticationType('HTTP_SIGNATURE');
    $merchant_config->setHost($host);

    $config = new Configuration();
    $config->setHost($host);

    return $this->clients[$mode] = new ApiClient($config, $merchant_config);
  }

  /**
   * Translate an SDK ApiException into a module CybersourceApiException.
   *
   * @param \CyberSource\ApiException $e
   *   The SDK exception.
   * @param string $action
   *   A short description of the attempted action (for the log message).
   *
   * @return \Drupal\cybersource_rest\Exception\CybersourceApiException
   *   The wrapped exception.
   */
  protected function translate(ApiException $e, string $action): CybersourceApiException {
    $status = (int) $e->getCode();
    $reason = '';
    $body = $e->getResponseBody();
    if (is_object($body)) {
      $reason = (string) ($body->reason ?? '');
    }
    elseif (is_string($body) && $body !== '') {
      $decoded = json_decode($body, TRUE);
      $reason = is_array($decoded) ? (string) ($decoded['reason'] ?? '') : '';
    }
    $this->logger->error('Cybersource REST failed to @action (HTTP @status@reason): @message', [
      '@action' => $action,
      '@status' => $status ?: '?',
      '@reason' => $reason !== '' ? ' ' . $reason : '',
      '@message' => $e->getMessage(),
    ]);
    return new CybersourceApiException(sprintf('Failed to %s.', $action), $status, $reason, $e);
  }

}
