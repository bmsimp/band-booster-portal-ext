<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Test;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Task 10 controller amendment 5: confirms the nightly job fails gracefully
 * (a clear CRM_Core_Exception) rather than fatally when QBO isn't connected
 * — the out-of-the-box state before Task 9's OAuth connect flow has ever
 * been run against this test DB (boosterportal_qbo_client_id/secret/
 * refresh_token/realm_id all default to '').
 *
 * RefreshMirror::_run() checks boosterportal_qbo_client_id/refresh_token
 * directly via Civi::settings() before ever constructing a QboClient, so
 * this is genuinely mock-free and cheap without relying on the Intuit SDK's
 * own internal validation to fail fast — it never reaches QboClient/the SDK
 * at all in this case, let alone the network. (A separate, unmocked concern
 * — what happens when credentials ARE present but the SDK call itself fails
 * — isn't covered here; that path rethrows as "QBO mirror refresh failed:
 * ..." and would need a real or fake QBO connection to exercise, which
 * RefreshMirror doesn't support injecting.)
 *
 * ->setCheckPermissions(FALSE): deliberate — this test exercises _run()'s
 * exception-wrapping, not the entity's permissions() gate, and headless
 * PHPUnit runs are not guaranteed to run as a user holding 'administer
 * CiviCRM'. Only files under the extension's Civi/ directory are scanned by
 * InvariantTest's checkPermissions=FALSE allowlist (tests/ is excluded), so
 * this needs no allowlist entry.
 */
class RefreshMirrorTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function testFailsGracefullyWithoutCredentials(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/quickbooks/i');
    (new RefreshMirror('BoosterPortal', 'refreshMirror'))
      ->setCheckPermissions(FALSE)
      ->execute();
  }

}
