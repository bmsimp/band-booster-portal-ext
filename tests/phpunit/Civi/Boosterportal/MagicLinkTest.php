<?php
namespace Civi\Boosterportal;

use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

class MagicLinkTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private array $fam;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->fam = FamilyBuilder::create([
      'household' => ['name' => 'Link Family', 'qbo_customer_id' => '101'],
      'parents' => [['first_name' => 'El', 'last_name' => 'Link', 'email' => 'el@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Link', 'qbo_subcustomer_id' => '102']],
    ]);
  }

  public function testIssueAndRedeemRoundTrip(): void {
    $token = (new MagicLink())->issue('el@example.org', '10.0.0.1');
    $this->assertNotNull($token);
    $cid = (new MagicLink())->redeem($token);
    $this->assertSame($this->fam['parent_ids'][0], $cid);
  }

  public function testTokenIsSingleUse(): void {
    $ml = new MagicLink();
    $token = $ml->issue('el@example.org', '10.0.0.1');
    $ml->redeem($token);
    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem($token);
  }

  public function testExpiredTokenRejected(): void {
    $ml = new MagicLink();
    $token = $ml->issue('el@example.org', '10.0.0.1');
    \CRM_Core_DAO::executeQuery(
      'UPDATE boosterportal_login_token SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem($token);
  }

  public function testUnknownEmailIssuesNothingButDoesNotThrow(): void {
    // Anti-enumeration: caller cannot distinguish unknown address from success.
    $this->assertNull((new MagicLink())->issue('nobody@example.org', '10.0.0.1'));
  }

  public function testRateLimitThreePerHour(): void {
    $ml = new MagicLink();
    $ml->issue('el@example.org', '10.0.0.1');
    $ml->issue('el@example.org', '10.0.0.1');
    $ml->issue('el@example.org', '10.0.0.1');
    $this->assertNull($ml->issue('el@example.org', '10.0.0.1'), 'Fourth request within the hour must be dropped');
  }

  public function testStudentEmailNeverGetsALink(): void {
    // §4.3: students are data-only and can NEVER authenticate. Even if a
    // student contact somehow carries an email, no token may be issued,
    // because only contacts holding outgoing permissioned edges qualify.
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->fam['student_ids'][0])
      ->addValue('email', 'kid@example.org')->execute();
    $this->assertNull((new MagicLink())->issue('kid@example.org', '10.0.0.1'));
  }

  /**
   * AMENDMENT (Task 15): eligibility must be scoped to the Portal_Parent_of
   * relationship type specifically, mirroring FamilyResolver's/the ACL
   * hook's own SQL semantics (Task 7). The plan's original bare
   * `is_permission_a_b > 0` join would also match core's own Employee-of
   * on-behalf-of-organisation edge (boosterportal.php's write-time guard
   * deliberately allows that type to carry a permission bit — see the N1
   * comment there) — an unrelated contribution-flow relationship that must
   * NEVER make someone link-eligible. This test creates exactly that
   * relationship (no Portal_Parent_of edge at all) and asserts issue()
   * still returns NULL.
   */
  public function testEmployeeOfEdgeIsNotLinkEligible(): void {
    $org = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'On Behalf Of Inc')
      ->execute()->first();

    $employee = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Not')
      ->addValue('last_name', 'AParent')
      ->execute()->first();
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $employee['id'])
      ->addValue('email', 'employee@example.org')
      ->addValue('is_primary', TRUE)
      ->execute();

    \Civi\Api4\Relationship::create(TRUE)
      ->addValue('contact_id_a', $employee['id'])
      ->addValue('contact_id_b', $org['id'])
      ->addValue('relationship_type_id:name', 'Employee of')
      ->addValue('is_permission_a_b:name', 'View and update')
      ->execute();

    $this->assertNull((new MagicLink())->issue('employee@example.org', '10.0.0.1'),
      'An Employee-of permissioned edge (on-behalf-of-organisation flow) must never grant portal link eligibility.');
  }

  public function testTokenIsHashedAtRest(): void {
    $token = (new MagicLink())->issue('el@example.org', '10.0.0.1');
    $rows = \CRM_Core_DAO::executeQuery('SELECT * FROM boosterportal_login_token')->fetchAll();
    $this->assertCount(1, $rows);
    foreach ($rows[0] as $column => $value) {
      $this->assertStringNotContainsString($token, (string) $value,
        "Raw token must never appear in boosterportal_login_token.{$column}.");
    }
    $this->assertNotSame($token, $rows[0]['token_hash']);
    $this->assertSame(hash('sha256', $token), $rows[0]['token_hash']);
  }

  public function testRedeemGarbageTokenThrowsWithoutSqlError(): void {
    $ml = new MagicLink();
    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem("garbage'; DROP TABLE boosterportal_login_token; --");
  }

  public function testRedeemTruncatedTokenThrowsWithoutSqlError(): void {
    $ml = new MagicLink();
    $token = $ml->issue('el@example.org', '10.0.0.1');
    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem(substr($token, 0, 8));
  }

  /**
   * Actually exercises sendLink()'s CRM_Utils_Mail::send() call path
   * (webmaster email SET, an eligible recipient) rather than only issue()
   * in isolation. This is the test that would have caught the "Argument #1
   * ($params) could not be passed by reference" bug found live against
   * Mailpit (Task 15 e2e verification) — CRM_Utils_Mail::send() declares
   * its first parameter `array &$params`, which an inline array literal
   * cannot satisfy (same class of bug ReconTest::
   * testRunSendsEmailWithoutThrowingWhenCriticalFindingsExistAndWebmasterEmailIsSet()
   * caught for emailCritical() in Task 13). ddev's mail capture means this
   * is a real, safe send in the dev/test environment (Mailpit), not a mock.
   */
  public function testSendLinkSendsEmailWithoutThrowingWhenEligible(): void {
    \Civi::settings()->set('boosterportal_webmaster_email', 'webmaster-magiclinktest@example.org');
    (new MagicLink())->sendLink('el@example.org', '10.0.0.1');
    $count = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_login_token');
    $this->assertSame(1, $count, 'sendLink() must have issued exactly one token for the eligible address.');
  }

  /**
   * IMPORTANT-1 (adversarial review): redeem() must be a single atomic
   * guarded UPDATE, not a SELECT-then-UPDATE with a window between them.
   * This test can't literally run two PHP threads concurrently inside
   * PHPUnit, but it DOES prove the guarded-UPDATE semantics directly: a
   * token whose used_at was already set by a completely separate UPDATE
   * (standing in for "another request won the race") must be rejected by
   * redeem() exactly the same as any other already-used token — i.e.
   * redeem()'s own UPDATE affects zero rows and throws, rather than
   * trusting a prior SELECT that raced against this direct UPDATE.
   */
  public function testRedeemRejectsTokenConcurrentlyMarkedUsedByDirectUpdate(): void {
    $ml = new MagicLink();
    $token = $ml->issue('el@example.org', '10.0.0.1');
    // Simulate a concurrent winner: mark the row used directly, bypassing
    // MagicLink entirely, exactly as a second request's own guarded UPDATE
    // would have done had it run first.
    \CRM_Core_DAO::executeQuery(
      'UPDATE boosterportal_login_token SET used_at = NOW() WHERE token_hash = %1',
      [1 => [hash('sha256', $token), 'String']]);
    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem($token);
  }

  /**
   * IMPORTANT-2(i) (adversarial review): a token issued while the parent
   * was eligible must be rejected at REDEMPTION time if the underlying
   * Portal_Parent_of edge has since been deactivated — closing the window
   * between issue() and redeem() during which eligibility can change while
   * the token is still technically unused and unexpired.
   */
  public function testRedeemRejectsTokenWhenEligibilityRevokedSinceIssue(): void {
    $ml = new MagicLink();
    $token = $ml->issue('el@example.org', '10.0.0.1');
    $this->assertNotNull($token, 'Sanity: must have been eligible at issue time');

    \Civi\Api4\Relationship::update(FALSE)
      ->addWhere('contact_id_a', '=', $this->fam['parent_ids'][0])
      ->addWhere('contact_id_b', '=', $this->fam['student_ids'][0])
      ->addWhere('relationship_type_id:name', '=', 'Portal_Parent_of')
      ->addValue('is_active', FALSE)
      ->execute();

    $this->expectException(\CRM_Core_Exception::class);
    $ml->redeem($token);
  }

  /**
   * MINOR-3 (adversarial review): purgeOldTokens() removes only rows that
   * are BOTH no-longer-live (used or expired) AND old enough — a recently
   * issued-but-still-unused-and-unexpired token must survive a purge run
   * even if some OTHER old row is due for cleanup.
   */
  public function testPurgeOldTokensDeletesOnlyOldUsedOrExpiredRows(): void {
    $ml = new MagicLink();
    $freshToken = $ml->issue('el@example.org', '10.0.0.1');
    $this->assertNotNull($freshToken);

    // An old, already-used row (simulating a redemption from >24h ago).
    \CRM_Core_DAO::executeQuery(
      "UPDATE boosterportal_login_token
       SET used_at = DATE_SUB(NOW(), INTERVAL 25 HOUR),
           created_at = DATE_SUB(NOW(), INTERVAL 25 HOUR),
           expires_at = DATE_SUB(NOW(), INTERVAL 25 HOUR)
       WHERE token_hash = %1",
      [1 => [hash('sha256', $freshToken), 'String']]);

    // A fresh, still-live row that must NOT be purged.
    $liveToken = $ml->issue('el@example.org', '10.0.0.2');
    $this->assertNotNull($liveToken);

    $deleted = $ml->purgeOldTokens(24);
    $this->assertSame(1, $deleted, 'Only the old used row should have been purged.');
    $remaining = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_login_token');
    $this->assertSame(1, $remaining, 'The fresh, still-live token must survive the purge.');
  }

  /**
   * IMPORTANT-3 (adversarial review): the requesting IP is now a SECOND
   * rate-limit dimension. A single IP spraying requests across many
   * DIFFERENT (here, still-eligible) addresses must still be capped at
   * MAX_PER_HOUR total — before this fix, each address got its own
   * independent budget with no ceiling on how many addresses one IP could
   * try.
   */
  public function testRateLimitByIpAcrossDifferentAddresses(): void {
    $fam2 = FamilyBuilder::create([
      'household' => ['name' => 'Link Family 2', 'qbo_customer_id' => '201'],
      'parents' => [['first_name' => 'Bay', 'last_name' => 'Link2', 'email' => 'bay@example.org']],
      'students' => [['first_name' => 'Kid2', 'last_name' => 'Link2', 'qbo_subcustomer_id' => '202']],
    ]);
    $this->assertNotEmpty($fam2['parent_ids']);

    $ml = new MagicLink();
    $ip = '203.0.113.9';
    $this->assertNotNull($ml->issue('el@example.org', $ip));
    $this->assertNotNull($ml->issue('bay@example.org', $ip));
    // A third distinct, otherwise-eligible-and-not-individually-rate-limited
    // address from the SAME ip trips the ip-side backstop even though
    // el@example.org still has 2/3 of its own per-email budget left.
    $fam3 = FamilyBuilder::create([
      'household' => ['name' => 'Link Family 3', 'qbo_customer_id' => '301'],
      'parents' => [['first_name' => 'Cal', 'last_name' => 'Link3', 'email' => 'cal@example.org']],
      'students' => [['first_name' => 'Kid3', 'last_name' => 'Link3', 'qbo_subcustomer_id' => '302']],
    ]);
    $this->assertNotEmpty($fam3['parent_ids']);
    $this->assertNotNull($ml->issue('cal@example.org', $ip), 'Third distinct address from the same IP is still within MAX_PER_HOUR.');
    $this->assertNull($ml->issue('el@example.org', $ip), 'A 4th request from this IP within the hour must be dropped by the IP-side backstop, even though el@example.org has its own per-email budget left.');
  }

}
