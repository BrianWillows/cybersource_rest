<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\cybersource_rest\CredentialsStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Object-oriented hook implementations for Cybersource REST.
 *
 * Discovered automatically on Drupal 11.1+ via the #[Hook] attribute
 * (https://www.drupal.org/node/3442349). cybersource_rest.module and
 * cybersource_rest.install keep legacy stubs that delegate here (or to the
 * same services) so Drupal 10.3 still works; on modern cores the stubs are
 * skipped so each hook runs exactly once.
 *
 * Note for Drupal 10 compatibility: the #[Hook] attribute and the
 * RequirementSeverity enum do not exist there, but neither is resolved when
 * this class is merely loaded — attributes are only instantiated via
 * reflection (11.1+), and the enum is only referenced inside
 * runtimeRequirements(), which core only invokes on 11.3+.
 */
final class CybersourceRestHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected CredentialsStatus $credentialsStatus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('cybersource_rest.credentials_status'),
    );
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name !== 'help.page.cybersource_rest') {
      return '';
    }
    return '<p>' . $this->t('Cybersource REST / Flex Microform v2 payment gateway for Drupal Commerce. Card data is captured in Cybersource-hosted Microform iframes (the PAN/CVV never reach this server) and charged server-side via the Cybersource REST API. API credentials are provided through settings.php, not stored in site configuration. See the module README for setup and the PCI scope caveat.') . '</p>';
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, keyed by machine name.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = $this->credentialsStatus->runtimeRequirements();
    // The shared builder returns legacy integer severities (so the same array
    // serves the legacy hook_requirements() stub on Drupal <= 11.2); this
    // hook's contract wants the enum, whose backing values match the legacy
    // integers.
    foreach ($requirements as &$requirement) {
      if (isset($requirement['severity']) && is_int($requirement['severity'])) {
        $requirement['severity'] = RequirementSeverity::from($requirement['severity']);
      }
    }
    return $requirements;
  }

}
