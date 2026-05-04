<?php

namespace Civi\PayflowPro;

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Payflowpro_ExtensionUtil as E;

/**
 * This implements an abstraction layer for the PayflowPro API
 * used by CRM_Core_Payment_PayflowPro and \Civi\PayflowPro\RecurIPN
 */
class Api {

  /**
   * PayflowPro decline/error codes (RESULT): https://www.paypalobjects.com/en_US/vhelp/paypalmanager_help/result_values_for_transaction_declines_or_errors.htm
   * Additionall will return:
   * - RESPMSG: Optional response message.
   * - RPREF: Reference number to this particular action request.
   * - PROFILEID If RESULT = 0, then this value is the Profile ID.
   *      Profile IDs for test profiles start with the characters RT.
   *      Profile IDs for live profiles start with RP.
   */

  /**
   * @var \CRM_Core_Payment_PayflowPro $paymentProcessor;
   */
  protected \CRM_Core_Payment_PayflowPro $paymentProcessor;

  /**
   * @param \CRM_Core_Payment_PayflowPro $paymentProcessor
   */
  public function __construct(\CRM_Core_Payment_PayflowPro $paymentProcessor) {
    $this->paymentProcessor = $paymentProcessor;
  }

  /**
   * Get the PaymentProcessor object
   */
  private function getPaymentProcessor(): \CRM_Core_Payment_PayflowPro {
    return $this->paymentProcessor;
  }

  /**
   * Get the array of PaymentProcessor configuration
   *
   * @return array
   */
  private function getPaymentProcessorArray(): array {
    return $this->paymentProcessor->getPaymentProcessor();
  }

  /**
   * The PayflowPro user
   *
   * @return string
   */
  public function getUser(): string {
    //if you have not set up a separate user account the vendor name is used as the username
    if (!$this->getPaymentProcessorArray()['subject']) {
      return $this->getPaymentProcessorArray()['user_name'];
    }
    else {
      return $this->getPaymentProcessorArray()['subject'];
    }
  }

  /**
   * Convert the CiviCRM PaymentProcessor auth params into keys for PayflowPro authentication
   *
   * @return array
   */
  public function getQueryArrayAuth(): array {
    return [
      'USER' => $this->getUser(),
      'VENDOR' => $this->getPaymentProcessorArray()['user_name'],
      'PARTNER' => $this->getPaymentProcessorArray()['signature'],
      'PWD' => $this->getPaymentProcessorArray()['password'],
    ];
  }

  /**
   * convert to a name/value pair (nvp) string
   *
   * @param $payflow_query_array
   *
   * @return array|string
   */
  public function convert_to_nvp($payflow_query_array) {
    foreach ($payflow_query_array as $key => $value) {
      $payflow_query[] = $key . '[' . strlen($value) . ']=' . $value;
    }
    $payflow_query = implode('&', $payflow_query);

    return $payflow_query;
  }

