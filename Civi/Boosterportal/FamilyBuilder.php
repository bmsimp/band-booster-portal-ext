<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Relationship;

/**
 * The single place family structure is wired (§4.4, §5.2):
 *  - Household carries qbo_customer_id (billing anchor, no ACL role).
 *  - Students carry qbo_subcustomer_id and NEVER authenticate.
 *  - Each parent gets a DIRECT permissioned relationship (view-only) to each
 *    student. ACLs never traverse the Household.
 * Used by both the test fixtures and the Task 11 importer so they cannot drift.
 */
class FamilyBuilder {

  /**
   * @param array $def
   *   [
   *     'household' => ['name' => string, 'qbo_customer_id' => ?string],
   *     'parents' => [['first_name' => string, 'last_name' => string, 'email' => ?string], ...],
   *     'students' => [['first_name' => string, 'last_name' => string, 'qbo_subcustomer_id' => ?string], ...],
   *   ]
   * @return array
   *   [
   *     'household_id' => int,
   *     'parent_ids' => int[],
   *       Positionally correlated with $def['parents']: $result['parent_ids'][$i] is the
   *       contact created from $def['parents'][$i].
   *     'student_ids' => int[],
   *       Positionally correlated with $def['students']: $result['student_ids'][$i] is the
   *       contact created from $def['students'][$i].
   *   ]
   * @throws \InvalidArgumentException
   *   If required fields are missing, before any write happens.
   */
  public static function create(array $def): array {
    self::validate($def);

    $result = NULL;
    \CRM_Core_Transaction::create()->run(function($tx) use ($def, &$result) {
      $result = self::doCreate($def);
    });
    return $result;
  }

  /**
   * Validate required fields up front so a malformed $def fails loudly, before any
   * contact/relationship is written, instead of leaving a partial/ghost family behind.
   *
   * @throws \InvalidArgumentException
   */
  private static function validate(array $def): void {
    if (empty($def['household']['name'])) {
      throw new \InvalidArgumentException('FamilyBuilder: household.name is required.');
    }
    foreach (($def['parents'] ?? []) as $i => $p) {
      if (empty($p['first_name']) || empty($p['last_name'])) {
        throw new \InvalidArgumentException("FamilyBuilder: parents[$i] requires first_name and last_name.");
      }
    }
    foreach (($def['students'] ?? []) as $i => $s) {
      if (empty($s['first_name']) || empty($s['last_name'])) {
        throw new \InvalidArgumentException("FamilyBuilder: students[$i] requires first_name and last_name.");
      }
    }
  }

  private static function doCreate(array $def): array {
    $householdId = Contact::create(FALSE)
      ->addValue('contact_type', 'Household')
      ->addValue('household_name', $def['household']['name'])
      ->addValue('Booster_QBO.qbo_customer_id', $def['household']['qbo_customer_id'] ?? NULL)
      ->execute()->first()['id'];

    $studentIds = [];
    foreach (($def['students'] ?? []) as $s) {
      $studentIds[] = Contact::create(FALSE)
        ->addValue('contact_type', 'Individual')
        ->addValue('first_name', $s['first_name'])
        ->addValue('last_name', $s['last_name'])
        ->addValue('Booster_QBO_Student.qbo_subcustomer_id', $s['qbo_subcustomer_id'] ?? NULL)
        ->execute()->first()['id'];
    }

    $parentIds = [];
    foreach (($def['parents'] ?? []) as $p) {
      $create = Contact::create(FALSE)
        ->addValue('contact_type', 'Individual')
        ->addValue('first_name', $p['first_name'])
        ->addValue('last_name', $p['last_name']);
      if (!empty($p['email'])) {
        $create->addChain('email', \Civi\Api4\Email::create(FALSE)
          ->addValue('contact_id', '$id')
          ->addValue('email', $p['email'])
          ->addValue('is_primary', TRUE));
      }
      $parentIds[] = $create->execute()->first()['id'];
    }

    foreach (array_merge($parentIds, $studentIds) as $memberId) {
      Relationship::create(FALSE)
        ->addValue('contact_id_a', $memberId)
        ->addValue('contact_id_b', $householdId)
        ->addValue('relationship_type_id:name', 'Household Member of')
        ->execute();
    }

    foreach ($parentIds as $parentId) {
      foreach ($studentIds as $studentId) {
        Relationship::create(FALSE)
          ->addValue('contact_id_a', $parentId)
          ->addValue('contact_id_b', $studentId)
          ->addValue('relationship_type_id:name', 'Portal_Parent_of')
          ->addValue('is_permission_a_b', \CRM_Contact_BAO_Relationship::VIEW)
          ->addValue('is_permission_b_a', \CRM_Contact_BAO_Relationship::NONE)
          ->execute();
      }
    }

    return [
      'household_id' => $householdId,
      'parent_ids' => $parentIds,
      'student_ids' => $studentIds,
    ];
  }

}
