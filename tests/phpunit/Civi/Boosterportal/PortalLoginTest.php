<?php
namespace Civi\Boosterportal;

use PHPUnit\Framework\TestCase;

/**
 * Task 17 warm-up (Task 15 security review follow-up): isSafeParentUser()
 * (CRM_Boosterportal_Page_PortalLogin::isSafeParentUser()) is the gate that
 * decides whether a Drupal user loaded via a redeemed magic-link token is
 * safe to sign a CMS session in as (IMPORTANT-2(ii) in that class's
 * docblock). Until now its coverage was live-only (manually verified
 * against a real UFMatch row + Drupal roles table — see the Task 15 review
 * report).
 *
 * REFACTOR NOTE: this function was originally typed
 * isSafeParentUser(\Drupal\user\UserInterface $user). Attempting to mock
 * that interface here first (PHPUnit's createMock(\Drupal\user\UserInterface::class))
 * failed outright with "Class or interface Drupal\user\UserInterface does
 * not exist" — this extension's headless PHPUnit bootstrap
 * (tests/phpunit/bootstrap.php) only boots the CiviCRM classloader level,
 * never Drupal's own autoloader/service container, so the interface isn't
 * merely un-bootable, it isn't even a loadable class/interface symbol in
 * this process. That makes the "pass a stub/mock implementing UserInterface"
 * approach impossible here, exactly per the task's fallback instruction —
 * so the function was refactored to take (int $uid, array $roles) instead,
 * with the caller (PortalLogin::runLogin()) now responsible for extracting
 * $user->id() and $user->getRoles(TRUE) before calling it. See the
 * function's own docblock in CRM/Boosterportal/Page/PortalLogin.php for the
 * full reasoning. What's under test below is now a pure function of two
 * plain PHP values — no Drupal dependency of any kind.
 */
class PortalLoginTest extends TestCase {

  public function testUid1IsNeverSafeEvenWithOnlyParentRole(): void {
    // Drupal's uid 1 bypasses every permission check regardless of role
    // list — must be rejected unconditionally (IMPORTANT-2(ii)).
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(1, ['parent']));
  }

  public function testExactlyParentRoleIsSafe(): void {
    $this->assertTrue(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(42, ['parent']));
  }

  public function testParentPlusBoardMemberIsNotSafe(): void {
    // The privilege-escalation case this gate exists for: a contact who is
    // both a provisioned parent AND separately a board_member must not get
    // a session with privileges beyond "parent" via the magic-link door.
    // Unsorted input on purpose — the function must sort before comparing.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(42, ['board_member', 'parent']));
  }

  public function testParentWithImplicitAuthenticatedRoleIsSafe(): void {
    // MINOR-6 (quality review) — actually distinct from
    // testExactlyParentRoleIsSafe() now, and backed by a live check rather
    // than an assumption: is ['authenticated', 'parent'] really the shape
    // \Drupal\user\Entity\User::getRoles(TRUE) hands back for an ordinary
    // parent account?
    //
    // Checked both ways: Drupal core's own getRoles() (User.php) only adds
    // the locked anonymous/authenticated role when $exclude_locked_roles is
    // FALSE — a getRoles(TRUE) call never includes it. Reproduced live
    // against a real provisioned parent account on the dev site:
    // getRoles(TRUE) => ["parent"]; only getRoles(FALSE)/getRoles() (no
    // argument) => ["authenticated", "parent"]. So on the real call path
    // (PortalLogin::runLogin() calls getRoles(TRUE)), 'authenticated' is
    // NEVER present — isSafeParentUser() passing ['parent'] alone would
    // already have covered the real-world case correctly.
    //
    // isSafeParentUser() now ALSO defensively strips 'authenticated'/
    // 'anonymous' if they happen to be present anyway (belt-and-braces —
    // see that function's own docblock for why this is safe: it can only
    // ever remove a rejection reason, never manufacture a match). This test
    // exercises exactly that defensive path with the UNSORTED
    // ['authenticated', 'parent'] shape a getRoles(FALSE) call — or any
    // future caller mistake — would actually produce, proving the
    // filter-then-sort logic handles it correctly either way.
    $this->assertTrue(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(42, ['authenticated', 'parent']));
  }

  public function testNoRolesIsNotSafe(): void {
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(42, []));
  }

  public function testBoardMemberOnlyIsNotSafe(): void {
    // Not a parent at all — must never be treated as one.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(42, ['board_member']));
  }

}
