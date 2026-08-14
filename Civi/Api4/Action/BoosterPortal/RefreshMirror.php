<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\Mirror;
use Civi\Boosterportal\QboClient;

class RefreshMirror extends AbstractAction {

  public function _run(Result $result) {
    // Check the two settings that MUST be set for a connection to exist at
    // all before ever constructing a QboClient. This turns the common,
    // expected "OAuth connect flow (Task 9) has never been run" case into
    // its own precise message, rather than relying on whatever exception
    // text QboClient happens to throw for that same situation — see the
    // catch-all below for genuinely unexpected failures.
    $clientId = \Civi::settings()->get('boosterportal_qbo_client_id');
    $refreshToken = \Civi::settings()->get('boosterportal_qbo_refresh_token');
    if (empty($clientId) || empty($refreshToken)) {
      throw new \CRM_Core_Exception('QuickBooks is not connected — see runbooks/qbo-oauth.md.');
    }

    // Credentials are present but something else went wrong (QBO down,
    // Intuit rejected the refresh token as expired/revoked, a genuine SDK
    // bug, ...). QboClient talks to Intuit lazily (first query, not
    // construction), so this surfaces here as whatever the SDK/HTTP layer
    // throws (ServiceException, SdkException, a Guzzle transfer exception,
    // ...). Left uncaught, that's a fatal white-screen from a cron job with
    // no useful message. Catch broadly (\Throwable, not just \Exception —
    // misconfigured SDK calls can also surface as a TypeError) and rethrow
    // as a clear CRM_Core_Exception — but WITHOUT the "not connected"
    // headline above, which would be misleading once credentials are
    // actually configured.
    try {
      $count = (new Mirror(new QboClient()))->refresh();
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('QBO mirror refresh failed: ' . $e->getMessage());
    }
    $result[] = ['mirrored' => $count];
  }

}
