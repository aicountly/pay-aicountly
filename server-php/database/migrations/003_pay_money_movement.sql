-- ---------------------------------------------------------------------------
-- Aicountly Pay — refunds, disputes, mandates, settlements, reconciliation
-- and payments collected outside Pay
--
-- WHERE THIS STOPS. Pay tracks money from the payer to the merchant's bank
-- account and answers one question about it: did what we collected arrive, less
-- what the provider charged? That is settlement reconciliation.
--
-- It is NOT bank reconciliation. "Which ledger does this bank line belong to,
-- and what is the accounting effect" is Books' question, Books has the ledgers
-- to answer it, and a second answer computed here would be a second set of
-- books. So there is no ledger column anywhere below, no accounting date, no
-- voucher, and no credit note — a refund here tells Books a refund happened and
-- Books decides what document that becomes.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Refunds
--
-- MAKER-CHECKER IS A SETTING, NOT A HARD RULE, because a one-person business
-- has nobody to check it and a fifty-person one must not let a clerk send
-- ₹2,00,000 back unreviewed. pay_settings.refund_requires_approval and
-- refund_approval_threshold_minor decide; `approved_by` records who, and the
-- constraint below refuses to let one person be both.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_refunds (
    refund_id           BIGSERIAL PRIMARY KEY,
    refund_uuid         TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    bo_id               BIGINT       NOT NULL DEFAULT 0,

    attempt_id          BIGINT       NOT NULL REFERENCES pay_payment_attempts(attempt_id) ON DELETE RESTRICT,
    request_id          BIGINT       REFERENCES pay_payment_requests(request_id) ON DELETE SET NULL,

    amount_minor        BIGINT       NOT NULL CHECK (amount_minor > 0),
    currency            TEXT         NOT NULL DEFAULT 'INR',
    -- FULL | PARTIAL, decided at request time against the payment's balance.
    refund_kind         TEXT         NOT NULL DEFAULT 'PARTIAL',

    -- REQUESTED | APPROVED | PROCESSING | REFUNDED | FAILED | REJECTED
    status              TEXT         NOT NULL DEFAULT 'REQUESTED',

    reason_code         TEXT,
    reason              TEXT         NOT NULL,

    provider_code       TEXT,
    provider_refund_id  TEXT,
    provider_status     TEXT,
    failure_code        TEXT,
    failure_reason      TEXT,

    -- Refunds settle too, and a refund that has not been deducted from a
    -- settlement is an exception the Settlements dashboard shows.
    settlement_id       BIGINT,
    settlement_status   TEXT         NOT NULL DEFAULT 'PENDING',

    source_sync_status  TEXT         NOT NULL DEFAULT 'NOT_APPLICABLE',
    source_synced_at    TIMESTAMPTZ,

    requested_by        TEXT         NOT NULL,
    requested_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    approved_by         TEXT,
    approved_at         TIMESTAMPTZ,
    rejected_by         TEXT,
    rejected_at         TIMESTAMPTZ,
    rejected_reason     TEXT,
    processed_at        TIMESTAMPTZ,
    refunded_at         TIMESTAMPTZ,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    -- The whole point of maker-checker. Enforced here as well as in the service
    -- because a check that lives only in one code path is a check that one day
    -- gets a second code path.
    CONSTRAINT pay_refund_four_eyes CHECK (approved_by IS NULL OR approved_by <> requested_by)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_refund_provider
    ON pay_refunds (provider_code, provider_refund_id)
    WHERE provider_refund_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_refunds_attempt ON pay_refunds (attempt_id);
CREATE INDEX IF NOT EXISTS idx_pay_refunds_company ON pay_refunds (cmp_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_refunds_pending ON pay_refunds (cmp_id, requested_at) WHERE status IN ('REQUESTED', 'APPROVED', 'PROCESSING');

-- --------------------------------------------------------------------------
-- Disputes and chargebacks
--
-- Consumed from provider events where the provider has an API for them, and
-- recorded by hand where it does not. Provider-neutral on purpose: the reason
-- codes are the card networks', not ours, and normalising them into our own
-- vocabulary would lose the exact code the merchant has to quote when they
-- respond.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_disputes (
    dispute_id          BIGSERIAL PRIMARY KEY,
    dispute_uuid        TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    payer_id            BIGINT       REFERENCES pay_payers(payer_id) ON DELETE SET NULL,

    provider_code       TEXT,
    provider_dispute_id TEXT,

    amount_minor        BIGINT       NOT NULL DEFAULT 0,
    currency            TEXT         NOT NULL DEFAULT 'INR',
    -- CHARGEBACK | RETRIEVAL | PRE_ARBITRATION | FRAUD
    dispute_kind        TEXT         NOT NULL DEFAULT 'CHARGEBACK',
    reason_code         TEXT,
    reason_description  TEXT,

    -- OPEN | UNDER_REVIEW | EVIDENCE_SUBMITTED | WON | LOST | CLOSED
    status              TEXT         NOT NULL DEFAULT 'OPEN',
    -- The deadline the network gave. Missing it loses the dispute by default,
    -- which is why it is indexed and shown on the Action Centre.
    evidence_due_at     TIMESTAMPTZ,
    evidence_submitted_at TIMESTAMPTZ,
    -- [{kind, provider_document_id, note, uploaded_at}] — references only.
    evidence            JSONB        NOT NULL DEFAULT '[]'::jsonb,
    notes               TEXT,

    opened_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    closed_at           TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_dispute_provider
    ON pay_disputes (provider_code, provider_dispute_id)
    WHERE provider_dispute_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_disputes_company  ON pay_disputes (cmp_id, status, opened_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_disputes_deadline ON pay_disputes (evidence_due_at) WHERE status IN ('OPEN', 'UNDER_REVIEW');

-- --------------------------------------------------------------------------
-- Mandates — a standing authority to collect
--
-- PAY OWNS THE MANDATE. Billing owns the SUBSCRIPTION. The difference is not
-- pedantry: the plan, its price, its cycle, when the next invoice is raised and
-- what happens on renewal are commercial decisions that live in Billing. What
-- lives here is the payer's authority to be debited, the provider's token for
-- it, and whether the last debit worked.
--
-- There is deliberately no plan_id, no billing_cycle and no next_invoice_date
-- column. A Pay mandate against a Billing subscription carries source_app and
-- source_id, and Billing is asked when a screen needs the plan.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_mandates (
    mandate_id          BIGSERIAL PRIMARY KEY,
    mandate_uuid        TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    bo_id               BIGINT       NOT NULL DEFAULT 0,

    payer_id            BIGINT       REFERENCES pay_payers(payer_id) ON DELETE SET NULL,
    source_app          TEXT,
    source_type         TEXT,
    source_id           TEXT,
    source_reference    TEXT,

    connection_id       BIGINT       REFERENCES pay_provider_connections(connection_id) ON DELETE SET NULL,
    provider_code       TEXT,
    provider_mandate_id TEXT,
    provider_token_ref  TEXT,

    -- UPI_AUTOPAY | ENACH | CARD_ON_FILE | OTHER
    mandate_type        TEXT         NOT NULL DEFAULT 'UPI_AUTOPAY',
    -- PENDING | ACTIVE | PAUSED | CANCELLED | EXPIRED | FAILED
    status              TEXT         NOT NULL DEFAULT 'PENDING',

    -- The ceiling the payer authorised, not a price. What is actually charged
    -- each time comes from the app that raised the debit.
    max_amount_minor    BIGINT,
    currency            TEXT         NOT NULL DEFAULT 'INR',
    -- As the provider expresses it: MONTHLY, WEEKLY, AS_PRESENTED…
    frequency           TEXT,
    valid_from          DATE,
    valid_until         DATE,

    last_debit_at       TIMESTAMPTZ,
    last_debit_status   TEXT,
    consecutive_failures INTEGER     NOT NULL DEFAULT 0,

    created_by          TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    cancelled_at        TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_mandate_provider
    ON pay_mandates (provider_code, provider_mandate_id)
    WHERE provider_mandate_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_mandates_company ON pay_mandates (cmp_id, status);
CREATE INDEX IF NOT EXISTS idx_pay_mandates_payer   ON pay_mandates (cmp_id, payer_id);

-- --------------------------------------------------------------------------
-- Settlements — a provider's batch, and whether it reached the bank
--
-- NO TAX RATE IS HARD-CODED HERE, and none should ever be. `provider_fee_minor`
-- and `provider_tax_minor` are what the PROVIDER reported on their own
-- settlement record. Computing 18% of the fee ourselves and calling it GST
-- would produce a figure that disagrees with the provider's by a rupee on most
-- batches, and reconciliation would then flag every batch it ever saw.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_settlements (
    settlement_id       BIGSERIAL PRIMARY KEY,
    settlement_uuid     TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    connection_id       BIGINT       REFERENCES pay_provider_connections(connection_id) ON DELETE SET NULL,
    provider_code       TEXT         NOT NULL,
    provider_mode       TEXT         NOT NULL DEFAULT 'DIRECT',
    -- The provider's own batch id. Unique per provider, which is what makes
    -- importing the same statement twice a no-op.
    provider_settlement_id TEXT,

    settlement_date     DATE,
    -- EXPECTED | PROCESSING | SETTLED | PARTIALLY_SETTLED | DELAYED | FAILED | RECONCILIATION_REQUIRED
    status              TEXT         NOT NULL DEFAULT 'EXPECTED',

    currency            TEXT         NOT NULL DEFAULT 'INR',
    gross_minor         BIGINT       NOT NULL DEFAULT 0,
    refund_minor        BIGINT       NOT NULL DEFAULT 0,
    provider_fee_minor  BIGINT       NOT NULL DEFAULT 0,
    provider_tax_minor  BIGINT       NOT NULL DEFAULT 0,
    adjustment_minor    BIGINT       NOT NULL DEFAULT 0,
    -- What the provider says they will send.
    expected_net_minor  BIGINT       NOT NULL DEFAULT 0,
    -- What the bank actually credited, once somebody confirms it.
    settled_net_minor   BIGINT,
    -- settled_net - expected_net. Non-zero opens a reconciliation case.
    difference_minor    BIGINT       NOT NULL DEFAULT 0,

    -- The bank side, as far as Pay is concerned: did it arrive, and under what
    -- reference. Not a bank ledger and not a bank statement importer.
    bank_reference      TEXT,
    bank_credited_at    TIMESTAMPTZ,
    bank_account_last4  TEXT,
    -- MANUAL | PROVIDER_API | STATEMENT_UPLOAD
    bank_matched_by     TEXT,
    bank_matched_at     TIMESTAMPTZ,

    transaction_count   INTEGER      NOT NULL DEFAULT 0,
    expected_at         TIMESTAMPTZ,
    imported_at         TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_settlement_provider
    ON pay_settlements (cmp_id, provider_code, provider_settlement_id)
    WHERE provider_settlement_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pay_settlements_company ON pay_settlements (cmp_id, settlement_date DESC);
CREATE INDEX IF NOT EXISTS idx_pay_settlements_status  ON pay_settlements (cmp_id, status);

-- One line per payment or refund inside a batch. This is what makes
-- "which of my payments is in this ₹2,99,350?" answerable.
CREATE TABLE IF NOT EXISTS pay_settlement_items (
    item_id             BIGSERIAL PRIMARY KEY,
    cmp_id              BIGINT       NOT NULL,
    settlement_id       BIGINT       NOT NULL REFERENCES pay_settlements(settlement_id) ON DELETE CASCADE,

    -- Exactly one of these two is set.
    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    refund_id           BIGINT       REFERENCES pay_refunds(refund_id) ON DELETE SET NULL,

    -- PAYMENT | REFUND | FEE | TAX | ADJUSTMENT | CHARGEBACK
    item_kind           TEXT         NOT NULL DEFAULT 'PAYMENT',
    provider_payment_id TEXT,
    gross_minor         BIGINT       NOT NULL DEFAULT 0,
    fee_minor           BIGINT       NOT NULL DEFAULT 0,
    tax_minor           BIGINT       NOT NULL DEFAULT 0,
    net_minor           BIGINT       NOT NULL DEFAULT 0,

    -- MATCHED | UNMATCHED | AMOUNT_MISMATCH — how this line lines up with the
    -- attempt it claims to be.
    match_status        TEXT         NOT NULL DEFAULT 'MATCHED',
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pay_settlement_items_batch   ON pay_settlement_items (settlement_id);
CREATE INDEX IF NOT EXISTS idx_pay_settlement_items_attempt ON pay_settlement_items (attempt_id) WHERE attempt_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_pay_settlement_items_unmatched ON pay_settlement_items (cmp_id, match_status) WHERE match_status <> 'MATCHED';

-- --------------------------------------------------------------------------
-- Reconciliation cases — the exceptions, with somebody's name against them
--
-- A case is opened by the reconciler and closed by a human. It is not a log
-- line: it has an owner, a resolution and a note, because "7 unreconciled
-- transactions" that nobody can be assigned is a number that stays at 7.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_reconciliation_cases (
    case_id             BIGSERIAL PRIMARY KEY,
    case_uuid           TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,

    -- UNRECONCILED_TRANSACTION | SETTLEMENT_AMOUNT_MISMATCH | MISSING_BANK_SETTLEMENT
    -- | UNEXPECTED_PROVIDER_FEE | DELAYED_SETTLEMENT | REFUND_NOT_SETTLED
    -- | DUPLICATE_SETTLEMENT_ENTRY | PARTIAL_SETTLEMENT | DISPUTE_ADJUSTMENT
    -- | SOURCE_SYNC_FAILED | PROVIDER_STATE_CONFLICT | DUPLICATE_CALLBACK
    case_kind           TEXT         NOT NULL,
    -- LOW | MEDIUM | HIGH
    severity            TEXT         NOT NULL DEFAULT 'MEDIUM',
    -- OPEN | INVESTIGATING | RESOLVED | WRITTEN_OFF | IGNORED
    status              TEXT         NOT NULL DEFAULT 'OPEN',

    settlement_id       BIGINT       REFERENCES pay_settlements(settlement_id) ON DELETE SET NULL,
    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    refund_id           BIGINT       REFERENCES pay_refunds(refund_id) ON DELETE SET NULL,

    expected_minor      BIGINT,
    actual_minor        BIGINT,
    difference_minor    BIGINT,
    currency            TEXT         NOT NULL DEFAULT 'INR',

    -- One sentence a human can act on, written when the case is opened.
    summary             TEXT         NOT NULL,
    detail              JSONB        NOT NULL DEFAULT '{}'::jsonb,

    assigned_to         TEXT,
    resolved_by         TEXT,
    resolved_at         TIMESTAMPTZ,
    resolution_note     TEXT,

    opened_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- One open case per (kind, subject). The reconciler runs repeatedly and must
-- not open the same case every time it runs.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_recon_open_settlement
    ON pay_reconciliation_cases (cmp_id, case_kind, settlement_id)
    WHERE settlement_id IS NOT NULL AND status IN ('OPEN', 'INVESTIGATING');
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_recon_open_attempt
    ON pay_reconciliation_cases (cmp_id, case_kind, attempt_id)
    WHERE attempt_id IS NOT NULL AND status IN ('OPEN', 'INVESTIGATING');

CREATE INDEX IF NOT EXISTS idx_pay_recon_company ON pay_reconciliation_cases (cmp_id, status, severity, opened_at DESC);

-- --------------------------------------------------------------------------
-- External payments — money collected outside Pay
--
-- Cash at the counter, a NEFT straight to the bank, a cheque, a UPI transfer
-- made person-to-person. It is real money against a real request and the
-- request must reflect it, but NO PROVIDER CAN CORROBORATE IT — which is
-- exactly why this is its own table with its own evidence columns rather than
-- a payment attempt with provider_code = 'CASH'.
--
-- There is deliberately no "mark as collected" shortcut in this product. Every
-- row here names a method, a date, a reference and a person, because a payment
-- nobody can trace is the one a merchant most needs to be able to trace later.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_external_payments (
    external_id         BIGSERIAL PRIMARY KEY,
    external_uuid       TEXT         NOT NULL UNIQUE,
    cmp_id              BIGINT       NOT NULL,
    bo_id               BIGINT       NOT NULL DEFAULT 0,

    request_id          BIGINT       REFERENCES pay_payment_requests(request_id) ON DELETE SET NULL,
    -- The attempt row this created, so the request's totals include it and the
    -- payments list shows it beside everything else.
    attempt_id          BIGINT       REFERENCES pay_payment_attempts(attempt_id) ON DELETE SET NULL,
    payer_id            BIGINT       REFERENCES pay_payers(payer_id) ON DELETE SET NULL,

    -- Set when the payment is against a source document but no Pay request was
    -- ever raised: money arrived for an invoice nobody had asked for through Pay.
    source_app          TEXT,
    source_type         TEXT,
    source_id           TEXT,
    source_reference    TEXT,

    amount_minor        BIGINT       NOT NULL CHECK (amount_minor > 0),
    currency            TEXT         NOT NULL DEFAULT 'INR',
    -- CASH | BANK_TRANSFER | CHEQUE | UPI_MANUAL | CARD_MACHINE | OTHER
    method              TEXT         NOT NULL,
    -- UTR, cheque number, terminal reference. Required — see the class comment.
    external_reference  TEXT         NOT NULL,
    received_at         DATE         NOT NULL,
    note                TEXT,
    -- A reference to an attachment held elsewhere, not the file.
    attachment_ref      TEXT,

    source_sync_status  TEXT         NOT NULL DEFAULT 'NOT_APPLICABLE',
    source_synced_at    TIMESTAMPTZ,

    -- ACTIVE | REVERSED. A cheque that bounced is reversed, never deleted.
    status              TEXT         NOT NULL DEFAULT 'ACTIVE',
    reversed_by         TEXT,
    reversed_at         TIMESTAMPTZ,
    reversal_reason     TEXT,

    recorded_by         TEXT         NOT NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- The same UTR against the same request twice is a double entry, not a second
-- payment. Reversed rows are excluded so a corrected re-entry is possible.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pay_external_reference
    ON pay_external_payments (cmp_id, method, external_reference, amount_minor)
    WHERE status = 'ACTIVE';

CREATE INDEX IF NOT EXISTS idx_pay_external_company ON pay_external_payments (cmp_id, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_external_request ON pay_external_payments (request_id);
