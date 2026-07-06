<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
    protected FileSystemInterface $fileSystem,
    protected CredentialProvider $credentials,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the module's runtime requirements (status report entries).
   *
   * Surfaces the setup that silently breaks payments: the private filesystem,
   * the credentials file, test mode, key expiry, and the checkout CSP note.
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

    $private = $this->fileSystem->realpath('private://');
    if (!$private) {
      $requirements['cybersource_rest_private'] = [
        'title' => $this->t('Cybersource REST: private filesystem'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('Set $settings["file_private_path"] to a directory OUTSIDE the web root. Cybersource credentials are read from %uri.', ['%uri' => CredentialProvider::CREDENTIALS_URI]),
        'severity' => self::SEVERITY_ERROR,
      ];
      return $requirements;
    }

    try {
      $this->credentials->load();
      $modes = $this->credentials->configuredModes();
      if (!$modes) {
        $requirements['cybersource_rest_creds'] = [
          'title' => $this->t('Cybersource REST: credentials'),
          'value' => $this->t('No complete profiles configured'),
          'description' => $this->t('Copy cybersource_rest.credentials.example.yml to %uri and fill in a test and/or live profile. Payments cannot be taken until then.', ['%uri' => CredentialProvider::CREDENTIALS_URI]),
          'severity' => self::SEVERITY_WARNING,
        ];
      }
      else {
        $requirements['cybersource_rest_creds'] = [
          'title' => $this->t('Cybersource REST: credentials'),
          'value' => $this->t('Loaded from %uri (modes: @modes)', [
            '%uri' => CredentialProvider::CREDENTIALS_URI,
            '@modes' => implode(', ', $modes),
          ]),
          'severity' => self::SEVERITY_OK,
        ];
      }
    }
    catch (\Throwable $e) {
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
          'description' => $this->t('These Cybersource REST API keys are past their recorded expiry: @list. Generate a new key in the Business Center (Payment Configuration » Key Management » REST APIs), update %uri, and refresh its key_expiry.', [
            '@list' => implode(', ', $expired),
            '%uri' => CredentialProvider::CREDENTIALS_URI,
          ]),
          'severity' => self::SEVERITY_ERROR,
        ];
      }
      if ($expiring) {
        $requirements['cybersource_rest_key_expiring'] = [
          'title' => $this->t('Cybersource REST: key expiry'),
          'value' => $this->t('Expiring soon: @list', ['@list' => implode(', ', $expiring)]),
          'description' => $this->t('These Cybersource REST API keys reach their recorded expiry within a month: @list. Generate a replacement key in the Business Center (Payment Configuration » Key Management » REST APIs) and update %uri before then.', [
            '@list' => implode(', ', $expiring),
            '%uri' => CredentialProvider::CREDENTIALS_URI,
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
    $private = $this->fileSystem->realpath('private://');
    $path = ($private ?: '<private files>') . '/keys/cybersource_rest.yml';
    return $this->t('The Cybersource REST credentials file is missing or could not be read (@msg). Create it at <span style="white-space:nowrap">%uri</span> — on this server that is the file <span style="white-space:nowrap">%path</span> — readable by the web server user only (e.g. chmod 640). Copy the module\'s cybersource_rest.credentials.example.yml as a starting point and fill in your merchant_id, key_id and shared_secret.', [
      '@msg' => $reason,
      '%uri' => CredentialProvider::CREDENTIALS_URI,
      '%path' => $path,
    ]);
  }

}
