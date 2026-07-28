<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Credential-health messaging and runtime requirements for Cybersource REST.
 *
 * Shared by the requirements hooks (status report) and
 * CredentialsCheckSubscriber (admin-page messages) so both surfaces show
 * identical wording. This lived as procedural helpers in
 * cybersource_rest.module/.install before the OO-hooks conversion; it is a
 * proper injectable service now.
 */
final class CredentialsStatus {

  use StringTranslationTrait;

  /**
   * Requirement severities, as the legacy integer values.
   *
   * These match both the legacy REQUIREMENT_* constants and the backing
   * values of \Drupal\Core\Extension\Requirement\RequirementSeverity, so
   * runtimeRequirements() can serve the legacy hook_requirements() stub
   * directly (Drupal <= 11.2) and be converted with RequirementSeverity::from()
   * by the object-oriented hook_runtime_requirements() (Drupal 11.3+). Using
   * our own constants avoids referencing the enum class here, which does not
   * exist on Drupal 10, and avoids depending on install.inc being loaded.
   */
  public const SEVERITY_INFO = -1;
  public const SEVERITY_OK = 0;
  public const SEVERITY_WARNING = 1;
  public const SEVERITY_ERROR = 2;

  public function __construct(
    protected CredentialProvider $credentials,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the module's runtime requirements (status report entries).
   *
   * Surfaces the setup that silently breaks payments: the credentials
   * settings, test mode, key expiry, and the checkout CSP note.
   *
   * Severities are returned as integers (see the SEVERITY_* constants) so the
   * same array serves both the legacy and the object-oriented requirements
   * hooks.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, keyed by machine name.
   */
  public function runtimeRequirements(): array {
    $requirements = [];

    // Credentials must be provided through settings.php; without either
    // setting nothing works.
    if (!$this->credentials->isConfigured()) {
      $requirements['cybersource_rest_creds'] = [
        'title' => $this->t('Cybersource REST: credentials'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('In settings.php, set $settings["@file_setting"] to the absolute path of your credentials YAML file (outside the web root), or provide the credentials array as $settings["@inline_setting"] populated from your host\'s secret store. See the module README.', [
          '@file_setting' => CredentialProvider::SETTING_CREDENTIALS_FILE,
          '@inline_setting' => CredentialProvider::SETTING_CREDENTIALS,
        ]),
        'severity' => self::SEVERITY_ERROR,
      ];
      return $requirements;
    }

    // Credentials resolvable, readable, and actually filled in.
    try {
      $this->credentials->load();
      $modes = $this->credentials->configuredModes();
      if (!$modes) {
        $requirements['cybersource_rest_creds'] = [
          'title' => $this->t('Cybersource REST: credentials'),
          'value' => $this->t('No complete profiles configured'),
          'description' => $this->t('The credentials in %source contain no complete profile. Use cybersource_rest.credentials.example.yml as a starting point. Payments cannot be taken until then.', ['%source' => $this->credentials->source()]),
          'severity' => self::SEVERITY_WARNING,
        ];
      }
      else {
        $requirements['cybersource_rest_creds'] = [
          'title' => $this->t('Cybersource REST: credentials'),
          'value' => $this->t('Loaded from %source (modes: @modes)', [
            '%source' => $this->credentials->source(),
            '@modes' => implode(', ', $modes),
          ]),
          'severity' => self::SEVERITY_OK,
        ];
      }
    }
    catch (\Throwable $e) {
      // Missing or unreadable/invalid file: payments cannot work, so this is
      // an error with the exact location and steps to create the file (shared
      // with the admin message from CredentialsCheckSubscriber).
      $requirements['cybersource_rest_creds'] = [
        'title' => $this->t('Cybersource REST: credentials'),
        'value' => $this->t('Missing or invalid — payments cannot be taken'),
        'description' => $this->credentialsErrorMessage($e->getMessage()),
        'severity' => self::SEVERITY_ERROR,
      ];
    }

    // Warn while any Cybersource REST gateway is in test mode.
    $test = [];
    foreach ($this->entityTypeManager->getStorage('commerce_payment_gateway')->loadMultiple() as $gateway) {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway */
      if ($gateway->getPluginId() === 'cybersource_rest' && $gateway->getPlugin()->getMode() === 'test') {
        $test[] = $gateway->label();
      }
    }
    if ($test) {
      $requirements['cybersource_rest_test_mode'] = [
        'title' => $this->t('Cybersource REST: mode'),
        'value' => $this->t('TEST mode: @list', ['@list' => implode(', ', $test)]),
        'description' => $this->t('Payments are not real in test mode. Switch to live before taking real orders.'),
        'severity' => self::SEVERITY_WARNING,
      ];
    }

    // Optional key-expiry monitoring. If the operator records a key_expiry for
    // a REST API key (e.g. an organisation that rotates keys on a schedule),
    // warn a month out and error once the recorded date has passed.
    try {
      $expiries = $this->credentials->keyExpiries();
    }
    catch (\Throwable $e) {
      $expiries = [];
    }
    if ($expiries) {
      $today = new \DateTimeImmutable('today');
      $soon = $today->modify('+1 month');
      $expired = [];
      $expiring = [];
      foreach ($expiries as $label => $date) {
        $when = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$when) {
          // Unparseable date: tell the operator rather than silently ignore
          // it.
          $expiring[] = $this->t('@label (unreadable date "@date" — use YYYY-MM-DD)', [
            '@label' => $label,
            '@date' => $date,
          ]);
          continue;
        }
        if ($when < $today) {
          $expired[] = sprintf('%s (%s)', $label, $date);
        }
        elseif ($when < $soon) {
          $expiring[] = sprintf('%s (%s)', $label, $date);
        }
      }
      if ($expired) {
        $requirements['cybersource_rest_key_expired'] = [
          'title' => $this->t('Cybersource REST: key expiry'),
          'value' => $this->t('Expired: @list', ['@list' => implode(', ', $expired)]),
          'description' => $this->t('These Cybersource REST API keys are past their recorded expiry: @list. Generate a new key in the Business Center (Payment Configuration » Key Management » REST APIs), update the credentials in %source, and refresh its key_expiry.', [
            '@list' => implode(', ', $expired),
            '%source' => $this->credentials->source(),
          ]),
          'severity' => self::SEVERITY_ERROR,
        ];
      }
      if ($expiring) {
        $requirements['cybersource_rest_key_expiring'] = [
          'title' => $this->t('Cybersource REST: key expiry'),
          'value' => $this->t('Expiring soon: @list', ['@list' => implode(', ', $expiring)]),
          'description' => $this->t('These Cybersource REST API keys reach their recorded expiry within a month: @list. Generate a replacement key in the Business Center (Payment Configuration » Key Management » REST APIs) and update the credentials in %source before then.', [
            '@list' => implode(', ', $expiring),
            '%source' => $this->credentials->source(),
          ]),
          'severity' => self::SEVERITY_WARNING,
        ];
      }
    }

    // Microform renders card fields (as Cybersource-hosted iframes) in the
    // merchant page; a checkout CSP is an operator responsibility we cannot
    // verify.
    $requirements['cybersource_rest_csp'] = [
      'title' => $this->t('Cybersource REST: checkout CSP'),
      'value' => $this->t('Operator responsibility'),
      'description' => $this->t('Card fields are embedded (as Cybersource-hosted iframes) in your checkout page, so a Content-Security-Policy is required for PCI DSS. Confirm your PCI scope with your acquirer or QSA; see README.'),
      'severity' => self::SEVERITY_INFO,
    ];

    return $requirements;
  }

  /**
   * Builds the actionable "credentials missing or invalid" message.
   *
   * The file path is wrapped so it does not break mid-path.
   *
   * @param string $reason
   *   The underlying reason (e.g. an exception message).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated, marked-up message.
   */
  public function credentialsErrorMessage(string $reason): TranslatableMarkup {
    return $this->t('The Cybersource REST credentials could not be loaded (@msg). Provide them in settings.php via <span style="white-space:nowrap">%source</span>: either an absolute path to a YAML file outside the web root, readable by the web server user only (e.g. chmod 640), or the credentials array itself populated from your host\'s secret store. Copy the module\'s cybersource_rest.credentials.example.yml as a starting point and fill in your merchant_id, key_id and shared_secret.', [
      '@msg' => $reason,
      '%source' => $this->credentials->source(),
    ]);
  }

}
