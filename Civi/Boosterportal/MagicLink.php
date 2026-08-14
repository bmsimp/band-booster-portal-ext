<?php
namespace Civi\Boosterportal;

/**
 * Magic-link auth (§4.3): single-use, 20-min window, address-bound,
 * rate-limited (3/address/hour). A link IS a bearer token — accepted risk,
 * §4.3 — so the token is high-entropy, stored hashed, and dies on first use.
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
 */
class MagicLink {

  public const WINDOW_MINUTES = 20;
  public const MAX_PER_HOUR = 3;

  /** @return string|null the raw token (caller builds the URL), NULL if not issued */
  public function issue(string $email, string $ip): ?string {
    $recent = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM boosterportal_login_token
       WHERE email = %1 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
      [1 => [$email, 'String']]);
    if ($recent >= self::MAX_PER_HOUR) {
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

  /** @return int contact id @throws \CRM_Core_Exception on any invalid token */
  public function redeem(string $token): int {
    $row = \CRM_Core_DAO::executeQuery(
      'SELECT id, contact_id FROM boosterportal_login_token
       WHERE token_hash = %1 AND used_at IS NULL AND expires_at > NOW()',
      [1 => [hash('sha256', $token), 'String']])->fetchAll();
    if (!$row) {
      throw new \CRM_Core_Exception('Invalid or expired link');
    }
    \CRM_Core_DAO::executeQuery(
      'UPDATE boosterportal_login_token SET used_at = NOW() WHERE id = %1',
      [1 => [$row[0]['id'], 'Integer']]);
    return (int) $row[0]['contact_id'];
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

}
