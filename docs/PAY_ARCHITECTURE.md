# Aicountly Pay — architecture

Pay is the payment layer for the AICOUNTLY fleet. It collects money on behalf of
the other products, routes it through payment providers, tracks it until the
bank confirms it, and tells the originating app what happened.

It is deliberately small in scope. Pay owns the payment lifecycle:

```
Collect → Process → Route → Track → Refund → Settle → Reconcile → Analyse
```

and nothing else. It does not post journal entries, does not close invoices,
does not hold a customer master, and does not know what a financial year is.

---

## 1. The rule everything else follows from

**No product reads another product's database. Ever.**

There is no replication here, no foreign data wrapper, no `dblink`, no shared
schema, no nightly sync job, and no copy of anybody else's master data. When Pay
needs to know what invoice INV-2026-1011 is for, it asks Books over HTTP, at the
moment it needs to know, and does not keep the answer.

This is enforced, not merely intended:

- `Db` opens exactly one connection, to Pay's own database. The integration
  suite scans every file under `src/` for `new PDO(`, `dblink`, `postgres_fdw`,
  `CREATE SERVER` and `IMPORT FOREIGN SCHEMA`, and fails the build on a hit.
- A second test reads `information_schema` after the migrations run and fails if
  any `pay_` table has a name in the forbidden set — company, branch, financial
  year, customer, item, invoice, ledger, account, voucher, tax.
- A third checks that `pay_payers` carries a *reference* to a customer and never
  a copy: no `gstin`, no `pan`, no `credit_limit`, no addresses, no opening
  balance.

What Pay stores about another product's records is the smallest thing that can
find them again: `source_app`, `source_type`, `source_id`, and a human-readable
`reference` for the payer to recognise. Everything else is fetched live.

### What this costs, and why it is worth it

A live read can fail. Pay's answer is to fail honestly rather than to cache:

- The **tenant check** fails CLOSED. If Manage cannot be reached,
  `Context::assertAllowed()` answers 503, not "allowed". A tenant check that
  fails open is not a tenant check.
- A **source read** that fails degrades the screen, not the payment. The payment
  request already holds the amount and the reference; the extra detail Books
  would have added is simply absent, and the screen says so.
- A **source callback** that fails is retried with backoff from the outbox, and
  a permanent refusal opens a reconciliation case for a person. Money is never
  dropped because the other side had a bad minute.

---

## 2. Shape of the thing

```
pay.aicountly.com
├── /                      React 19 + Vite, TypeScript          web/
└── /api                   PHP 8.4, no framework, no composer   server-php/
                              │
                              ├── PostgreSQL 16 — Pay's own database, 28 tables
                              │
                              └── live HTTPS to:
                                   my.aicountly.com   auth + session
                                   manage.…           company access
                                   books/billing/     the apps that asked
                                   sales/pos.…        for the money
                                   provider APIs      Razorpay, Cashfree, …
```

`server-php/index.php` is the whole front door. It handles `/health`, the portal
auth relay at `/global/*` and `/session` itself, then hands everything else to
`Router` with the table in `Routes.php`. Authentication, company scope and the
tenant check happen once, there, rather than being remembered per endpoint.

### Layers

| Layer | Where | What it may do |
|---|---|---|
| Controllers | `src/Controllers/` | Parse input, check a permission, call a service, shape a response. No SQL, no provider calls. |
| Domain services | `src/Domain/` | The rules. Transactions, state machines, money. |
| Providers | `src/Payments/Providers/` | One folder per gateway, behind one interface. |
| Sources | `src/Payments/Sources/` | One adapter per originating app, behind one interface. |
| Clients | `src/Clients/` | Outbound HTTP to a sibling product. |
| Dashboards | `src/Dashboards/`, `src/PayPulse/` | Read-only aggregates. |

No controller names a provider. No provider knows what an invoice is. No source
adapter knows what Razorpay is.

---

## 3. Tenancy and scope

