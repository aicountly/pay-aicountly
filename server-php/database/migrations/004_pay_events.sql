-- ---------------------------------------------------------------------------
-- Aicountly Pay — inbound webhooks, outbound callbacks, and the developer API
--
-- READ THIS BEFORE CALLING pay_outbound_events A SYNC TABLE. It is not one.
--
-- A sync table holds another product's data, refreshed on a schedule, so that
-- this product can read it without asking. Nothing here holds another product's
-- data. pay_outbound_events holds MESSAGES WE OWE — "a payment succeeded, here
-- is its id" — queued because the receiving app might be down at the moment the
-- money arrived, and because a payment that succeeded must not be rolled back
-- just because Books had a bad minute. The message is delivered, acknowledged
-- and finished with. It is an outbox, and an outbox is the opposite of a
-- mirror: it exists so that we never have to hold what belongs to somebody else.
--
-- The same logic runs inbound. pay_webhook_events stores the PROVIDER'S raw
-- callback exactly as received, because the signature is over those bytes and
-- because a provider's own record is the only evidence of what they told us.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Inbound webhooks from payment providers
--
-- Stored BEFORE processing, always, even when the signature fails. A rejected
-- webhook is the most interesting row in this table: it is either a provider
-- whose secret we have wrong, or somebody forging callbacks at us, and both are
-- things an operator needs to see rather than a 401 that went to nobody.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_webhook_events (
    event_id            BIGSERIAL PRIMARY KEY,
    event_uuid          TEXT         NOT NULL UNIQUE,
    -- NULL when the payload could not be attributed to a company — an unknown
    -- account, or a forgery. Those rows are kept and are visible only to an
    -- operator, never inside a company's screens.
    cmp_id              BIGINT,
    connection_id       BIGINT       REFERENCES pay_provider_connections(connection_id) ON DELETE SET NULL,

    provider_code       TEXT         NOT NULL,
    -- The provider's own event name: payment.captured, charge.succeeded…
    provider_event_type TEXT,
    provider_event_id   TEXT,

    -- sha256 over (provider, event id, type, payload). THE duplicate check:
    -- providers redeliver, and a redelivery must find its own fingerprint and
    -- stop rather than capture the payment a second time.
    fingerprint         TEXT         NOT NULL,

    -- The bytes the signature was computed over. Kept verbatim; re-encoding
    -- JSON reorders keys and the signature stops verifying.
    raw_payload         TEXT         NOT NULL,
    headers             JSONB        NOT NULL DEFAULT '{}'::jsonb,

    signature_valid     BOOLEAN      NOT NULL DEFAULT FALSE,
    signature_reason    TEXT,

    -- RECEIVED | PROCESSED | DUPLICATE | REJECTED | IGNORED | FAILED
    -- IGNORED is for an event type we do not act on, and is not a failure.
    status              TEXT         NOT NULL DEFAULT 'RECEIVED',
    -- Our normalised name for it, from Payments/Events/EventNames.php.
    normalized_event    TEXT,
    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    refund_id           BIGINT       REFERENCES pay_refunds(refund_id) ON DELETE SET NULL,

    process_error       TEXT,
    processing_ms       INTEGER,
    received_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    processed_at        TIMESTAMPTZ
);

-- The duplicate guard, at the database rather than in a code path somebody can
-- forget to run.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_webhook_fingerprint ON pay_webhook_events (fingerprint);

