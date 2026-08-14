<?php
namespace Civi\Api4;

use Civi\Api4\Generic\BasicGetAction;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Read-only DAO-less entity over boosterportal_recon_finding (§6, Task 13),
 * so SearchKit can display it on the webmaster dashboard
 * (managed/ReconDashboard.mgd.php).
 *
 * SECURITY: findings routinely contain cross-family data (e.g. check 1's
 * detail carries a student_contact_id AND the household it wrongly sits
 * under; check 18's detail carries a cross-household parent/student pair by
 * design). A parent holding only 'access CiviCRM' — the portal role, Task 4
 * — must NOT be able to read this entity at all; only the webmaster/admin
 * tier should. Empirically verified (ReconTest::testReconFindingGetDeniedFor
 * AccessCiviCRMOnly / ::testReconFindingGetAllowedForAdmin): a session
 * holding only 'access CiviCRM' gets Civi\API\Exception\UnauthorizedException
 * from civicrm_api4('ReconFinding', 'get', ['checkPermissions' => TRUE]),
 * and a session additionally holding 'access CiviCRM backend and API' (the
 * permission named in permissions() below) is let through — i.e. this
 * permission name DOES gate the way the plan intended, so no tightening to
 * 'administer CiviCRM' was needed.
 */
class ReconFinding extends Generic\AbstractEntity {

  public static function get(bool $checkPermissions = TRUE) {
    return (new BasicGetAction(static::getEntityName(), __FUNCTION__, function () {
      return \CRM_Core_DAO::executeQuery(
        'SELECT id, check_num, severity, title, detail, found_at
         FROM boosterportal_recon_finding ORDER BY severity, check_num')->fetchAll();
    }))->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE) {
    return (new BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, fn() => [
      ['name' => 'id', 'data_type' => 'Integer'],
      ['name' => 'check_num', 'data_type' => 'Integer'],
      ['name' => 'severity'], ['name' => 'title'], ['name' => 'detail'],
      ['name' => 'found_at', 'data_type' => 'Timestamp'],
    ]))->setCheckPermissions($checkPermissions);
  }

  public static function permissions(): array {
    return [
      'get' => ['access CiviCRM backend and API'],
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

}
