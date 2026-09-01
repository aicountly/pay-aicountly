# pay-aicountly

Pay for Aicountly — a React single-page app built with Vite and TypeScript,
with a small PHP API alongside it. Both halves deploy to cPanel.

| Environment | App | API |
| --- | --- | --- |
| Production | https://pay.aicountly.com | https://pay.aicountly.com/api |
| Sandbox | https://pay.gh.aicountly.com | https://pay.gh.aicountly.com/api |

## What this app does today

Login → Dashboard. The dashboard shows a welcome message and a **Log out**
button, and nothing else. No navigation, no modules, no placeholder cards —
those arrive with the product.

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS: the app redirects to the portal, the portal returns an `auth_token`, and
the app exchanges it for a short-lived session key. A user who is already signed
in to another AICOUNTLY product lands straight on the dashboard.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Layout

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
docs/         deployment and auth notes
```

## Getting started

Requires Node.js 22 or newer.

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the **sandbox**
portal. Point `VITE_API_BASE_URL` at the deployed sandbox API
(`https://pay.gh.aicountly.com/api`) so the token exchange has somewhere to
go — and add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's
`api/.env`, since localhost is the one case where the app and API are not
same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run preview` | Serve the production build locally |

The PHP API has no build step and no dependencies. To run it locally:

```bash
cd server-php
cp .env.example .env      # set APP_ENV=local
php -S localhost:8000
```

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two of them, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

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
