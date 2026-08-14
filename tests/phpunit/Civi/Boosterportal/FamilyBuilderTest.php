<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

class FamilyBuilderTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function testBuildFamilyWiresQboIdsAndPermissionedEdges(): void {
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'Smith Family', 'qbo_customer_id' => '101'],
      'parents' => [['first_name' => 'Pat', 'last_name' => 'Smith', 'email' => 'pat@example.org']],
      'students' => [['first_name' => 'Sam', 'last_name' => 'Smith', 'qbo_subcustomer_id' => '102']],
    ]);

    $hh = Contact::get(FALSE)->addWhere('id', '=', $fam['household_id'])
      ->addSelect('Booster_QBO.qbo_customer_id')->execute()->single();
    $this->assertSame('101', $hh['Booster_QBO.qbo_customer_id']);

    $student = Contact::get(FALSE)->addWhere('id', '=', $fam['student_ids'][0])
      ->addSelect('Booster_QBO_Student.qbo_subcustomer_id')->execute()->single();
    $this->assertSame('102', $student['Booster_QBO_Student.qbo_subcustomer_id']);

    // Direct permissioned parent→student edge: parent (a) may VIEW student (b), not edit.
    $rel = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $fam['parent_ids'][0])
      ->addWhere('contact_id_b', '=', $fam['student_ids'][0])
      ->execute()->single();
    $this->assertEquals(\CRM_Contact_BAO_Relationship::VIEW, $rel['is_permission_a_b']);
    $this->assertEquals(\CRM_Contact_BAO_Relationship::NONE, $rel['is_permission_b_a']);

    // Parent and student are Household members (billing anchor, no ACL role — §4.4).
    $memberCount = Relationship::get(FALSE)
      ->addWhere('contact_id_b', '=', $fam['household_id'])
      ->addWhere('relationship_type_id:name', '=', 'Household Member of')
      ->execute()->count();
    $this->assertSame(2, $memberCount);
  }

}
