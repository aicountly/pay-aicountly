-- ---------------------------------------------------------------------------
-- Aicountly Pay — providers, credentials, routing and managed onboarding
--
-- THE THREE MODES, and why they are one table rather than three:
--
--   DIRECT    the merchant's own Razorpay / Cashfree / Stripe / PayU account.
--             We hold their API credentials and call that account.
--
--   MANAGED   Aicountly's own arrangement with an authorised payment partner.
--             The merchant is onboarded through us; the partner still does the
--             KYC and still holds the money until settlement.
--
--   HYBRID    is not a mode. It is simply a company with more than one row in
--             pay_provider_connections and a routing table that sends different
--             payments to different ones. Modelling it as a third mode would
--             mean a company could not be half-way through adding a second
--             provider, which is exactly when a merchant is most likely to be.
--
-- CREDENTIALS ARE ENCRYPTED AT REST, always, in a separate table from the
-- connection so that listing providers — which every gateway screen does — does
-- not read a single byte of ciphertext into memory. See src/Crypto.php.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_provider_connections (
    connection_id       BIGSERIAL PRIMARY KEY,
    connection_uuid     TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    -- RAZORPAY | CASHFREE | STRIPE | PAYU | MOCK
    provider_code       TEXT         NOT NULL,
    -- DIRECT | MANAGED
    provider_mode       TEXT         NOT NULL DEFAULT 'DIRECT',
    -- What the merchant calls it: "My Razorpay", "Aicountly Managed".
    display_name        TEXT         NOT NULL,

    -- PENDING | ACTIVE | DISABLED | CREDENTIALS_INVALID | SUSPENDED
    -- CREDENTIALS_INVALID is its own state rather than a flavour of DISABLED:
    -- the merchant did not turn this off, it stopped working, and the two need
    -- different words on the screen and different behaviour in the router.
    status              TEXT         NOT NULL DEFAULT 'PENDING',
    status_reason       TEXT,

    -- Non-secret identifiers the merchant should see to recognise the account.
    merchant_ref        TEXT,
    -- Masked tail of the key id, e.g. "••••••••8921". Never the secret.
    key_hint            TEXT,

    -- Methods this connection may take, e.g. ["UPI","CARD","NETBANKING"].
    -- The router will not send a method that is not in here, whatever the
    -- routing rules say.
    supported_methods   JSONB        NOT NULL DEFAULT '[]'::jsonb,
    supported_currencies JSONB       NOT NULL DEFAULT '["INR"]'::jsonb,
    capabilities        JSONB        NOT NULL DEFAULT '{}'::jsonb,

    -- TEST | LIVE. A TEST connection can never take a live payment and a LIVE
    -- one never appears in sandbox: mixing them is how a real card gets charged
    -- during a demo.
    environment         TEXT         NOT NULL DEFAULT 'LIVE',

    is_primary          BOOLEAN      NOT NULL DEFAULT FALSE,
    priority            INTEGER      NOT NULL DEFAULT 100,

    -- Health, written by the provider client on every call. Read by the router
    -- to skip a provider that is currently failing, and by the Gateways
    -- dashboard. Rolling counters, reset by the health sweep.
    health_state        TEXT         NOT NULL DEFAULT 'UNKNOWN',
    health_checked_at   TIMESTAMPTZ,
    recent_success      INTEGER      NOT NULL DEFAULT 0,
    recent_failure      INTEGER      NOT NULL DEFAULT 0,
    avg_latency_ms      INTEGER,
    last_error_at       TIMESTAMPTZ,
    last_error_code     TEXT,

    -- Where this provider sends its webhooks, and whether we have confirmed it.
    webhook_url         TEXT,
    webhook_verified_at TIMESTAMPTZ,

    settlement_cycle    TEXT,

    connected_by        TEXT,
    connected_at        TIMESTAMPTZ,
    disabled_at         TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- One connection per provider per mode per environment, per company. A merchant
-- may hold a live and a test Razorpay at once, and a direct Razorpay alongside
-- a managed one, but not two live direct Razorpays — which would leave the
-- router with no way to choose.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_connection
    ON pay_provider_connections (cmp_id, provider_code, provider_mode, environment);

CREATE INDEX IF NOT EXISTS idx_pay_connections_company ON pay_provider_connections (cmp_id, status);

-- --------------------------------------------------------------------------
-- Credentials — the only ciphertext in this database
--
-- SEPARATE TABLE, one row per secret, so that:
--   * the provider list never touches them;
--   * a rotation replaces one row rather than rewriting a JSON blob;
--   * `SELECT * FROM pay_provider_connections` in a console is not a breach.
--
-- `secret_value` is an AES-256-GCM envelope (see src/Crypto.php) and is never
-- returned to the browser under any circumstance. The API answers "Configured"
-- and the masked hint from the connection row, and nothing else.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_provider_credentials (
    credential_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    connection_id   BIGINT       NOT NULL REFERENCES pay_provider_connections(connection_id) ON DELETE CASCADE,

    -- key_id | key_secret | webhook_secret | access_token | refresh_token | account_id
    credential_kind TEXT         NOT NULL,
    -- v1.<key id>.<nonce>.<tag>.<ciphertext>
    secret_value    TEXT         NOT NULL,
    -- What may be shown: "rzp_live_••••8921". Derived at write time.
    masked_hint     TEXT,

    expires_at      TIMESTAMPTZ,
    rotated_at      TIMESTAMPTZ,
    rotated_by      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (connection_id, credential_kind)
);

CREATE INDEX IF NOT EXISTS idx_pay_credentials_company ON pay_provider_credentials (cmp_id);

-- --------------------------------------------------------------------------
-- Routing rules — which provider takes which payment
--
-- Evaluated in `priority` order, first match wins. A rule names a primary and
-- an optional fallback; the router still refuses either if it is disabled, has
-- invalid credentials, does not support the method or the currency, or is
-- currently unhealthy. A routing rule is a preference, never an override of
-- what a provider can actually do.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_routing_rules (
    rule_id             BIGSERIAL PRIMARY KEY,
    cmp_id              BIGINT       NOT NULL,
    rule_name           TEXT         NOT NULL,
    priority            INTEGER      NOT NULL DEFAULT 100,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Conditions. NULL means "does not narrow on this".
    match_method        TEXT,
    match_currency      TEXT,
    -- DOMESTIC | INTERNATIONAL, for the card rules merchants actually write.
    match_scope         TEXT,
    match_source_app    TEXT,
    match_min_minor     BIGINT,
    match_max_minor     BIGINT,

    primary_connection_id  BIGINT REFERENCES pay_provider_connections(connection_id) ON DELETE CASCADE,
    fallback_connection_id BIGINT REFERENCES pay_provider_connections(connection_id) ON DELETE SET NULL,

    -- Whether a failure on the primary may be retried on the fallback at all.
    allow_failover      BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Set when Pay Pulse proposed this rule. A recommendation that a human
    -- accepted is still a human's decision, and the trail says which.
    suggested_by_ai     BOOLEAN      NOT NULL DEFAULT FALSE,
    accepted_by         TEXT,

    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_routing_company ON pay_routing_rules (cmp_id, is_active, priority);

-- --------------------------------------------------------------------------
-- Every routing decision, as it was made
--
-- Written on every payment. It is what turns "why did this go through Stripe"
-- from an argument into a lookup, and it is what the Gateways dashboard counts
-- to show traffic distribution.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_routing_decisions (
    decision_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    attempt_id      BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE CASCADE,
    rule_id         BIGINT       REFERENCES pay_routing_rules(rule_id) ON DELETE SET NULL,
    chosen_connection_id BIGINT  REFERENCES pay_provider_connections(connection_id) ON DELETE SET NULL,
    -- RULE | DEFAULT | FALLBACK | ONLY_ELIGIBLE
    decided_by      TEXT         NOT NULL,
    -- Connections considered and why each was rejected. The reason a merchant
    -- can be told "Stripe was skipped: it does not support UPI" instead of
    -- "routing failed".
    considered      JSONB        NOT NULL DEFAULT '[]'::jsonb,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_routing_decisions_attempt ON pay_routing_decisions (attempt_id);
CREATE INDEX IF NOT EXISTS idx_pay_routing_decisions_company ON pay_routing_decisions (cmp_id, created_at DESC);

-- --------------------------------------------------------------------------
-- Aicountly Managed onboarding
--
-- WHAT THIS TABLE IS NOT: an approval workflow. Aicountly does not verify
-- anybody's PAN. The authorised payment partner does the KYC and decides, and
-- these columns mirror THEIR state so the merchant can see where they stand
-- without leaving Pay.
--
-- `kyc_status` therefore only ever changes because the partner said so — either
-- on a status poll or on a webhook. Nothing in this product sets it to VERIFIED
-- on its own, and the integration test asserts that.
--
-- NO DOCUMENT CONTENT. A PAN card scan is uploaded straight to the partner and
-- what stays here is that it was uploaded, when, and what the partner called it.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_managed_onboarding (
    onboarding_id       BIGSERIAL PRIMARY KEY,
    cmp_id              BIGINT       NOT NULL,
    connection_id       BIGINT       REFERENCES pay_provider_connections(connection_id) ON DELETE CASCADE,
    provider_code       TEXT         NOT NULL,

    -- The partner's id for this merchant.
    provider_merchant_id TEXT,

    -- NOT_STARTED | IN_PROGRESS | ACTION_REQUIRED | UNDER_REVIEW | VERIFIED | REJECTED | SUSPENDED
    kyc_status          TEXT         NOT NULL DEFAULT 'NOT_STARTED',
    kyc_status_detail   TEXT,
    kyc_updated_at      TIMESTAMPTZ,

    -- Business profile as submitted. Identifiers, not documents.
    legal_name          TEXT,
    business_type       TEXT,
    business_category   TEXT,
    pan_last4           TEXT,
    gstin               TEXT,
    registered_address  JSONB,
    contact_name        TEXT,
    contact_email       TEXT,
    contact_phone       TEXT,

    -- Settlement bank, masked. The full account number goes to the partner and
    -- is not kept: Pay never initiates a bank transfer, so it never needs it.
    bank_account_last4  TEXT,
    bank_ifsc           TEXT,
    bank_name           TEXT,
    bank_verified_at    TIMESTAMPTZ,

    -- [{kind, provider_document_id, uploaded_at, status}] — no file content.
    documents           JSONB        NOT NULL DEFAULT '[]'::jsonb,
    -- What the partner says is still missing, in the partner's own words.
    required_actions    JSONB        NOT NULL DEFAULT '[]'::jsonb,

    submitted_by        TEXT,
    submitted_at        TIMESTAMPTZ,
    activated_at        TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (cmp_id, provider_code)
);

CREATE INDEX IF NOT EXISTS idx_pay_managed_status ON pay_managed_onboarding (cmp_id, kyc_status);
