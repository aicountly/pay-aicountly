-- ---------------------------------------------------------------------------
-- Aicountly Pay — the payment lifecycle, and nothing else
--
-- WHAT IS NOT IN THIS SCHEMA, ON PURPOSE:
--
--   no company master          Manage owns it. We keep cmp_id, a bare integer.
--   no branch master           Manage owns it. We keep bo_id.
--   no financial year master   Manage owns it. Pay does not scope by year at
--                              all — see src/Context.php for why.
--   no customer master         Books, Billing and Sales own their customers.
--                              We keep a reference and a payment-time snapshot.
--   no invoice, no voucher     The source app owns the document. We keep
--                              source_app + source_type + source_id.
--   no ledger, no tax, no GST  Books owns accounting. Pay never computes a tax.
--
-- There is no synchronisation job anywhere in this product, no mirror table, no
-- "cache of the customer master", and no second connection string. Everything
-- above is read live over HTTP on the request that needs it (src/Clients), and
-- the release-blocking test in tests/integration.php reads information_schema
-- and FAILS if a table matching one of those forbidden shapes ever appears.
--
-- WHAT IS OURS, because nobody else records it:
--   * the payment request, its attempts and the money that arrived
--   * which provider took it, and what the provider called it
--   * refunds, disputes, mandates
--   * settlement batches and whether the bank credit matched
--   * every webhook we received and every callback we owe a source app
--
-- MONEY IS BIGINT MINOR UNITS. ₹1,180.00 is 118000. See Domain/Money.php.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Settings — one row per company
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_settings (
    cmp_id                  BIGINT       PRIMARY KEY,
    -- Shown to the payer on the public checkout. Not a copy of the company
    -- master: it is the trading name this merchant wants on a payment page,
    -- which is often not the registered name Manage holds.
    checkout_display_name   TEXT,
    checkout_logo_url       TEXT,
    checkout_support_email  TEXT,
    checkout_support_phone  TEXT,
    checkout_terms_url      TEXT,

    default_currency        TEXT         NOT NULL DEFAULT 'INR',
    -- Hours a payment link stays live when the caller names no expiry.
    default_link_expiry_hours INTEGER    NOT NULL DEFAULT 168,
    allow_partial_by_default BOOLEAN     NOT NULL DEFAULT FALSE,
    -- Below this, a partial payment is refused: a ₹10 part-payment against a
    -- ₹1,00,000 invoice costs more in gateway fees than it collects.
    min_partial_minor       BIGINT       NOT NULL DEFAULT 10000,

    -- Refunds above this need a second person. NULL = no approval needed.
    refund_approval_threshold_minor BIGINT,
    refund_requires_approval BOOLEAN     NOT NULL DEFAULT FALSE,

    -- Pay Pulse may RECOMMEND routing changes always; it may APPLY them only
    -- when this is on. See Payments/Routing/RoutingEngine.php.
    auto_routing_enabled    BOOLEAN      NOT NULL DEFAULT FALSE,
    pay_pulse_enabled       BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Retry cadence for the outbound source callbacks, in minutes.
    callback_retry_minutes  JSONB        NOT NULL DEFAULT '[1, 5, 30, 180, 720]'::jsonb,

    onboarded_at            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------------------------
-- Roles — who may do what inside Pay
--
-- Layered over the portal identity. Pay stores no password, no email and no
-- user master: `user_uuid` is the portal's uuid and nothing else.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_roles (
    role_id         BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    role_code       TEXT         NOT NULL,
    role_name       TEXT         NOT NULL,
    description     TEXT,
    template_key    TEXT         NOT NULL DEFAULT 'custom',
    -- A JSON array of permission codes from Permissions::CATALOG.
    permissions     JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_system       BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, role_code)
);

CREATE TABLE IF NOT EXISTS pay_role_assignments (
    assignment_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    user_uuid       TEXT         NOT NULL,
    role_id         BIGINT       NOT NULL REFERENCES pay_roles(role_id) ON DELETE CASCADE,
    created_by      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, role_id)
);

