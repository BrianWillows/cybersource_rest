<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\cybersource_rest\CredentialProvider;
use Drupal\cybersource_rest\CredentialsStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Warns admins, on admin pages, when an enabled gateway lacks credentials.
 *
 * Done at request time (not hook_page_attachments) so the message is in the
 * messenger BEFORE the status-messages block is built, and therefore shows on
 * the current page rather than the next one. Complements the status report.
 */
final class CredentialsCheckSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected AdminContext $adminContext,
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CredentialProvider $credentials,
    protected MessengerInterface $messenger,
    protected CredentialsStatus $credentialsStatus,
  ) {}

  /**
   * Adds an admin error when an enabled REST gateway has no credentials.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $route = $event->getRequest()->attributes->get('_route_object');
    if (!$route || !$this->adminContext->isAdminRoute($route)) {
      return;
    }
    if (!$this->currentUser->hasPermission('administer commerce_payment_gateway')) {
      return;
    }

    // Only warn when an ENABLED cybersource_rest gateway exists.
    $active = FALSE;
    $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadByProperties(['status' => TRUE]);
    foreach ($gateways as $gateway) {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway */
      if ($gateway->getPluginId() === 'cybersource_rest') {
        $active = TRUE;
        break;
      }
    }
    if (!$active) {
      return;
    }

    try {
      $this->credentials->load();
      // A loadable file with no complete profile is still unusable.
      if (!$this->credentials->configuredModes()) {
        $this->messenger->addError($this->credentialsStatus->credentialsErrorMessage((string) $this->t('no complete test or live profile')));
      }
    }
    catch (\Throwable $e) {
      $this->messenger->addError($this->credentialsStatus->credentialsErrorMessage($e->getMessage()));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 0 runs after the router (priority 32), so the route is resolved.
    return [KernelEvents::REQUEST => ['onRequest', 0]];
  }

}
