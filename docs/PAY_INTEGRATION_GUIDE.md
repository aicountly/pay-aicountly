# Integrating with Aicountly Pay

Two audiences, one contract.

- **A sibling AICOUNTLY product** — Books, Billing, Sales, POS, and whatever
  comes next — that wants Pay to collect money against one of its documents.
- **A third party** using Pay's public API with an API key.

Both create a payment request, both receive the same signed events. The only
difference is how they authenticate.

Everything here is **live HTTP**. There is no database link between Pay and any
other product, and there will not be one. See
[PAY_ARCHITECTURE.md](PAY_ARCHITECTURE.md) §1.

---

## 1. Authenticating

| Caller | Header | Scope comes from |
|---|---|---|
| Signed-in user | `Authorization: Bearer <ses_key>` | `?cmp_id=` + Manage |
| Sibling product | `X-Service-Key: <key>` + `X-Actor-Uuid: <user>` | `?cmp_id=` |
| API client | `Authorization: Bearer <api key>` | The key itself |

A **service key** is issued by Pay to a product and listed in Pay's
`SERVICE_KEYS` as `app:key` pairs. Give each product its own so a leak can be
traced and rotated on its own. A service call still carries `X-Actor-Uuid` — the
person who pressed the button in the calling app — because the audit trail
should name a human, not a machine.

An **API key's company is the key**. `Context::forApiClient()` reads the company
off the key and discards whatever the request asked for, so a stolen key cannot
be pointed at another company.

Every product calling a sibling also sends `X-Saas-Origin: <own product key>`.
That is a re-entry guard: without it, two products calling each other inside one
request can exhaust a PHP-FPM pool and deadlock the pair.

---

## 2. Asking Pay to collect

```http
POST /api/v1/payment-requests?cmp_id=55&bo_id=0
X-Service-Key: <books key>
X-Actor-Uuid: 9f2c…
Idempotency-Key: books-invoice-90011-v1
Content-Type: application/json

{
  "source_app":   "BOOKS",
  "source_type":  "SALES_INVOICE",
  "source_id":    "90011",
  "source_fy_id": 7,
  "reference":    "INV-2026-1011",
  "description":  "Invoice INV-2026-1011",
  "amount":       1180.00,
  "currency":     "INR",

  "payer_name":   "UrbanNest Pvt Ltd",
  "payer_email":  "accounts@urbannest.example",
  "payer_mobile": "9876543210",
  "customer_ref": "501",

  "allow_partial_payment": true,
  "min_partial_amount":    100.00,
  "allowed_methods":       ["UPI", "CARD", "NETBANKING"],
  "expires_at":            "2026-09-24T18:30:00+05:30",
  "callback_reference":    "books-90011",
  "return_url":            "https://books.aicountly.com/invoices/90011"
}
```

```json
{
  "data": {
    "payment_request_id": "PAYREQ-TLHO1G-527DD95B19FEA782",
    "status": "ACTIVE",
    "amount": 1180.00,
    "outstanding": 1180.00,
    "currency": "INR",
    "expires_at": "2026-09-24T18:30:00+05:30"
  }
}
```

### The fields that matter

| Field | Why |
|---|---|
| `source_app` / `source_type` / `source_id` | The **reference**, not a copy. Pay stores these three and asks you about the document when it needs detail. An API client cannot set `source_app` — it is taken from the registration, never from the body. |
| `source_fy_id` | Carried as context and handed back on every event, because *your* ledger cares about the year. Pay does not, and never filters on it. |
| `reference` | What the payer will recognise on the checkout page. |
| `amount` | Major units, as a number or a numeric string. Pay stores minor units. |
| `customer_ref` | Your customer's id in your system. Pay keeps the id and the contact details it needs to send a link — nothing else. No GSTIN, no credit limit, no addresses. |
| `callback_reference` | Anything you want echoed back on every event. Usually your own job id. |
| `return_url` | Where the payer goes after paying. Refused if it is not on an allowed host — an open redirect on a payment page is a phishing kit. |

### Idempotency

`Idempotency-Key` is required on every mutating call and stored with its
response. Replaying a key returns the **first** answer rather than creating a
second request, so a timeout on your side is safe to retry. Make the key
deterministic from your own document — `books-invoice-90011-v1` — not random.

