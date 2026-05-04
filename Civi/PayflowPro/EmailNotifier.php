<?php

namespace Civi\PayflowPro;

use CRM_Payflowpro_ExtensionUtil as E;

/**
 * Sends email notifications to configured staff/admin addresses when recurring
 * payment profiles encounter failures or are cancelled.
 *
 * Email addresses are configured via the 'payflowpro_notification_email'
 * domain setting (comma-separated list).
 */
class EmailNotifier {

  /**
   * Send an email notification when a recurring payment fails.
   *
   * @param array $contributionRecur
   *   The ContributionRecur record.
   * @param array $contribution
   *   The failed Contribution record.
   * @param array $failureDetails
   *   Details about the failure (keys: failure_reason, failure_code, trxn_id).
   *
   * @return bool
   *   TRUE if email was sent, FALSE if no recipients configured or send failed.
   */
  public function sendFailureNotification(array $contributionRecur, array $contribution, array $failureDetails): bool {
    $recipients = $this->getRecipients();
    if (empty($recipients)) {
      return FALSE;
    }

    $contact = $this->getContact((int) $contributionRecur['contact_id']);

    $subject = E::ts('PayflowPro: Recurring payment failure for %1 (Recur ID %2)', [
      1 => $contact['display_name'] ?? E::ts('Unknown'),
      2 => $contributionRecur['id'],
    ]);

    $body = $this->buildFailureEmailBody($contributionRecur, $contribution, $failureDetails, $contact);

    return $this->sendEmail($recipients, $subject, $body);
  }

  /**
   * Send an email notification when a recurring payment profile is cancelled.
   *
   * @param array $contributionRecur
   *   The ContributionRecur record.
   * @param string $reason
   *   Human-readable reason for the cancellation.
   *
   * @return bool
   *   TRUE if email was sent, FALSE if no recipients configured or send failed.
   */
  public function sendCancellationNotification(array $contributionRecur, string $reason): bool {
    $recipients = $this->getRecipients();
    if (empty($recipients)) {
      return FALSE;
    }

    $contact = $this->getContact((int) $contributionRecur['contact_id']);

    $subject = E::ts('PayflowPro: Recurring profile cancelled for %1 (Recur ID %2)', [
      1 => $contact['display_name'] ?? E::ts('Unknown'),
      2 => $contributionRecur['id'],
    ]);

    $body = $this->buildCancellationEmailBody($contributionRecur, $reason, $contact);

    return $this->sendEmail($recipients, $subject, $body);
  }

  /**
   * Get the list of notification recipient email addresses from settings.
   *
   * @return array
   *   Array of email address strings, empty if none configured.
   */
  private function getRecipients(): array {
    $setting = \Civi::settings()->get('payflowpro_notification_email');
    if (empty($setting)) {
      return [];
    }
    $emails = array_map('trim', explode(',', $setting));
    return array_filter($emails);
  }

  /**
   * Retrieve basic contact details.
   *
   * @param int $contactID
   *
   * @return array
   */
  private function getContact(int $contactID): array {
    try {
      return \Civi\Api4\Contact::get(FALSE)
        ->addSelect('display_name', 'id')
        ->addWhere('id', '=', $contactID)
        ->execute()
        ->first() ?? [];
    }
    catch (\Exception $e) {
      \Civi::log('payflowpro')->warning('EmailNotifier: Could not retrieve contact ' . $contactID . ': ' . $e->getMessage());
      return [];
    }
  }

