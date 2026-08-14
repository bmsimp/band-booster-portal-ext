<?php
namespace Civi\Api4;

use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * BoosterPortal API entity facade (§5.4). Not a real DB entity — a thin
 * dispatch point for one-off actions (nightly mirror refresh, reconciliation,
 * the parent-facing balance lookup) that don't fit CiviCRM's CRUD entity
 * model. Later tasks add actions here; this file's job is just wiring +
 * the permissions() allow-list, which is the single place all of this
 * entity's actions get their required permission from.
 */
class BoosterPortal extends AbstractEntity {

  public static function getFields(bool $checkPermissions = TRUE) {
    return (new BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, fn() => []))
      ->setCheckPermissions($checkPermissions);
  }

  public static function refreshMirror(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\RefreshMirror(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function importFamilies(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\ImportFamilies(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getMyBalance(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\GetMyBalance(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /** Task 13 (§6): nightly reconciliation — checks 1,2,5-18. */
  public static function runRecon(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\RunRecon(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /** Task 13 (§6): hourly reconciliation — checks 3, 4, 4b. */
  public static function runReconHourly(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\RunReconHourly(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Task 15 adversarial review, MINOR-3: daily housekeeping for
   * boosterportal_login_token (deletes old used/expired rows).
   */
  public static function purgeLoginTokens(bool $checkPermissions = TRUE) {
    return (new \Civi\Api4\Action\BoosterPortal\PurgeLoginTokens(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function permissions(): array {
    return [
      'refreshMirror' => ['administer CiviCRM'],
      // Task 11: one-time console importer. Same gate as refreshMirror —
      // console/admin-only, never a parent-facing action.
      'importFamilies' => ['administer CiviCRM'],
      // Task 12: row scoping to the caller's own family happens entirely
      // inside the action (via Civi\Boosterportal\FamilyResolver, derived
      // from the session contact id only) — 'access CiviCRM' is
      // deliberately the only gate here, same as any other parent-facing
      // action. AclLeakTest guards the scoping.
      'getMyBalance' => ['access CiviCRM'],
      // Task 13: cron/admin-only, same gate as refreshMirror — neither is
      // ever reachable from a parent-facing route.
      'runRecon' => ['administer CiviCRM'],
      'runReconHourly' => ['administer CiviCRM'],
      // Task 15 adversarial review, MINOR-3: cron/admin-only, same shape as
      // refreshMirror/runRecon — never reachable from a parent-facing route.
      'purgeLoginTokens' => ['administer CiviCRM'],
    ];
  }

}
