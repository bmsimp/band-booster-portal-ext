<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'boosterportal.civix.php';
// phpcs:enable

use CRM_Boosterportal_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function boosterportal_civicrm_config(\CRM_Core_Config $config): void {
  _boosterportal_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function boosterportal_civicrm_install(): void {
  _boosterportal_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function boosterportal_civicrm_enable(): void {
  _boosterportal_civix_civicrm_enable();
}
