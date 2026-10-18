# Narzan native identity — Messenger 1.2.1 release candidate

This implementation is a narrow member-device protocol using authorization codes
and PKCE, not a general-purpose OAuth/OIDC identity provider. It does not depend
on PocketWire entitlements, admin permissions or another module's service user.
The existing browser v1 routes, session and CSRF flow are retained.

## Site activation

Mobile access is **off by default**. No real site was upgraded or enabled by the
source change. Before activation on a named development site:

1. Back up the database and verify the existing encryption key is unchanged.
2. Upgrade the paired Messenger/ProcessMessenger modules to 121; this adds the
   `request_hash` column and four empty `messenger_mobile_*` tables. Existing
   message ciphertext/AAD are not rewritten. Permissions are not granted to roles.
3. Set `mobile_origin` to the operator-verified exact HTTPS origin, with optional
   port and no path. ProcessWire must correctly recognize HTTPS behind any trusted
   proxy. Native routes additionally require the web server HTTPS environment value
   to be on or 1. A TLS reverse proxy must set this through trusted server/FastCGI
   configuration and strip client forwarded headers; X-Forwarded-Proto alone is
   rejected even when ProcessWire infers config->https from it.
4. Set `mobile_redirect_uris` to exact registered Narzan callbacks, one per line.
   There is deliberately no invented production callback or bundle identifier.
   Only HTTPS or reverse-DNS private app schemes (for example org.example.app)
   are accepted. No wildcard, HTTP, fragment, user-info, script or data callbacks.
5. If needed, configure the site's **existing** relative `mobile_login_path` and
   its actual `mobile_login_return_param`. The connector does not implement or
   replace sign-in, registration, SSO or two-factor authentication. With no login
   integration, an anonymous consent page responds 401 and requires normal site
   sign-in before reopening authorization. Registration remains a site decision.
6. Exclude mobile and member API/consent routes from all shared caches, ensure
   the webserver forwards Authorization to PHP, verify HTTPS and exact host, then
   opt in with `mobile_enabled`. This is an explicit site-owner deployment step.

For ProcessWire in a subdirectory use the discovery path within that site's base
path. Root `/.well-known/narzan` is useful only when the server routes it to that
installation; do not assume every host has a root-level rewrite. All advertised
endpoint paths must resolve under the verified site origin. The native client
must reject cross-origin redirects/credential forwarding and check returned issuer.

## Discovery and browser authorization

- `GET /.well-known/narzan` or `GET /messenger-mobile/v1/discovery/` returns
  `protocol=narzan-v1`, `issuer`, opaque site ID and relative endpoints.
- Generate a fresh high-entropy verifier (43–128 RFC7636 unreserved characters),
  S256 challenge (43 base64url characters), and independent random state
  (22–128 base64url characters). Persist verifier/state only for this attempt.
- Open `/messenger-mobile/v1/authorize/` in the **system browser** with
  `redirect_uri`, `code_challenge`, `code_challenge_method=S256`, `state`,
  optional `device_name`. A GET never grants credentials.
- The logged-in ordinary member reviews and explicitly approves the device via
  CSRF-protected POST. The endpoint responds 303 to the exact callback with code,
  original state and issuer; a styled accessible link remains in the fallback body. Native browser sessions must match state/issuer
  before consuming the code. No client password or embedded shared client secret.
- `GET /messenger-mobile/v1/devices/` is a browser-session device management page;
  own-device revocation requires CSRF-protected POST. No bearer or cross-user
  management authority is accepted there.

## Native JSON endpoints

Native token requests carry no cookies and no Authorization header. POST bodies
are JSON objects at most 8192 bytes, with only the documented fields. No CORS
access is enabled. Operator-configured HTTPS/host checks are mandatory.

```text
POST /messenger-mobile/v1/token/
  {"code":"...","code_verifier":"...","redirect_uri":"..."}
POST /messenger-mobile/v1/refresh/
  {"refresh_token":"..."}
POST /messenger-mobile/v1/revoke/
  {"refresh_token":"..."}
```

Token response fields: `token_type=Bearer`, `access_token`, `refresh_token`,
`expires_in=600`, `refresh_expires_in=2592000`, `connection_id`, `issuer`,
`scope=messenger.member`. Authorization codes expire in 120 seconds and are
consumed once. Store device credentials in platform secure storage, never logs,
URLs or an ordinary message cache.

Refresh is serialized and single-use: it consumes the prior refresh token,
invalidates previous access tokens for the connection and issues a fresh pair.
**Lost refresh responses require reauthorization:** there is no replay grace
cache. Reusing a consumed refresh token revokes the whole connection, including
its newer tokens. The client must use one refresh flight per connection and
must not blindly retry an ambiguous refresh. A stolen refresh token can revoke
only its own connection through the possession-based revoke endpoint.

Every bearer use checks current user existence/recipient eligibility and current
restrictions. Device revocation applies immediately to access and refresh.
Disabling mobile access denies existing device credentials; it does not delete
or revoke them permanently. Reenablement can restore unexpired credentials, so
use device revocation when permanent removal is intended. Tokens are stored only
as installation-keyed hashes; the encryption key/tableSalt must not be casually
rotated. This is not a new key-rotation workflow.

## Member API and browser separation

