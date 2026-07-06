<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Attribute\CommercePaymentMethodType;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\CreditCard;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Credit card payment method type for the Cybersource REST / Microform gateway.
 *
 * Adds a `transient_token` field that holds the single-use Microform JWT until
 * the payment is created. The token is never reusable; this gateway charges it
 * once and does not (yet) exchange it for a permanent Token Management
 * instrument.
 */
#[CommercePaymentMethodType(
  id: 'cybersource_rest_credit_card',
  label: new TranslatableMarkup('Credit card (Cybersource Microform)'),
)]
class CybersourceRestCreditCard extends CreditCard {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = parent::buildFieldDefinitions();

    $fields['transient_token'] = BundleFieldDefinition::create('string_long')
      ->setLabel($this->t('Transient token'))
      ->setDescription($this->t('The single-use Cybersource Flex Microform transient token (JWT) used to charge this card.'))
      ->setDefaultValue('');

    return $fields;
  }

}
