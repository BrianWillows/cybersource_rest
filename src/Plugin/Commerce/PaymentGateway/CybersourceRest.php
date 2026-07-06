<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_log\LogStorageInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\cybersource_rest\CredentialProvider;
use Drupal\cybersource_rest\CybersourceApiClientInterface;
use Drupal\cybersource_rest\Exception\CybersourceApiException;
use Drupal\cybersource_rest\PluginForm\CybersourceRestForm;
use Drupal\cybersource_rest\TransientToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Cybersource REST / Flex Microform v2 payment gateway.
 *
 * The customer types into Cybersource-hosted Microform iframes (PAN + CVV never
 * touch this server), which produce a single-use transient token. We charge the
 * token server-side via the Cybersource REST API.
 *
 * API credentials (merchant id / keyId / shared secret) are NOT stored in site
 * config — they are resolved at runtime, by mode, from a private .yml file (see
 * \Drupal\cybersource_rest\CredentialProvider), so secrets never enter config
 * exports or git.
 */
#[CommercePaymentGateway(
  id: 'cybersource_rest',
  label: new TranslatableMarkup('Cybersource (REST Microform)'),
  display_label: new TranslatableMarkup('Credit / debit card'),
  forms: [
    'add-payment-method' => CybersourceRestForm::class,
  ],
  js_library: 'cybersource_rest/form',
  payment_method_types: ['cybersource_rest_credit_card'],
  credit_card_types: [
    'amex', 'dinersclub', 'discover', 'jcb', 'maestro', 'mastercard', 'visa',
  ],
  requires_billing_information: TRUE,
)]
class CybersourceRest extends OnsitePaymentGatewayBase implements CybersourceRestInterface {

  /**
   * The Cybersource REST API client.
   *
   * @var \Drupal\cybersource_rest\CybersourceApiClientInterface
   */
  protected CybersourceApiClientInterface $apiClient;

  /**
   * The credential provider.
   *
   * @var \Drupal\cybersource_rest\CredentialProvider
   */
  protected CredentialProvider $credentials;

