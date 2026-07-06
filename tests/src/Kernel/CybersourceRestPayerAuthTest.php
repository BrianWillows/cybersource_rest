<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Kernel;

use CyberSource\Model\PtsV2PaymentsPost201Response;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;
use Drupal\cybersource_rest\CybersourceApiClientInterface;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Tests the 3-D Secure (payer authentication) handling of createPayment().
 *
 * The security property under test is FAIL CLOSED: with payer authentication
 * enabled, a payment request without a matching, session-bound authentication
 * result must be refused before any API call — a client that skips or tampers
 * with the browser-side 3DS steps can never reach authorization.
 *
 * @group cybersource_rest
 * @coversDefaultClass \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRest
 */
class CybersourceRestPayerAuthTest extends OrderKernelTestBase {

  /**
   * The transient token used across the tests.
   */
  protected const TOKEN = 'header.payload.signature';

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_log',
    'cybersource_rest',
  ];

  /**
   * The payment gateway under test (payer_auth enabled).
   */
  protected PaymentGateway $gateway;

  /**
   * A draft order totalling 19.99 USD.
   */
  protected Order $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_log');
    $this->installConfig(['commerce_payment']);

    $this->gateway = PaymentGateway::create([
      'id' => 'cybersource_rest',
      'label' => 'Cybersource REST',
      'plugin' => 'cybersource_rest',
      'configuration' => [
        'mode' => 'test',
        'transaction_type' => 'sale',
        'payer_auth' => TRUE,
      ],
    ]);
    $this->gateway->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => '1',
      'unit_price' => new Price('19.99', 'USD'),
    ]);
    $order_item->save();
    $this->order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'order_items' => [$order_item],
    ]);
    $this->order->recalculateTotalPrice();
    $this->order->save();
  }

  /**
   * Swap in a mock API client and return the gateway plugin that uses it.
   *
   * @param \Drupal\cybersource_rest\CybersourceApiClientInterface $client
   *   The mock client.
   *
   * @return \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRest
   *   The gateway plugin bound to the mock.
   */
  protected function gatewayWith(CybersourceApiClientInterface $client) {
    $this->container->set('cybersource_rest.api_client', $client);
    $gateway = PaymentGateway::load('cybersource_rest');
    /** @var \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRest $plugin */
    $plugin = $gateway->getPlugin();
    return $plugin;
  }

  /**
   * Create a stored payment method carrying the test transient token.
   */
  protected function paymentMethod(): PaymentMethod {
    $payment_method = PaymentMethod::create([
      'type' => 'cybersource_rest_credit_card',
      'payment_gateway' => 'cybersource_rest',
      'card_type' => 'visa',
      'card_number' => '1111',
      'card_exp_month' => '12',
      'card_exp_year' => '2031',
      'transient_token' => self::TOKEN,
      'reusable' => FALSE,
    ]);
    $payment_method->save();
    return $payment_method;
  }

  /**
   * Build a new payment for the order + method.
   */
  protected function newPayment(PaymentMethod $payment_method): Payment {
    return Payment::create([
      'state' => 'new',
      'amount' => $this->order->getTotalPrice(),
      'payment_gateway' => 'cybersource_rest',
      'payment_method' => $payment_method->id(),
      'order_id' => $this->order->id(),
    ]);
  }

  /**
   * Seed a payer-authentication result as the enrollment check would.
   *
   * @param array<string, mixed> $overrides
   *   Result overrides.
   */
  protected function stashResult(array $overrides = []): void {
    $result = $overrides + [
      'order_id' => (string) $this->order->id(),
      'token_hash' => hash('sha256', self::TOKEN),
      'outcome' => 'authenticated',
      'authenticationTransactionId' => 'AUTH-TXN-1',
      'cavv' => 'test-cavv-value',
      'ucafAuthenticationData' => '',
      'ucafCollectionIndicator' => '',
      'commerceIndicator' => 'vbv',
      'eci' => '05',
      'xid' => 'test-xid',
      'specificationVersion' => '2.2.0',
      'directoryServerTransactionId' => 'DS-TXN-1',
      'paresStatus' => 'Y',
    ];
    $this->container->get('tempstore.private')->get('cybersource_rest')
      ->set('payer_auth:' . $this->order->id(), $result);
  }

  /**
   * With 3DS enabled and NO authentication result, the payment is refused.
   *
   * @covers ::applyPayerAuthentication
   */
  public function testPaymentRefusedWithoutAuthentication(): void {
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->never())->method('createPayment');

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());

    $this->expectException(InvalidRequestException::class);
    $plugin->createPayment($payment);
  }

  /**
   * An authentication result for a DIFFERENT card token is refused.
   *
   * @covers ::applyPayerAuthentication
   */
  public function testPaymentRefusedOnTokenMismatch(): void {
    $this->stashResult(['token_hash' => hash('sha256', 'some.other.token')]);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->never())->method('createPayment');

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());

    $this->expectException(InvalidRequestException::class);
    $plugin->createPayment($payment);
  }

  /**
   * Frictionless success: the CAVV/ECI data rides into the authorization.
   *
   * @covers ::applyPayerAuthentication
   */
  public function testFrictionlessAuthenticationDataCarried(): void {
    $this->stashResult();
    $captured = NULL;
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-3DS-1', 'status' => 'AUTHORIZED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->once())->method('createPayment')
      ->willReturnCallback(function (string $mode, array $request) use (&$captured, $response) {
        $captured = $request;
        return $response;
      });

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());
    $plugin->createPayment($payment);

    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame('vbv', $captured['processingInformation']['commerceIndicator']);
    $cai = $captured['consumerAuthenticationInformation'];
    $this->assertSame('test-cavv-value', $cai['cavv']);
    $this->assertSame('test-xid', $cai['xid']);
    $this->assertSame('DS-TXN-1', $cai['directoryServerTransactionId']);
    $this->assertSame('2.2.0', $cai['paSpecificationVersion']);
    $this->assertArrayNotHasKey('ucafAuthenticationData', $cai, 'Empty Mastercard fields are omitted.');
    $this->assertArrayNotHasKey('actionList', $captured['processingInformation']);

    // The result is single-use: a second payment attempt is refused.
    $this->assertNull($this->container->get('tempstore.private')->get('cybersource_rest')->get('payer_auth:' . $this->order->id()));
  }

  /**
   * After a challenge, the payment validates the authentication server-side.
   *
   * @covers ::applyPayerAuthentication
   */
  public function testChallengeValidationRequested(): void {
    $this->stashResult(['outcome' => 'challenge_pending', 'cavv' => '', 'paresStatus' => 'C']);
    $captured = NULL;
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-3DS-2', 'status' => 'AUTHORIZED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->once())->method('createPayment')
      ->willReturnCallback(function (string $mode, array $request) use (&$captured, $response) {
        $captured = $request;
        return $response;
      });

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());
    $plugin->createPayment($payment);

    $this->assertSame(['VALIDATE_CONSUMER_AUTHENTICATION'], $captured['processingInformation']['actionList']);
    $this->assertSame('AUTH-TXN-1', $captured['consumerAuthenticationInformation']['authenticationTransactionId']);
    $this->assertSame('completed', $payment->getState()->getId());
  }

  /**
   * Authentication unavailable: proceed WITHOUT authentication data.
   *
   * @covers ::applyPayerAuthentication
   */
  public function testUnavailableProceedsWithoutAuthData(): void {
    $this->stashResult(['outcome' => 'unavailable', 'cavv' => '', 'commerceIndicator' => '']);
    $captured = NULL;
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-3DS-3', 'status' => 'AUTHORIZED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->once())->method('createPayment')
      ->willReturnCallback(function (string $mode, array $request) use (&$captured, $response) {
        $captured = $request;
        return $response;
      });

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());
    $plugin->createPayment($payment);

    $this->assertArrayNotHasKey('consumerAuthenticationInformation', $captured);
    $this->assertArrayNotHasKey('actionList', $captured['processingInformation']);
    $this->assertSame('completed', $payment->getState()->getId());
  }

  /**
   * With 3DS DISABLED, payments proceed with no authentication involvement.
   */
  public function testDisabledPayerAuthDoesNotInterfere(): void {
    $configuration = $this->gateway->getPluginConfiguration();
    $configuration['payer_auth'] = FALSE;
    $this->gateway->setPluginConfiguration($configuration);
    $this->gateway->save();

    $captured = NULL;
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-PLAIN', 'status' => 'AUTHORIZED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->once())->method('createPayment')
      ->willReturnCallback(function (string $mode, array $request) use (&$captured, $response) {
        $captured = $request;
        return $response;
      });

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());
    $plugin->createPayment($payment);

    $this->assertArrayNotHasKey('consumerAuthenticationInformation', $captured);
    $this->assertSame('completed', $payment->getState()->getId());
  }

}
