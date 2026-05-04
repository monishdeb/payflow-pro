<?php

namespace Civi\PayflowPro\Afform;

use Civi\Api4\PaymentToken;
use Civi\Checkout\AvailabilityPublisher;

/**
 * Publishes a `_has_payment_token` flag onto Contribution entities in
 * the Afform.prefill response so the PaymentToken CheckoutOption can
 * use it in a `visible_when` rule.
 *
 * The flag is set per-Contribution (each Contribution may belong to a
 * different contact). Server-side validation of "this contact must
 * have a token to select PaymentToken" is handled by
 * PaymentToken::validate() — see that class.
 *
 * @service civi.payflowpro.payment_token_availability
 */
class PaymentTokenAvailability extends AvailabilityPublisher {

  /**
   * Field name PaymentToken::getVisibleWhen() reads.
   */
  public const FIELD = 'payflowpro_has_payment_token';

  protected function getProcessorTypeName(): ?string {
    return 'PayflowPro';
  }

  protected function computeAvailability(?int $contactId, ?int $processorId): array {
    return [
      self::FIELD => $this->contactHasToken($contactId, $processorId),
    ];
  }

  /**
   * Public so PaymentToken::validate() can run the same check against
   * submitted values.
   *
   * @param int|null $contactId
   * @param int|null $paymentProcessorId
   *   If NULL, checks across all PayflowPro processors site-wide.
   *   Better to over-show than under-show — empty-token-list UX is
   *   recoverable; silently hiding a valid choice is not.
   */
  public function contactHasToken(?int $contactId, ?int $paymentProcessorId): bool {
    if (!$contactId) {
      return FALSE;
    }
    try {
      $query = PaymentToken::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addClause('OR',
          ['expiry_date', 'IS NULL'],
          ['expiry_date', '>', date('Y-m-d')]
        )
        ->selectRowCount();
      if ($paymentProcessorId) {
        $query->addWhere('payment_processor_id', '=', $paymentProcessorId);
      }
      else {
        $query->addWhere('payment_processor_id.payment_processor_type_id:name', '=', $this->getProcessorTypeName());
      }
      return $query->execute()->count() > 0;
    }
    catch (\Throwable $e) {
      \Civi::log()->warning(
        'PaymentTokenAvailability: token lookup failed ({message}); defaulting to FALSE.',
        ['message' => $e->getMessage()]
      );
      return FALSE;
    }
  }

}