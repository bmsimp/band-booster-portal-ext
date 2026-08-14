<?php
namespace Civi\Boosterportal;

/**
 * Magic-link auth (§4.3): single-use, 20-min window, address-bound,
 * rate-limited (3/address/hour, plus an IP backstop — see issue()). A link
 * IS a bearer token — accepted risk, §4.3 — so the token is high-entropy,
 * stored hashed, and dies on first use.
 *
 * ELIGIBILITY (AMENDMENT — Task 15 review): the plan's original draft
 * eligibility join was a bare Contact::get() + addJoin('Relationship', ...,
 * ['rel.is_permission_a_b', '>', 0]) — i.e. "any outgoing permissioned
 * relationship, of any type". That predates Task 7's ACL type-scoping.
 * boosterportal.php's own write-time guard (hook_civicrm_pre) deliberately
 * allows a SECOND relationship type to carry a non-NONE is_permission_a_b:
 * core's own 'Employee of' edge from the on-behalf-of-organisation
 * contribution flow (N1 there). A bare "is_permission_a_b > 0" join would
 * therefore also make an on-behalf-of contributor link-eligible, which is
 * wrong — that edge has nothing to do with the parent portal. This class
 * instead mirrors FamilyResolver's own SQL exactly: same relationship type
 * (Portal_Parent_of, looked up the same way
 * _boosterportal_portal_parent_relationship_type_id() in boosterportal.php
 * does), same direction (contact_id_a = the candidate, i.e. outgoing),
 * same is_active/date-window filter, same is_permission_a_b whitelist (VIEW
 * or EDIT). See MagicLinkTest::testEmployeeOfEdgeIsNotLinkEligible() and
 * ::testStudentEmailNeverGetsALink() for the fixtures that keep this honest.
 *
 * This class is necessarily pre-auth (there is no session yet — issuing and
 * redeeming a link IS how a session gets created) so every DB access here
 * is raw, parameterized SQL rather than an ACL-checked Api4 call — see
 * InvariantTest's allowlist entry on this file for the containment argument.
 *
 * ADVERSARIAL SECURITY REVIEW (post-launch, Task 15 follow-up) — three
 * findings fixed here, in order of severity:
 *
 *  IMPORTANT-1 (TOCTOU on single-use): the original redeem() did a
 *  SELECT-then-UPDATE — two separate statements with a window between them
 *  in which two concurrent redemptions of the same token could both pass
 *  the SELECT's "not yet used" check before either UPDATE lands, defeating
 *  single-use. redeem() below is now ONE atomic guarded UPDATE (the
 *  single-use predicate — used_at IS NULL AND expires_at > NOW() — lives in
 *  the UPDATE's WHERE clause itself, not a prior read), and only a row
 *  actually mutated by *this* UPDATE (affectedRows() === 1) is trusted.
 *  MySQL's own row-lock on the UPDATE serializes concurrent attempts against
 *  the same row; at most one can ever see affectedRows() === 1.
 *
 *  IMPORTANT-2(i) (stale eligibility at redemption): a token issued while a
 *  parent was eligible could still be sitting unused when the underlying
 *  Portal_Parent_of edge is revoked/deactivated/expires, or the contact is
 *  deleted, minutes later but still inside the 20-minute window. redeem()
 *  now re-runs the SAME eligibility check issue() used, keyed by the
 *  token's contact id, and rejects if it no longer passes — closing the
 *  window between issue-time and redeem-time. (IMPORTANT-2(ii), the Drupal
 *  role safety check, lives in CRM_Boosterportal_Page_PortalLogin — this
 *  class has no Drupal dependency.)
 *
 *  IMPORTANT-3 (email-only rate limit): keying solely on the requested
 *  email means one actor with no rate limit of their own can spray requests
 *  across many different guessed/harvested addresses from a single source
 *  with no ceiling at all (each address independently gets its own fresh
 *  3/hour budget). issue() now also counts recent requests BY REQUESTING IP
 *  and blocks if either dimension is at the ceiling — see issue() for the
 *  residual (shared-NAT parents) documented there and in
 *  runbooks/magic-links.md.
 */
class MagicLink {

  public const WINDOW_MINUTES = 20;
  public const MAX_PER_HOUR = 3;