  /**
   * Submit transaction using cURL
   *
   * @param string $payflow_query value string to be posted
   *
   * @return string
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function submit_transaction(string $payflow_query): string {
    $submiturl = $this->getPaymentProcessorArray()['url_site'];
    // get data ready for API
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Guzzle';
    // Here's your custom headers; adjust appropriately for your setup:
    $headers[] = "Content-Type: text/namevalue";
    //or text/xml if using XMLPay.
    $headers[] = "Content-Length : " . strlen($payflow_query);
    // Length of data to be passed
    // Here the server timeout value is set to 45, but notice
    // below in the cURL section, the timeout
    // for cURL is 90 seconds.  You want to make sure the server
    // timeout is less, then the connection.
    $headers[] = "X-VPS-Timeout: 45";
    //random unique number  - the transaction is retried using this transaction ID
    // in this function but if that doesn't work and it is re- submitted
    // it is treated as a new attempt. Payflow Pro doesn't allow
    // you to change details (e.g. card no) when you re-submit
    // you can only try the same details
    $headers[] = "X-VPS-Request-ID: " . rand(1, 1000000000);
    // optional header field
    $headers[] = "X-VPS-VIT-Integration-Product: CiviCRM";
    // other Optional Headers.  If used adjust as necessary.
    // Name of your OS
    //$headers[] = "X-VPS-VIT-OS-Name: Linux";
    // OS Version
    //$headers[] = "X-VPS-VIT-OS-Version: RHEL 4";
    // What you are using
    //$headers[] = "X-VPS-VIT-Client-Type: PHP/cURL";
    // For your info
    //$headers[] = "X-VPS-VIT-Client-Version: 0.01";
    // For your info
    //$headers[] = "X-VPS-VIT-Client-Architecture: x86";
    // Application version
    //$headers[] = "X-VPS-VIT-Integration-Version: 0.01";
    $response = $this->paymentProcessor->getGuzzleClient()->post($submiturl, [
      'body' => $payflow_query,
      'headers' => $headers,
      'curl' => [
        CURLOPT_SSL_VERIFYPEER => \Civi::settings()->get('verifySSL'),
        CURLOPT_USERAGENT => $user_agent,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYHOST => \Civi::settings()->get('verifySSL') ? 2 : 0,
        CURLOPT_POST => TRUE,
      ],
    ]);

    // Try to submit the transaction up to 3 times with 5 second delay.  This can be used
    // in case of network issues.  The idea here is since you are posting via HTTPS there
    // could be general network issues, so try a few times before you tell customer there
    // is an issue.

    $i = 1;
    while ($i++ <= 3) {
      $responseData = $response->getBody();
      $http_code = $response->getStatusCode();
      if ($http_code != 200) {
        // Let's wait 5 seconds to see if its a temporary network issue.
        sleep(5);
      }
      elseif ($http_code == 200) {
        // we got a good response, drop out of loop.
        break;
      }
    }
    if ($http_code != 200) {
      throw new PaymentProcessorException('Error connecting to the Payflow Pro API server.', 9015);
    }

    $responseDataString = $responseData->getContents();
    if (($responseDataString === FALSE) || (strlen($responseDataString) === 0)) {
      throw new PaymentProcessorException("Error: Connection to payment gateway failed - no data
                                           returned. Gateway url set to $submiturl", 9006);
    }

    /*
     * If gateway returned no data - tell 'em and bail out
     */
    if (empty($responseDataString)) {
      throw new PaymentProcessorException('Error: No data returned from payment gateway.', 9007);
    }