Every scoped request carries `cmp_id` and `bo_id` in the query string. Those are
a **claim**, not a fact. `Context::assertAllowed()` asks Manage whether this
session may open that company, memoises the answer for the request, and refuses
otherwise. Every Pay table has `cmp_id` and every query goes through
`Context::scope()`, which appends the predicate rather than trusting a caller to
remember it.

**Financial year is not a scope here**, unlike every other product in the fleet.
A payment happens when it happens; it does not belong to a year Pay has any say
over. `fy_id` is carried as *context* and passed through to the source app when
the caller supplied one. It is never a column on a `pay_` table and never
appears in a `WHERE` clause.

The company's *name* is read live from Manage on the request that needs it and
passed straight through to the screen (`Context::companyName()`). Pay has
nowhere to keep it and wants nowhere.

---

## 4. Money

Money is stored as `BIGINT` minor units — paise for INR — and never as a float.
Every provider's wire format is minor units too, so there is no conversion at
the edge where a rounding error would become a real one. `Money` is the only
thing that converts, and it converts at the presentation boundary.

A currency is carried on every row that holds an amount. Mixed-currency
arithmetic throws rather than guessing.

---

## 5. State machines

Six of them, all in `src/Domain/States.php`, all enforced by
`States::assertTransition()` rather than by convention.

### Payment request

```
DRAFT ──▶ ACTIVE ──┬──▶ PARTIALLY_PAID ──▶ PAID
                   ├──▶ PAID
                   ├──▶ EXPIRED
                   └──▶ CANCELLED
```

The request's status is **derived, never set**. `PaymentRequestService::
recalculate()` reads the attempts in the same transaction and computes the
status and the totals from them. The ordering matters and is deliberate:

1. `CANCELLED` and `DRAFT` are left alone — a cancelled request that happens to
   receive money is a reconciliation case, not a resurrection.
2. Fully covered wins over expired. Money that arrived one second before the
   deadline is money that arrived.
3. Expiry beats partly-paid, so a half-paid request that ran out of time reads
   as `EXPIRED` and stops accepting more.

### Payment attempt

```
CREATED ──┬──▶ INITIATED ──▶ PENDING ──┬──▶ AUTHORIZED ──▶ CAPTURED ──▶ SUCCESS
          │                            ├──▶ FAILED
          │                            └──▶ CANCELLED
          └──▶ (any money state, directly)