  /**
   * Build the email body for a failed payment notification.
   *
   * @param array $contributionRecur
   * @param array $contribution
   * @param array $failureDetails
   * @param array $contact
   *
   * @return string
   */
  private function buildFailureEmailBody(array $contributionRecur, array $contribution, array $failureDetails, array $contact): string {
    $siteURL = \CRM_Utils_System::baseCMSURL();
    $contactName = $contact['display_name'] ?? E::ts('Unknown');
    $contactID = $contributionRecur['contact_id'] ?? E::ts('N/A');
    $recurID = $contributionRecur['id'] ?? E::ts('N/A');
    $profileID = $contributionRecur['processor_id'] ?? E::ts('N/A');
    $amount = \CRM_Utils_Money::format($contributionRecur['amount'] ?? 0, $contributionRecur['currency'] ?? NULL);
    $frequency = trim(($contributionRecur['frequency_interval'] ?? 1) . ' ' . ($contributionRecur['frequency_unit'] ?? ''));
    $failureReason = $failureDetails['failure_reason'] ?? E::ts('Unknown reason');
    $failureCode = $failureDetails['failure_code'] ?? '';
    $trxnID = $failureDetails['trxn_id'] ?? E::ts('N/A');

    $lines = [
      E::ts('A recurring payment has failed in PayflowPro. Please review and take action.'),
      '',
      E::ts('--- Contact Details ---'),
      E::ts('Name: %1', [1 => $contactName]),
      E::ts('Contact ID: %1', [1 => $contactID]),
      '',
      E::ts('--- Contribution Details ---'),
      E::ts('Contribution Recur ID: %1', [1 => $recurID]),
      E::ts('PayflowPro Profile ID: %1', [1 => $profileID]),
      E::ts('Amount: %1', [1 => $amount]),
      E::ts('Frequency: %1', [1 => $frequency]),
      '',
      E::ts('--- Failure Details ---'),
      E::ts('Reason: %1', [1 => $failureReason]),
    ];

    if ($failureCode !== '') {
      $lines[] = E::ts('Failure Code: %1', [1 => $failureCode]);
    }

    $lines[] = E::ts('Transaction ID: %1', [1 => $trxnID]);
    $lines[] = '';
    $lines[] = E::ts('--- Suggested Actions ---');
    $lines[] = E::ts('1. Contact the member to update their payment information.');
    $lines[] = E::ts('2. Once updated, reactivate the recurring profile if needed.');
    $lines[] = E::ts('3. Review the contribution record in CiviCRM: %1civicrm/contact/view/contributionrecur?reset=1&id=%2&cid=%3', [
      1 => $siteURL,
      2 => $recurID,
      3 => $contactID,
    ]);

    return implode("\n", $lines);
  }

  /**
   * Build the email body for a profile cancellation notification.
   *
   * @param array $contributionRecur
   * @param string $reason
   * @param array $contact
   *
   * @return string
   */
  private function buildCancellationEmailBody(array $contributionRecur, string $reason, array $contact): string {
    $siteURL = \CRM_Utils_System::baseCMSURL();
    $contactName = $contact['display_name'] ?? E::ts('Unknown');
    $contactID = $contributionRecur['contact_id'] ?? E::ts('N/A');
    $recurID = $contributionRecur['id'] ?? E::ts('N/A');
    $profileID = $contributionRecur['processor_id'] ?? E::ts('N/A');
    $amount = \CRM_Utils_Money::format($contributionRecur['amount'] ?? 0, $contributionRecur['currency'] ?? NULL);
    $frequency = trim(($contributionRecur['frequency_interval'] ?? 1) . ' ' . ($contributionRecur['frequency_unit'] ?? ''));

    $lines = [
      E::ts('A recurring payment profile has been cancelled in PayflowPro. Please review and take action.'),
      '',
      E::ts('--- Contact Details ---'),
      E::ts('Name: %1', [1 => $contactName]),
      E::ts('Contact ID: %1', [1 => $contactID]),
      '',
      E::ts('--- Contribution Details ---'),
      E::ts('Contribution Recur ID: %1', [1 => $recurID]),
      E::ts('PayflowPro Profile ID: %1', [1 => $profileID]),
      E::ts('Amount: %1', [1 => $amount]),
      E::ts('Frequency: %1', [1 => $frequency]),
      '',
      E::ts('--- Cancellation Details ---'),
      E::ts('Reason: %1', [1 => $reason]),
      '',
      E::ts('--- Suggested Actions ---'),
      E::ts('1. Contact the member to discuss reactivating their recurring contribution.'),
      E::ts('2. To reactivate the profile, go to: %1civicrm/contact/view/contributionrecur?reset=1&id=%2&cid=%3', [
        1 => $siteURL,
        2 => $recurID,
        3 => $contactID,
      ]),
    ];

    return implode("\n", $lines);
  }

  /**
   * Send a plain-text email to a list of recipients.
   *
   * @param array $recipients
   *   Array of email address strings.
   * @param string $subject
   * @param string $body
   *
   * @return bool
   */
  private function sendEmail(array $recipients, string $subject, string $body): bool {
    if (empty($recipients)) {
      return FALSE;
    }

    $from = \CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE);
    $fromEmail = reset($from);

    $params = [
      'subject' => $subject,
      'text' => $body,
      'from' => $fromEmail,
    ];

    $success = FALSE;
    foreach ($recipients as $toEmail) {
      if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        \Civi::log('payflowpro')->warning('EmailNotifier: Skipping invalid email address: ' . $toEmail);
        continue;
      }
      try {
        $params['toEmail'] = $toEmail;
        \CRM_Utils_Mail::send($params);
        $success = TRUE;
        \Civi::log('payflowpro')->info('EmailNotifier: Notification sent to ' . $toEmail . ' — ' . $subject);
      }
      catch (\Exception $e) {
        \Civi::log('payflowpro')->error('EmailNotifier: Failed to send to ' . $toEmail . ': ' . $e->getMessage());
      }
    }

    return $success;
  }

}
