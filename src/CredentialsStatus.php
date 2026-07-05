<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Credential-health messaging for the Cybersource REST module.
 *
 * Shared by hook_requirements() (status report) and
 * CredentialsCheckSubscriber (admin-page messages) so both surfaces show
 * identical wording. This lived as a procedural helper in
 * cybersource_rest.module before the OO-hooks conversion; it is a proper
 * injectable service now.
 */
final class CredentialsStatus {

  use StringTranslationTrait;

  public function __construct(
    protected FileSystemInterface $fileSystem,
  ) {}

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