CREATE INDEX IF NOT EXISTS idx_pay_role_assign_user ON pay_role_assignments (cmp_id, user_uuid);

-- --------------------------------------------------------------------------
-- Payers — the counterparty, as Pay knows them
--
-- NOT a customer master and not a copy of one. Two kinds of row live here:
--
--   SOURCE   a customer that belongs to Books / Billing / Sales / POS. We keep
--            the app and its id, and nothing else authoritative. The name and
--            address on the screen are read live from that app.
--
--   STANDALONE  somebody who only ever existed as a payer: a walk-in paying a
--            link, a customer of an app that has no customer master. Pay owns
--            this record because nobody else does.
--
-- The contact columns on a SOURCE row are a PAYMENT-TIME SNAPSHOT — the number
-- the link was actually sent to — kept because it is part of the audit trail of
-- that payment, not because it is the customer's current number. When the
-- source app has a newer one, the source app is right.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_payers (
    payer_id            BIGSERIAL PRIMARY KEY,
    payer_uuid          TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    -- SOURCE | STANDALONE
    payer_kind          TEXT         NOT NULL DEFAULT 'STANDALONE',
    -- BOOKS | BILLING | SALES | POS | PAY | <future app>. NULL for standalone.
    source_app          TEXT,
    -- The id THAT APP uses. Opaque here; never joined to anything.
    source_customer_ref TEXT,

    display_name        TEXT         NOT NULL,
    email               TEXT,
    mobile              TEXT,

    -- Denormalised behaviour, computed from this company's own payments by
    -- PayPulse\BehaviourService. Rebuildable from pay_payment_attempts at any
    -- time; nothing here is authoritative and nothing is read from another app.
    first_paid_at       TIMESTAMPTZ,
    last_paid_at        TIMESTAMPTZ,
    payment_count       INTEGER      NOT NULL DEFAULT 0,
    total_paid_minor    BIGINT       NOT NULL DEFAULT 0,
    -- FAST | DELAYED | PARTIAL | METHOD_SENSITIVE | RETRY_RECOVERABLE | NEW
    behaviour_segment   TEXT         NOT NULL DEFAULT 'NEW',
    preferred_method    TEXT,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- One Pay record per source customer, so repeated requests against the same
-- Books customer collect into one payment history instead of one row each.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_payers_source
    ON pay_payers (cmp_id, source_app, source_customer_ref)
    WHERE source_app IS NOT NULL AND source_customer_ref IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_payers_company ON pay_payers (cmp_id, display_name);
CREATE INDEX IF NOT EXISTS idx_pay_payers_mobile  ON pay_payers (cmp_id, mobile) WHERE mobile IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_pay_payers_email   ON pay_payers (cmp_id, email) WHERE email IS NOT NULL;

-- --------------------------------------------------------------------------
-- Payment requests — what the merchant asked for
--
-- THE SOURCE REFERENCE IS FOUR COLUMNS AND NO MORE:
--   source_app + source_type + source_id  identify the document elsewhere
--   source_reference                      the human-readable number, for the payer
--
-- There is no invoice total, no line, no tax, no party balance. When a screen
-- needs the invoice behind a request it calls that app. The amount here is what
-- the source app SAID was payable at the moment the request was raised, and it
-- is immutable afterwards: a payment is made against an amount, and changing
-- that amount later would rewrite what the customer agreed to pay.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_payment_requests (
    request_id          BIGSERIAL PRIMARY KEY,
    -- The id that leaves this system. Opaque, non-sequential, safe in a URL.
    request_uuid        TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    bo_id               BIGINT       NOT NULL DEFAULT 0,

    -- Where it came from. 'PAY' means somebody raised it here.
    source_app          TEXT         NOT NULL DEFAULT 'PAY',
    source_type         TEXT,
    source_id           TEXT,
    source_reference    TEXT,
    -- Manage's financial year for the SOURCE document, carried so the callback
    -- can tell Books which year to file its receipt in. Never scoped on.
    source_fy_id        BIGINT,

    payer_id            BIGINT       REFERENCES pay_payers(payer_id) ON DELETE SET NULL,
    -- Payment-time snapshot of who was asked. Part of the transaction record.
    payer_name          TEXT,
    payer_email         TEXT,
    payer_mobile        TEXT,

    description         TEXT,
    amount_minor        BIGINT       NOT NULL CHECK (amount_minor > 0),
    currency            TEXT         NOT NULL DEFAULT 'INR',

    allow_partial       BOOLEAN      NOT NULL DEFAULT FALSE,
    min_partial_minor   BIGINT,

    -- DRAFT | ACTIVE | PARTIALLY_PAID | PAID | EXPIRED | CANCELLED
    -- DERIVED from the attempts and refunds below, never set from a webhook.
    status              TEXT         NOT NULL DEFAULT 'ACTIVE',
    -- Running totals, recalculated in the same transaction as every payment.
    -- They are a cache of SUM() over the attempts and exist so a list of two
    -- hundred requests is one query rather than two hundred and one.
    paid_minor          BIGINT       NOT NULL DEFAULT 0,
    refunded_minor      BIGINT       NOT NULL DEFAULT 0,

    -- Methods this request accepts, e.g. ["UPI","CARD"]. Empty = whatever the
    -- routing engine can serve.
    allowed_methods     JSONB        NOT NULL DEFAULT '[]'::jsonb,

    expires_at          TIMESTAMPTZ,
    return_url          TEXT,
    -- Opaque string the source app gets back on every callback about this
    -- request. Pay never parses it.
    callback_reference  TEXT,
    notes               TEXT,

    -- Engagement, for the collections funnel. Counters only; no visitor record,
    -- no IP, no device fingerprint.
    viewed_count        INTEGER      NOT NULL DEFAULT 0,
    first_viewed_at     TIMESTAMPTZ,
    checkout_started_at TIMESTAMPTZ,

    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    cancelled_at        TIMESTAMPTZ,
    cancelled_reason    TEXT,
    paid_at             TIMESTAMPTZ,

    -- Never more refunded than collected, whatever a provider reports.
    CONSTRAINT pay_request_refund_bound CHECK (refunded_minor <= paid_minor)
);

-- One live request per source document. A source app that retries its create
-- call after a timeout gets the SAME request back rather than a second one
-- pointing at the same invoice. Cancelled requests are excluded so a document
-- whose request was called off can be asked for again.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_request_source
    ON pay_payment_requests (cmp_id, source_app, source_type, source_id)
    WHERE source_id IS NOT NULL AND status <> 'CANCELLED';

CREATE INDEX IF NOT EXISTS idx_pay_requests_company_status ON pay_payment_requests (cmp_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_requests_payer          ON pay_payment_requests (cmp_id, payer_id);
CREATE INDEX IF NOT EXISTS idx_pay_requests_source_app     ON pay_payment_requests (cmp_id, source_app, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_requests_expiry         ON pay_payment_requests (expires_at) WHERE status IN ('ACTIVE', 'PARTIALLY_PAID');
CREATE INDEX IF NOT EXISTS idx_pay_requests_reference      ON pay_payment_requests (cmp_id, source_reference);

-- --------------------------------------------------------------------------
-- Payment attempts — one journey through one provider
--
-- SEPARATE FROM THE REQUEST BECAUSE THEY ARE SEPARATE FACTS. One ₹1,00,000
-- request can hold a failed UPI attempt, an abandoned net-banking attempt, a
-- successful ₹40,000 card payment and a later successful ₹60,000 UPI payment.
-- A single status column on the request would have to lose three of those four,
-- and the ones it loses are the ones that answer "why did this take four days".
--
-- NO CARD DATA. Not the number, not the CVV, not a PAN, not an expiry. The four
-- columns below (method, network, last4, token reference) are what a provider
-- returns and what a human needs to recognise their own card on a statement.
-- Anything more belongs to the provider's vault and stays there.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_payment_attempts (
    attempt_id          BIGSERIAL PRIMARY KEY,
    attempt_uuid        TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    bo_id               BIGINT       NOT NULL DEFAULT 0,

    request_id          BIGINT       REFERENCES pay_payment_requests(request_id) ON DELETE RESTRICT,
    payer_id            BIGINT       REFERENCES pay_payers(payer_id) ON DELETE SET NULL,

    amount_minor        BIGINT       NOT NULL CHECK (amount_minor > 0),
    currency            TEXT         NOT NULL DEFAULT 'INR',

    -- CREATED | INITIATED | PENDING | AUTHORIZED | CAPTURED | SUCCESS | FAILED | CANCELLED
    status              TEXT         NOT NULL DEFAULT 'CREATED',

    -- UPI | CARD | NETBANKING | WALLET | EMI | PAYLATER | OTHER
    payment_method      TEXT,
    -- VISA, MASTERCARD, RUPAY, HDFC, PHONEPE… whatever the provider named.
    method_network      TEXT,
    method_last4        TEXT,
    -- The provider's own token/vault reference. NOT a card number and never
    -- usable outside that provider's account.
    method_token_ref    TEXT,
    payer_vpa_masked    TEXT,

    -- Which provider took it. connection_id is NULL for an external payment.
    connection_id       BIGINT,
    -- DIRECT | MANAGED | EXTERNAL
    provider_mode       TEXT         NOT NULL DEFAULT 'DIRECT',
    -- RAZORPAY | CASHFREE | STRIPE | PAYU | EXTERNAL | MOCK
    provider_code       TEXT,
    provider_payment_id TEXT,
    provider_order_id   TEXT,
    -- Provider's own failure code and its own words, kept verbatim: the
    -- retry advice in Pay Pulse is built on the code, and a paraphrase would
    -- make two different failures look like one.
    failure_code        TEXT,
    failure_reason      TEXT,

    -- How the payer arrived: LINK | QR | CHECKOUT | MANDATE | API | EXTERNAL
    channel             TEXT         NOT NULL DEFAULT 'LINK',
    -- Which retry of this request this was, for the recovery queue.
    attempt_no          INTEGER      NOT NULL DEFAULT 1,
    -- Set when this attempt exists because an earlier one failed.
    retry_of_attempt_id BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,

    refunded_minor      BIGINT       NOT NULL DEFAULT 0,
    settlement_id       BIGINT,
    -- PENDING | SETTLED | PARTIAL | NOT_APPLICABLE
    settlement_status   TEXT         NOT NULL DEFAULT 'PENDING',

    -- Where Pay stands with the app that raised the request.
    -- NOT_APPLICABLE | PENDING | SENT | FAILED
    source_sync_status  TEXT         NOT NULL DEFAULT 'NOT_APPLICABLE',
    source_synced_at    TIMESTAMPTZ,

    initiated_at        TIMESTAMPTZ,
    authorized_at       TIMESTAMPTZ,
    captured_at         TIMESTAMPTZ,
    -- When the money actually moved, per the provider. This is the timestamp
    -- every figure on every dashboard is grouped by — not created_at, which is
    -- when we made the row.
    paid_at             TIMESTAMPTZ,
    failed_at           TIMESTAMPTZ,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT pay_attempt_refund_bound CHECK (refunded_minor <= amount_minor)
);

-- The same provider payment must never produce two attempt rows. This is what
-- makes a redelivered webhook harmless at the database level, rather than only
-- in the code that happens to check first.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_attempt_provider_payment
    ON pay_payment_attempts (provider_code, provider_payment_id)
    WHERE provider_payment_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_attempts_request   ON pay_payment_attempts (request_id, created_at);
CREATE INDEX IF NOT EXISTS idx_pay_attempts_company   ON pay_payment_attempts (cmp_id, status, paid_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_attempts_paid      ON pay_payment_attempts (cmp_id, paid_at DESC) WHERE status IN ('SUCCESS', 'CAPTURED');
CREATE INDEX IF NOT EXISTS idx_pay_attempts_provider  ON pay_payment_attempts (cmp_id, provider_code, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_attempts_method    ON pay_payment_attempts (cmp_id, payment_method, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_attempts_settlement ON pay_payment_attempts (settlement_id) WHERE settlement_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_pay_attempts_sync      ON pay_payment_attempts (source_sync_status) WHERE source_sync_status = 'FAILED';
CREATE INDEX IF NOT EXISTS idx_pay_attempts_payer     ON pay_payment_attempts (cmp_id, payer_id, paid_at DESC);

-- --------------------------------------------------------------------------
-- Payment links and QR codes
--
-- A link is a WAY TO REACH a payment request, not a second kind of request.
-- One request can have several: a WhatsApp link, an emailed link and a printed
-- QR all collecting against the same ₹1,00,000, all sharing its paid total.
--
-- The token is what appears in the URL and it is random, not derived from the
-- id. An incrementing id in a public URL is an invitation to walk the range and
-- read other people's payment pages.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_payment_links (
    link_id         BIGSERIAL PRIMARY KEY,
    link_uuid       TEXT         NOT NULL UNIQUE,
    cmp_id          BIGINT       NOT NULL,
    request_id      BIGINT       NOT NULL REFERENCES pay_payment_requests(request_id) ON DELETE CASCADE,

    -- LINK | QR
    link_kind       TEXT         NOT NULL DEFAULT 'LINK',
    -- The public token. 32 URL-safe characters of randomness.
    public_token    TEXT         NOT NULL UNIQUE,

    -- LINK | WHATSAPP | EMAIL | SMS | QR | IN_APP | EMBEDDED
    channel         TEXT         NOT NULL DEFAULT 'LINK',
    -- Provider-hosted link, when the provider made one for us.
    provider_code   TEXT,
    provider_link_id TEXT,
    provider_link_url TEXT,
    -- The QR payload, when the provider issued one. A string, not an image:
    -- rendering belongs to the browser and the print stylesheet.
    qr_payload      TEXT,
    -- STATIC QR is only ever set when the configured provider actually
    -- supports one. We do not invent the capability.
    is_reusable     BOOLEAN      NOT NULL DEFAULT FALSE,

    -- ACTIVE | EXPIRED | CANCELLED | CONSUMED
    status          TEXT         NOT NULL DEFAULT 'ACTIVE',
    expires_at      TIMESTAMPTZ,

    view_count      INTEGER      NOT NULL DEFAULT 0,
    checkout_count  INTEGER      NOT NULL DEFAULT 0,
    last_viewed_at  TIMESTAMPTZ,
    sent_at         TIMESTAMPTZ,
    sent_to         TEXT,

    created_by      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_links_request ON pay_payment_links (request_id);
CREATE INDEX IF NOT EXISTS idx_pay_links_company ON pay_payment_links (cmp_id, link_kind, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_links_expiry  ON pay_payment_links (expires_at) WHERE status = 'ACTIVE';

-- --------------------------------------------------------------------------
-- Idempotency — do it once, however many times you are asked
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_idempotency_keys (
    fingerprint     TEXT         PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    operation       TEXT         NOT NULL,
    idempotency_key TEXT         NOT NULL,
    -- IN_FLIGHT | DONE | FAILED
    state           TEXT         NOT NULL DEFAULT 'IN_FLIGHT',
    -- The first caller's answer, replayed verbatim to every later one.
    result          JSONB,
    claimed_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    completed_at    TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_pay_idempotency_sweep ON pay_idempotency_keys (claimed_at);

-- --------------------------------------------------------------------------
-- Audit — append-only, and never holding what it audits
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_audit_log (
    audit_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    actor_uuid      TEXT         NOT NULL,
    actor_kind      TEXT         NOT NULL,
    source_app      TEXT,
    action          TEXT         NOT NULL,
    entity_type     TEXT         NOT NULL,
    entity_id       TEXT,
    -- Secrets are redacted before they reach these columns. See src/Audit.php.
    before_state    JSONB,
    after_state     JSONB,
    reason          TEXT,
    ip_address      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_audit_company ON pay_audit_log (cmp_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_audit_entity  ON pay_audit_log (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_pay_audit_action  ON pay_audit_log (cmp_id, action, created_at DESC);
