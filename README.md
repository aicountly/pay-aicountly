# Aicountly Pay

Payment collection and orchestration for the AICOUNTLY fleet. The other products
decide that money is owed; Pay is what collects it.

| Environment | App | API |
| --- | --- | --- |
| Production | https://pay.aicountly.com | https://pay.aicountly.com/api |
| Sandbox | https://pay.gh.aicountly.com | https://pay.gh.aicountly.com/api |

## What it does

```
Collect → Process → Route → Track → Refund → Settle → Reconcile → Analyse
```

- **Payment requests** raised by Books, Billing, Sales, POS — or in Pay itself —
  with partial payments, expiry, and a link or UPI QR for the payer.
- **A hosted checkout** a customer opens from a link, with no AICOUNTLY account
  and no sign-in.
- **Providers** behind one interface: Razorpay, Cashfree, Stripe, PayU. Each
  merchant connects their own account (DIRECT), uses Aicountly's partner
  arrangement (MANAGED), or both with routing rules between them (HYBRID).
- **Refunds** with maker-checker enforced by a database constraint, disputes,
  and recurring mandates.
- **Settlements and reconciliation** — what the provider says, what Pay recorded
  and what the bank credited, with the differences named and assigned.
- **Payments taken outside Pay** recorded with a UTR or a cheque number. There is
  no bare "Mark as Collected" button in this product.
- **Five dashboards**, five routes: Overview, Collections, Gateways,
  Settlements, Pay Pulse.
- **Pay Pulse** — payment intelligence computed from this company's own
  payments. It recommends; it does not act unless auto-optimisation is switched
  on, and it is off by default.
- **A developer API** with keys, webhooks and a delivery log.

## What it does not do

Pay owns the payment lifecycle and nothing else. It posts no journal entries,
closes no invoices, holds no customer master and has no opinion about a
financial year.

**No product reads another product's database.** There is no replication here,
no foreign data wrapper, no `dblink`, no shared schema and no sync job. Pay
stores a *reference* to another product's record — app, type, id — and asks that
product over HTTP when it needs the detail. The test suite enforces this: it
scans the source for second connections and reads `information_schema` after the
migrations to fail the build if a `pay_` table has grown into somebody else's
master.

See [docs/PAY_ARCHITECTURE.md](docs/PAY_ARCHITECTURE.md).

## Layout

```
web/          React 19 + Vite + TypeScript. Builds to web/dist, deployed to the document root.
server-php/   PHP 8.4 API, no framework and no composer. Deployed to api/ inside the document root.
docs/         architecture, integration, deployment and auth notes
```

| Document | For |
| --- | --- |
| [PAY_ARCHITECTURE.md](docs/PAY_ARCHITECTURE.md) | How it is built and why |
| [PAY_INTEGRATION_GUIDE.md](docs/PAY_INTEGRATION_GUIDE.md) | Wiring another app or a third party into it |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Getting it onto a host |
| [auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md) | Portal SSO |

## Running the whole thing locally

One command brings up the database, the API, a stub standing in for the portal
and every sibling product, the built app, and enough demo data for every screen
to say something:

```bash
server-php/tests/devstack.sh
```

```
app      http://127.0.0.1:5199
api      http://127.0.0.1:8795/health
```

Any `auth_token` signs you in — the stub mints a session for it. Nothing reaches
`my.aicountly.com` or any other product. Requires PHP 8.4 with `pdo_pgsql`, a
reachable PostgreSQL 16, and Node 22+.

Use the `MOCK` provider (`MOCK_PROVIDER_ENABLED=1`) to drive payments end to end
without a gateway account. It refuses to be constructed when `APP_ENV=production`.

### Tests

```bash
server-php/tests/run.sh               # integration suite against real PostgreSQL
server-php/tests/devstack.sh --smoke  # the stack, plus a browser pass over every screen
```

The integration suite covers the state machines, idempotency, partial payments,
refund maker-checker, webhook deduplication, routing, settlement matching, the
dashboards, permissions, and the no-database-synchronisation rules above.

The smoke pass opens every route in a real browser against the running stack and
fails on a console error, a 4xx, an empty page or a visible crash — the class of
bug that a type check and an API test both miss.

### The halves on their own

```bash
cd web && npm install && npm run dev      # Vite on http://localhost:5173
cd server-php && cp .env.example .env && php -S localhost:8000
```

`npm run dev` signs in through the sandbox portal, so point `VITE_API_BASE_URL`
at a running API and add `http://localhost:5173` to that API's
`CORS_ALLOWED_ORIGINS` — localhost is the one case where the app and the API are
not same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run preview` | Serve the production build locally |

The PHP API has no build step and no dependencies. Apply the migrations with
`php server-php/bin/migrate.php` — it is idempotent, and `/health` reports what
is applied and what is pending.

`php server-php/bin/worker.php` is everything that must happen without a user
waiting, and belongs on a cron: delivering the callbacks Pay owes, expiring
requests, pulling settlement batches, reconciling, recomputing Pay Pulse,
checking provider health, and sweeping spent idempotency keys. It takes a lock,
so a run that overruns its minute is normal rather than a pile-up.

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token or password in a `VITE_` variable.

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

The server side has more, and three of them have no working fallback:
`PAY_ENCRYPTION_KEY`, `PAY_HASH_PEPPER` and `PAY_CALLBACK_SIGNING_SECRET`, plus
the database credentials. `GET /api/health` reports `usable: false` when
something required is missing, which is how you tell a deploy that went green on
an app whose every real endpoint answers 503. Every variable is documented in
[server-php/.env.example](server-php/.env.example).

### These are build-time values, not runtime values

This matters for how you change an endpoint in production.

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of `server-php`, which is PHP and does read its own `.env`
on every request.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both
workflows trigger exclusively via `workflow_dispatch`.

To deploy: **Actions** → pick a workflow → **Run workflow** → pick a branch →
**Run**.

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | document root, then `api/` inside it |

Production and sandbox deploy separately, so releasing to one cannot disturb
the other. Within one environment, web and API deploy together in the same
run — they always change in step, so there is no separate "API only" workflow
to remember to run. Source, `node_modules`, and `.env` never reach the server.

Before deploying, each workflow checks that every required SSH secret is set and
that the remote root is a safe path, so a misconfigured repository fails in
seconds instead of part-way through a deploy.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

`*_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path works too.
Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

The repository **variables** `PROD_API_BASE_URL` and `SANDBOX_API_BASE_URL` are
optional. Unset, the app calls its own origin + `/api` — which is where the same
workflow puts the API. Set one only to point the app at a different API domain.

### Notes on the rsync steps

Each workflow runs two `rsync --delete` steps, one after the other, and the
excludes are what make that safe.

The **web** step syncs the document root and excludes:

- `api/` — the PHP backend lives inside the document root and is deployed by the
  next step in the same run. **Without this exclude the web step would delete
  the entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step syncs `api/` and excludes `.env`, `.env.*` and `.git*`: the
API's `.env` is created once on the server and read at runtime, so it must
survive every deploy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback — which is also what serves the portal's `/auth/callback` landing — plus
cache headers (`index.html` uncached, hashed assets cached for a year).
