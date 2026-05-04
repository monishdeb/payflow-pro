<?php

namespace Civi\PayflowPro;

use Civi\Api4\Activity;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Psr\Log\LogLevel;

/**
 * PayflowPro doesn't offer Webhooks / Instant Payment Notification (IPN)
 * Instead we query it for updates via a scheduled job.
 * This class contains that functionality.
 */
class RecurIPN {

  use \CRM_Core_Payment_MJWIPNTrait;

  /**
   * The CiviCRM Payment Processor ID.
   *
   * @var int
   */
  private int $paymentProcessorID;

  /**
   *
   * @var string
   */
  protected string $paymentHistoryType;

  /**
   *
   */
  public function __construct($paymentProcessorID, $paymentHistoryType = 'Y') {
    $this->paymentProcessorID = $paymentProcessorID;
    $this->paymentHistoryType = $paymentHistoryType;
    $this->setPaymentProcessor($paymentProcessorID);
  }

  /**
   *
   */
  public function importLatestRecurPayments($recurProfileIDs = []): array {
    $paymentProcessor = $this->getPaymentProcessor();
    if (!$paymentProcessor instanceof \CRM_Core_Payment_PayflowPro) {
      return [];
    }
    /**
     * @var \CRM_Core_Payment_PayflowPro $this->getPaymentProcessor()
     */
    $payflowAPI = new Api($paymentProcessor);

    // Get the list of recurring contributions to query.
    $contributionRecurApi = ContributionRecur::get(FALSE)
      ->addWhere('is_test', '=', $this->getPaymentProcessor()->getIsTestMode())
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorID);
    if (!empty($recurProfileIDs)) {
      $contributionRecurApi->addWhere('processor_id', 'IN', $recurProfileIDs);
    }
    $contributionRecurs = $contributionRecurApi->execute();
    foreach ($contributionRecurs as $contributionRecur) {
      $results['recur'][$contributionRecur['id']] = [];
      // This gets a list of payments sorted by date.
      $recurPaymentHistory = $payflowAPI->getRecurPaymentHistory($contributionRecur['processor_id'], $this->paymentHistoryType);
      // Get all the existing contributions for this recur ordered by most recent first.
      $contributions = Contribution::get(FALSE)
        ->addSelect('*', 'contribution_status_id:name')
        ->addWhere('contribution_recur_id', '=', $contributionRecur['id'])
        ->addWhere('is_test', '=', $this->getPaymentProcessor()->getIsTestMode())
        ->addOrderBy('receive_date', 'DESC')
        ->execute()
        ->indexBy('trxn_id')
        ->getArrayCopy();

      // Now we need to loop through history received from PayflowPro and
      //   compare with what we have recorded in CiviCRM.
      foreach ($recurPaymentHistory as $payflowRecurPayment) {
        $matchedContribution = FALSE;
        foreach ($contributions as $contribution) {
          // Check for existing contribution matching recurPayment by trxn_id.
          if (str_contains($contribution['trxn_id'], $payflowRecurPayment['trxn_id'])) {
            // We've got a matching contribution for the payflow recur payment (ie. it's already recorded)
            // @todo Check status as we may not be Completed / Successful
            $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['existing'] = TRUE;
            $matchedContribution = TRUE;
            $newContributionID = $contribution['id'];
            break;
          }
        }
        if (!$matchedContribution) {
          // Create the next contribution for a recurring contribution.
          $repeatContributionParams = [
            'contribution_recur_id' => $contributionRecur['id'],
            'contribution_status_id' => \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
            'receive_date' => date('YmdHis', $payflowRecurPayment['trxn_date']),
            'order_reference' => $payflowRecurPayment['trxn_id'],
            'trxn_id' => $payflowRecurPayment['trxn_id'],
            'total_amount' => $payflowRecurPayment['amount'],
            'fee_amount' => 0,
          ];
          $newContributionID = $this->repeatContribution($repeatContributionParams);
          $results['recur'][$contributionRecur['id']]['contributions'][$newContributionID]['created'] = TRUE;
          $contribution = Contribution::get(FALSE)
            ->addWhere('id', '=', $newContributionID)
            ->execute()
            ->first();
        }

        // Since FISERV doesn't go from settlement pending to completed in test mode we can simulate (skip) that
        // by setting Pending = Completed in this case.
        if ($this->getPaymentProcessor()->getIsTestMode() && \Civi::settings()->get('payflowpro_testmodesettlement')) {
          if ($payflowRecurPayment['status_id:name'] === 'Pending' && (intval($payflowRecurPayment['P_TRANSTATE']) === 6 || intval($payflowRecurPayment['TRANSTATE']) === 6)) {
            // "settlement pending" we will treat as "Completed"
            $payflowRecurPayment['status_id:name'] = 'Completed';
          }
        }

        // Now we have a contribution (either matched or created)
        // Check status and record appropriately.
        switch ($payflowRecurPayment['status_id:name']) {
          case 'Completed':
            if ($contribution['contribution_status_id:name'] === 'Completed') {
              $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['already_completed'] = TRUE;
            }
            else {
              // Now record the payment.
              $completedContributionParams = [
                'contribution_id' => $newContributionID,
                'trxn_date' => date('YmdHis', $payflowRecurPayment['trxn_date']),
                'order_reference' => $payflowRecurPayment['trxn_id'],
                'trxn_id' => $payflowRecurPayment['trxn_id'],
                'total_amount' => $payflowRecurPayment['amount'],
                'fee_amount' => 0,
              ];
              $this->updateContributionCompleted($completedContributionParams);
              $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['completed'] = TRUE;
            }
            break;

          case 'Failed':
            $failedContributionParams = [
              'contribution_id' => $contribution['id'],
              'order_reference' => $payflowRecurPayment['trxn_id'],
              'failure_date' => date('YmdHis', $payflowRecurPayment['trxn_date']),
              'failure_reason' => $payflowRecurPayment['status_id:name_description'],
              'failure_code' => $payflowRecurPayment['TRANSTATE'],
              'category:name' => 'Uncategorized',
              'data' => $payflowRecurPayment,
              'identifier' => $payflowRecurPayment['trxn_id'],
              'level' => LogLevel::ERROR,
            ];
            $this->updateContributionFailed($failedContributionParams);

            $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['failed'] = TRUE;
            break;

          case 'Pending':
            $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['pending'] = TRUE;
            break;
        }
        $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['description'] = $payflowRecurPayment['status_id:name_description'];
        $results['recur'][$contributionRecur['id']]['contributions'][$contribution['id']]['date'] = date('YmdHis', $payflowRecurPayment['trxn_date']);
      }
    }

