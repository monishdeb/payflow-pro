<?php

use CRM_Payflowpro_ExtensionUtil as E;

return [
  [
    'name' => 'job_payflowpro_checkcancelledprofiles',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'is_active' => FALSE,
        'name' => 'PayflowPro: Check for cancelled recurring profiles',
        'description' => E::ts('Query PayflowPro API for each active recurring contribution to detect profiles auto-cancelled by PayPal (e.g. due to exceeding MAXFAILPAYMENTS). Creates a staff alert activity when a cancellation is found.'),
        'run_frequency' => 'Daily',
        'api_entity' => 'PayflowPro',
        'api_action' => 'checkCancelledProfiles',
        'parameters' => 'version=4
paymentProcessorID=1',
      ],
    ],
  ],
];