  /**
   * The module logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The commerce_log entity storage.
   *
   * @var \Drupal\commerce_log\LogStorageInterface
   */
  protected LogStorageInterface $logStorage;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Cybersource card-network names keyed by Commerce credit card type id.
   */
  protected const NETWORK_MAP = [
    'visa' => 'VISA',
    'mastercard' => 'MASTERCARD',
    'maestro' => 'MAESTRO',
    'amex' => 'AMEX',
    'discover' => 'DISCOVER',
    'dinersclub' => 'DINERSCLUB',
    'jcb' => 'JCB',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('cybersource_rest.api_client');
    $instance->credentials = $container->get('cybersource_rest.credentials');
    $instance->logger = $container->get('logger.channel.cybersource_rest');
    /** @var \Drupal\commerce_log\LogStorageInterface $log_storage */
    $log_storage = $container->get('entity_type.manager')->getStorage('commerce_log');
    $instance->logStorage = $log_storage;
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return array<string, mixed>
   */
  public function defaultConfiguration(): array {
    return [
      'transaction_type' => 'authorization',
      'log_api_calls' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * Whether the gateway captures funds immediately (vs authorize only).
   */
  protected function capturesByDefault(): bool {
    return ($this->configuration['transaction_type'] ?? 'authorization') === 'sale';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Credentials are read from a FIXED private file (private://keys/cybersource_rest.yml)
    // and are deliberately NOT configurable here — a configurable path would let
    // an admin point the gateway at attacker-controlled credentials. This panel
    // only reports whether that file is present and which modes it covers.
    $form['credentials_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Credentials file'),
      '#markup' => $this->credentialsStatus(),
    ];

    if ($this->getMode() === 'test') {
      $form['test_mode_warning'] = [
        '#type' => 'item',
        '#markup' => '<strong>' . $this->t('⚠ TEST mode: payments are charged against the Cybersource sandbox, not the customer. Never leave a production gateway in test mode.') . '</strong>',
      ];
    }

    $form['transaction_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Transaction type'),
      '#options' => [
        'authorization' => $this->t('Authorization only (capture later)'),
        'sale' => $this->t('Sale (authorize and capture)'),
      ],
      '#default_value' => $this->configuration['transaction_type'],
    ];

    $form['log_api_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API requests and responses to the site log'),
      '#description' => $this->t('Diagnostic only — leave OFF in production. When on, the verbose log includes the billing name, address and email (personal data); it never includes the card number or the transient payment token, which are redacted. The order audit log (decision/reason only) is always written regardless of this setting.'),
      '#default_value' => $this->configuration['log_api_calls'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The by-ref $form type must stay invariant with the parent's plain array.
   *
   * @phpstan-param array<mixed, mixed> $form
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if (!in_array($values['transaction_type'] ?? '', ['authorization', 'sale'], TRUE)) {
      $form_state->setError($form['transaction_type'], $this->t('Invalid transaction type.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * The by-ref $form type must stay invariant with the parent's plain array.
   *
   * @phpstan-param array<mixed, mixed> $form
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['log_api_calls'] = (bool) $values['log_api_calls'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generateCaptureContext(): string {
    $request = $this->requestStack->getCurrentRequest();
    $origin = $request ? 'https://' . $request->getHttpHost() : '';
    $context = [
      'targetOrigins' => array_filter([$origin]),
      'allowedCardNetworks' => $this->allowedCardNetworks(),
      'clientVersion' => 'v2',
    ];
    return $this->apiClient->generateCaptureContext($this->getMode(), $context);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $payment_details
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details): void {
    if (empty($payment_details['cybersource_token'])) {
      throw new InvalidRequestException('Cybersource Microform did not return a transient token.');
    }
    try {
      $token = TransientToken::fromJwt((string) $payment_details['cybersource_token']);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidRequestException('The Cybersource transient token is malformed.', 0, $e);
    }

    // Display metadata only (the charge is validated by Cybersource): brand from
    // the BIN, masked number, and the expiry Cybersource echoed into the token
    // from the customer's entry.
    $payment_method->set('card_type', $this->cardTypeFromBin($token->bin));
    $payment_method->set('card_number', $token->last4());
    $payment_method->set('card_exp_month', $token->expirationMonth);
    $payment_method->set('card_exp_year', $token->expirationYear);
    $payment_method->set('transient_token', $token->jwt);

    // The transient token expires in ~15 minutes; keep a small buffer and never
    // expose it as a reusable saved card.
    $payment_method->setExpiresTime($this->time->getRequestTime() + 840);
    $payment_method->setReusable(FALSE);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method): void {
    // The transient token is single-use and self-expiring at Cybersource; there
    // is no stored instrument to revoke, so just delete the local record.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   *
   * The $capture argument is ignored: whether funds are captured is governed by
   * the gateway's "transaction_type" setting (sale = capture, authorization =
   * authorize only), so the behaviour cannot drift from what the merchant chose.
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);
    $order = $payment->getOrder();

    $token = (string) $payment_method->get('transient_token')->value;
    if ($token === '') {
      throw new InvalidRequestException('The payment method has no Cybersource transient token.');
    }

    $amount = $payment->getAmount();
    $capture = $this->capturesByDefault();
    $request = [
      'clientReferenceInformation' => ['code' => (string) $order->id()],
      'processingInformation' => [
        'capture' => $capture,
        'commerceIndicator' => 'internet',
      ],
      'orderInformation' => [
        'amountDetails' => [
          'totalAmount' => $amount->getNumber(),
          'currency' => $amount->getCurrencyCode(),
        ],
        'billTo' => $this->billTo($payment_method, $order),
      ],
      'tokenInformation' => ['transientTokenJwt' => $token],
    ];

    try {
      $this->maybeLogApi('createPayment request', $request);
      $response = $this->apiClient->createPayment($this->getMode(), $request);
    }
    catch (CybersourceApiException $e) {
      // The single-use token is (likely) spent even on error, so clear it.
      $this->clearTransientToken($payment_method);
      // An HTTP status of 0 means we got NO response: the charge may actually
      // have been created at Cybersource, so flag it for manual reconciliation
      // rather than pretend it definitely failed.
      $ambiguous = $e->getHttpStatus() === 0;
      $this->logResponse($order, sprintf(
        'Payment request failed (%s): %s (%s).',
        $ambiguous ? 'NO RESPONSE — outcome uncertain, a charge may exist; reconcile in the Business Center' : 'HTTP ' . $e->getHttpStatus(),
        $e->getMessage(),
        $e->getReason() ?: 'no reason'
      ));
      throw new InvalidRequestException($e->getMessage(), 0, $e);
    }

    // The token is single-use and now consumed by Cybersource whatever the
    // decision; drop it before branching so a spent token never lingers on a
    // decline.
    $this->clearTransientToken($payment_method);

    $status = strtoupper((string) $response->getStatus());
    $remote_id = (string) $response->getId();
    $this->logResponse($order, sprintf('mode=%s status=%s transaction_id=%s', $this->getMode(), $status, $remote_id));

    // Only a clean AUTHORIZED is a final success. AUTHORIZED_PENDING_REVIEW means
    // Decision Manager is still reviewing: accept it but HOLD it as a pending
    // authorization — never auto-complete a sale that is still under review.
    $review = $status === 'AUTHORIZED_PENDING_REVIEW';
    if ($status !== 'AUTHORIZED' && !$review) {
      $this->throwForStatus($status, $response);
    }

    $payment->setRemoteId($remote_id);
    $payment->setRemoteState($status);
    // A captured sale completes; an authorisation (or a held review) waits.
    $payment->setState(($capture && !$review) ? 'completed' : 'authorization');
    $payment->save();
  }

  /**
   * Clear a consumed transient token from a stored payment method.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   */
  protected function clearTransientToken(PaymentMethodInterface $payment_method): void {
    if ($payment_method->hasField('transient_token') && (string) $payment_method->get('transient_token')->value !== '') {
      $payment_method->set('transient_token', '');
      $payment_method->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    $amount = $amount ?: $payment->getAmount();
    $request = [
      'orderInformation' => [
        'amountDetails' => [
          'totalAmount' => $amount->getNumber(),
          'currency' => $amount->getCurrencyCode(),
        ],
      ],
    ];
    try {
      $response = $this->apiClient->capturePayment($payment->getPaymentGatewayMode(), $request, (string) $payment->getRemoteId());
    }
    catch (CybersourceApiException $e) {
      throw new PaymentGatewayException($e->getMessage(), 0, $e);
    }
    // A capture settles asynchronously: PENDING/TRANSMITTED are the success
    // statuses. Anything else means the money was NOT captured.
    $status = strtoupper((string) $response->getStatus());
    if (!in_array($status, ['PENDING', 'TRANSMITTED'], TRUE)) {
      $this->throwForStatus($status, $response);
    }
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    $order = $payment->getOrder();
    $request = ['clientReferenceInformation' => ['code' => (string) ($order ? $order->id() : $payment->id())]];
    try {
      $response = $this->apiClient->voidPayment($payment->getPaymentGatewayMode(), $request, (string) $payment->getRemoteId());
    }
    catch (CybersourceApiException $e) {
      throw new PaymentGatewayException($e->getMessage(), 0, $e);
    }
    // A 201 only means a void resource was created — confirm it actually voided
    // before we mark the authorization reversed.
    $status = strtoupper((string) $response->getStatus());
    if (!in_array($status, ['VOIDED', 'PENDING', 'TRANSMITTED'], TRUE)) {
      $this->throwForStatus($status, $response);
    }
    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);
    $request = [
      'orderInformation' => [
        'amountDetails' => [
          'totalAmount' => $amount->getNumber(),
          'currency' => $amount->getCurrencyCode(),
        ],
      ],
    ];
    try {
      $response = $this->apiClient->refundPayment($payment->getPaymentGatewayMode(), $request, (string) $payment->getRemoteId());
    }
    catch (CybersourceApiException $e) {
      throw new PaymentGatewayException($e->getMessage(), 0, $e);
    }
    // Confirm Cybersource accepted the refund (it settles asynchronously) before
    // recording the money as refunded.
    $status = strtoupper((string) $response->getStatus());
    if (!in_array($status, ['PENDING', 'TRANSMITTED'], TRUE)) {
      $this->throwForStatus($status, $response);
    }

    $old_refunded = $payment->getRefundedAmount();
    $new_refunded = $old_refunded->add($amount);
    $payment->setRefundedAmount($new_refunded);
    $payment->setState($new_refunded->lessThan($payment->getAmount()) ? 'partially_refunded' : 'refunded');
    $payment->save();
  }

  /**
   * Throw the most specific Commerce exception for a non-success status.
   *
   * @param string $status
   *   The Cybersource status (uppercased).
   * @param object $response
   *   The SDK response (has getErrorInformation()).
   */
  protected function throwForStatus(string $status, object $response): void {
    $reason = '';
    $message = '';
    if (method_exists($response, 'getErrorInformation') && ($info = $response->getErrorInformation())) {
      $reason = (string) $info->getReason();
      $message = (string) $info->getMessage();
    }
    $detail = sprintf('Cybersource status "%s"%s%s.', $status, $reason !== '' ? ', reason ' . $reason : '', $message !== '' ? ': ' . $message : '');
    if (in_array($status, ['DECLINED', 'AUTHORIZED_RISK_DECLINED', 'INVALID_REQUEST'], TRUE)) {
      if ($status === 'INVALID_REQUEST') {
        throw new InvalidRequestException($detail);
      }
      throw new HardDeclineException($detail);
    }
    throw new PaymentGatewayException($detail);
  }

  /**
   * Render a human-readable status of the configured credentials file.
   */
  protected function credentialsStatus(): string {
    try {
      $modes = $this->credentials->configuredModes();
      return (string) $this->t('@uri is readable. Configured modes: @modes.', [
        '@uri' => CredentialProvider::CREDENTIALS_URI,
        '@modes' => $modes ? implode(', ', $modes) : $this->t('(none)'),
      ]);
    }
    catch (\Throwable $e) {
      return (string) $this->t('⚠ @uri could not be loaded. Ensure the private filesystem is configured and the file is present and valid YAML.', [
        '@uri' => CredentialProvider::CREDENTIALS_URI,
      ]);
    }
  }

  /**
   * The Cybersource card-network names allowed for this gateway.
   *
   * @return string[]
   *   Uppercase Cybersource network identifiers.
   */
  protected function allowedCardNetworks(): array {
    $networks = [];
    foreach ($this->getCreditCardTypes() as $card_type) {
      $id = $card_type->getId();
      if (isset(self::NETWORK_MAP[$id])) {
        $networks[] = self::NETWORK_MAP[$id];
      }
    }
    return $networks ?: array_values(self::NETWORK_MAP);
  }

  /**
   * Detect the Commerce credit card type id from a BIN.
   *
   * @param string $bin
   *   The card BIN (leading digits) from the transient token.
   *
   * @return string
   *   The Commerce credit card type id, or '' if undetectable.
   */
  protected function cardTypeFromBin(string $bin): string {
    if ($bin === '') {
      return '';
    }
    foreach (CreditCard::getTypes() as $type) {
      foreach ($type->getNumberPrefixes() as $prefix) {
        if (CreditCard::matchPrefix($bin, $prefix)) {
          return $type->getId();
        }
      }
    }
    return '';
  }

  /**
   * Build the Cybersource billTo block from the payment method / order.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method (carries the billing profile).
   * @param \Drupal\commerce_order\Entity\OrderInterface|null $order
   *   The order (for the email).
   *
   * @return array<string, string>
   *   The billTo fields, omitting any that are empty.
   */
  protected function billTo(PaymentMethodInterface $payment_method, ?OrderInterface $order): array {
    $fields = [];
    $profile = $payment_method->getBillingProfile();
    if ($profile && !$profile->get('address')->isEmpty()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $profile->get('address')->first();
      $fields = [
        'firstName' => (string) $address->getGivenName(),
        'lastName' => (string) $address->getFamilyName(),
        'address1' => (string) $address->getAddressLine1(),
        'address2' => (string) $address->getAddressLine2(),
        'locality' => (string) $address->getLocality(),
        'administrativeArea' => (string) $address->getAdministrativeArea(),
        'postalCode' => (string) $address->getPostalCode(),
        'country' => (string) $address->getCountryCode(),
      ];
    }
    $fields['email'] = (string) ($order ? $order->getEmail() : '');
    return array_filter($fields, static fn ($v) => $v !== '');
  }

  /**
   * Write an order audit-log line (decision/status only, never card data).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface|null $order
   *   The order, if available.
   * @param string $message
   *   The message.
   */
  protected function logResponse(?OrderInterface $order, string $message): void {
    if ($order === NULL) {
      return;
    }
    try {
      $this->logStorage->generate($order, 'cybersource_rest_response', ['message' => $message])->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to write commerce_log entry: @e', ['@e' => $e->getMessage()]);
    }
  }

  /**
   * Log an API request/response payload when verbose logging is enabled.
   *
   * The single-use transient token is redacted: it is a live payment instrument
   * with no diagnostic value, so it must never reach the site log.
   *
   * @param string $label
   *   What is being logged.
   * @param array<string, mixed> $data
   *   The payload (may contain billing PII; only logged when explicitly enabled).
   */
  protected function maybeLogApi(string $label, array $data): void {
    if (empty($this->configuration['log_api_calls'])) {
      return;
    }
    if (isset($data['tokenInformation']['transientTokenJwt'])) {
      $data['tokenInformation']['transientTokenJwt'] = '[redacted]';
    }
    $this->logger->notice('@label: <pre>@data</pre>', [
      '@label' => $label,
      '@data' => print_r($data, TRUE),
    ]);
  }

}
