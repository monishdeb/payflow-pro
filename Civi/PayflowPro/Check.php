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

namespace Civi\PayflowPro;

use Civi\Core\Service\AutoSubscriber;
use CRM_Payflowpro_ExtensionUtil as E;

class Check extends AutoSubscriber {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents(): array {
    return [
      '&hook_civicrm_check' => 'checkRequirements',
    ];
  }

  /**
   * @var string
   */
  const MIN_VERSION_MJWSHARED = '1.5.0';

  /**
   * @var array
   */
  private array $messages;

  /**
   * Implements hook_civicrm_check()
   *
   * @param array $messages
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function checkRequirements(array &$messages): void {
    $this->messages = $messages;
    $this->checkExtensionMjwshared();
    $this->checkCURLOpts();
    $messages = $this->messages;
  }

  /**
   * @param string $extensionName
   * @param string $minVersion
   * @param string $actualVersion
   */
  private function requireExtensionMinVersion(string $extensionName, string $minVersion, string $actualVersion) {
    $actualVersionModified = $actualVersion;
    if (substr($actualVersion, -4) === '-dev') {
      $actualVersionModified = substr($actualVersion, 0, -4);
      $devMessageAlreadyDefined = FALSE;
      foreach ($this->messages as $message) {
        if ($message->getName() === __FUNCTION__ . $extensionName . '_requirements_dev') {
          // Another extension already generated the "Development version" message for this extension
          $devMessageAlreadyDefined = TRUE;
        }
      }
      if (!$devMessageAlreadyDefined) {
        $message = new \CRM_Utils_Check_Message(
          __FUNCTION__ . $extensionName . '_requirements_dev',
          E::ts('You are using a development version of %1 extension.',
            [1 => $extensionName]),
          E::ts('%1: Development version', [1 => $extensionName]),
          \Psr\Log\LogLevel::WARNING,
          'fa-code'
        );
        $this->messages[] = $message;
      }
    }

    if (version_compare($actualVersionModified, $minVersion) === -1) {
      $message = new \CRM_Utils_Check_Message(
        __FUNCTION__ . $extensionName . E::SHORT_NAME . '_requirements',
        E::ts('The %1 extension requires the %2 extension version %3 or greater but your system has version %4.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => $extensionName,
            3 => $minVersion,
            4 => $actualVersion
          ]),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-exclamation-triangle'
      );
      $message->addAction(
        E::ts('Upgrade now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $this->messages[] = $message;
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function checkExtensionMjwshared(): void {
    // mjwshared: required. Requires min version
    $extensionKey = 'mjwshared';
    $extensionName = 'Payment Shared (MJWshared)';
    $extension = \Civi\Api4\Extension::get(FALSE)
      ->addSelect('status', 'version')
      ->addWhere('key', '=', $extensionKey)
      ->execute()
      ->first();

    if (empty($extension) || ($extension['status'] !== 'installed')) {
      $message = new \CRM_Utils_Check_Message(
        __FUNCTION__ . E::SHORT_NAME . '_requirements',
        E::ts('The <em>%1</em> extension requires the <em>Payment Shared</em> extension which is not installed. See <a href="%2" target="_blank">details</a> for more information.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => 'https://civicrm.org/extensions/mjwshared',
          ]
        ),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionKey, 'key' => $extensionKey]]
      );
      $this->messages[] = $message;
    }
    else {
      $this->requireExtensionMinVersion($extensionName, self::MIN_VERSION_MJWSHARED, $extension['version']);
    }
  }

  /**
   * Check CURL options
   *
   * @return void
   */
  private function checkCURLOpts(): void {
    if (!defined('CURLOPT_SSLCERT')) {
      $message = new \CRM_Utils_Check_Message(
        'payflowpro_curlopt',
        E::ts('Payflow Pro requires curl with SSL support'),
        E::ts('PayflowPro - Missing requirements'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-gear'
      );
      $this->messages[] = $message;
    }
  }

}
