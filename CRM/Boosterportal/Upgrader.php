<?php
declare(strict_types = 1);
use CRM_Boosterportal_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
final class CRM_Boosterportal_Upgrader extends \CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Run sql/install.sql on install (§5.4 — the QBO mirror + login-token schema).
   *
   * civix's newer default (`generate:upgrader` on this civix format,
   * 25.10.2) wires info.xml's <upgrader> to
   * `CiviMix\Schema\Boosterportal\AutomaticUpgrader`, which lazily loads a
   * separate `civimix-schema` package to auto-run sql/install.sql — but that
   * package isn't vendored/available in this CiviCRM core install (there is
   * no schema-php mixin under vendor/civicrm/civicrm-core/mixin here; only
   * afform-entity-php, ang-php, case-xml, entity-types-php, mgd-php, menu-xml,
   * scan-classes, setting-admin, setting-php, smarty, smarty-v2, and theme-php
   * exist), so that class can never be resolved and would fatal on install.
   * This extension instead uses the classic mechanism: info.xml declares
   * <upgrader>CRM_Boosterportal_Upgrader</upgrader>
   * (this class), and CRM_Extension_Upgrader_Base::onInstall()/onUninstall()
   * only auto-source sql files matching the glob `sql/*_install.sql` /
   * `sql/*_uninstall.sql` — NOT the bare `sql/install.sql` / `sql/uninstall.sql`
   * filenames this extension's schema plan specifies — so those still need
   * calling explicitly here, same as civix's own commented-out example did.
   *
   * This path only fires on an actual install event (a genuinely fresh
   * `cv ext:enable`, or Civi\Test's headless installMe() against a fresh
   * test DB). It does NOT retroactively run for an extension that's already
   * installed — the live dev site's tables were created via a one-time
   * manual `cv sql < sql/install.sql` instead (documented in the Task 10
   * commit that introduced this schema).
   */
  public function install(): void {
    $this->executeSqlFile('sql/install.sql');
  }

  /**
   * Run sql/uninstall.sql on uninstall — the drop-table counterpart to
   * install() above. Same "bare filename, not the *_install.sql glob"
   * reasoning applies.
   */
  public function uninstall(): void {
    $this->executeSqlFile('sql/uninstall.sql');
  }

}
