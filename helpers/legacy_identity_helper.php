<?php
declare(strict_types=1);

/**
 * Shared Canonical Identity Helper for Legacy Debt Module.
 *
 * Provides a single, authoritative canonicalization strategy for:
 * - Legacy debtor import
 * - Legacy debtor matching
 * - Normal loan matching
 * - Migration duplicate detection
 * - Duplicate resolution workflows
 */

if (!function_exists('legacyNormalizeValue')) {
    function legacyNormalizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('legacyNormalizeIdentity')) {
    /**
     * Canonicalize PF numbers and National IDs:
     * - Trims leading and trailing whitespace.
     * - Preserves leading zeroes.
     * - Normalizes letter casing to uppercase.
     * - Strips harmless separators (hyphens, spaces, slashes, dots, underscores, punctuation).
     * - Retains all alphanumeric characters without loss of identifying digits.
     */
    function legacyNormalizeIdentity(?string $value): ?string
    {
        $value = legacyNormalizeValue($value);
        if ($value === null) {
            return null;
        }

        // Strip non-alphanumeric separators, preserve leading zeroes, uppercase
        $cleaned = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
        return $cleaned === '' ? null : $cleaned;
    }
}

if (!function_exists('legacyNormalizeName')) {
    /**
     * Canonicalize names:
     * - Trims leading and trailing whitespace.
     * - Collapses internal whitespace sequences into a single space.
     * - Converts to uppercase with UTF-8 support.
     */
    function legacyNormalizeName(?string $value): ?string
    {
        $value = legacyNormalizeValue($value);
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_strtoupper($value, 'UTF-8');
    }
}

if (!function_exists('legacyIdentitiesMatch')) {
    /**
     * Check if two identifiers match either exactly or canonically.
     */
    function legacyIdentitiesMatch(?string $a, ?string $b): bool
    {
        $rawA = legacyNormalizeValue($a);
        $rawB = legacyNormalizeValue($b);
        if ($rawA === null || $rawB === null) {
            return false;
        }
        if ($rawA === $rawB) {
            return true;
        }
        $canonA = legacyNormalizeIdentity($rawA);
        $canonB = legacyNormalizeIdentity($rawB);
        return $canonA !== null && $canonA === $canonB;
    }
}

