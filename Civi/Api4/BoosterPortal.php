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

  public static function permissions(): array {
    return [
      'refreshMirror' => ['administer CiviCRM'],
      // Task 11: one-time console importer. Same gate as refreshMirror —
      // console/admin-only, never a parent-facing action.
      'importFamilies' => ['administer CiviCRM'],
      // getMyBalance's action class arrives in Task 12; row scoping to the
      // caller's own family happens inside the action, not via a broader
      // permission — 'access CiviCRM' is deliberately the only gate here.
      'getMyBalance' => ['access CiviCRM'],
    ];
  }

}
