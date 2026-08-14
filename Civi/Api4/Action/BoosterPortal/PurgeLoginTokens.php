<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\MagicLink;

/**
 * MINOR-3 (Task 15 adversarial review): daily housekeeping for
 * boosterportal_login_token — deletes rows that are no longer live (used or
 * expired) and old enough that nothing could still be investigating them.
 * Thin wrapper, identical in shape to RunRecon/RefreshMirror — the daily
 * job (managed/MagicLinkJobs.mgd.php) calls this via
 * BoosterPortal.purgeLoginTokens.
 */
class PurgeLoginTokens extends AbstractAction {

  public function _run(Result $result) {
    $result[] = ['deleted' => (new MagicLink())->purgeOldTokens()];
  }

}
