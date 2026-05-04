<?php

namespace Civi\PayflowPro;

use Civi\Checkout\CheckoutOptionUtils;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @service civi.payflowpro.checkoutOptions
 */
class PayflowProConnections extends AutoService implements EventSubscriberInterface {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents() {
    return [
      'civi.checkout.options' => 'getCheckoutOptions',
    ];
  }

  public function getCheckoutOptions(GenericHookEvent $e): void {
    $payflowProPairs = CheckoutOptionUtils::getPaymentProcessorPairs(['PayflowPro']);

    foreach ($payflowProPairs as $name => $pair) {
      $e->options["payflowpro_{$name}"] = new CheckoutOption\PayflowPro($pair['live'], $pair['test']);
      $e->options["payflowpro_token_{$name}"] = new CheckoutOption\PaymentToken($pair['live'], $pair['test']);
    }
  }

}