<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Action\PayflowPro;

use Civi\PayflowPro\RecurIPN;

/**
 * Check active recurring contributions against PayflowPro for profiles that
 * have been auto-cancelled by PayPal (e.g. due to exceeding MAXFAILPAYMENTS).
 *
 * When a cancellation is detected:
 *  - The ContributionRecur status is updated to "Cancelled".
 *  - A "Payment Processor Notification" activity is created on the contact's
 *    record so staff can review and take action.
 *
 * Run this as a scheduled job (e.g. daily) since PayflowPro does not push
 * notifications for auto-cancelled profiles.
 */
class CheckCancelledProfiles extends \Civi\Api4\Generic\AbstractAction {

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  protected int $paymentProcessorID = 0;

  /**
   * Optional: limit check to specific PayflowPro profile IDs.
   *
   * @var array
   */
  protected array $recurProfileIDs = [];

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result): void {
    if (empty($this->paymentProcessorID)) {
      throw new \CRM_Core_Exception('Missing paymentProcessorID');
    }

    $ipnProcessor = new RecurIPN($this->paymentProcessorID);
    $results = $ipnProcessor->checkCancelledProfiles($this->recurProfileIDs);

    $result->exchangeArray($results ?? []);
  }

}
