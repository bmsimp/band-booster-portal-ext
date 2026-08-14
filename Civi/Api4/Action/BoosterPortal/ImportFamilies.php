<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\Importer;

/**
 * Task 11 API surface: console/admin-only wrapper around the one-time
 * Importer. Gated by 'administer CiviCRM' — see BoosterPortal::permissions().
 */
class ImportFamilies extends AbstractAction {

  /** @var bool Dry-run is the default; pass dryRun=0 to write. */
  protected bool $dryRun = TRUE;

  public function _run(Result $result) {
    $result[] = (new Importer())->run($this->dryRun);
  }

}
