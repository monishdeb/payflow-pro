<?php

namespace Civi\PayflowPro\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Checkout\CheckoutOptionUtils;
use Civi\Checkout\CheckoutSession;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;

abstract class CheckoutOptionBase implements CheckoutOptionInterface, AfformCheckoutOptionInterface {

  /**
   * @var array
   */
  protected array $liveConnection;

  /**
   * @var array
   */
  protected array $testConnection;

  public function __construct(
    array $liveConnection,
    array $testConnection
  ) {
    $this->liveConnection = $liveConnection;
    $this->testConnection = $testConnection;
  }

  public function getLabel(): string {
    return $this->liveConnection['title'];
  }

  public function getFrontendLabel(): string {
    return $this->liveConnection['frontend_title'];
  }

  public function getPaymentProcessorId(): int {
    return $this->liveConnection['id'];
  }

  protected function getConnectionDetails(bool $testMode): array {
    return $testMode ? $this->testConnection : $this->liveConnection;
  }

  public function continueCheckout(CheckoutSession $session): void {
    // payflowpor processors have doPayment integrations which happen in a
    // single shot
  }

  protected function getQuickformProcessor(bool $testMode = FALSE): \CRM_Core_Payment {
    return \Civi\Payment\System::singleton()->getById($this->getConnectionDetails($testMode)['id']);
  }

  public function validate(AfformValidateEvent $event): void {
    // no specific validation rules
  }

  public function getPaymentMethod(): string {
    return 'payflowpro';
  }

  /**
   * @inheritDoc
   * @throws \CRM_Core_Exception
   */
  public function startCheckout(CheckoutSession $session): void {
    $params = $session->getCheckoutParams();

    // FIXME: copy doPayment implementation and switch to Api4 style keys
    $whatOldStyleKeysDoesDoPaymentNeed = ['amount', 'contributionID', 'contactID', 'currency', 'invoiceID'];
    $params += CheckoutOptionUtils::fetchRequiredParams($session->getContributionId(), [], $whatOldStyleKeysDoesDoPaymentNeed, $params);

    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $params['contact_id'])
      ->execute()
      ->first();
    $contactAddress = Address::get(FALSE)
      ->addWhere('contact_id', '=', $params['contact_id'])
      ->addWhere('is_billing', '=', TRUE)
      ->execute()
      ->first();
    if (!$contactAddress) {
      $contactAddress = Address::get(FALSE)
        ->addWhere('contact_id', '=', $params['contact_id'])
        ->addWhere('is_primary', '=', TRUE)
        ->execute()
        ->first();
    }
    $params['billingFirstName'] = $contact['first_name'];
    $params['billingLastName'] = $contact['last_name'];
    $params['billingStreetAddress'] = $contactAddress['street_address'] ?? '';
    $params['billingCity'] = $contactAddress['city'] ?? '';
    $params['billingStateProvince'] = $contactAddress['state_province_id'] ?? '';
    $params['billingPostalCode'] = $contactAddress['postal_code'] ?? '';
    $params['billingCountry'] = $contactAddress['country_id'] ?? '';

    $payment = $this->getQuickformProcessor($session->isTestMode())->doPayment($params);

    // check payment status
    switch ($payment['payment_status']) {
      case 'Completed':
        // Payment was approved / will go through
        // key details are passed back to the CheckoutSession
        $session->setTransactionId($payment['trxn_id']);
        $session->setOrderReference($payment['order_reference']);

        $session->createPayment();
        $session->success();
        // ensure clientside messages are shown
        $session->setResponseItem('message', $session->getStatusMessage());
        return;

      case 'Pending':
        // Payment wasn't rejected, but wasn't approved either
        $session->pending();
        // ensure clientside messages are shown
        $session->setResponseItem('message', $session->getStatusMessage());
        return;
    }
  }

}