    return $results ?? [];
  }

  /**
   * Check active recurring contributions against PayflowPro and detect profiles
   * that have been cancelled by PayPal (e.g. due to exceeding MAXFAILPAYMENTS).
   *
   * When a profile cancellation is detected:
   *  - The CiviCRM ContributionRecur status is updated to "Cancelled".
   *  - A CiviCRM "Payment Processor Notification" activity is created on the
   *    contact's record so staff can take action (e.g. contact the member or
   *    reactivate the profile).
   *
   * NOTE: PayflowPro does not push notifications when a profile is auto-cancelled.
   * This method must be called on a schedule (e.g. daily) to detect cancellations.
   *
   * @param array $recurProfileIDs
   *   Optional list of specific profile IDs to check. If empty, all active
   *   recurring contributions for the payment processor are checked.
   *
   * @return array  Results keyed by ContributionRecur ID.
   * @throws \CRM_Core_Exception
   */
  public function checkCancelledProfiles(array $recurProfileIDs = []): array {
    $paymentProcessor = $this->getPaymentProcessor();
    if (!$paymentProcessor instanceof \CRM_Core_Payment_PayflowPro) {
      return [];
    }

    $payflowAPI = new Api($paymentProcessor);

    $contributionRecurApi = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'processor_id', 'contribution_status_id:name', 'amount', 'currency', 'frequency_unit', 'frequency_interval')
      ->addWhere('is_test', '=', $this->getPaymentProcessor()->getIsTestMode())
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorID)
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending'])
      ->addWhere('processor_id', 'IS NOT EMPTY');
    if (!empty($recurProfileIDs)) {
      $contributionRecurApi->addWhere('processor_id', 'IN', $recurProfileIDs);
    }
    $contributionRecurs = $contributionRecurApi->execute();

    $results = [];
    foreach ($contributionRecurs as $contributionRecur) {
      $profileID = $contributionRecur['processor_id'];
      try {
        $profileStatus = $payflowAPI->getProfileStatus($profileID);
        $status = strtoupper($profileStatus['status'] ?? '');
        $results[$contributionRecur['id']]['profile_status'] = $status;

        if ($status === 'CANCEL') {
          // PayPal has cancelled this profile (e.g. due to MAXFAILPAYMENTS exceeded).
          // Update the CiviCRM recurring contribution status to Cancelled.
          ContributionRecur::update(FALSE)
            ->addWhere('id', '=', $contributionRecur['id'])
            ->addValue('contribution_status_id:name', 'Cancelled')
            ->addValue('cancel_date', date('Y-m-d H:i:s'))
            ->addValue('cancel_reason', 'Automatically cancelled by PayPal (payment processor). Profile ID: ' . $profileID)
            ->execute();

          // Create a staff alert activity on the contact's record.
          $this->createCancellationAlert($contributionRecur, $profileID);

          $results[$contributionRecur['id']]['cancelled'] = TRUE;
          \Civi::log('payflowpro')->warning('PayflowPro: Profile ' . $profileID . ' has been cancelled by PayPal. ContributionRecur ID: ' . $contributionRecur['id'] . '. Contact ID: ' . $contributionRecur['contact_id']);
        }
        else {
          $results[$contributionRecur['id']]['cancelled'] = FALSE;
        }
      }
      catch (\Exception $e) {
        $results[$contributionRecur['id']]['error'] = $e->getMessage();
        \Civi::log('payflowpro')->error('PayflowPro: Error checking profile status for ' . $profileID . ': ' . $e->getMessage());
      }
    }

    return $results;
  }

  /**
   * Create a CiviCRM activity to alert staff that a recurring profile has been
   * cancelled by PayPal.
   *
   * Staff can use this activity as a queue to review cancelled profiles and
   * take action such as contacting the member or reactivating the profile via
   * the reactivate button on the ContributionRecur record.
   *
   * @param array $contributionRecur  The ContributionRecur record.
   * @param string $profileID         The PayflowPro profile ID.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function createCancellationAlert(array $contributionRecur, string $profileID): void {
    $recurUrl = \CRM_Utils_System::url(
      'civicrm/contact/view/contributionrecur',
      ['reset' => 1, 'id' => $contributionRecur['id'], 'cid' => $contributionRecur['contact_id']],
      TRUE
    );
    $subject = \CRM_Payflowpro_ExtensionUtil::ts(
      'PayflowPro recurring profile cancelled by PayPal - Profile ID: %1',
      [1 => $profileID]
    );
    $details = \CRM_Payflowpro_ExtensionUtil::ts(
      'The PayflowPro recurring profile %1 has been automatically cancelled by PayPal. ' .
      'This may be due to exceeding the maximum number of failed payment attempts (MAXFAILPAYMENTS). ' .
      'The recurring contribution (ID: %2) has been marked as Cancelled in CiviCRM. ' .
      'Please review and take action: contact the member to update payment details, then reactivate the profile. ' .
      'View recurring contribution: %3',
      [
        1 => $profileID,
        2 => $contributionRecur['id'],
        3 => $recurUrl,
      ]
    );

    Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Payment Processor Notification')
      ->addValue('status_id:name', 'Scheduled')
      ->addValue('subject', $subject)
      ->addValue('details', $details)
      ->addValue('target_contact_id', $contributionRecur['contact_id'])
      ->addValue('source_contact_id', $contributionRecur['contact_id'])
      ->execute();
  }

}
