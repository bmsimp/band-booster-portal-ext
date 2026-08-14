# boosterportal

A [CiviCRM extension](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/) for a
high school marching band booster club's parent portal. It's the only custom code in that
system, and its scope is deliberately narrow: read-only QuickBooks Online integration, per-family
balance display, and reconciliation between CiviCRM's contact/contribution records and QBO's
customer/invoice data. Everything else the portal needs — forms, admin screens, dashboards — is
built on CiviCRM's own SearchKit, FormBuilder, and Afform, not on custom code here.

Licensed under [AGPL-3.0](LICENSE.txt).

## Status

Early scaffold. Phase 1 (of a phased build-out) is in progress — nothing here is production
functionality yet.

## Project docs

Design, architecture, and phase planning live in this project's infra/docs repository
(`band-booster-portal`), currently private.

## Development

This extension lives inside, and is developed against, the host Drupal + CiviCRM site's local
DDEV environment — it isn't a standalone install. Tests run headlessly via PHPUnit inside that
site's `ddev` container; see the host site repo's docs for environment setup and the exact
test-running commands (test-database bootstrap script, phpunit invocation, and known-harmless
warnings).
