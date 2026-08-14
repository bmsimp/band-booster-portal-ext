<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\Recon;

/**
 * Thin wrapper, identical in shape to RefreshMirror (Task 10) — the hourly
 * job (managed/ReconJobs.mgd.php) calls this via BoosterPortal.runReconHourly.
 */
class RunReconHourly extends AbstractAction {

  public function _run(Result $result) {
    $result[] = ['findings' => count((new Recon())->runHourly())];
  }

}