---

## 3. Giving the payer a way to pay

```http
POST /api/v1/payment-requests/PAYREQ-…-527DD95B19FEA782/link?cmp_id=55
Idempotency-Key: books-invoice-90011-link-v1
```

```json
{
  "data": {
    "link_id": "LNK-TLHO2W-69C8B123CA8E9CFD",
    "url": "https://pay.aicountly.com/pay/p/05KGcfMaXYKvR-o4CYyYDSuH6g59Fzsz",
    "status": "ACTIVE",
    "expires_at": "2026-09-24T18:30:00+05:30"
  }
}
```

`/qr` is the same call with a UPI QR payload attached.

The token in that URL **is the payer's credential** and the only one they will
ever have. It is 32 CSPRNG bytes, it expires with the request, and the checkout
page is reachable without any AICOUNTLY account. Do not lowercase it, do not
trim it, do not log it beside a customer name.

---

## 4. Hearing what happened

Pay POSTs a signed event to the source app that raised the request, and to any
merchant webhook endpoints registered on the Developers screen.

```http
POST https://books.aicountly.com/api/v1/pay/events
Content-Type: application/json
X-Pay-Signature: <hex hmac-sha256 of the raw body>
X-Pay-Event-Id: EVT-TLHO3P-1C9A4F…
User-Agent: AicountlyPay/1.0
```

```json
{
  "event": "PAYMENT_SUCCESS",
  "event_uuid": "EVT-TLHO3P-1C9A4F…",
  "cmp_id": 55,
  "bo_id": 0,

  "source_app": "BOOKS",
  "source_type": "SALES_INVOICE",
  "source_id": "90011",
  "source_reference": "INV-2026-1011",
  "source_fy_id": 7,
  "callback_reference": "books-90011",

  "request_uuid": "PAYREQ-…-527DD95B19FEA782",
  "attempt_uuid": "PAY-…-D35997C5188AA5E4",
  "refund_uuid": null,

  "amount": 1180.00,
  "amount_minor": 118000,
  "currency": "INR",

  "provider": "RAZORPAY",
  "provider_mode": "DIRECT",
  "method": "UPI",
  "paid_at": "2026-09-17T14:22:09+05:30"
}
```

### Events

| Group | Names |
|---|---|
| Request | `PAYMENT_REQUEST_CREATED`, `PAYMENT_REQUEST_CANCELLED`, `PAYMENT_REQUEST_EXPIRED` |
| Payment | `PAYMENT_INITIATED`, `PAYMENT_PENDING`, `PAYMENT_SUCCESS`, `PAYMENT_FAILED`, `PARTIAL_PAYMENT_RECEIVED` |
| External | `EXTERNAL_PAYMENT_RECORDED`, `EXTERNAL_PAYMENT_REVERSED` |
| Refund | `REFUND_REQUESTED`, `REFUND_PROCESSING`, `REFUND_SUCCESS`, `REFUND_FAILED` |
| Dispute | `DISPUTE_CREATED`, `DISPUTE_UPDATED` |
| Settlement | `SETTLEMENT_EXPECTED`, `SETTLEMENT_RECEIVED`, `SETTLEMENT_MISMATCH` |
| Mandate | `MANDATE_CREATED`, `MANDATE_ACTIVE`, `MANDATE_PAUSED`, `MANDATE_CANCELLED`, `MANDATE_FAILED` |
| Provider | `PROVIDER_DEGRADED`, `PROVIDER_RECOVERED` |

Some events are **merchant-only** — settlements, disputes, mandates and refund
progress go to registered webhook endpoints but not to the source app, because
the source app asked for money and does not need the operational detail.

### What a receiver must do

**1. Verify the signature** over the **raw body**, before parsing:

