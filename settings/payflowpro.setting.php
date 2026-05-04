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
  'payflowpro_notification_email' => [
    'name' => 'payflowpro_notification_email',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Notification email address(es)'),
    'description' => E::ts('Comma-separated list of email addresses to notify when a recurring payment profile is cancelled or fails (e.g. admin@example.com,staff@example.com). Leave blank to disable email notifications.'),
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 30,
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
    'title' => E::ts('Retry number of days (RETRYNUMDAYS)'),
    'description' => E::ts('Number of days to retry a failed payment before waiting until the next billing cycle. Valid values: 1-4. Default: 1. Per PayPal Recurring Billing specification (RETRYNUMDAYS).'),
    'html_attributes' => [
      'size' => 5,
      'min' => 1,
      'max' => 4,
    ],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 40,
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
    'title' => E::ts('Maximum failed payments (MAXFAILPAYMENTS)'),
    'description' => E::ts('Number of failed payment periods allowed before PayPal automatically cancels the profile. Set to 0 (recommended) for no limit — this prevents accidental profile cancellations since failures are counted across the entire profile lifetime, not per billing period. Per PayPal Recurring Billing specification (MAXFAILPAYMENTS).'),
    'html_attributes' => [
      'size' => 5,
      'min' => 0,
    ],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 50,
      ]
    ],
  ],
];
