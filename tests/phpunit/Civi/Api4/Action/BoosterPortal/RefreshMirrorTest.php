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
 * Mock-free per the plan's suggestion, and cheap despite constructing a real
 * QboClient: verified empirically (`cv api4 BoosterPortal.refreshMirror`
 * against the dev site with no credentials configured) that the Intuit SDK's
 * OAuth2LoginHelper::getAccessToken() rejects an access-token object with no
 * client id *before* issuing any HTTP request — "Can't get OAuth 2 Client ID
 * from Access Token Object. It is not set." — so this never touches the
 * network and completes in well under a second.
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