CREATE INDEX IF NOT EXISTS idx_pay_webhook_company  ON pay_webhook_events (cmp_id, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_webhook_status   ON pay_webhook_events (status, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_webhook_provider ON pay_webhook_events (provider_code, provider_event_id);
CREATE INDEX IF NOT EXISTS idx_pay_webhook_invalid  ON pay_webhook_events (provider_code, received_at DESC) WHERE signature_valid = FALSE;

-- --------------------------------------------------------------------------
-- Outbound events — what Pay owes the app that raised the request
--
-- NEVER SILENTLY LOST. A row lands here inside the same transaction that
-- records the payment, so there is no window in which money was taken and
-- nobody was told. The worker delivers it, backs off on failure, and a human
-- can retry by hand from the Reconciliation screen when the automatic attempts
-- are exhausted.
--
-- PAY DOES NOT WRITE INTO THE RECEIVING APP. This is a POST to that app's own
-- API, signed, which that app is free to act on however its domain requires:
-- Books decides whether it becomes a receipt voucher, Billing decides what it
-- does to the subscription. Pay never inserts a row over there.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_outbound_events (
    outbound_id         BIGSERIAL PRIMARY KEY,
    -- Sent as `event_id` and used by the receiver for ITS idempotency. Stable
    -- across every retry — a new id per attempt would defeat the whole point.
    event_uuid          TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    -- PAYMENT_SUCCESS, REFUND_SUCCESS… see Payments/Events/EventNames.php.
    event_name          TEXT         NOT NULL,
    -- BOOKS | BILLING | SALES | POS | WEBHOOK (a merchant's own endpoint)
    target_app          TEXT         NOT NULL,
    target_url          TEXT,
    -- Set when the destination is a merchant endpoint rather than a sibling app.
    endpoint_id         BIGINT,

    request_id          BIGINT       REFERENCES pay_payment_requests(request_id) ON DELETE SET NULL,
    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    refund_id           BIGINT       REFERENCES pay_refunds(refund_id) ON DELETE SET NULL,

    payload             JSONB        NOT NULL,

    -- PENDING | SENDING | DELIVERED | FAILED | EXHAUSTED | ABANDONED
    -- EXHAUSTED means the automatic attempts ran out and a human must decide.
    -- It is distinct from FAILED, which is a single attempt that did not land.
    status              TEXT         NOT NULL DEFAULT 'PENDING',
    attempt_count       INTEGER      NOT NULL DEFAULT 0,
    max_attempts        INTEGER      NOT NULL DEFAULT 6,
    next_attempt_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    last_attempt_at     TIMESTAMPTZ,
    response_status     INTEGER,
    -- First 500 characters of the response, for an operator. Never the whole
    -- body: a 500 page from a PHP app can carry a stack trace and a DSN.
    response_excerpt    TEXT,
    last_error          TEXT,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    delivered_at        TIMESTAMPTZ
);

-- The worker's query: what is due, oldest first.
CREATE INDEX IF NOT EXISTS idx_pay_outbound_due
    ON pay_outbound_events (next_attempt_at)
    WHERE status IN ('PENDING', 'FAILED');

CREATE INDEX IF NOT EXISTS idx_pay_outbound_company ON pay_outbound_events (cmp_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_outbound_attempt ON pay_outbound_events (attempt_id);

-- --------------------------------------------------------------------------
-- Developer surface — API clients, keys and merchant webhook endpoints
--
-- This is what lets Aicountly Pay be used by software that is not an Aicountly
-- product. A merchant's own website, a custom ERP, a future Aicountly app that
-- does not exist yet: all three use the same public contract and none of them
-- needs Pay to know anything about their schema.
--
-- THE SECRET IS HASHED, not encrypted. We issue it once, show it once, and
-- never need it in the clear again — so a leak of this table buys nothing.
-- (A provider credential is the opposite case and is encrypted; see 002.)
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_api_clients (
    client_id           BIGSERIAL PRIMARY KEY,
    client_uuid         TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    client_name         TEXT         NOT NULL,
    description         TEXT,
    -- What source_app payment requests from this client are attributed to.
    -- Defaults to EXTERNAL; a future Aicountly app registers under its own name
    -- and appears correctly in "Open requests by source" from day one.
    source_app          TEXT         NOT NULL DEFAULT 'EXTERNAL',

    -- ACTIVE | SUSPENDED | REVOKED
    status              TEXT         NOT NULL DEFAULT 'ACTIVE',

    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_api_clients_company ON pay_api_clients (cmp_id, status);

CREATE TABLE IF NOT EXISTS pay_api_keys (
    key_id              BIGSERIAL PRIMARY KEY,
    key_uuid            TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    client_id           BIGINT       NOT NULL REFERENCES pay_api_clients(client_id) ON DELETE CASCADE,

    -- The public half, which appears in logs and on screen: pk_live_7rxJf8Kq.
    key_prefix          TEXT         NOT NULL UNIQUE,
    -- HMAC of the secret half. Never reversible, never shown again.
    secret_hash         TEXT         NOT NULL,
    -- TEST | LIVE. A TEST key can only reach TEST provider connections.
    environment         TEXT         NOT NULL DEFAULT 'TEST',
    -- A JSON array from Permissions::API_SCOPES.
    scopes              JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- ACTIVE | REVOKED
    status              TEXT         NOT NULL DEFAULT 'ACTIVE',
    last_used_at        TIMESTAMPTZ,
    -- Enough to tell a key that is still in use from one that is not, without
    -- keeping a request log keyed to a merchant's customers.
    use_count           BIGINT       NOT NULL DEFAULT 0,

    expires_at          TIMESTAMPTZ,
    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    revoked_by          TEXT,
    revoked_at          TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_pay_api_keys_client ON pay_api_keys (client_id, status);
CREATE INDEX IF NOT EXISTS idx_pay_api_keys_active ON pay_api_keys (key_prefix) WHERE status = 'ACTIVE';

-- Where a merchant wants Pay's own events delivered.
CREATE TABLE IF NOT EXISTS pay_webhook_endpoints (
    endpoint_id         BIGSERIAL PRIMARY KEY,
    endpoint_uuid       TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    client_id           BIGINT       REFERENCES pay_api_clients(client_id) ON DELETE CASCADE,

    target_url          TEXT         NOT NULL,
    -- Which of our events they want. Empty = all.
    subscribed_events   JSONB        NOT NULL DEFAULT '[]'::jsonb,
    -- The secret WE issue for them to verify our signature with. Encrypted
    -- rather than hashed, because unlike an API key we have to use it again on
    -- every delivery to compute the signature.
    signing_secret      TEXT         NOT NULL,
    secret_hint         TEXT,

    -- ACTIVE | PAUSED | DISABLED
    -- An endpoint that has failed for days is PAUSED automatically, and the
    -- merchant is told. Retrying a dead URL forever is how a queue fills up.
    status              TEXT         NOT NULL DEFAULT 'ACTIVE',
    consecutive_failures INTEGER     NOT NULL DEFAULT 0,
    last_success_at     TIMESTAMPTZ,
    last_failure_at     TIMESTAMPTZ,
    last_failure_reason TEXT,

    environment         TEXT         NOT NULL DEFAULT 'LIVE',
    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_endpoints_company ON pay_webhook_endpoints (cmp_id, status);

-- --------------------------------------------------------------------------
-- Pay Pulse — recommendations, and whether anybody acted on them
--
-- WHAT IS STORED HERE IS A SUGGESTION AND ITS OUTCOME. Pay Pulse does not hold
-- a model, does not learn across companies, and computes nothing that is not
-- derived from this company's own payment rows. There is no central brain: the
-- insight engine reads pay_payment_attempts and pay_settlements for one cmp_id
-- and writes what it found.
--
-- `applied_by` is the important column. A recommendation Pay Pulse made is not
-- a change Pay Pulse made — routing can only be altered automatically when the
-- merchant has switched auto-optimisation on, and even then the row records
-- that it was the engine and not a person.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_pulse_insights (
    insight_id          BIGSERIAL PRIMARY KEY,
    insight_uuid        TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    -- PREDICT | RECOVER | ROUTE | DETECT | OPTIMISE
    capability          TEXT         NOT NULL,
    -- A stable key for one kind of finding, so the same finding is updated
    -- rather than piling up: upi_failure_spike, links_expiring_soon…
    insight_key         TEXT         NOT NULL,
    -- INFO | OPPORTUNITY | WARNING | RISK
    severity            TEXT         NOT NULL DEFAULT 'INFO',

    headline            TEXT         NOT NULL,
    detail              TEXT,
    -- The rows and figures it was computed from, so a merchant can check it.
    -- An insight a user cannot verify is an insight they will not act on.
    evidence            JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- 0–100. How much of the underlying data was actually available.
    confidence          INTEGER      NOT NULL DEFAULT 0,

    -- What it proposes: {kind, label, target, params}. Null for an observation.
    suggested_action    JSONB,
    estimated_value_minor BIGINT,

    -- NEW | SEEN | ACTIONED | DISMISSED | EXPIRED
    status              TEXT         NOT NULL DEFAULT 'NEW',
    -- A user uuid, or 'pay_pulse:auto' when auto-optimisation applied it.
    applied_by          TEXT,
    applied_at          TIMESTAMPTZ,
    dismissed_by        TEXT,
    dismissed_at        TIMESTAMPTZ,

    computed_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    expires_at          TIMESTAMPTZ,

    UNIQUE (cmp_id, insight_key)
);

CREATE INDEX IF NOT EXISTS idx_pay_pulse_company ON pay_pulse_insights (cmp_id, status, severity, computed_at DESC);

-- --------------------------------------------------------------------------
-- Provider observability — one row per outbound provider call, rolled up
--
-- Counts and latencies only. No payload, no identifier, no payer. It is what
-- the Gateways dashboard draws and what the router reads to decide a provider
-- is currently unhealthy, and it is aggregated by the minute so it stays small
-- enough to query on every routing decision.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_provider_metrics (
    metric_id           BIGSERIAL PRIMARY KEY,
    cmp_id              BIGINT       NOT NULL,
    connection_id       BIGINT       REFERENCES pay_provider_connections(connection_id) ON DELETE CASCADE,
    provider_code       TEXT         NOT NULL,
    -- createPayment | refundPayment | fetchSettlement | webhook…
    operation           TEXT         NOT NULL,
    payment_method      TEXT,
    -- Truncated to the minute.
    bucket_at           TIMESTAMPTZ  NOT NULL,

    call_count          INTEGER      NOT NULL DEFAULT 0,
    success_count       INTEGER      NOT NULL DEFAULT 0,
    failure_count       INTEGER      NOT NULL DEFAULT 0,
    total_latency_ms    BIGINT       NOT NULL DEFAULT 0,
    max_latency_ms      INTEGER      NOT NULL DEFAULT 0,
    -- The most recent failure code in this bucket, for the health panel.
    last_error_code     TEXT,

    UNIQUE (cmp_id, connection_id, operation, payment_method, bucket_at)
);

CREATE INDEX IF NOT EXISTS idx_pay_metrics_window ON pay_provider_metrics (cmp_id, bucket_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_metrics_conn   ON pay_provider_metrics (connection_id, bucket_at DESC);
