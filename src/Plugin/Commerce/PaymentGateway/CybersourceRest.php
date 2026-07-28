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
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Url;
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
 * config — they are resolved at runtime, by mode, from settings.php (see
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
   * The private tempstore for payer-authentication results.
   *
   * Session-bound on purpose: a 3-D Secure result belongs to the customer who
   * authenticated, and it must never be readable or replayable from another
   * session.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected PrivateTempStore $paStore;

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
   * Cybersource numeric card-type codes keyed by Commerce credit card type id.
   *
   * Used for paymentInformation.card.type in the payer-auth enrollment check.
   */
  protected const TYPE_CODES = [
    'visa' => '001',
    'mastercard' => '002',
    'amex' => '003',
    'discover' => '004',
    'dinersclub' => '005',
    'jcb' => '007',
    'maestro' => '042',
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
    /** @var \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore_factory */
    $tempstore_factory = $container->get('tempstore.private');
    $instance->paStore = $tempstore_factory->get('cybersource_rest');
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
      'payer_auth' => FALSE,
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

    // Credentials come from settings.php (an inline array or a file path;
    // see CredentialProvider) and are deliberately NOT configurable here:
    // keeping the secrets and their location out of config means they cannot
    // leak through config export/sync, and changing what the gateway
    // authenticates with always requires filesystem (deployment) access
    // rather than a Drupal role. This panel only reports whether credentials
    // are present and which modes they cover.
    $form['credentials_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Credentials'),
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

    $form['payer_auth'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3-D Secure (Payer Authentication)'),
      '#description' => $this->t('Authenticates the cardholder (frictionless or challenge) before the charge, as required for UK/EU Strong Customer Authentication, and shifts fraud liability to the issuer for authenticated transactions. Payer Authentication must be enabled ("boarded") on your Cybersource merchant account — checkout card payments will fail while this is ticked without it. Test it against the sandbox first; see README.'),
      '#default_value' => $this->configuration['payer_auth'],
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
      $this->configuration['payer_auth'] = (bool) $values['payer_auth'];
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
   */
  public function isPayerAuthEnabled(): bool {
    return !empty($this->configuration['payer_auth']);
  }

  /**
   * {@inheritdoc}
   */
  public function setupPayerAuthentication(OrderInterface $order, string $transient_token): array {
    $response = $this->apiClient->setupPayerAuth($this->getMode(), [
      'clientReferenceInformation' => ['code' => (string) $order->id()],
      // The RISK endpoints take the token's jti claim, not the full JWT.
      'tokenInformation' => ['transientToken' => $this->tokenReference($transient_token)],
    ]);
    $info = $response->getConsumerAuthenticationInformation();
    return [
      'accessToken' => (string) $info->getAccessToken(),
      'deviceDataCollectionUrl' => (string) $info->getDeviceDataCollectionUrl(),
      'referenceId' => (string) $info->getReferenceId(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function enrollPayerAuthentication(OrderInterface $order, string $transient_token, string $reference_id, array $browser, array $billing = []): array {
    $amount = $order->getTotalPrice();
    if ($amount === NULL) {
      throw new InvalidRequestException('The order has no total to authenticate.');
    }

    // Card expiry/type for the lookup come from the transient token — server
    // data, not client input.
    try {
      $token = TransientToken::fromJwt($transient_token);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidRequestException('The Cybersource transient token is malformed.', 0, $e);
    }
    $card = [
      'expirationMonth' => $token->expirationMonth,
      'expirationYear' => $token->expirationYear,
    ];
    $type_code = self::TYPE_CODES[$this->cardTypeFromBin($token->bin)] ?? '';
    if ($type_code !== '') {
      $card['type'] = $type_code;
    }

    $request = [
      'clientReferenceInformation' => ['code' => (string) $order->id()],
      'consumerAuthenticationInformation' => [
        'referenceId' => $reference_id,
        'returnUrl' => Url::fromRoute('cybersource_rest.payer_auth_return', [], ['absolute' => TRUE])->toString(),
        'deviceChannel' => 'BROWSER',
        // 500x600 challenge window (code 03) — matches the JS modal.
        'acsWindowSize' => '03',
      ],
      'orderInformation' => [
        'amountDetails' => [
          'totalAmount' => $amount->getNumber(),
          'currency' => $amount->getCurrencyCode(),
        ],
        'billTo' => $this->billToForEnrollment($order, $billing),
      ],
      'paymentInformation' => ['card' => $card],
      // The RISK endpoints take the token's jti claim, not the full JWT.
      'tokenInformation' => ['transientToken' => $this->tokenReference($transient_token)],
      'deviceInformation' => $this->deviceInformation($browser),
    ];

    $this->maybeLogApi('payer-auth enrollment request', $request);
    $response = $this->apiClient->checkPayerAuthEnrollment($this->getMode(), $request);

    $status = strtoupper((string) $response->getStatus());
    $info = $response->getConsumerAuthenticationInformation();
    $pares = strtoupper((string) $info->getParesStatus());
    $this->logResponse($order, sprintf('3DS enrollment: status=%s paresStatus=%s veresEnrolled=%s', $status, $pares, (string) $info->getVeresEnrolled()));

    // Base result stashed for createPayment(). The CAVV/ECI values NEVER go to
    // the browser: they stay server-side, bound to this session, this order and
    // this exact token.
    $result = [
      'order_id' => (string) $order->id(),
      'token_hash' => hash('sha256', $transient_token),
      'authenticationTransactionId' => (string) $info->getAuthenticationTransactionId(),
      'cavv' => (string) $info->getCavv(),
      'ucafAuthenticationData' => (string) $info->getUcafAuthenticationData(),
      'ucafCollectionIndicator' => (string) $info->getUcafCollectionIndicator(),
      'commerceIndicator' => (string) $info->getEcommerceIndicator(),
      'eci' => (string) $info->getEci(),
      'xid' => (string) $info->getXid(),
      'specificationVersion' => (string) $info->getSpecificationVersion(),
      'directoryServerTransactionId' => (string) $info->getDirectoryServerTransactionId(),
      'paresStatus' => $pares,
    ];

    if ($status === 'PENDING_AUTHENTICATION') {
      $result['outcome'] = 'challenge_pending';
      $this->paStore->set($this->paKey($order), $result);
      // Two documented challenge shapes exist: the Cardinal step-up wrapper
      // (stepUpUrl + a JWT access token) and the raw EMV 3DS CReq flow
      // (acsUrl + pareq). Which one the response carries depends on the
      // merchant account; the JS handles both.
      return [
        'status' => 'challenge',
        'stepUpUrl' => (string) $info->getStepUpUrl(),
        'accessToken' => (string) $info->getAccessToken(),
        'acsUrl' => (string) $info->getAcsUrl(),
        'pareq' => (string) $info->getPareq(),
        'width' => 500,
        'height' => 600,
      ];
    }

    if ($status === 'AUTHENTICATION_SUCCESSFUL') {
      // paresStatus Y (authenticated) or I (informational/exemption) carry
      // authentication data; U/A without a CAVV is "attempted/unavailable" —
      // the payment may proceed but without a liability shift.
      $authenticated = $result['cavv'] !== '' || $result['ucafAuthenticationData'] !== '';
      $result['outcome'] = $authenticated ? 'authenticated' : 'unavailable';
      $this->paStore->set($this->paKey($order), $result);
      return ['status' => $authenticated ? 'authenticated' : 'unavailable'];
    }

    // AUTHENTICATION_FAILED and anything unrecognised: fail closed. No stash —
    // a subsequent createPayment() cannot proceed.
    $this->paStore->delete($this->paKey($order));
    return ['status' => 'failed'];
  }

  /**
   * The tempstore key for an order's payer-authentication result.
   */
  protected function paKey(OrderInterface $order): string {
    return 'payer_auth:' . $order->id();
  }

  /**
   * The token reference (jti) the payer-auth risk endpoints expect.
   *
   * @param string $transient_token
   *   The full transient-token JWT.
   *
   * @return string
   *   The jti claim.
   *
   * @throws \Drupal\commerce_payment\Exception\InvalidRequestException
   *   If the token is malformed or carries no jti.
   */
  protected function tokenReference(string $transient_token): string {
    try {
      $token = TransientToken::fromJwt($transient_token);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidRequestException('The Cybersource transient token is malformed.', 0, $e);
    }
    if ($token->jti === '') {
      throw new InvalidRequestException('The Cybersource transient token has no jti reference.');
    }
    return $token->jti;
  }

  /**
   * Build the billTo block for the payer-auth enrollment check.
   *
   * The enrollment runs BEFORE the order-information step is submitted, so the
   * customer-typed billing fields arrive from the browser (as in any
   * JS-orchestrated 3-D Secure integration — they are cardholder-entered by
   * definition). Each value is length-capped server-side, and anything already
   * saved on the ORDER (an existing billing profile, the order email) takes
   * precedence over the client copy. The authoritative amount/currency never
   * come from the client, and the AUTHORIZATION's billTo is always built from
   * the saved profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array<string, mixed> $billing
   *   The billing fields posted by our checkout JS.
   *
   * @return array<string, string>
   *   The billTo fields, omitting any that are empty.
   */
  protected function billToForEnrollment(OrderInterface $order, array $billing): array {
    $clean = static function ($value, int $max): string {
      $value = is_scalar($value) ? trim((string) $value) : '';
      return mb_substr($value, 0, $max);
    };
    $fields = [
      'firstName' => $clean($billing['firstName'] ?? '', 60),
      'lastName' => $clean($billing['lastName'] ?? '', 60),
      'address1' => $clean($billing['address1'] ?? '', 60),
      'address2' => $clean($billing['address2'] ?? '', 60),
      'locality' => $clean($billing['locality'] ?? '', 50),
      'administrativeArea' => $clean($billing['administrativeArea'] ?? '', 20),
      'postalCode' => $clean($billing['postalCode'] ?? '', 10),
      'country' => strtoupper($clean($billing['country'] ?? '', 2)),
    ];
    $profile = $order->getBillingProfile();
    if ($profile && !$profile->get('address')->isEmpty()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $profile->get('address')->first();
      $saved = array_filter([
        'firstName' => (string) $address->getGivenName(),
        'lastName' => (string) $address->getFamilyName(),
        'address1' => (string) $address->getAddressLine1(),
        'address2' => (string) $address->getAddressLine2(),
        'locality' => (string) $address->getLocality(),
        'administrativeArea' => (string) $address->getAdministrativeArea(),
        'postalCode' => (string) $address->getPostalCode(),
        'country' => (string) $address->getCountryCode(),
      ], static fn ($v) => $v !== '');
      $fields = $saved + $fields;
    }
    $email = (string) $order->getEmail();
    if ($email === '') {
      $candidate = $clean($billing['email'] ?? '', 254);
      $email = filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }
    $fields['email'] = $email;
    return array_filter($fields, static fn ($v) => $v !== '');
  }

  /**
   * Sanitise the browser fingerprint fields for the enrollment check.
   *
   * These are client-supplied by nature (they describe the browser), so each
   * one is validated/clamped server-side; the IP, user agent and accept header
   * come from the request, not from the client payload.
   *
   * @param array<string, mixed> $browser
   *   The raw browser fields posted by our JS.
   *
   * @return array<string, string>
   *   The deviceInformation block.
   */
  protected function deviceInformation(array $browser): array {
    $request = $this->requestStack->getCurrentRequest();
    $int = static function ($value, int $min, int $max, int $fallback): string {
      $v = is_numeric($value) ? (int) $value : $fallback;
      return (string) max($min, min($max, $v));
    };
    $bool = static fn ($value): string => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    $language = (string) ($browser['language'] ?? '');
    if (!preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $language)) {
      $language = 'en';
    }
    $device = [
      'ipAddress' => (string) ($request ? $request->getClientIp() : ''),
      'httpAcceptBrowserValue' => (string) ($request ? $request->headers->get('Accept', '*/*') : '*/*'),
      'httpAcceptContent' => (string) ($request ? $request->headers->get('Accept', '*/*') : '*/*'),
      'userAgentBrowserValue' => (string) ($request ? $request->headers->get('User-Agent', '') : ''),
      'httpBrowserLanguage' => $language,
      'httpBrowserColorDepth' => $int($browser['colorDepth'] ?? NULL, 1, 48, 24),
      'httpBrowserScreenHeight' => $int($browser['screenHeight'] ?? NULL, 0, 20000, 0),
      'httpBrowserScreenWidth' => $int($browser['screenWidth'] ?? NULL, 0, 20000, 0),
      'httpBrowserTimeDifference' => $int($browser['timeDifference'] ?? NULL, -1440, 1440, 0),
      'httpBrowserJavaEnabled' => $bool($browser['javaEnabled'] ?? FALSE),
      'httpBrowserJavaScriptEnabled' => 'true',
    ];
    return array_filter($device, static fn ($v) => $v !== '');
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

    // Display metadata only (the charge is validated by Cybersource): brand
    // from
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
   * authorize only), so the behaviour cannot drift from what the merchant
   * chose.
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

    if ($this->isPayerAuthEnabled()) {
      $request = $this->applyPayerAuthentication($request, $order, $token);
    }

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

    // Only a clean AUTHORIZED is a final success. AUTHORIZED_PENDING_REVIEW
    // means
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
   * Attach the payer-authentication result to a payment request. FAIL CLOSED.
   *
   * When 3-D Secure is enabled, a payment request without a matching,
   * session-bound authentication result is refused — a client that skips or
   * tampers with the browser-side 3DS steps cannot reach authorization. The
   * result is single-use: it is deleted as soon as it is consumed.
   *
   * @param array<string, mixed> $request
   *   The payment request being built.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid.
   * @param string $token
   *   The transient-token JWT (must be the token that was authenticated).
   *
   * @return array<string, mixed>
   *   The decorated request.
   */
  protected function applyPayerAuthentication(array $request, OrderInterface $order, string $token): array {
    $pa = $this->paStore->get($this->paKey($order));
    $this->paStore->delete($this->paKey($order));
    if (!is_array($pa)
      || ($pa['order_id'] ?? '') !== (string) $order->id()
      || !hash_equals((string) ($pa['token_hash'] ?? ''), hash('sha256', $token))) {
      $this->logResponse($order, '3DS: payment refused — no payer-authentication result for this order/card (fail closed).');
      throw new InvalidRequestException('3-D Secure authentication was not completed. Please re-enter your card details and try again.');
    }

    switch ($pa['outcome'] ?? '') {
      case 'challenge_pending':
        // The customer completed (or abandoned) the challenge in the browser;
        // Cybersource validates the actual challenge result server-to-server
        // and refuses the authorization if it did not succeed.
        $request['processingInformation']['actionList'] = ['VALIDATE_CONSUMER_AUTHENTICATION'];
        $request['consumerAuthenticationInformation'] = [
          'authenticationTransactionId' => $pa['authenticationTransactionId'],
        ];
        break;

      case 'authenticated':
        // Frictionless success: carry the authentication data into the
        // authorization (the documented field mapping).
        if ($pa['commerceIndicator'] !== '') {
          $request['processingInformation']['commerceIndicator'] = $pa['commerceIndicator'];
        }
        $request['consumerAuthenticationInformation'] = array_filter([
          'cavv' => $pa['cavv'],
          'ucafAuthenticationData' => $pa['ucafAuthenticationData'],
          'ucafCollectionIndicator' => $pa['ucafCollectionIndicator'],
          'xid' => $pa['xid'],
          'directoryServerTransactionId' => $pa['directoryServerTransactionId'],
          'paSpecificationVersion' => $pa['specificationVersion'],
        ], static fn ($v) => $v !== '');
        break;

      case 'unavailable':
        // Authentication attempted but unavailable: proceed WITHOUT a
        // liability shift (standard scheme behaviour). Recorded for audit.
        $this->logResponse($order, '3DS: authentication unavailable — proceeding without liability shift.');
        break;

      default:
        $this->logResponse($order, sprintf('3DS: payment refused — unusable authentication outcome "%s".', (string) ($pa['outcome'] ?? '')));
        throw new InvalidRequestException('3-D Secure authentication was not completed. Please re-enter your card details and try again.');
    }
    return $request;
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
    $this->logResponse($payment->getOrder(), sprintf('capture %s %s status=%s transaction_id=%s', $amount->getNumber(), $amount->getCurrencyCode(), $status, (string) $payment->getRemoteId()));
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
    $this->logResponse($order, sprintf('void status=%s transaction_id=%s', $status, (string) $payment->getRemoteId()));
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
    // Confirm Cybersource accepted the refund (it settles asynchronously)
    // before
    // recording the money as refunded.
    $status = strtoupper((string) $response->getStatus());
    if (!in_array($status, ['PENDING', 'TRANSMITTED'], TRUE)) {
      $this->throwForStatus($status, $response);
    }
    $this->logResponse($payment->getOrder(), sprintf('refund %s %s status=%s transaction_id=%s', $amount->getNumber(), $amount->getCurrencyCode(), $status, (string) $payment->getRemoteId()));

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
   * Render a human-readable status of the configured credentials.
   */
  protected function credentialsStatus(): string {
    try {
      $modes = $this->credentials->configuredModes();
      return (string) $this->t('Credentials loaded from @source. Configured modes: @modes.', [
        '@source' => $this->credentials->source(),
        '@modes' => $modes ? implode(', ', $modes) : $this->t('(none)'),
      ]);
    }
    catch (\Throwable $e) {
      return (string) $this->t('⚠ Credentials could not be loaded from @source. Set $settings["cybersource_rest.credentials_file"] (or $settings["cybersource_rest.credentials"]) in settings.php and ensure the credentials are valid YAML. See the status report and README.', [
        '@source' => $this->credentials->source(),
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
   *   The payload (may contain billing PII; only logged when explicitly
   *   enabled).
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
