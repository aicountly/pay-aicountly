<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company and branch every scoped request carries.
 *
 * Two ids that scope Pay's own rows, and one that does not.
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that simply
 * returns nothing, which would leak the difference between "no rows" and "not
 * yours" and would break the moment a query forgot its WHERE clause.
 *
 * WHY FINANCIAL YEAR IS NOT A SCOPE HERE, unlike every other product in the
 * fleet. A payment happens when it happens. Its timestamp is the moment the
 * money moved, and a settlement lands two days later whatever the books say
 * about year ends. Scoping payment rows by `fy_id` would mean a payment against
 * a March invoice, captured on 2 April, either falls outside its own request's
 * scope or has its timestamp bent to fit — and a settlement batch that spans a
 * year boundary could not be represented at all.
 *
 * So `fy_id` is carried as CONTEXT and passed through to the source app, which
 * does need it: Books files the receipt in a year, and only Books knows which.
 * It is never a column on a Pay table and never appears in a WHERE clause.
 */
final class Context
{
    /** @var array<string, bool> */
    private static array $verified = [];

    /**
     * Company access type per (company, session), from Manage.
     *
     * Memoised beside the tenant check because it arrives on the same row: the
     * call that proves you may open this company is the call that says whether
     * you own it.
     *
     * @var array<string, ?int>
     */
    private static array $accessTypes = [];

    private function __construct(
        public readonly int $cmpId,
        /** 0 = all branches. Narrows Pay's own rows; never invented. */
        public readonly int $boId,
        /**
         * Manage's financial year for the ORIGINATING app, when the caller sent
         * one. Passed through on source callbacks and never stored on a Pay row.
         */
        public readonly int $fyId,
    ) {
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function fromRequest(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $boId  = Http::intParam('bo_id', 0) ?? 0;
        $fyId  = Http::intParam('fy_id', 0) ?? 0;

        if ($cmpId <= 0) {
            Http::error(400, 'context_required', 'Pick a company first (cmp_id is required).');
        }

        return new self($cmpId, max(0, $boId), max(0, $fyId));
    }

    /**
     * The scope an API key is locked to, ignoring whatever the request asked for.
     *
     * A merchant's own integration must not be able to widen its reach by
     * changing a query parameter, so the company comes off the key and the
     * request only gets to choose the branch.
     */
    public static function forApiClient(Auth $auth): self
    {
        if ($auth->boundCmpId <= 0) {
            Http::forbidden('This API key is not bound to a company.');
        }

        return new self($auth->boundCmpId, max(0, Http::intParam('bo_id', 0) ?? 0), max(0, Http::intParam('fy_id', 0) ?? 0));
    }

    /** Scope for a job with no request behind it: a worker, a webhook, a settlement import. */
    public static function forBackground(int $cmpId, int $boId = 0, int $fyId = 0): self
    {
        return new self($cmpId, max(0, $boId), max(0, $fyId));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure
     * to reach Manage is a 503 and not an allow, because the alternative is
     * serving one tenant's payments to another whenever Manage has a bad minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            // A service key is issued to a product, not to a person, and the
            // owning product has already checked the human behind it.
            return;
        }

        if ($auth->isApiClient()) {
            // The key IS the scope. It was minted inside this company by
            // somebody who had already passed this check, and it cannot name
            // another one — Context::forApiClient() reads the company off the
            // key and discards what the request asked for.
            if ($auth->boundCmpId !== $this->cmpId) {
                Http::forbidden('This API key cannot act on that company.');
            }

            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (isset(self::$verified[$key])) {
            return;
        }

        $manage = (new ManageClient())->withSession($auth->sesKey());
        $result = $manage->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // Unreachable is not "allowed". A tenant check that fails open is
            // not a tenant check.
            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.');
        }

        $company = ManageAccess::companyRow($result['body'] ?? null);
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        self::$accessTypes[$key] = ManageAccess::forCompany($manage, $this->cmpId, $company);
        self::$verified[$key] = true;
    }

    /**
     * 1 = owner of this company, 0 = delegated, null = Manage did not say.
     *
     * Resolves on demand so a caller that reaches it before assertAllowed()
     * still gets an answer rather than a silent "not the owner".
     */
    public function accessType(Auth $auth): ?int
    {
        if ($auth->isMachine()) {
            return ManageAccess::OWNER;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (!array_key_exists($key, self::$accessTypes)) {
            $this->assertAllowed($auth);
        }

        $fromManage = self::$accessTypes[$key] ?? null;
        if ($fromManage !== null) {
            return $fromManage;
        }

        return $auth->accessType();
    }

    public function isOwner(Auth $auth): bool
    {
        return $this->accessType($auth) === ManageAccess::OWNER;
    }

    /** Test seam: the memo is per request in production and must not leak between cases. */
    public static function forgetAccess(): void
    {
        self::$verified = [];
        self::$accessTypes = [];
    }

    /**
     * Context to send to a source app.
     *
     * `fy_id` goes out ONLY when the caller gave one. Sending a zero would
     * invite the other end to read it as a year, and a receipt filed in
     * financial year nought is a support ticket nobody can explain.
     *
     * @return array{cmp_id:int, bo_id:int, fy_id?:int}
     */
    public function asQuery(): array
    {
        $out = ['cmp_id' => $this->cmpId, 'bo_id' => $this->boId];
        if ($this->fyId > 0) {
            $out['fy_id'] = $this->fyId;
        }

        return $out;
    }

    /** @return array{cmp_id:int, bo_id:int, fy_id?:int} */
    public function asBody(): array
    {
        return $this->asQuery();
    }

    /**
     * The WHERE fragment and bindings every query in this product starts with.
     *
     * `bo_id` 0 means all branches, so it narrows only when it is set. There is
     * no `fy_id` here by design — see the class comment.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function scopeClause(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = $prefix . 'cmp_id = :ctx_cmp_id';
        $params = ['ctx_cmp_id' => $this->cmpId];

        if ($this->boId > 0) {
            $sql .= ' AND (' . $prefix . 'bo_id = :ctx_bo_id OR ' . $prefix . 'bo_id = 0)';
            $params['ctx_bo_id'] = $this->boId;
        }

        return [$sql, $params];
    }
}
