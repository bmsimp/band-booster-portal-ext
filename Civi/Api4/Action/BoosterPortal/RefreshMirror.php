<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\Mirror;
use Civi\Boosterportal\QboClient;

class RefreshMirror extends AbstractAction {

  public function _run(Result $result) {
    // QboClient talks to Intuit lazily (first query, not construction), so a
    // missing/invalid connection (no client id/secret/refresh token/realm —
    // the out-of-the-box state before Task 9's OAuth connect flow has ever
    // been run) surfaces here as whatever the SDK/HTTP layer throws
    // (ServiceException, SdkException, a Guzzle transfer exception, ...).
    // Left uncaught, that's a fatal white-screen from a cron job with no
    // useful message. Catch broadly (\Throwable, not just \Exception —
    // misconfigured SDK calls can also surface as a TypeError) and rethrow
    // as a clear, actionable CRM_Core_Exception instead.
    try {
      $count = (new Mirror(new QboClient()))->refresh();
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception(
        'QuickBooks is not connected — see runbooks/qbo-oauth.md. (' . $e->getMessage() . ')'
      );
    }
    $result[] = ['mirrored' => $count];
  }

}