```php
$raw = file_get_contents('php://input');
$expected = hash_hmac('sha256', $raw, $sharedSecret);

if (!hash_equals($expected, $_SERVER['HTTP_X_PAY_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

Parse-then-verify lets a re-encoded body pass a check the original would fail.

**2. Deduplicate on `X-Pay-Event-Id`.** Pay retries, and a retry after your
successful-but-slow response is normal. The id is stable across every retry of
the same event. A receipt posted twice is a customer asking why their invoice
shows a credit balance.

**3. Answer 2xx quickly, then do the work.** Pay's delivery timeout is 15
seconds. Post the receipt in a job, not in the request.

**4. Mean what you say with a 4xx.** A 4xx is read as a permanent refusal: Pay
stops retrying and opens a `SOURCE_SYNC_FAILED` reconciliation case for a human.
Return 5xx if you want it tried again.

### Retries

Exponential backoff, capped. `Outbox::claimNext()` takes rows with
`FOR UPDATE SKIP LOCKED`, so running several workers is safe. Delivery history
is on the Developers screen, and a single message can be retried by hand from
there.

---

## 5. Reading the money back

```http
GET /api/v1/payments?cmp_id=55&source_app=BOOKS&status=SUCCESS&from=2026-09-01&to=2026-09-30
GET /api/v1/payments/PAY-…-D35997C5188AA5E4?cmp_id=55
GET /api/v1/payment-requests/PAYREQ-…?cmp_id=55
```

`GET /v1/payment-requests/{id}` includes every attempt, every link, and — read
live, for this one screen only — the source document behind it. Never on a list:
a list of 50 requests must not become 50 calls to Books.

---

## 6. Refunds

```http
POST /api/v1/refunds?cmp_id=55
Idempotency-Key: books-refund-90011-v1

{ "payment_id": "PAY-…", "amount": 500.00, "reason": "Short shipped" }
```

A refund is `REQUESTED` and goes no further until somebody **else** approves it.
`CONSTRAINT pay_refund_four_eyes` makes that a database rule, not a code path,
so no future endpoint can bypass it. A company can set an approval threshold in
Settings; below it, approval is automatic and still recorded.

---

## 7. Money that did not come through Pay

A customer who paid by NEFT is recorded, never marked:

```http
POST /api/v1/external-payments?cmp_id=55
Idempotency-Key: books-external-90011-v1

{
  "request_id": "PAYREQ-…",
  "amount": 1180.00,
  "method": "NEFT",
  "external_reference": "UTR20260917001",
  "received_at": "2026-09-17T11:04:00+05:30",
  "note": "Confirmed against the bank statement"
}
```

The reference is **method-specific and required**: a UTR for NEFT/RTGS/IMPS, a
cheque number for a cheque, a UPI reference for UPI. There is no bare "Mark as
Collected" anywhere in this product, because a collection with no evidence is
the line item nobody can explain three months later.

External payments are `settlement_status = NOT_APPLICABLE` — the money went
straight to the bank and no provider is going to settle it — and are reversible
with a reason.

---

## 8. Adding a new source app

Pay is built so that a new AICOUNTLY product becomes a first-class source
without touching the payment engine. Everything below is additive.

**1. Write the adapter.** `src/Payments/Sources/InventoryAdapter.php`:

```php
final class InventoryAdapter extends AbstractSourceAdapter
{
    public function app(): string         { return 'INVENTORY'; }
    public function displayName(): string { return 'Inventory'; }

    protected function baseEnvKey(): string { return 'INVENTORY_API_BASE'; }

    /** The document types this app can ask Pay to collect against. */
    public function sourceTypes(): array  { return ['DELIVERY_CHALLAN']; }

