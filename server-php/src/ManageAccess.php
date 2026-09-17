<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Whether this person owns the company they are opening.
 *
 * WHY THIS IS NOT THE PORTAL'S ANSWER
 *
 * The portal validates a *session*: it says the ses_key is live and who it
 * belongs to. It knows nothing about companies, so it cannot say whether that
 * person owns the one they just picked — the same human is the owner of one
 * company and a delegated user of the next.
 *
 * Ownership is a fact about a (person, company) pair, and Manage owns it. It
 * comes back on the company row, which this product already fetches on every
 * scoped request in order to check tenant access.
 *
 * Reading `acs_type` off the portal session instead is the bug this class
 * exists to close: that key is simply absent from a validatesession response,
 * so every user resolved as "not an owner", every permission check failed, and
 * the menu collapsed to the two entries that are not gated on anything.
 *
 * The resolution order mirrors the fleet's ManageCompanyAccessMapper, and the
 * browser's manageShapes.ts applies the same three rules in the same order —
 * a company must not be owned in the sidebar and shared in the API.
 */
final class ManageAccess
{
    public const OWNER = 1;
    public const DELEGATED = 0;

    /** Labels Manage has used for "not the owner" across its versions. */
    private const DELEGATED_LABELS = ['shared', 'delegated', 'user', 'viewer', 'editor', 'member'];

    /**
     * 1 = owner, 0 = delegated, null = Manage did not say.
     *
     * Null is not "no": it means unknown, and the caller decides. This product
     * treats unknown as delegated, which fails closed — a person wrongly shown
     * as delegated sees too little and says so, where one wrongly shown as an
     * owner sees the bank balance.
     *
     * @param array<string, mixed> $row a company row from Manage
     */
    public static function resolve(array $row): ?int
    {
        $explicit = $row['acs_type'] ?? null;
        if (is_int($explicit) || (is_string($explicit) && $explicit !== '' && ctype_digit($explicit))) {
            $value = (int) $explicit;
            if ($value === self::OWNER || $value === self::DELEGATED) {
                return $value;
            }
        }

        $ownership = $row['ownership'] ?? null;
        if (is_string($ownership) && $ownership !== '') {
            $label = strtolower(trim($ownership));
            if ($label === 'owner') {
                return self::OWNER;
            }
            if (in_array($label, self::DELEGATED_LABELS, true)) {
                return self::DELEGATED;
            }
        }

        // Oldest of the three, and the reason it is last: `is_creator` says who
        // made the company, which is usually but not always who owns it now.
        foreach (['is_creator', 'creator', 'is_owner'] as $key) {
            $flag = $row[$key] ?? null;
            if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true' || $flag === 'yes') {
                return self::OWNER;
            }
        }

        return null;
    }

    /**
     * Ownership for one company, from whichever Manage endpoint actually says.
     *
     * `companyinfo` is tried first because the tenant check has already fetched
     * it — if it carries ownership, this costs nothing. But it does not always:
     * the browser's own parser pulls ownership off the COMPANIES LIST row and
     * not off companyinfo, so the list is the authority and companyinfo is the
     * shortcut.
     *
     * Bounded at five pages. An accountant with three hundred client companies
     * is a real user, and an unbounded scan on a path that runs per request is
     * how one of them waits ten seconds for a dashboard.
     *
     * @param array<string, mixed> $companyInfoRow the row the tenant check already read
     */
    public static function forCompany(Clients\ManageClient $manage, int $cmpId, array $companyInfoRow): ?int
    {
        $fromInfo = self::resolve($companyInfoRow);
        if ($fromInfo !== null) {
            return $fromInfo;
        }

        for ($page = 1; $page <= 5; $page++) {
            $result = $manage->companies(['filter' => 'all', 'page' => $page, 'per_page' => 100]);
            if (!($result['ok'] ?? false)) {
                return null;
            }

            $rows = self::listRows($result['body'] ?? null);
            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $id = (int) ($row['comp_id'] ?? $row['cmp_id'] ?? $row['id'] ?? 0);
                if ($id === $cmpId) {
                    return self::resolve($row);
                }
            }

            if (count($rows) < 100) {
                return null;
            }
        }

        return null;
    }

    /**
     * Rows out of any `/manage/companies` envelope.
     *
     * Mirrors extractCompanyRows() in manageShapes.ts. The envelope has had
     * several shapes over the years and both halves of this product have to
     * read all of them the same way.
     *
     * @param array<string, mixed>|null $body
     * @return list<array<string, mixed>>
     */
    public static function listRows(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        $candidates = [
            $body['data'] ?? null,
            is_array($body['data'] ?? null) ? ($body['data']['companies'] ?? null) : null,
            is_array($body['data'] ?? null) ? ($body['data']['items'] ?? null) : null,
            $body['companies'] ?? null,
            $body['items'] ?? null,
            $body,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                $rows = array_values(array_filter($candidate, 'is_array'));
                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        return [];
    }

    /**
     * The company row inside whatever envelope Manage used.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public static function companyRow(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        foreach ([$body['data'] ?? null, $body['company'] ?? null, $body] as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate)) {
                return $candidate;
            }
            if (is_array($candidate) && array_is_list($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
                return $candidate[0];
            }
        }

        return [];
    }
}
