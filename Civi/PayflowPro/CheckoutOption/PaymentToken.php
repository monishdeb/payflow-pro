<?php

namespace Civi\PayflowPro\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\HasConditionalVisibilityTrait;
use Civi\PayflowPro\Afform\PaymentTokenAvailability;
use CRM_Payflowpro_ExtensionUtil as E;

class PaymentToken extends CheckoutOptionBase {
  use HasConditionalVisibilityTrait;

  public function getLabel(): string {
    return E::ts('Use Existing Credit Card');
  }

  public function getFrontendLabel(): string {
    return E::ts('Use Existing Credit Card');
  }

  public function getAfformSettings(bool $testMode): array {
    return $this->mergeVisibleWhen([
      'template' => '~/afPayflowpro/payflowpro-payment-token-checkout.html',
      'payment_processor_id' => $this->getPaymentProcessorId(),
    ]);
  }

  public function getAfformModule(): ?string {
    return 'afPayflowpro';
  }

  public function getVisibleWhen(): array {
    return [
      ['{entity}[0][fields][' . PaymentTokenAvailability::FIELD . ']', 'IS NOT EMPTY'],
    ];
  }

  /**
   * Server-side enforcement.
   *
   * Does NOT call $this->validateVisibility() because the visibility
   * rule references a synthetic field that has been stripped from
   * submitted values by Submit::preprocessSubmittedValues. We run the
   * underlying token-existence query directly against the submitted
   * contact instead.
   *
   * Note that the existing token-id validation already enforces the
   * security boundary — a user without tokens for this contact cannot
   * submit a valid `payment_token` value. This check exists for clean
   * error messaging on spoof attempts.
   */
  public function validate(AfformValidateEvent $event): void {
    /** @var PaymentTokenAvailability $publisher */
    $publisher = \Civi::service('civi.payflowpro.payment_token_availability');

    foreach ($event->getFormDataModel()->getEntities() as $entityName => $entity) {
      if (($entity['type'] ?? NULL) !== 'Contribution') {
        continue;
      }
      foreach ($event->getSubmittedValues()[$entityName] ?? [] as $record) {
        // isSelectedOnRecord() comes from HasConditionalVisibilityTrait.
        if (!$this->isSelectedOnRecord($record)) {
          continue;
        }
        $contactId = $this->resolveSubmittedContactId($entity, $record, $event->getSubmittedValues());
        $processorId = $this->resolveSubmittedProcessorId($entity, $record);

        if (!$publisher->contactHasToken($contactId, $processorId)) {
          $event->setError(E::ts('Cannot use saved card: no payment token found for this contact.'));
        }
      }
    }
  }

  private function resolveSubmittedContactId(array $entity, array $record, array $submitted): ?int {
    $direct = $record['fields']['contact_id'] ?? NULL;
    if (is_numeric($direct)) {
      return (int) $direct;
    }
    $ref = $entity['data']['contact_id'] ?? NULL;
    if (is_numeric($ref)) {
      return (int) $ref;
    }
    if (is_string($ref) && $ref !== '') {
      $referenced = $submitted[$ref][0]['fields']['id'] ?? NULL;
      return is_numeric($referenced) ? (int) $referenced : NULL;
    }
    return NULL;
  }

  private function resolveSubmittedProcessorId(array $entity, array $record): ?int {
    $submitted = $record['fields']['afform_payment_processor'] ?? NULL;
    if (is_numeric($submitted)) {
      return (int) $submitted;
    }
    $declared = $entity['data']['afform_payment_processor'] ?? NULL;
    return is_numeric($declared) ? (int) $declared : NULL;
  }

}