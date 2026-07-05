<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Object-oriented hook implementations for Cybersource REST.
 *
 * Discovered automatically on Drupal 11.1+ via the #[Hook] attribute
 * (https://www.drupal.org/node/3442349). cybersource_rest.module keeps
 * #[LegacyHook] stubs that delegate here so Drupal 10.3 still works;
 * on 11.1+ the stubs are skipped and only these methods run.
 */
final class CybersourceRestHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name !== 'help.page.cybersource_rest') {
      return '';
    }
    return '<p>' . $this->t('Cybersource REST / Flex Microform v2 payment gateway for Drupal Commerce. Card data is captured in Cybersource-hosted Microform iframes (the PAN/CVV never reach this server) and charged server-side via the Cybersource REST API. API credentials live in a private file, not in site configuration. See the module README for setup and the PCI scope caveat.') . '</p>';
  }

}
