<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Controller;

use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\cybersource_rest\Exception\CybersourceApiException;
use Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In-checkout endpoints for 3-D Secure (payer authentication).
 *
 * The checkout JS calls setup() and enroll() between card tokenisation and
 * form submission. Both routes require a session CSRF token AND ownership of
 * the order (see access()); the authentication RESULT never travels through
 * the browser — it is stashed server-side, session-bound, by the gateway
 * plugin, and consumed (fail closed, single use) by createPayment().
 *
 * The gateway is a route parameter (not read from the order) because these
 * calls happen BEFORE the order-information step is submitted, i.e. before
 * Commerce saves the chosen gateway onto the order. The access check pins it
 * to an enabled cybersource_rest gateway with 3-D Secure switched on.
 */
final class PayerAuthController extends ControllerBase {

  /**
   * Upper bound for a transient-token JWT (defensive input cap).
   */
  private const MAX_TOKEN_LENGTH = 8192;

  public function __construct(
    protected CartSessionInterface $cartSession,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_cart.cart_session'),
      $container->get('logger.channel.cybersource_rest'),
    );
  }

  /**
   * Access: session owns this draft order; gateway is 3DS-enabled and ours.
   */
  public function access(PaymentGatewayInterface $commerce_payment_gateway, OrderInterface $commerce_order, AccountInterface $account): AccessResultInterface {
    $plugin = $commerce_payment_gateway->getPlugin();
    if (!$commerce_payment_gateway->status() || !$plugin instanceof CybersourceRestInterface || !$plugin->isPayerAuthEnabled()) {
      return AccessResult::forbidden('Not an enabled Cybersource REST gateway with 3-D Secure.')->addCacheableDependency($commerce_payment_gateway);
    }
    if ($commerce_order->getState()->getId() !== 'draft') {
      return AccessResult::forbidden('Order is not in checkout.')->addCacheableDependency($commerce_order);
    }
    // Ownership: the anonymous cart session, or the authenticated customer.
    $owns = $this->cartSession->hasCartId((int) $commerce_order->id(), CartSessionInterface::ACTIVE)
      || (!$account->isAnonymous() && (int) $commerce_order->getCustomerId() === (int) $account->id());
    return AccessResult::allowedIf($owns)
      ->addCacheableDependency($commerce_payment_gateway)
      ->addCacheableDependency($commerce_order)
      ->cachePerUser();
  }

  /**
   * Step 1: payer-auth setup — returns the device-data-collection parameters.
   */
  public function setup(PaymentGatewayInterface $commerce_payment_gateway, OrderInterface $commerce_order, Request $request): JsonResponse {
    $token = $this->tokenFromRequest($request);
    if ($token === NULL) {
      return new JsonResponse(['error' => 'invalid_token'], 400);
    }
    /** @var \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRestInterface $plugin */
    $plugin = $commerce_payment_gateway->getPlugin();
    try {
      return new JsonResponse($plugin->setupPayerAuthentication($commerce_order, $token));
    }
    catch (\Throwable $e) {
      $this->logger->error('3DS setup failed for order @o: @m', [
        '@o' => $commerce_order->id(),
        '@m' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'unavailable'], 502);
    }
  }

  /**
   * Step 2: the enrollment check (after device data collection).
   */
  public function enroll(PaymentGatewayInterface $commerce_payment_gateway, OrderInterface $commerce_order, Request $request): JsonResponse {
    $token = $this->tokenFromRequest($request);
    if ($token === NULL) {
      return new JsonResponse(['error' => 'invalid_token'], 400);
    }
    $body = $this->jsonBody($request);
    $reference_id = (string) ($body['referenceId'] ?? '');
    if ($reference_id === '' || strlen($reference_id) > 128) {
      return new JsonResponse(['error' => 'invalid_reference'], 400);
    }
    $browser = is_array($body['browser'] ?? NULL) ? $body['browser'] : [];
    $billing = is_array($body['billing'] ?? NULL) ? $body['billing'] : [];
    /** @var \Drupal\cybersource_rest\Plugin\Commerce\PaymentGateway\CybersourceRestInterface $plugin */
    $plugin = $commerce_payment_gateway->getPlugin();
    try {
      return new JsonResponse($plugin->enrollPayerAuthentication($commerce_order, $token, $reference_id, $browser, $billing));
    }
    catch (CybersourceApiException $e) {
      $this->logger->error('3DS enrollment failed for order @o: @m', [
        '@o' => $commerce_order->id(),
        '@m' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'unavailable'], 502);
    }
    catch (\Throwable $e) {
      $this->logger->error('3DS enrollment error for order @o: @m', [
        '@o' => $commerce_order->id(),
        '@m' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'unavailable'], 500);
    }
  }

  /**
   * The ACS challenge-return page (rendered inside the challenge iframe).
   *
   * Deliberately reads nothing from the request and mutates nothing: it only
   * tells the parent window (same origin) that the challenge flow finished so
   * checkout can proceed. Whether the challenge SUCCEEDED is decided by
   * Cybersource, server-to-server, when the payment is created.
   */
  public function challengeReturn(): Response {
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>…</title></head><body>'
      . '<script>if (window.parent && window.parent !== window) { window.parent.postMessage({cybersourceRestPaComplete: true}, window.location.origin); }</script>'
      . '</body></html>';
    $response = new Response($html);
    $response->headers->set('Cache-Control', 'no-store');
    // The page must be frameable by our own checkout page only.
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
    return $response;
  }

  /**
   * Extract and sanity-check the transient token from the JSON body.
   */
  protected function tokenFromRequest(Request $request): ?string {
    $token = (string) ($this->jsonBody($request)['token'] ?? '');
    if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || substr_count($token, '.') !== 2) {
      return NULL;
    }
    return $token;
  }

  /**
   * Decode the JSON request body (empty array on garbage).
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  protected function jsonBody(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
