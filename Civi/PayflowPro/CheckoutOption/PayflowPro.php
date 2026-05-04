<?php

namespace Civi\PayflowPro\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionUtils;
use Civi\Checkout\CheckoutSession;

class PayflowPro extends CheckoutOptionBase {

  public function getLabel(): string {
    return $this->liveConnection['title'];
  }

  public function getFrontendLabel(): string {
    return $this->liveConnection['frontend_title'];
  }

  /**
   * @inheritDoc
   */
  public function getPaymentProcessorId(): int {
    return $this->liveConnection['id'];
  }

  protected function getConnectionDetails(bool $testMode): array {
    return $testMode ? $this->testConnection : $this->liveConnection;
  }

  /**
   * @inheritDoc
   */
  public function continueCheckout(CheckoutSession $session): void {
    // payflowpro processors have doPayment integrations which happen in a
    // single shot
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getQuickformProcessor(bool $testMode = FALSE): \CRM_Core_Payment {
    return \Civi\Payment\System::singleton()->getById($this->getConnectionDetails($testMode)['id']);
  }

  public function validate(AfformValidateEvent $event): void {
    // TODO: Implement validate() method.
  }

  public function getAfformSettings(bool $testMode): array {
    $save_credit_card = [
        'save_payment_token' => [
        'htmlType'    => 'checkBox',
        'name'        => 'save_payment_token',
        'title'       => ts('Save card details for future use?'),
        'is_required' => FALSE,
      ],
    ];
    $fields = CheckoutOptionUtils::mapQuickformFieldMetadata($this->getQuickformProcessor()->getPaymentFormFieldsMetadata());
    return [
      'fields' => $save_credit_card + $fields,
    ];
  }

  public function getAfformModule(): ?string {
    return NULL;
  }

}