if (!function_exists('legacyAssessMigrationIdentityMatches')) {
    /**
     * Comprehensive identity matching for migration between legacy debt accounts
     * and normal loan applications / users.
     *
     * Invariants enforced:
     * 1. Exact identity matches detected.
     * 2. Canonical identity matches detected.
     * 3. Conflicting PF and National ID combinations flagged and blocked.
     * 4. Ambiguous identities blocked pending controlled resolution.
     * 5. Existing user-to-loan relationships considered.
     * 6. Existing migration linkage checked.
     * 7. Duplicate migration attempts fail safely.
     * 8. Matching decision fully detailed for auditing.
     *
     * @param PDO $db Active database connection with row locking
     * @param array $account Legacy account record including debtor fields
     * @return array Assessment results: [
     *     'status' => 'NO_MATCH'|'EXACT_MATCH'|'CANONICAL_MATCH'|'CONFLICT'|'AMBIGUOUS'|'ALREADY_LINKED',
     *     'matches' => array of candidate loans,
     *     'linked_loan_id' => ?int,
     *     'decision_notes' => string,
     *     'audit_payload' => array
     * ]
     */
    function legacyAssessMigrationIdentityMatches(PDO $db, array $account): array
    {
        $accountId = (int)($account['legacy_account_id'] ?? 0);
        $linkedId = (int)($account['linked_loan_application_id'] ?? 0);
        $debtorUserId = !empty($account['linked_user_id']) ? (int)$account['linked_user_id'] : null;

        $rawPf = legacyNormalizeValue((string)($account['pf_number'] ?? ''));
        $rawId = legacyNormalizeValue((string)($account['national_id'] ?? ''));
        $canonPf = legacyNormalizeIdentity($rawPf);
        $canonId = legacyNormalizeIdentity($rawId);

        // Fetch candidate normal loans based on user_id, raw values, or canonical regex matches
        $conditions = [];
        $params = [];

        if ($debtorUserId !== null) {
            $conditions[] = 'user_id = ?';
            $params[] = $debtorUserId;
        }
        if ($rawPf !== null) {
            $conditions[] = 'pf_number = ?';
            $params[] = $rawPf;
        }
        if ($rawId !== null) {
            $conditions[] = 'id_number = ?';
            $params[] = $rawId;
        }
        if ($canonPf !== null) {
            $conditions[] = "REGEXP_REPLACE(CAST(pf_number AS CHAR), '[^A-Za-z0-9]', '') = ?";
            $params[] = $canonPf;
        }
        if ($canonId !== null) {
            $conditions[] = "REGEXP_REPLACE(CAST(id_number AS CHAR), '[^A-Za-z0-9]', '') = ?";
            $params[] = $canonId;
        }
        if ($linkedId > 0) {
            $conditions[] = 'id = ?';
            $params[] = $linkedId;
        }

        if (empty($conditions)) {
            return [
                'status' => 'NO_MATCH',
                'matches' => [],
                'linked_loan_id' => null,
                'decision_notes' => 'No identifying fields present on legacy debtor',
                'audit_payload' => ['match_count' => 0, 'status' => 'NO_MATCH']
            ];
        }

        $sql = 'SELECT id, application_id, user_id, pf_number, id_number, loan_amount, status
                  FROM loan_applications
                 WHERE (' . implode(' OR ', $conditions) . ')
                 ORDER BY id FOR UPDATE';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Deduplicate rows by id
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[(int)$row['id']] = $row;
        }
        $candidates = array_values($candidates);

        // Check whether any candidate is already linked to ANOTHER legacy account
        if (!empty($candidates)) {
            $candidateIds = array_map(static fn($c) => (int)$c['id'], $candidates);
            $inClause = implode(',', array_fill(0, count($candidateIds), '?'));
            $linkCheck = $db->prepare(
                "SELECT legacy_account_id, linked_loan_application_id
                   FROM legacy_debt_accounts
                  WHERE linked_loan_application_id IN ({$inClause})
                    AND legacy_account_id != ?"
            );
            $linkParams = array_merge($candidateIds, [$accountId]);
            $linkCheck->execute($linkParams);
            $existingOtherLinks = $linkCheck->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($existingOtherLinks)) {
                $otherAccId = (int)$existingOtherLinks[0]['legacy_account_id'];
                $otherLoanId = (int)$existingOtherLinks[0]['linked_loan_application_id'];
                return [
                    'status' => 'ALREADY_LINKED',
                    'matches' => $candidates,
                    'linked_loan_id' => $otherLoanId,
                    'decision_notes' => "Candidate normal loan {$otherLoanId} is already linked to legacy account {$otherAccId}",
                    'audit_payload' => [
                        'match_count' => count($candidates),
                        'status' => 'ALREADY_LINKED',
                        'conflicting_account_id' => $otherAccId,
                        'conflicting_loan_id' => $otherLoanId
                    ]
                ];
            }
        }

        if (empty($candidates)) {
            return [
                'status' => 'NO_MATCH',
                'matches' => [],
                'linked_loan_id' => null,
                'decision_notes' => 'No candidate normal loans found matching debtor identity',
                'audit_payload' => ['match_count' => 0, 'status' => 'NO_MATCH']
            ];
        }

        // Detailed evaluation of candidates
        $exactMatches = [];
        $canonicalMatches = [];
        $conflicts = [];
        $evaluatedCandidates = [];

        foreach ($candidates as $cand) {
            $cId = (int)$cand['id'];
            $candUserId = !empty($cand['user_id']) ? (int)$cand['user_id'] : null;
            $candRawPf = legacyNormalizeValue((string)($cand['pf_number'] ?? ''));
            $candRawId = legacyNormalizeValue((string)($cand['id_number'] ?? ''));
            $candCanonPf = legacyNormalizeIdentity($candRawPf);
            $candCanonId = legacyNormalizeIdentity($candRawId);

            $pfExact = ($rawPf !== null && $candRawPf !== null && $rawPf === $candRawPf);
            $idExact = ($rawId !== null && $candRawId !== null && $rawId === $candRawId);
            $userExact = ($debtorUserId !== null && $candUserId !== null && $debtorUserId === $candUserId);

            $pfCanon = ($canonPf !== null && $candCanonPf !== null && $canonPf === $candCanonPf);
            $idCanon = ($canonId !== null && $candCanonId !== null && $canonId === $candCanonId);

            // Flag conflicting combinations:
            // e.g. PF matches canonically but National ID is present on both and differs,
            // or National ID matches canonically but PF is present on both and differs.
            $pfConflict = ($pfCanon && $canonId !== null && $candCanonId !== null && !$idCanon);
            $idConflict = ($idCanon && $canonPf !== null && $candCanonPf !== null && !$pfCanon);

            $candAnalysis = [
                'candidate' => $cand,
                'pf_exact' => $pfExact,
                'id_exact' => $idExact,
                'user_exact' => $userExact,
                'pf_canon' => $pfCanon,
                'id_canon' => $idCanon,
                'has_conflict' => ($pfConflict || $idConflict)
            ];
            $evaluatedCandidates[] = $candAnalysis;

            if ($candAnalysis['has_conflict']) {
                $conflicts[] = $cand;
            } elseif ($pfExact && $idExact) {
                $exactMatches[] = $cand;
            } elseif ($pfCanon || $idCanon || $userExact) {
                $canonicalMatches[] = $cand;
            }
        }

        if (!empty($conflicts)) {
            return [
                'status' => 'CONFLICT',
                'matches' => $candidates,
                'linked_loan_id' => (int)$conflicts[0]['id'],
                'decision_notes' => 'Conflicting PF number and National ID combination detected across legacy and normal loan records',
                'audit_payload' => [
                    'match_count' => count($candidates),
                    'status' => 'CONFLICT',
                    'conflicts' => array_map(static fn($c) => $c['application_id'], $conflicts)
                ]
            ];
        }

        // Check for ambiguous candidates (e.g. multiple distinct normal loans)
        if (count($candidates) > 1) {
            // If one candidate is already linked to this account, ensure all other candidates don't create ambiguity
            if ($linkedId > 0) {
                $linkedMatch = array_filter($candidates, static fn($c) => (int)$c['id'] === $linkedId);
                if (empty($linkedMatch)) {
                    return [
                        'status' => 'CONFLICT',
                        'matches' => $candidates,
                        'linked_loan_id' => $linkedId,
                        'decision_notes' => 'Existing linked loan does not match evaluated candidates',
                        'audit_payload' => ['status' => 'CONFLICT', 'linked_id' => $linkedId]
                    ];
                }
            } else {
                return [
                    'status' => 'AMBIGUOUS',
                    'matches' => $candidates,
                    'linked_loan_id' => null,
                    'decision_notes' => 'Ambiguous identity match: multiple candidate normal loans exist for this identity',
                    'audit_payload' => [
                        'match_count' => count($candidates),
                        'status' => 'AMBIGUOUS',
                        'candidates' => array_map(static fn($c) => $c['application_id'], $candidates)
                    ]
                ];
            }
        }

        // Single candidate remaining
        $single = $candidates[0];
        $isExact = !empty($exactMatches);

        return [
            'status' => $isExact ? 'EXACT_MATCH' : 'CANONICAL_MATCH',
            'matches' => $candidates,
            'linked_loan_id' => (int)$single['id'],
            'decision_notes' => $isExact
                ? "Exact identity match found for normal loan {$single['application_id']}"
                : "Canonical identity match found for normal loan {$single['application_id']}",
            'audit_payload' => [
                'match_count' => 1,
                'status' => $isExact ? 'EXACT_MATCH' : 'CANONICAL_MATCH',
                'loan_id' => (int)$single['id'],
                'application_id' => $single['application_id']
            ]
        ];
    }
}