    /** Live read for the request detail screen. Never called from a list. */
    public function fetchDocument(Context $ctx, Auth $auth, string $type, string $id): ?array
    {
        return $this->client($auth)->get("v1/challans/{$id}", $ctx->asQuery());
    }
}
```

`AbstractSourceAdapter` derives the availability flag (`INVENTORY_ENABLED`), the
service key (`INVENTORY_SERVICE_KEY`) and the outbound client from `app()`; the
base URL env key is named explicitly so a product whose env variable predates
this convention can keep it.

**2. Register it.** One line in `SourceRegistry::ADAPTERS`.

**3. Configure it.** In `server-php/.env`:

```
INVENTORY_ENABLED=1
INVENTORY_API_BASE=https://inventory.aicountly.com
INVENTORY_SERVICE_KEY=<key Inventory issued to Pay>
```

**4. Expose the receiver.** Inventory adds `POST /api/v1/pay/events` and does
the four things in §4.

That is the whole integration. No migration, no change to any controller, no
change to any provider, no change to the dashboards — they group by `source_app`
and pick the new one up on its first payment.

An app that has not written an adapter yet can still raise payment requests
today: `source_app` is a string, and `SourceRegistry` degrades to a generic
adapter that carries the reference without the live document read.

---

## 9. Setting up a provider

### DIRECT — the merchant's own gateway

On **Gateways → Connect provider**, the merchant enters their own credentials.
They are encrypted with AES-256-GCM before they touch the database and are never
returned to the browser again — the screen shows a mask and a "Test" button.

| Provider | Needs | Webhook URL to paste into their dashboard |
|---|---|---|
| Razorpay | Key ID, Key Secret, Webhook Secret | `https://pay.aicountly.com/api/v1/webhooks/razorpay?c=<connection-uuid>` |
| Cashfree | App ID, Secret Key, Webhook Secret | `…/api/v1/webhooks/cashfree?c=<connection-uuid>` |
| Stripe | Secret Key, Webhook Signing Secret | `…/api/v1/webhooks/stripe?c=<connection-uuid>` |
| PayU | Merchant Key, Salt | `…/api/v1/webhooks/payu?c=<connection-uuid>` |

The `?c=` is not decoration. A provider dashboard accepts a URL and nothing
else, so the connection has to be identifiable from one; without it a merchant
with two Razorpay accounts has two webhooks Pay cannot tell apart.

Pay refuses a test key in a connection marked live, and the reverse, where the
provider has a prefix convention to check (`rzp_test_`/`rzp_live_`,
`sk_test_`/`sk_live_`). Cashfree and PayU have none, so they are not checked — a
guess would refuse valid credentials.

### MANAGED — Aicountly's own arrangement

Not live. The code is complete and the partner agreement is not, so:

```
MANAGED_CASHFREE_ENABLED=0
PAY_MANAGED_CASHFREE_KEY_ID=CHANGE_ME
PAY_MANAGED_CASHFREE_KEY_SECRET=CHANGE_ME
```

With those unset, every managed call returns `partner_not_configured` and the UI
says *"Partner credentials not configured. Aicountly Managed cannot accept
applications until they are."* It does not queue applications it cannot submit
and it does not report a KYC status it has not been given.

To turn it on: obtain the partner credentials, set the three variables, flip the
flag. `ProviderRegistry::managedReadiness()` starts reporting available and the
onboarding screens light up.

### HYBRID

Connect both and add routing rules on **Gateways → Manage rules**. A rule is a
preference, never a permission — see PAY_ARCHITECTURE.md §6.

---

## 10. Running it locally

```bash
server-php/tests/devstack.sh
```

Brings up the migrations, the API on `:8795`, a stub playing the portal + Manage
+ the source apps on `:8794`, the built React app on `:5199`, and demo data. Any
`auth_token` signs you in; the stub mints a session for it. Nothing reaches
`my.aicountly.com` or any other product.

```bash
server-php/tests/run.sh              # the integration suite
server-php/tests/devstack.sh --smoke # the stack + a browser pass over every screen
```

Use the `MOCK` provider (`MOCK_PROVIDER_ENABLED=1`) to drive payments end to end
without a gateway account. It refuses to be constructed when `APP_ENV=production`
— the constructor throws, and the registry refuses to build it — so it cannot
follow you onto a live host.

---

## 11. Errors

```json
{
  "error": {
    "code": "below_minimum_partial",
    "message": "Part payments on this request must be at least ₹100.00.",
    "details": []
  },
  "message": "Part payments on this request must be at least ₹100.00."
}
```

`message` is written for the person who will read it. `code` is what you branch
on.

| Status | Means |
|---|---|
| 400 | The request is wrong. `code` says how. |
| 401 | No usable session or key. |
| 403 | Authenticated, not permitted, or the wrong company. |
| 404 | No such record **in this company**. |
| 409 | A state machine refused: already paid, cancelled, expired. |
| 422 | Understood and refused — a provider declined, a rule forbade it. |
| 429 | Rate limited. Back off. |
| 503 | Pay cannot reach something it needs. **Retry.** A tenant check that cannot reach Manage answers 503 rather than guessing. |

A 503 from the tenant check is not a bug report. It is the isolation rule
working: Pay would rather be briefly unavailable than briefly show one company's
payments to another.
