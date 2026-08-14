<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Api4\RelationshipType;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Security review N1/N2: hook_civicrm_pre() in boosterportal.php guards
 * against permissioned relationships (is_permission_a_b/b_a) being set on any
 * relationship type other than Portal_Parent_of — except CiviCRM core's own
 * Employee-of on-behalf-of-organisation flow
 * (CRM_Contribute_Form_Contribution_Confirm), which the guard must not break:
 * that flow rethrows anything other than a "Duplicate Relationship"
 * CRM_Core_Exception, so a guard exception there fatals the contribution
 * confirm page AFTER payment has already been captured.
 *
 * This exercises the guard directly via the same Api4 Relationship::create()
 * shape core uses, rather than driving the whole contribution form.
 */
class RelationshipPermissionGuardTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private int $individualA;
  private int $individualC;
  private int $org;

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->individualA = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Guard')
      ->addValue('last_name', 'TestA')
      ->execute()->first()['id'];
    $this->individualC = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Guard')
      ->addValue('last_name', 'TestC')
      ->execute()->first()['id'];
    $this->org = Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Guard Test Org')
      ->execute()->first()['id'];
  }

  /**
   * N1: exactly the shape CRM_Contribute_Form_Contribution_Confirm uses —
   * contact_id_a = individual, contact_id_b = organization, relationship_type_id
   * = Employee of, is_permission_a_b:name = 'View and update', is_permission_b_a
   * left unset (defaults to NONE). Must NOT throw.
   */
  public function testEmployeeOfWithViewAndUpdatePermissionIsAllowed(): void {
    $rel = Relationship::create(FALSE)
      ->addValue('contact_id_a', $this->individualA)
      ->addValue('contact_id_b', $this->org)
      ->addValue('relationship_type_id', \CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID())
      ->addValue('is_permission_a_b:name', 'View and update')
      ->execute()->single();

    $this->assertEquals(\CRM_Contact_BAO_Relationship::EDIT, $rel['is_permission_a_b'],
      'Sanity: the relationship was not actually created with the permission bit set — test is vacuous');
    $this->assertEquals(\CRM_Contact_BAO_Relationship::NONE, $rel['is_permission_b_a'],
      "Sanity: core's on-behalf-of flow never sets is_permission_b_a — confirms the N2 check below is meaningful");
  }

  /**
   * N2: is_permission_b_a must be rejected on EVERY relationship type,
   * including the Employee-of exemption — that exemption only ever covers
   * is_permission_a_b.
   */
  public function testEmployeeOfWithReversePermissionIsRejected(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/is_permission_b_a/');
    Relationship::create(FALSE)
      ->addValue('contact_id_a', $this->individualA)
      ->addValue('contact_id_b', $this->org)
      ->addValue('relationship_type_id', \CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID())
      ->addValue('is_permission_b_a:name', 'View and update')
      ->execute();
  }

  /**
   * N2: is_permission_b_a is rejected even on Portal_Parent_of itself — the
   * portal edge is directional (§4.4), and core's relationshipList()/allow()
   * honour is_permission_b_a exactly the same way as is_permission_a_b, just
   * reversed, so setting it here would let a student directly view their
   * own parent.
   */
  public function testPortalParentOfWithReversePermissionIsRejected(): void {
    $portalTypeId = RelationshipType::get(FALSE)
      ->addWhere('name_a_b', '=', 'Portal_Parent_of')
      ->execute()->single()['id'];

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/is_permission_b_a/');
    Relationship::create(FALSE)
      ->addValue('contact_id_a', $this->individualA)
      ->addValue('contact_id_b', $this->individualC)
      ->addValue('relationship_type_id', $portalTypeId)
      ->addValue('is_permission_b_a:name', 'View only')
      ->execute();
  }

  /**
   * Regression guard for the original C2 finding: a stock, non-portal
   * relationship type must still be refused, even after the N1 exemption
   * widened the allow-list to include Employee of.
   */
  public function testUnrelatedTypeWithForwardPermissionIsStillRejected(): void {
    $childOfTypeId = RelationshipType::get(FALSE)
      ->addWhere('name_a_b', '=', 'Child of')
      ->execute()->single()['id'];

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/is_permission_a_b/');
    Relationship::create(FALSE)
      ->addValue('contact_id_a', $this->individualA)
      ->addValue('contact_id_b', $this->individualC)
      ->addValue('relationship_type_id', $childOfTypeId)
      ->addValue('is_permission_a_b:name', 'View only')
      ->execute();
  }

}
