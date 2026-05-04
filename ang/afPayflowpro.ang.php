<?php

// Angular module crmPayflowpro
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
return [
  'js'       => [
    'ang/afPayflowpro.js',
    'ang/afPayflowpro/*.js',
    'ang/afPayflowpro/*/*.js',
  ],
  'css'      => [
    'ang/afPayflowpro.css',
  ],
  'partials' => ['ang/afPayflowpro'],
  'requires' => ['afCheckout'],
  'settings' => [],
  'exports'  => [
    'af-payflowpro-payment-token-checkout' => 'E',
  ],
];