```

That last edge is not sloppiness. A provider-hosted link is paid without Pay
seeing anything in between: the first we hear is a webhook saying *captured*.
Refusing that jump would drop a real payment on the floor because our record was
one step behind.

Terminal states are terminal. A provider that later contradicts a settled
payment does not reverse it — `States::isTerminalConflict()` recognises the
disagreement and `ReconciliationService` opens a case for a person.

### The others

- **Refund** — `REQUESTED → APPROVED → PROCESSING → REFUNDED`, with `REJECTED`
  and `FAILED`. Maker-checker is a database constraint, not a code path:
  `CONSTRAINT pay_refund_four_eyes CHECK (approved_by IS NULL OR approved_by <> requested_by)`.
- **Settlement** — `EXPECTED → PROCESSING → SETTLED`, plus `PARTIALLY_SETTLED`,
  `DELAYED`, `FAILED` and `RECONCILIATION_REQUIRED`.
- **Mandate** — `PENDING → ACTIVE → PAUSED / CANCELLED / EXPIRED / FAILED`.
- **Dispute** — `OPEN → UNDER_REVIEW → EVIDENCE_SUBMITTED → WON / LOST / CLOSED`.

---

## 6. Providers

### The interface

```php
interface PaymentProviderInterface
{
    public function code(): string;
    public function supportedMethods(): array;
    public function createPayment(array $input): array;
    public function fetchPayment(string $providerPaymentId): array;
    public function refund(array $input): array;
    public function verifyWebhook(string $payload, array $headers): array;
    public function normalizeWebhook(array $event): array;
    public function testCredentials(): array;
}
```

`ManagedPaymentProviderInterface` extends it with the merchant-onboarding half:
`onboardMerchant()`, `submitKyc()`, `fetchOnboardingStatus()`, `linkedAccount()`.

Every provider returns the *same normalised shape*. A controller never learns
which gateway took the payment, and adding a fifth provider touches exactly one
folder plus one line of the registry.

### Shipped

| Code | Direct | Managed | Notes |
|---|---|---|---|
| `RAZORPAY` | yes | flag | Basic auth, paise native |
| `CASHFREE` | yes | flag | Implements the managed interface |
| `STRIPE` | yes | — | Form-encoded bodies, international cards |
| `PAYU` | yes | — | SHA-512 hash with the five empty udf pipes |
| `MOCK` | local only | local only | Constructor **throws** when `APP_ENV=production` |

### The three modes

- **DIRECT** — the merchant's own gateway account. Their credentials, their
  settlement, their fees. Pay stores the credentials encrypted and orchestrates.
- **MANAGED** — Aicountly's own partner arrangement. Merchants onboard through
  Pay and money settles through the partner. The partner credentials live in
  `api/.env`, never in the database, because they belong to the deployment and
  no company row should be able to reach them.
- **HYBRID** — both connected at once, with routing rules deciding which takes
  which payment.

**Managed is architected, not live.** Every managed method begins with
`requirePartner()`, and with no partner credentials configured it returns
`partner_not_configured` and the UI says *"Partner credentials not configured"*.
It never pretends. `ProviderRegistry::managedReadiness()` is what the Gateways
screen reads, and it distinguishes "the flag is on" from "this actually works".

### Routing

`RoutingEngine::choose()` is `eligible()` then `rank()`. A rule is a
**preference, never a permission**: a provider that is disabled, mis-keyed, or
cannot take the method is skipped whatever a rule says. Every decision is
recorded in `pay_routing_decisions` with the candidates that lost and why, so
"why did this go to Razorpay?" has an answer.

Failover only happens on a **retryable** failure. A declined card is not
retried against a second provider — that is how one decline becomes four and a
customer's bank locks the card.

---

## 7. How money actually gets in

### Starting a payment

`PaymentService::start()`:

1. Reads the outstanding amount **from the database**, never from the browser.
   A proposed amount is a request, not an instruction.
2. Checks the request is payable, with a specific error code per refusal
   (`request_cancelled`, `request_expired`, `already_paid`, `partial_not_allowed`,
   `below_minimum_partial`).
3. Writes the attempt row **before** calling the provider. A provider that takes
   money and then times out must not be a payment nobody has a record of.
4. Calls the chosen provider and records what came back.

### Hearing that it worked

Three things can tell Pay a payment succeeded — a webhook, a status poll, or the
browser coming back from a redirect — and all three go through one function,
`PaymentService::applyProviderState()`. There is exactly one place where a
payment becomes successful.

`locate()` finds the attempt by provider payment id, then provider order id,
then attempt uuid, then the request reference. If all four fail, it creates an
**orphan attempt** and a reconciliation case rather than discarding the message.
Money that arrived is never dropped because we could not match it.

### Webhooks

`WebhookIngest` stores → verifies → fingerprints → processes, in that order.
Storing first means a signature failure is still evidence. The fingerprint is
computed from the event's *identity*, and `uq_pay_webhook_fingerprint` is the
duplicate guard — a unique index, so two simultaneous redeliveries cannot both
win. If processing then fails, the fingerprint is released, so a later
redelivery is not dismissed as a duplicate of a message that was never handled.

The connection is resolved from `?c=<connection-uuid>` on the webhook URL, with
a header fallback. Provider dashboards accept a URL and nothing else, so the
connection identity has to fit in one.

### Telling the source app

`Outbox` holds **messages owed, not mirrored data**. A successful payment queues
an event in the same transaction that recorded the payment, and a worker claims
it with `FOR UPDATE SKIP LOCKED` and delivers it signed. Retries back off; a
permanent 4xx exhausts the message and opens a `SOURCE_SYNC_FAILED`
reconciliation case rather than retrying forever.

The receiving app deduplicates on the event id. The stub in
`tests/stub/router.php` does exactly that, and a test proves a redelivery
produces one receipt and not two.

---

## 8. Settlement and reconciliation

A provider settles a batch to the bank. Pay imports the batch, matches its items
to attempts, and tracks the difference between three numbers that are allowed to
disagree:

1. what the provider's settlement file says,
2. what Pay's own records say,
3. what the bank actually credited.

**Every fee figure is the provider's own.** Pay never calculates a rate of its
own — a fee Pay computed and a fee the provider charged would differ by rounding
on the first day and by a tariff change on the thirtieth.

Differences become `pay_reconciliation_cases` with a name on them. The Exception
Centre on the Settlements dashboard is that table.

### Payments taken outside Pay

A customer who paid by bank transfer is recorded through
`ExternalPaymentService`, which demands a method-specific reference — a UTR for
NEFT, a cheque number, a UPI reference. There is no bare "Mark as Collected"
button anywhere in this product. An external payment is marked
`settlement_status = NOT_APPLICABLE`, because money that came straight to the
bank is not awaiting a provider settlement, and it is reversible with a reason.

---

## 9. Pay Pulse

Pay Pulse is **this company's own payment behaviour, described**. It is not a
central brain, it does not see other merchants' data, and it never reads a
ledger.

Five capabilities: **Predict**, **Recover**, **Route**, **Detect**, **Optimise**.

Two hard rules:

- It **recommends and does not act**, unless auto-optimisation is explicitly
  switched on for that company. It is off by default and the screen says so.
- It **says when it does not know**. With too few payments it reports that it is
  warming up rather than inventing a forecast. Individual findings computed from
  the rows that do exist — two failures still worth chasing — are still shown,
  because those are arithmetic, not a model.

Every insight carries the evidence it was computed from and a confidence, both
displayed. An insight you cannot check is an insight you cannot trust.

---

## 10. Security

| Control | How |
|---|---|
| Provider credentials | AES-256-GCM envelope encryption, `v1.<keyid>.<nonce>.<tag>.<ciphertext>`. `PAY_ENCRYPTION_KEY_OLD` allows rotation without downtime. Ciphertext lives in `pay_provider_credentials`, a separate table, so listing providers never touches it. |
| Credentials to the browser | Never. Write-only fields; the API returns a mask like `rzp_live_••••4821` and a test result, never the secret. A test asserts no response shape can carry one. |
| API key secrets | **Hashed**, not encrypted — HMAC-SHA256 with `PAY_HASH_PEPPER`. The plaintext is shown once, at creation, and is then unrecoverable. |
| Card data | Not stored. No PAN, no CVV, no stripe data. Payments go to provider-hosted pages or provider SDKs. |
| Public link tokens | 32 bytes from the CSPRNG, URL-safe, unguessable, expiring. A test asserts the length and the alphabet. |
| Tenant isolation | `Context` above. Fails closed. |
| Idempotency | `Idempotency-Key` on every mutating call, stored in `pay_idempotency_keys` with the response, so a retry returns the first answer instead of taking the money twice. |
| Outbound signatures | HMAC over the body with `PAY_CALLBACK_SIGNING_SECRET`, plus the event id, so a source app can verify and deduplicate. |
| Open redirects | `PaymentRequestService::safeUrl()` refuses a return URL that is not on an allowed host. |
| Audit | `pay_audit_log` records who did what, with before/after state, and redacts anything secret-shaped on the way in. |
| Portal relay | `/api/global/*` forwards an **allowlist** of three paths. Forwarding arbitrary paths would make this host an open proxy for the portal's whole auth surface. |

---

## 11. Dashboards

Five screens, five routes, five endpoints. They are not tabs on one page and
they are not one query with a filter.

| Screen | Route | Question it answers |
|---|---|---|
| Overview | `/` | Is money coming in? |
| Collections | `/dashboards/collections` | Are requests turning into payments? |
| Gateways | `/dashboards/gateways` | Are the providers healthy, and who takes what? |
| Settlements | `/dashboards/settlements` | Has the money reached the bank, and does it add up? |
| Pay Pulse | `/dashboards/pay-pulse` | What should we do about it? |

`Dashboards/Period` resolves the window. Two details that were bugs first:

- The window is emitted as ISO-8601 **with offset** and ends at the period
  *boundary*, not at "now". Ending at now meant a payment captured in the same
  second as the page load fell outside its own window.
- The comparison window is truncated to the **same elapsed fraction** and
  stepped back by **calendar**, not by seconds. Subtracting 30 days from "last
  month" lands on the 2nd of August after a 30-day September.

A metric that cannot be computed renders as **"Unavailable"** with the reason.
It never renders as ₹0.00 — a zero that means "no data" and a zero that means
"no money" are different facts and a dashboard that conflates them is worse than
no dashboard.

---

## 12. The database

28 tables, four migrations, all prefixed `pay_`.

| Migration | Tables |
|---|---|
| `001_pay_core.sql` | settings, roles, role_assignments, payers, payment_requests, payment_attempts, payment_links, idempotency_keys, audit_log |
| `002_pay_providers.sql` | provider_connections, provider_credentials, routing_rules, routing_decisions, managed_onboarding |
| `003_pay_money_movement.sql` | refunds, disputes, mandates, settlements, settlement_items, reconciliation_cases, external_payments |
| `004_pay_events.sql` | webhook_events, outbound_events, api_clients, api_keys, webhook_endpoints, pulse_insights, provider_metrics |

The header of `001` documents what is deliberately **absent**: no company,
branch or financial-year master; no customer, item or invoice master; no ledger,
account or voucher; no tax master. Those belong to the products that own them.

Run them with `php server-php/bin/migrate.php`. It is idempotent and records
what it applied; `/health` reports `applied` and `pending`.

---

## 13. Testing

```bash
server-php/tests/run.sh          # 77 integration tests against real PostgreSQL
server-php/tests/devstack.sh --smoke   # the whole stack + a browser pass
```

The integration suite talks to a real PostgreSQL database and a real HTTP stub
standing in for the portal, Manage and the source apps. It covers the state
machines, idempotency, partial payments, refund maker-checker, webhook
deduplication, routing, settlement matching, the dashboards, permissions, and
the architecture rules in §1.

`devstack.sh` brings up the database, the API, the stub and the built React app
on three ports and opens every route in a real browser, failing on a console
error, a 4xx, an empty page or a visible crash. It is what catches the class of
bug a type check and an API test both miss.

---

## 14. Where the bodies are buried

Things that look wrong and are not, so nobody "fixes" them back:

- **`Path::normalise()` preserves case.** An earlier version lowercased the
  whole path to compare it against the relay allowlist, which also lowercased
  every id and public link token in it. Every by-id route answered 404 on a
  deployment while the test suite stayed green, because the tests called the
  router with paths they had built themselves. The lowercase belongs at each
  comparison, on literal segments only.
- **`index.php` requires `src/Autoload.php` explicitly.** It is the one file the
  autoloader cannot load. Without that line the file parses fine and fatals on
  the first class it names, which is every route below `/session`.
- **`AuthProvider` checks `isPublicLocation()` first.** It mounts above the
  router, so a guard in `App` alone runs after the portal redirect has already
  left — and the redirect rewrites the address bar, throwing away the only
  credential a paying customer has.
- **`Outbox` uses `jsonb_exists()`, not the `?` operator.** PDO reads a bare `?`
  as a positional placeholder and refuses to mix it with named ones, which
  silently breaks every merchant webhook lookup.
- **A failed attempt is `settlement_status = NOT_APPLICABLE`.** The column
  defaults to `PENDING`, which is right in flight and wrong afterwards: it put
  "Settlement: Pending" beside "Failed" on every screen showing both.