    /*
     * Success so far - close the curl and check the data
     */
    return $responseDataString;
  }

  /**
   * Process the response received from PayflowPro API
   *
   * @param string $responseData
   *
   * @return mixed
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function processResponseData(string $responseData) {
    $responseResult = strstr($responseData, 'RESULT');
    if (empty($responseResult)) {
      throw new PaymentProcessorException('No RESULT code from PayPal.', 9016);
    }

    $nvpArray = [];
    while (strlen($responseResult)) {
      // name
      $keypos = strpos($responseResult, '=');
      $keyval = substr($responseResult, 0, $keypos);
      // value
      $valuepos = strpos($responseResult, '&') ? strpos($responseResult, '&') : strlen($responseResult);
      $valval = substr($responseResult, $keypos + 1, $valuepos - $keypos - 1);
      // decoding the respose
      $nvpArray[$keyval] = $valval;
      $responseResult = substr($responseResult, $valuepos + 1, strlen($responseResult));
    }
    // get the result code to validate.
    $result_code = $nvpArray['RESULT'];
    if ($result_code > 0) {
      $this->logError($nvpArray['RESPMSG']);
    }

    return $nvpArray;
  }

  /**
   * Log an info message with payment processor prefix
   * @param string $message
   *
   * @return void
   */
  public function logInfo(string $message) {
    $this->log('info', $message);
  }

  /**
   * Log an error message with payment processor prefix
   *
   * @param string $message
   *
   * @return void
   */
  public function logError(string $message) {
    $this->log('error', $message);
  }

  /**
   * Log a debug message with payment processor prefix
   *
   * @param string $message
   *
   * @return void
   */
  public function logDebug(string $message) {
    $this->log('debug', $message);
  }

  /**
   * @param string $level
   * @param string $message
   *
   * @return void
   */
  private function log(string $level, string $message) {
    $channel = 'payflowpro';
    $prefix = $channel . '(' . $this->getPaymentProcessor()->getID() . '): ';
    \Civi::log($channel)->$level($prefix . $message);
  }

  /**
   * @param string $recurProfileID
   * @param string $paymentHistoryType
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getRecurPaymentHistory(string $recurProfileID, string $paymentHistoryType = 'Y') {
    // Call the API to get the information about the recurring subscription.
    $payflowApi = $this;
    $payflow_query_array = $payflowApi->getQueryArrayAuth();
    $payflow_query_array['ACTION'] = 'I';
    $payflow_query_array['TRXTYPE'] = 'R';
    $payflow_query_array['ORIGPROFILEID'] = $recurProfileID;
    $payflow_query_array['PAYMENTHISTORY'] = $paymentHistoryType;

    $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

    $responseData = $payflowApi->submit_transaction($payflow_query);

    $nvpArray = $payflowApi->processResponseData($responseData);

    // get the result code to validate.
    $result_code = $nvpArray['RESULT'];
    if ($result_code > 0) {
      throw new PaymentProcessorException($nvpArray['RESPMSG']);
    }

    /**
     * Will return something in this format. We'll parse it now
    RESULT=0&RPREF=RKM500141021&PROFILEID=RT0000000100&P_PNREF1=VWYA06156256&P_
    TRANSTIME1=21-May-04 04:47
    PM&P_RESULT1=0&P_TENDER1=C&P_AMT1=1.00&P_TRANSTATE1=8&P_PNREF2=VWYA06156269
    &P_TRANSTIME2=27-May-04 01:19
     */

    $keyMap = [
      'PNREF' => [
        'key' => 'trxn_id',
        'type' => 'String',
      ],
      'TRANSTIME' => [
        // This is in format 21-May-04 04:47 that strtotime can parse.
        'key' => 'trxn_date',
        'type' => 'Timestamp',
      ],
      'TRANSTATE' => [
        'key' => 'status_id:name',
        'type' => 'Status',
      ],
      'TENDER' => [
        'key' => 'payment_method',
        'type' => 'String',
      ],
      'AMT' => [
        'key' => 'amount',
        'type' => 'Float',
      ],
      'NEXTPAYMENT' => [
        // This is in format 08302024 ie. MdY that strtotime can't parse..
        'key' => 'next_sched_contribution_date',
        'type' => 'NextPaymentFormat',
      ]
    ];

    // Result is 0 (ok) since we got here
    foreach ($nvpArray as $key => $value) {
      // It's a "payment" parameter
      // We're only going to look for the following parameters:
      // - PNREF: Payment reference: trxn_id eg. VWYA06156256
      // - TRANSTIME: Transaction time. Eg. 21-May-04 04:47
      // - TRANSTATE: Transaction status: one of:
      //    - 1: error
      //    - 6: settlement pending
      //    - 7: settlement in progress
      //    - 8: settlement completed/successfully
      //    - 11: settlement failed
      //    - 14: settlement incomplete
      // - TENDER: C = Credit card; P = PayPal; A = Automated Clearinghouse
      // - AMT: Amount (eg. 1.00)
      foreach ($keyMap as $srcKey => $dest) {
        if (str_contains($key, $srcKey)) {
          // Historical payments are prefixed with "P_" and suffixed with the payment ID.
          // So eg. P_XX2 parameters all represent payment 2 and P_XX1 represent payment 1.
          // But XX parameters represent a current value for the subscription - eg. AMT.
          if (str_starts_with($key, 'P_')) {
            $paymentID = filter_var($key, FILTER_SANITIZE_NUMBER_INT);
            $baseAPIKey = substr($key, 2, strlen($key) - strlen($paymentID) - 2);
          }
          else {
            $paymentID = 0;
            $baseAPIKey = $key;
          }

          switch ($dest['type']) {
            case 'Timestamp':
              $paymentsByID[$paymentID][$dest['key']] = strtotime($value);
              break;

            case 'NextPaymentFormat':
              if ($baseAPIKey === 'NEXTPAYMENT') {
                $timestamp = \DateTime::createFromFormat('mdY', $value)->getTimestamp();
                $paymentsByID[$paymentID][$dest['key']] = $timestamp;
              }
              break;

            case 'Status':
              $mapToCivi = [
                1 => [
                  'name' => 'Failed',
                  'description' => 'error',
                ],
                6 => [
                  'name' => 'Pending',
                  'description' => 'settlement pending',
                ],
                7 => [
                  'name' => 'Pending',
                  'description' => 'settlement in progress',
                ],
                8 => [
                  'name' => 'Completed',
                  'description' => 'settlement completed/successfully',
                ],
                11 => [
                  'name' => 'Failed',
                  'description' => 'settlement failed',
                ],
                14 => [
                  'name' => 'Failed',
                  'description' => 'settlement incomplete',
                ],
              ];
              $paymentsByID[$paymentID][$srcKey] = $value;
              $paymentsByID[$paymentID][$dest['key'] . '_description'] = $mapToCivi[$value]['description'];
              $paymentsByID[$paymentID][$dest['key']] = $mapToCivi[$value]['name'];
              break;

            default:
              $paymentsByID[$paymentID][$dest['key']] = $value;
          }
        }
      }
    }
    foreach ($paymentsByID as $id => $detail) {
      $date = $paymentsByID[$id]['trxn_date'] ?? $paymentsByID[$id]['next_sched_contribution_date'];
      $paymentsByDate[$date] = $detail;
      $paymentsByDate[$date]['id'] = $id;
    }

    return $paymentsByDate ?? [];
  }

  /**
   * Reactivate a cancelled PayFlowPro subscription
   *
   * @param string $recurProfileID
   *   The ContributionRecur.processor_id - the subscription/profileID from PayflowPro eg. RT0000000027
   * @param string|null $startDate
   *   Start date, will be converted to mdY - you should pass in as Ymd
   *
   * @return void
   */
  public function reactivateSubscription(string $recurProfileID, ?string $startDate = NULL): array {
    // See https://www.paypalobjects.com/webstatic/en_US/developer/docs/pdf/pp_wpppf_recurringbilling_guide.pdf P22.
    $payflow_query_array = $this->getQueryArrayAuth();
    $payflow_query_array['TRXTYPE'] = 'R';
    $payflow_query_array['ACTION'] = 'R';
    // @todo not sure if we use ORIGPROFILEID, ACCT or PROFILENAME here?
    $payflow_query_array['ORIGPROFILEID'] = $recurProfileID;
    if ($startDate) {
      $payflow_query_array['START'] = date('mdY', strtotime($startDate));
    }

    $payflow_query = $this->convert_to_nvp($payflow_query_array);

    $responseData = $this->submit_transaction($payflow_query);

    $nvpArray = $this->processResponseData($responseData);

    $resultArray = [
      'message' => '',
      'code' => $nvpArray['RESULT'],
      'response_message' => $nvpArray['RESPMSG'] ?? '',
      'rpref' => $nvpArray['RPREF'] ?? '',
      'success' => TRUE,
    ];

    switch ($nvpArray['RESULT']) {
      case 0:
        // Success.
        $resultArray['message'] = E::ts('Successfully reactivated PayflowPro subscription');

      default:
        $resultArray['success'] = FALSE;
        $resultArray['message'] = E::ts('Failed to reactivate PayflowPro subscription');
    }
    return $resultArray;
  }

  /**
   * @param string $amount eg. "1.23"
   * @param string $frequencyUnit eg "year"
   * @param int $frequencyInterval eg 1
   * @param int $installments eg 2
   * @param bool $isAuthorizeOnly - default FALSE, if TRUE, we setup (authorize) a new card/subscription without taking payment.
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function addSubscriptionParams(string $amount, string $frequencyUnit, int $frequencyInterval, int $installments = 0, bool $isAuthorizeOnly = FALSE): array {
    $payflow_query_array['TRXTYPE'] = 'R';
    if ($isAuthorizeOnly) {
      $payflow_query_array['OPTIONALTRX'] = 'A';
    }
    else {
      $payflow_query_array['OPTIONALTRX'] = 'S';
      // @todo Do we need to machinemoney format? ie. 1024.00 or is 1024 ok for API?
      $payflow_query_array['OPTIONALTRXAMT'] = \Civi::format()->machineMoney($amount);
    }
    // Amount of the initial Transaction. Required.
    $payflow_query_array['ACTION'] = 'A';
    // A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment.
    $payflow_query_array['PROFILENAME'] = urlencode('RegularContribution');
    // A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment.
    // TERM: in addition to the one happening with this transaction. If indefinite set TERM=0.
    $payflow_query_array['TERM'] = (($installments === 0) ? 0 : $installments - 1);

    // $payflow_query_array['COMPANYNAME']
    // $payflow_query_array['DESC']  =  not set yet  Optional
    // description of the goods or
    // services being purchased.
    // This parameter applies only for ACH_CCD accounts.

    // MAXFAILPAYMENTS: Number of billing periods (as specified by PAYPERIOD) for which a failed
    // payment is allowed before PayPal auto-cancels the profile. Default 0 = no limit (recommended).
    // WARNING: This counts total failures across the entire profile lifetime, not per billing period.
    // Setting to a non-zero value (e.g. 3) will cancel the profile after 3 failures even if they
    // are months apart. Use 0 to prevent accidental auto-cancellation; rely on staff notifications instead.
    $maxFailPayments = (int) (\Civi::settings()->get('payflowpro_maxfailpayments') ?? 0);
    $payflow_query_array['MAXFAILPAYMENTS'] = max(0, $maxFailPayments);

    // RETRYNUMDAYS: Number of days to retry a failed payment before waiting until the next billing
    // cycle. Valid values: 1–4. Default: 1.
    // Note: PayPal does not automatically retry when card details are updated mid-cycle; a manual
    // reactivation or period-payment action (ACTION=P) would be required to trigger an early retry.
    $retryNumDays = (int) (\Civi::settings()->get('payflowpro_retrynumdays') ?? 1);
    $payflow_query_array['RETRYNUMDAYS'] = min(4, max(1, $retryNumDays));

    if ($frequencyUnit === 'day') {
      throw new PaymentProcessorException('Current implementation does not support recurring with frequency "day"');
    }
    $interval = $frequencyInterval . " " . $frequencyUnit;
    switch ($interval) {
      case '1 week':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 7, date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (7 * $payflow_query_array['TERM']), date("Y"));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "WEEK";
        $paymentParams['frequency_unit'] = 'week';
        $paymentParams['frequency_interval'] = 1;
        break;

      case '2 weeks':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 14, date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (14 * $payflow_query_array['TERM']), date("Y "));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "BIWK";
        $paymentParams['frequency_unit'] = 'week';
        $paymentParams['frequency_interval'] = 2;
        break;

      case '4 weeks':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 28, date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (28 * $payflow_query_array['TERM']), date("Y"));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "FRWK";
        $paymentParams['frequency_unit'] = 'week';
        $paymentParams['frequency_interval'] = 4;
        break;

      case '1 month':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 1, date("d"), date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (1 * $payflow_query_array['TERM']), date("d"), date("Y"));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "MONT";
        $paymentParams['frequency_unit'] = 'month';
        $paymentParams['frequency_interval'] = 1;
        break;

      case '3 months':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 3, date("d"), date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (3 * $payflow_query_array['TERM']), date("d"), date("Y"));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "QTER";
        $paymentParams['frequency_unit'] = 'month';
        $paymentParams['frequency_interval'] = 3;
        break;

      case '6 months':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 6, date("d"), date("Y"));
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (6 * $payflow_query_array['TERM']), date("d"), date("Y"));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "SMYR";
        $paymentParams['frequency_unit'] = 'month';
        $paymentParams['frequency_interval'] = 6;
        break;

      case '1 year':
        $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d"), date("Y") + 1);
        $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d"), date("Y") + (1 * $payflow_query_array['TERM']));
        $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
        $payflow_query_array['PAYPERIOD'] = "YEAR";
        $paymentParams['frequency_unit'] = 'year';
        $paymentParams['frequency_interval'] = 1;
        break;
    }
    return [$payflow_query_array, $paymentParams ?? []];
  }

}
