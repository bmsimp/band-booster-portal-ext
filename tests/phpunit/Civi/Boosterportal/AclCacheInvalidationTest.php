<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Security review C1 (critical): civicrm_acl_contact_cache is a snapshot table
 * that CiviCRM never invalidates on a Relationship write — only opportunistic,
 * unrelated triggers (e.g. certain contact writes) flush it. Without an explicit
 * hook_civicrm_post() (see boosterportal.php), disabling or deleting a
 * Portal_Parent_of edge leaves the former parent with full bulk visibility of
 * the former child indefinitely.
 *
 * Deliberately NOT TransactionalInterface. CRM_ACL_BAO_Cache's own truncate path
 * (CRM_ACL_BAO_Cache::flushACLContactCache()) defers its TRUNCATE to
 * CRM_Core_Transaction::PHASE_POST_COMMIT when a transaction is active — i.e. a
 * transactional test's wrapping ROLLBACK would make any such deferred flush
 * invisible, and this regression could pass even with the hook missing or
 * broken. Every write here is real and committed, so this test cleans up its
 * own fixture data explicitly in tearDown() instead of relying on a rollback.
 */
class AclCacheInvalidationTest extends TestCase implements HeadlessInterface {

  private array $fam = [];

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function tearDown(): void {
    // Nothing rolls back for this test (see class docblock) — remove every
    // contact this test created. Relationships go with their contacts.
    if (!empty($this->fam)) {
      $ids = array_merge(
        [$this->fam['household_id']],
        $this->fam['parent_ids'],
        $this->fam['student_ids']
      );
      foreach ($ids as $id) {
        Contact::delete(FALSE)->addWhere('id', '=', $id)->setUseTrash(FALSE)->execute();
      }
    }
    parent::tearDown();
  }

  private function loginAsParent(int $contactId): void {
    $session = \CRM_Core_Session::singleton();
    $session->set('userID', $contactId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
  }

  /**
   * Clear every static, same-request short-circuit that a brand new PHP
   * process would not have, so what actually gets exercised is the persistent
   * civicrm_acl_contact_cache DB state (rebuilt or not) rather than an
   * in-memory flag left over from earlier in this test.
   */
  private function simulateNewRequest(): void {
    unset(\Civi::$statics['CRM_Contact_BAO_Contact_Permission']);
    unset(\Civi::$statics['CRM_ACL_API']);
    unset(\Civi::$statics['CRM_ACL_BAO_ACL']);
  }

  public function testDisablingOrDeletingTheEdgeRevokesVisibilityWithoutAnUnrelatedCacheFlush(): void {
    $this->fam = FamilyBuilder::create([
      'household' => ['name' => 'Cache Test Family', 'qbo_customer_id' => '901'],
      'parents' => [['first_name' => 'Cara', 'last_name' => 'Cache', 'email' => 'cara@example.org']],
      'students' => [['first_name' => 'Cody', 'last_name' => 'Cache', 'qbo_subcustomer_id' => '902']],
    ]);
    $parentId = $this->fam['parent_ids'][0];
    $studentId = $this->fam['student_ids'][0];

    $relationshipId = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $parentId)
      ->addWhere('contact_id_b', '=', $studentId)
      ->addWhere('relationship_type_id:name', '=', 'Portal_Parent_of')
      ->execute()->single()['id'];

    $this->loginAsParent($parentId);

    // --- Baseline: active edge grants visibility. ---
    $this->simulateNewRequest();
    $visible = Contact::get(TRUE)->execute()->column('id');
    $this->assertContains($studentId, $visible,
      'Parent cannot see own student with an active Portal_Parent_of edge — fixture or ACL broken, test is vacuous');

    // --- Disable (soft revoke): no other contact write happens here, so
    // nothing would opportunistically flush civicrm_acl_contact_cache; only
    // the explicit hook_civicrm_post() cache invalidation can fix this. ---
    Relationship::update(FALSE)
      ->addWhere('id', '=', $relationshipId)
      ->addValue('is_active', FALSE)
      ->execute();

    $this->simulateNewRequest();
    $visibleAfterDisable = Contact::get(TRUE)->execute()->column('id');
    $this->assertNotContains($studentId, $visibleAfterDisable,
      'Parent can still see student after the Portal_Parent_of edge was disabled — stale civicrm_acl_contact_cache; ' .
      'hook_civicrm_post() cache invalidation is missing or broken (security review C1)');

    // --- Re-enable: confirm visibility comes back, i.e. it really was the
    // disable (and its invalidation) driving the previous assertion, not some
    // other coincidental state change. ---
    Relationship::update(FALSE)
      ->addWhere('id', '=', $relationshipId)
      ->addValue('is_active', TRUE)
      ->execute();

    $this->simulateNewRequest();
    $visibleAfterReenable = Contact::get(TRUE)->execute()->column('id');
    $this->assertContains($studentId, $visibleAfterReenable,
      'Re-enabling the Portal_Parent_of edge did not restore visibility');

    // --- Delete outright: same guarantee must hold for delete, not just disable. ---
    Relationship::delete(FALSE)->addWhere('id', '=', $relationshipId)->execute();

    $this->simulateNewRequest();
    $visibleAfterDelete = Contact::get(TRUE)->execute()->column('id');
    $this->assertNotContains($studentId, $visibleAfterDelete,
      'Parent can still see student after the Portal_Parent_of edge was deleted — stale civicrm_acl_contact_cache; ' .
      'hook_civicrm_post() cache invalidation is missing or broken (security review C1)');
  }

}
