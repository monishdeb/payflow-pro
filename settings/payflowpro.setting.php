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

use CRM_Payflowpro_ExtensionUtil as E;

return [
  'payflowpro_testmodesettlement' => [
    'name' => 'payflowpro_testmodesettlement',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('In test mode complete transactions pending settlement.'),
    'description' => E::ts('In test mode some banks (eg. FISERV) don\'t progress to "settlement completed/successfully". Enable this to treat "settlement pending" as Completed.'),
    'html_attributes' => [],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 10,
      ]
    ],
  ],
  'payflowpro_cardonfile' => [
    'name' => 'payflowpro_cardonfile',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Card-on-file functionality.'),
    'description' => E::ts('Enable this to allow users to save their cards for future use.'),
    'html_attributes' => [],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 20,
      ]
    ],
  ],
  'payflowpro_retrynumdays' => [
    'name' => 'payflowpro_retrynumdays',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 1,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Failed payment retry days (RETRYNUMDAYS)'),
    'description' => E::ts('Number of days to retry a failed payment before waiting until the next billing period. Default: 1, maximum: 4. PayPal will attempt the payment once per day for this many days before giving up until the next billing cycle.'),
    'html_attributes' => [
      'min' => 1,
      'max' => 4,
      'size' => 4,
    ],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 30,
      ]
    ],
  ],
  'payflowpro_maxfailpayments' => [
    'name' => 'payflowpro_maxfailpayments',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Maximum failed payments before cancellation (MAXFAILPAYMENTS)'),
    'description' => E::ts('Number of failed payment periods allowed before PayPal automatically cancels the recurring profile. Set to 0 (recommended) for indefinite retries with no auto-cancellation. WARNING: This counts total failures across the entire lifetime of the profile, not per billing period. Setting to 3 means the profile is cancelled after any 3 failures even if they occur months apart.'),
    'html_attributes' => [
      'min' => 0,
      'size' => 4,
    ],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 40,
      ]
    ],
  ],
];
