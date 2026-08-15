<?php
namespace Civi\Boosterportal;

use PHPUnit\Framework\TestCase;

/**
 * isSafeParentUser() (CRM_Boosterportal_Page_PortalLogin::isSafeParentUser())
 * is the gate that decides whether a WordPress account reached through a
 * redeemed magic-link token is safe to sign a session in as (IMPORTANT-2(ii)
 * in that class's docblock).
 *
 * WORDPRESS PORT NOTE: the Drupal signature was
 * isSafeParentUser(int $uid, array $roles), with a uid-1 guard because
 * Drupal's user 1 bypasses every permission check regardless of its roles.
 * WordPress has no equivalent superuser id — an account is privileged if it
 * holds privileged CAPABILITIES — so the guard became a boolean the caller
 * computes, and the signature is now
 * isSafeParentUser(array $roles, bool $hasElevatedCapability).
 *
 * The function stays pure for the same reason it was made pure under Drupal:
 * this extension's headless PHPUnit bootstrap (tests/phpunit/bootstrap.php)
 * boots the CiviCRM classloader only. Under Drupal that meant
 * \Drupal\user\UserInterface was not even a loadable symbol to mock; under
 * WordPress it means get_userdata(), user_can() and is_super_admin() do not
 * exist in this process at all. Everything that must ask WordPress a question
 * lives in the caller (runLogin(), rolesOf(), hasElevatedCapability()); what
 * is tested below is a pure function of two plain PHP values.
 */
class PortalLoginTest extends TestCase {

  public function testExactlyParentRoleIsSafe(): void {
    $this->assertTrue(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['parent'], FALSE));
  }

  public function testAdministratorIsNeverSafe(): void {
    // The WordPress replacement for the uid-1 case. An administrator holds
    // every capability, including the ones CiviCRM reads to decide that an
    // account may see every contact, so this door must never open for one.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['administrator'], TRUE));
  }

  public function testParentPlusAdministratorIsNotSafe(): void {
    // The privilege-escalation case this gate exists for: someone who is both
    // a provisioned parent AND separately an administrator must not get a
    // privileged session by walking in through the magic-link door instead of
    // Entra SSO. Unsorted input on purpose — the function must sort before
    // comparing.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['administrator', 'parent'], TRUE));
  }

  public function testElevatedCapabilityAloneIsNotSafe(): void {
    // A role list of exactly ['parent'] is not enough on its own. This is an
    // account whose parent role has been granted something like manage_options
    // out of band — in wp-admin, or by a plugin. The capability answer alone
    // must be able to refuse it.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['parent'], TRUE));
  }

  public function testNoRolesIsNotSafe(): void {
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser([], FALSE));
  }

  public function testSubscriberOnlyIsNotSafe(): void {
    // Not a parent at all — must never be treated as one. WordPress hands
    // every new account 'subscriber' by default, so this is the shape an
    // account created by any other route arrives in.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['subscriber'], FALSE));
  }

  public function testParentPlusUnprivilegedExtraRoleIsNotSafe(): void {
    // Exactly-parent, not parent-and-anything. 'subscriber' carries no
    // privilege worth having, and the answer is still no: the door is defined
    // by an exact role list, not by whether the extra role looks harmless
    // today. Deciding "which extra roles are safe" is exactly the judgement
    // this function exists to avoid making.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['parent', 'subscriber'], FALSE));
  }

  public function testNonStringRoleEntriesCannotManufactureAMatch(): void {
    // WP_User::$roles is rebuilt from unserialized user meta, so its shape is
    // not structurally guaranteed. isSafeParentUser() drops anything that is
    // not a string before comparing, which is the safe direction: a corrupted
    // or tampered entry can only ever REMOVE a candidate from the comparison,
    // never satisfy it. An account whose roles are junk holds no parent role
    // at all and is refused.
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser([NULL], FALSE));
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser([123, ['nested']], FALSE));
    $this->assertFalse(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser([['parent']], FALSE));
  }

  public function testJunkAlongsideTheParentRoleStillSignsIn(): void {
    // The deliberate other half of the rule above, stated so nobody has to
    // infer it: an account that genuinely holds the parent role is not locked
    // out because its meta also carries a non-string entry. Nothing is given
    // away by that -- a junk entry is not a WordPress role and grants no
    // capability, and every capability the account really holds was checked
    // separately by hasElevatedCapability() before this function was called.
    $this->assertTrue(\CRM_Boosterportal_Page_PortalLogin::isSafeParentUser(['parent', 42], FALSE));
  }



}
