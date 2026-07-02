<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Kernel;

use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201ResponseErrorInformation;
use CyberSource\Model\PtsV2PaymentsRefundPost201Response;
use CyberSource\Model\PtsV2PaymentsVoidsPost201Response;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\cybersource_rest\CybersourceApiClientInterface;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Tests the Cybersource REST gateway's create/charge logic with a mocked API.
 *
 * The REST SDK is wrapped behind CybersourceApiClientInterface, so the gateway's
 * decision logic can be tested without any network access by swapping in a mock
 * client — the architectural win over newing-up SDK classes inline.
 *
 * @group cybersource_rest
 * @coversDefaultClass \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRest
 */
class CybersourceRestPaymentTest extends OrderKernelTestBase {

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
   * The payment gateway under test.
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
   * Create a stored payment method carrying a transient token.
   */
  protected function paymentMethod(): PaymentMethod {
    $payment_method = PaymentMethod::create([
      'type' => 'cybersource_rest_credit_card',
      'payment_gateway' => 'cybersource_rest',
      'card_type' => 'visa',
      'card_number' => '1111',
      'card_exp_month' => '12',
      'card_exp_year' => '2031',
      'transient_token' => 'header.payload.signature',
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
   * An AUTHORIZED response records a completed payment for a "sale" gateway.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentAuthorized(): void {
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-123', 'status' => 'AUTHORIZED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->expects($this->once())->method('createPayment')->willReturn($response);

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());
    $plugin->createPayment($payment);

    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame('CS-123', $payment->getRemoteId());
    $this->assertSame('AUTHORIZED', $payment->getRemoteState());
  }

  /**
   * A DECLINED response throws a hard decline and records no payment.
   *
   * @covers ::createPayment
   * @covers ::throwForStatus
   */
  public function testCreatePaymentDeclined(): void {
    $response = new PtsV2PaymentsPost201Response([
      'id' => 'CS-456',
      'status' => 'DECLINED',
      'errorInformation' => new PtsV2PaymentsPost201ResponseErrorInformation([
        'reason' => 'PROCESSOR_DECLINED',
        'message' => 'Decline - General decline of the card.',
      ]),
    ]);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->method('createPayment')->willReturn($response);

    $plugin = $this->gatewayWith($client);
    $payment = $this->newPayment($this->paymentMethod());

    $this->expectException(HardDeclineException::class);
    $plugin->createPayment($payment);
  }

  /**
   * AUTHORIZED_PENDING_REVIEW is held as an authorization, never completed.
   *
   * Even for a "sale" gateway a Decision Manager review must NOT auto-complete
   * the order, and the consumed token must be cleared.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentReviewHeld(): void {
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-789', 'status' => 'AUTHORIZED_PENDING_REVIEW']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->method('createPayment')->willReturn($response);

    $plugin = $this->gatewayWith($client);
    $method = $this->paymentMethod();
    $payment = $this->newPayment($method);
    $plugin->createPayment($payment);

    $this->assertSame('authorization', $payment->getState()->getId());
    $this->assertSame('AUTHORIZED_PENDING_REVIEW', $payment->getRemoteState());
    $this->assertSame('', (string) PaymentMethod::load($method->id())->get('transient_token')->value);
  }

  /**
   * A decline clears the spent transient token from the payment method.
   *
   * @covers ::createPayment
   * @covers ::clearTransientToken
   */
  public function testDeclineClearsToken(): void {
    $response = new PtsV2PaymentsPost201Response(['id' => 'CS-460', 'status' => 'DECLINED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->method('createPayment')->willReturn($response);

    $plugin = $this->gatewayWith($client);
    $method = $this->paymentMethod();
    try {
      $plugin->createPayment($this->newPayment($method));
      $this->fail('Expected a decline.');
    }
    catch (HardDeclineException) {
      // Expected.
    }
    $this->assertSame('', (string) PaymentMethod::load($method->id())->get('transient_token')->value);
  }

  /**
   * A void whose response is not a confirmed-void status is rejected.
   *
   * @covers ::voidPayment
   */
  public function testVoidRejectsUnconfirmedStatus(): void {
    $response = new PtsV2PaymentsVoidsPost201Response(['id' => 'V-1', 'status' => 'DECLINED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->method('voidPayment')->willReturn($response);
    $plugin = $this->gatewayWith($client);

    $payment = Payment::create([
      'state' => 'authorization',
      'amount' => $this->order->getTotalPrice(),
      'payment_gateway' => 'cybersource_rest',
      'order_id' => $this->order->id(),
      'remote_id' => 'CS-123',
    ]);
    $payment->save();

    $this->expectException(PaymentGatewayException::class);
    $plugin->voidPayment($payment);
  }

  /**
   * A refund whose response is not an accepted status is rejected.
   *
   * @covers ::refundPayment
   */
  public function testRefundRejectsUnconfirmedStatus(): void {
    $response = new PtsV2PaymentsRefundPost201Response(['id' => 'R-1', 'status' => 'DECLINED']);
    $client = $this->createMock(CybersourceApiClientInterface::class);
    $client->method('refundPayment')->willReturn($response);
    $plugin = $this->gatewayWith($client);

    $payment = Payment::create([
      'state' => 'completed',
      'amount' => $this->order->getTotalPrice(),
      'payment_gateway' => 'cybersource_rest',
      'order_id' => $this->order->id(),
      'remote_id' => 'CS-123',
    ]);
    $payment->save();

    $this->expectException(PaymentGatewayException::class);
    $plugin->refundPayment($payment);
  }

  /**
   * The transient token is decoded into card metadata on the payment method.
   *
   * @covers ::createPaymentMethod
   */
  public function testCreatePaymentMethod(): void {
    $card = [
      'number' => ['maskedValue' => '555555XXXXXX4444', 'bin' => '555555'],
      'expirationMonth' => ['value' => '04'],
      'expirationYear' => ['value' => '2030'],
    ];
    $encode = static fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $jwt = $encode(['alg' => 'RS256']) . '.' . $encode(['content' => ['paymentInformation' => ['card' => $card]]]) . '.sig';

    $plugin = $this->gatewayWith($this->createMock(CybersourceApiClientInterface::class));
    $payment_method = PaymentMethod::create([
      'type' => 'cybersource_rest_credit_card',
      'payment_gateway' => 'cybersource_rest',
    ]);
    $plugin->createPaymentMethod($payment_method, ['cybersource_token' => $jwt]);

    $this->assertSame('mastercard', $payment_method->get('card_type')->value);
    $this->assertSame('4444', $payment_method->get('card_number')->value);
    $this->assertSame('04', $payment_method->get('card_exp_month')->value);
    $this->assertSame('2030', $payment_method->get('card_exp_year')->value);
    $this->assertSame($jwt, $payment_method->get('transient_token')->value);
    $this->assertFalse($payment_method->isReusable());
  }

}