  /**
   * @return string|null the raw token (caller builds the URL), NULL if not issued
   */
  public function issue(string $email, string $ip): ?string {
    // IMPORTANT-3: rate limit by EITHER dimension — the requested address
    // (protects a specific address from being spammed) OR the requesting IP
    // (stops one source from cheaply spraying many different addresses,
    // since each address used to get its own independent 3/hour budget with
    // no cap on how many addresses a single IP could try). Residual: a
    // shared-NAT household/school network sharing one public IP can trip
    // the IP-side limit on behalf of unrelated parents behind it — see
    // runbooks/magic-links.md, which documents this as an accepted tradeoff
    // rather than something silently unbounded.
    $recentByEmail = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM boosterportal_login_token
       WHERE email = %1 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
      [1 => [$email, 'String']]);
    if ($recentByEmail >= self::MAX_PER_HOUR) {
      return NULL;
    }
    $recentByIp = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM boosterportal_login_token
       WHERE request_ip = %1 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
      [1 => [$ip, 'String']]);
    if ($recentByIp >= self::MAX_PER_HOUR) {
      return NULL;
    }

    $contactId = $this->eligibleContactIdFor($email);
    if (!$contactId) {
      return NULL; // Unknown or ineligible — indistinguishable from success.
    }

    $token = bin2hex(random_bytes(32));
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO boosterportal_login_token
        (contact_id, email, token_hash, created_at, expires_at, request_ip)
       VALUES (%1, %2, %3, NOW(), DATE_ADD(NOW(), INTERVAL %4 MINUTE), %5)',
      [1 => [$contactId, 'Integer'], 2 => [$email, 'String'],
       3 => [hash('sha256', $token), 'String'],
       4 => [self::WINDOW_MINUTES, 'Integer'], 5 => [$ip, 'String']]);
    return $token;
  }

  public function sendLink(string $email, string $ip): void {
    $token = $this->issue($email, $ip);
    if ($token === NULL) {
      return; // Same outward behavior either way — never reveal whether the address exists.
    }
    $url = \CRM_Utils_System::url('civicrm/portal/login', ['t' => $token], TRUE, NULL, FALSE, TRUE);
    // CRM_Utils_Mail::send() declares its $params argument BY REFERENCE
    // (array &$params) — a literal array expression can't bind to a
    // reference parameter, so this must be a named variable, not inlined.
    // Caught live testing the request-link -> Mailpit flow end-to-end
    // (Task 15): the plan's own draft inlined the array here and would
    // fatal on every real send with "Argument #1 ($params) could not be
    // passed by reference".
    $params = [
      'from' => \Civi::settings()->get('boosterportal_webmaster_email'),
      'toEmail' => $email,
      'subject' => 'Your Band Boosters sign-in link',
      'text' => "Follow this link to sign in (valid for " . self::WINDOW_MINUTES . " minutes, one use):\n\n{$url}\n\n"
        . "If you didn't request this, you can ignore it.",
    ];
    \CRM_Utils_Mail::send($params);
  }

  /**
   * @return int contact id
   * @throws \CRM_Core_Exception on any invalid, expired, already-used,
   *   garbage/truncated token, OR a token whose contact no longer passes
   *   the eligibility check (IMPORTANT-2(i) — see class docblock).
   */
  public function redeem(string $token): int {
    $hash = hash('sha256', $token);

    // IMPORTANT-1: single atomic guarded UPDATE — the "not already used,
    // not expired" predicate is evaluated and consumed in the SAME
    // statement, under MySQL's own row lock, so two concurrent redemptions
    // of the same token can never both succeed (see class docblock).
    $updateResult = \CRM_Core_DAO::executeQuery(
      'UPDATE boosterportal_login_token SET used_at = NOW()
       WHERE token_hash = %1 AND used_at IS NULL AND expires_at > NOW()',
      [1 => [$hash, 'String']]);
    if ($updateResult->affectedRows() !== 1) {
      throw new \CRM_Core_Exception('Invalid or expired link');
    }

    $contactId = \CRM_Core_DAO::singleValueQuery(
      'SELECT contact_id FROM boosterportal_login_token WHERE token_hash = %1',
      [1 => [$hash, 'String']]);
    // Defensive: the UPDATE above already proved a matching row exists (we
    // wouldn't be here otherwise), so this can only be NULL if something
    // deleted the row in the instant between the two statements — treat
    // that identically to "invalid token" rather than fatal on a NULL cast.
    if (!$contactId) {
      throw new \CRM_Core_Exception('Invalid or expired link');
    }
    $contactId = (int) $contactId;

    // IMPORTANT-2(i): re-check eligibility NOW, not just at issue() time —
    // see class docblock for why a token can go stale inside its own
    // window.
    if (!$this->isCurrentlyEligible($contactId)) {
      throw new \CRM_Core_Exception('Invalid or expired link');
    }

    return $contactId;
  }

  /**
   * MINOR-3: housekeeping for the login-token table — deletes rows that are
   * no longer live (already used, or past their expiry window) and old
   * enough that nothing could still be investigating them (a support
   * question like "why did my link not work" is answered within minutes,
   * not days). Wired to a daily scheduled Job
   * (managed/MagicLinkJobs.mgd.php) via BoosterPortal.purgeLoginTokens
   * (Civi/Api4/Action/BoosterPortal/PurgeLoginTokens.php) — admin/cron-only,
   * same permission shape as refreshMirror/runRecon.
   *
   * @return int number of rows deleted
   */
  public function purgeOldTokens(int $olderThanHours = 24): int {
    $result = \CRM_Core_DAO::executeQuery(
      'DELETE FROM boosterportal_login_token
       WHERE (used_at IS NOT NULL OR expires_at < NOW())
         AND created_at < DATE_SUB(NOW(), INTERVAL %1 HOUR)',
      [1 => [$olderThanHours, 'Positive']]);
    return $result->affectedRows();
  }

  /**
   * §4.3 shared-address rule: the address maps to whichever eligible
   * Individual holds it (primary email). Eligible = an Individual, not
   * deleted, holding an outgoing (contact_id_a), active, currently-in-window
   * Portal_Parent_of relationship with is_permission_a_b IN (VIEW, EDIT) —
   * exactly the candidate-set SQL boosterportal_civicrm_aclWhereClause() and
   * FamilyResolver::studentsOf() use, just walked in the other direction
   * (here: does the candidate hold ANY such outgoing edge at all, not which
   * specific students). No other relationship type — including core's
   * Employee-of on-behalf-of edge — ever qualifies. See the class docblock.
   *
   * MINOR-1: explicit ORDER BY c.id ASC before LIMIT 1 — if two eligible
   * Individuals somehow share the same primary email (shouldn't happen in
   * practice, but nothing in the data model forbids it), which ONE wins
   * must be deterministic rather than left to MySQL's unspecified row
   * order, so the same address always resolves to the same contact from
   * one call to the next.
   */
  private function eligibleContactIdFor(string $email): ?int {
    $portalTypeId = \_boosterportal_portal_parent_relationship_type_id();
    if (!$portalTypeId) {
      // RelationshipType not installed — nothing can be eligible.
      return NULL;
    }

    $contactId = \CRM_Core_DAO::singleValueQuery(
      'SELECT c.id
       FROM civicrm_contact c
       INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.email = %1 AND e.is_primary = 1
       WHERE c.contact_type = %2
         AND c.is_deleted = 0
         AND EXISTS (
           SELECT 1 FROM civicrm_relationship r
           WHERE r.contact_id_a = c.id
             AND r.relationship_type_id = %3
             AND r.is_active = 1
             AND r.is_permission_a_b IN (%4, %5)
             AND (r.start_date IS NULL OR r.start_date <= CURDATE())
             AND (r.end_date IS NULL OR r.end_date >= CURDATE())
         )
       ORDER BY c.id ASC
       LIMIT 1',
      [
        1 => [$email, 'String'],
        2 => ['Individual', 'String'],
        3 => [$portalTypeId, 'Positive'],
        4 => [\CRM_Contact_BAO_Relationship::EDIT, 'Positive'],
        5 => [\CRM_Contact_BAO_Relationship::VIEW, 'Positive'],
      ]);
    return $contactId ? (int) $contactId : NULL;
  }

  /**
   * IMPORTANT-2(i): the redeem()-time counterpart to eligibleContactIdFor()
   * — same eligibility rule (Portal_Parent_of, active, VIEW/EDIT, date
   * window, not deleted), just keyed by contact id (already resolved) rather
   * than re-deriving it from an email address. Kept as a SEPARATE query
   * rather than reusing eligibleContactIdFor() so this method's contract is
   * "is this SPECIFIC contact still eligible" and can never accidentally
   * resolve to a DIFFERENT contact than the one the token was issued to —
   * eligibleContactIdFor() intentionally CAN return a different contact
   * than a caller expects (that is its whole shared-address job at issue()
   * time), which is the wrong shape for a redemption-time re-check.
   */
  private function isCurrentlyEligible(int $contactId): bool {
    $portalTypeId = \_boosterportal_portal_parent_relationship_type_id();
    if (!$portalTypeId) {
      return FALSE;
    }

    $stillEligible = \CRM_Core_DAO::singleValueQuery(
      'SELECT c.id
       FROM civicrm_contact c
       WHERE c.id = %1
         AND c.contact_type = %2
         AND c.is_deleted = 0
         AND EXISTS (
           SELECT 1 FROM civicrm_relationship r
           WHERE r.contact_id_a = c.id
             AND r.relationship_type_id = %3
             AND r.is_active = 1
             AND r.is_permission_a_b IN (%4, %5)
             AND (r.start_date IS NULL OR r.start_date <= CURDATE())
             AND (r.end_date IS NULL OR r.end_date >= CURDATE())
         )',
      [
        1 => [$contactId, 'Positive'],
        2 => ['Individual', 'String'],
        3 => [$portalTypeId, 'Positive'],
        4 => [\CRM_Contact_BAO_Relationship::EDIT, 'Positive'],
        5 => [\CRM_Contact_BAO_Relationship::VIEW, 'Positive'],
      ]);
    return (bool) $stillEligible;
  }

}