Send `Authorization: Bearer <access_token>` to the existing member v1 routes.
Do not send cookies; mixed credentials are rejected. Unrelated upstream HTTP
authentication headers do not become native authority and keep the browser
session/CSRF path; only an explicit Bearer scheme selects native authentication. Identity is bound only for
that request and restored in `finally`; `forceLogin` and browser session mutation
are not used. Native member writes do not use browser CSRF. Browser writes
continue to require their session's original CSRF token, even with mobile on.
The token cannot authorize admin/moderation/broadcast operations or arbitrary PHP.

There are shared per-account native budgets of 180 reads/60 writes per minute,
plus the domain message/recipient limits. Auth requests have per-IP and site
budgets; approval is account-limited. HTTP429 includes Retry-After. All rate
identities are keyed before storage; no raw addresses are saved. Unknown or
invalid native credentials fail closed; cookie fallback is not available.

`GET sync` is a conservative full-cache invalidation signal, **not** incremental
change delivery. See API.md. No push service, Kotlin client, attachment support,
Files/Folio integration, or physical-device/store verification is included here.

## Validation and remaining gates

Run `bash tests/run.sh`. Tests use real domain/auth/controller code with fixture
ProcessWire services and disposable SQLite. HTTP tests exercise real PHP request
body/header handling on an owned localhost server that is always stopped. TLS is
represented by fixture configuration, not an actual HTTPS certificate handshake.

A bounded October 18 integration run used disposable ProcessWire 3.0.255,
MySQL 8.4.9 and PHP 8.5.8: fresh 121 installation, 110→121 upgrade preserving
ciphertext/key, retained-data reinstall, old-code rollback, actual local TLS,
browser session/CSRF and native PKCE/refresh/revoke flows, three concurrent code
exchange races and three refresh races, restrictions and real policy hooks.
Chrome verified the existing web UI loading, sending and editing messages.
The TLS client trusted only the task-local certificate; no system trust or live
site settings changed. A local TLS proxy forwarded Authorization and supplied a
server HTTPS indicator through a private bridge; direct HTTP with only a forged
X-Forwarded-Proto was rejected after the 1.2.1 correction.

Before production, still verify the consuming site's SSO/2FA/login return flow,
its TLS/reverse-proxy and cache configuration, its exact ProcessWire/PHP versions,
and physical iOS/Android callbacks. The local proxy is not evidence about a
production proxy, CDN or browser trust chain. Test at the PHP8.1 minimum
separately when that runtime is available. Legacy v1 timestamps still lack an
explicit UTC offset; native clients need a deliberate time-zone mapping.

Uninstall retains credential tables and messaging data. Revoking a device does
not uninstall modules or delete conversations. After rollback to old code, the
additive column/tables may remain; do not remove them destructively as rollback.


## Mobile handoff UX (1.4.1)

The ordinary flow returns automatically through a `303 Location` after explicit browser consent. Narzan must show its own connecting/success UI only after it receives and validates the callback and completes token exchange. The browser authorization boundary remains necessary for existing website SSO/2FA; this change does not add native password collection or an embedded webview.

The inline owner-provided Narzan mark and responsive light/dark CSS make consent/fallback readable without any third-party resource. A SHA-256 style hash permits only those exact static styles; scripts, frames and other resource loads remain denied. Form submission permits self and the validated callback scheme/origin so Chromium does not block the post-consent handoff. Redirect URI exact matching, state, issuer, PKCE, CSRF, token lifetime/rotation, logging restrictions and cookie/bearer isolation are unchanged. No live site is upgraded automatically.

Browser/OS restrictions can require choosing the receiving app or pressing a fallback link. A redirect response body is not guaranteed to be displayed after a browser blocks/intercepts the Location; the native app must retain cancellation/retry. Treat mobile-browser E2E as a release gate in addition to HTTP contract tests.

Verification on 2026-10-18: the disposable ProcessWire/MySQL server passed 19 real-TLS handoff checks, including CSRF rejection, exact 303 callback, cookie-free PKCE exchange, single-use code rejection, revocation and preservation of the browser web session. An Android 15 emulator with Chrome also completed the native browser-login test through the automatic 303 return without pressing the fallback link. This verifies that tested browser/device combination; the full native regression run and physical-device/browser variants remain separate gates. The original production checkout and live website were not modified.

Primary implementation references checked 2026-10-18:

- [RFC 8252: OAuth 2.0 for Native Apps](https://www.rfc-editor.org/rfc/rfc8252.html): external user-agent and native return URI/PKCE boundaries.
- [OpenID AppAuth Android](https://github.com/openid/AppAuth-Android#capturing-the-authorization-redirect): browser completion redirects to the registered app URI; webviews are not supported by that SDK. Narzan uses its existing protocol/client rather than introducing this SDK.
- [Chrome: Android Intents](https://developer.chrome.com/docs/android/intents): resolver/user-gesture restrictions mean automatic app launch cannot be guaranteed across all contexts.
- [GitLab's OAuth CSP correction](https://gitlab.com/gitlab-org/gitlab/-/merge_requests/90082) and [W3C CSP redirect discussion](https://github.com/w3c/webappsec-csp/issues/8): Chromium can enforce form-action on the redirect after an authorization POST; restrict its additional source to the validated callback context.
