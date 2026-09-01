# AICOUNTLY auth workflow (Pay)

How Pay signs a user in. This is the shared AICOUNTLY SaaS flow — the same
one Smart Books and the other products use — reduced to what a blank app needs.
The canonical implementation lives on **my.aicountly.com**; nothing here mints,
signs or stores a credential of its own.

## Tokens

| Token | Lifetime | Storage | Use |
|-------|----------|---------|-----|
| `auth_token` | Long-lived | `localStorage` + a `.aicountly.com` cookie | Mint / refresh a `ses_key` |
| `ses_key` | ~15 minutes | **Memory only** | `Authorization: Bearer` on product APIs |

`ses_key` must **never** be written to `localStorage` or `sessionStorage`. In
this app it lives in a module variable in `web/src/auth/tokens.ts` and dies with
the page.

The `auth_token` cookie is scoped to `.aicountly.com` on purpose:
`localStorage` is origin-scoped, so without the cookie a user arriving from
another AICOUNTLY product would have to sign in again.

## Login flow

1. User opens `pay.aicountly.com` (or `pay.gh.aicountly.com`).
2. No `auth_token` → redirect to
   `{portal}/login/authentication_jump/pay?returnUrl={origin}/auth/callback`.
   The portal reuses an existing portal web session — this is what makes moving
   between AICOUNTLY products seamless. With no session it shows its login form.
3. Portal redirects back to `/auth/callback?auth_token=…`. The SPA history
   fallback in `web/public/.htaccess` serves the app at that path; there is no
   router, so `AuthProvider` reads the token at boot and clears it from the URL.
4. App stores `auth_token`, then `POST /api/global/seskey` with
   `Bearer auth_token` → `ses_key`.
5. Dashboard.

Logout clears both tokens and the shared cookie, tells the portal to invalidate
the `auth_token`, and navigates to `{portal}/login/logout` so the portal's own
session cookie goes too. Skipping that last step leaves the portal session
alive and the next visit signs the user straight back in.

## Host mapping

| Pay host | Login redirect | Auth API | Product API |
|---|---|---|---|
| `pay.aicountly.com` | `my.aicountly.com` | `my.aicountly.com` | `pay.aicountly.com/api` |
| `pay.gh.aicountly.com` | `sandbox.aicountly.com` | `my.aicountly.com` | `pay.gh.aicountly.com/api` |

**`sandbox.aicountly.com` is for the login redirect only.** `seskey`,
`seskey/refresh` and `validatesession` always answer on `my.aicountly.com`, in
sandbox as well as production. Pointing a sandbox build at
`sandbox.aicountly.com` for those calls is the usual way to break sandbox
sign-in.

One build serves both environments: `resolveProductKeyFromHost()` reads
`pay` out of either hostname, and `isSandboxHost()` picks the portal.

## Why the calls go through this product's own API

The browser calls `/api/global/seskey` on its **own origin**, and `server-php`
relays that to `my.aicountly.com` server-to-server.

A brand-new product domain is not in the portal's CORS allowlist on day one, so
a direct browser call would fail with nothing but a CORS message to show for it.
The relay sidesteps that entirely. The app still falls back to calling the
portal directly if the relay is missing — useful before the API is deployed.

The relay is an **allowlist** (`RELAYED_PATHS` in `server-php/index.php`):
`seskey`, `seskey/refresh`, `refresh_authtoken`. Forwarding arbitrary paths
would turn this host into an open proxy for the portal's whole auth surface,
with the portal seeing this server's IP instead of the caller's.

## This product's backend

- Does **not** issue `auth_token` or `ses_key`.
- Does **not** implement `/api/seskey`, `/api/seskey/refresh` or `/api/logout` —
  it only relays the first two.
- Validates a caller by `POST https://my.aicountly.com/api/validatesession` with
  the Bearer `ses_key`; `status: 1` means the session is live. A transport
  failure counts as *not* authenticated, so a portal outage denies access rather
  than granting it.

`GET /api/session` is the one protected endpoint, and exists so the flow can be
verified end to end in each environment.

## Verifying an environment

```bash
# 1. The API is up and says which environment it is
curl https://pay.gh.aicountly.com/api/health

# 2. The relay reaches the portal (401 without a token is the correct answer —
#    a 404 means the API is not deployed, a 504 means it cannot reach the portal)
curl -i -X POST https://pay.gh.aicountly.com/api/global/seskey

# 3. Unrelayed paths are refused
curl -i -X POST https://pay.gh.aicountly.com/api/global/login   # expect 404
```

In the browser: open the site, expect a jump to the portal, sign in, expect to
land back on the dashboard. Then press **Log out** and confirm that reopening
the site does **not** sign you straight back in.
