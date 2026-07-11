# Checkoutly API Gateway — Design

Status: **draft for review** · Owner: Checkoutly · Last updated: 2026-07-11

## 1. Goal

Turn the licensing server into a value-delivery gateway, not just a key checker.
The module calls our API for features that normally require the merchant to set up
their own paid third-party API keys (Google Places, etc.). We supply those from our
side, gated behind a valid license.

This does three things:

1. **Kills the point of cracking.** A cracked module can flip the local
   `FeatureGate` to `true`, but gateway features require a call our server answers
   only for a valid, non-revoked license. The value lives server-side, not in the
   shipped PHP.
2. **Collapses merchant setup to zero.** "Paste your license key" replaces "create
   a Google Cloud project, enable billing, restrict a key, paste it." This is the
   core ease-of-use differentiator.
3. **Justifies the subscription.** We are paying the upstream bill and running the
   infrastructure — something a one-time-purchase competitor cannot match.

## 2. Non-goals

- Not a general public API. Only our own module talks to it.
- Not a replacement for the offline license check. The offline RSA verify still
  guards the checkout hot path locally (no network on the hot path). The gateway is
  an *additional* server-side gate for the specific features it powers.
- The gateway is never on the critical path of order placement. If it is
  down/slow/over-quota, the affected feature degrades to the plain native behavior;
  checkout still completes.

## 3. Architecture

```
Browser (checkout)
   │  GET /module/checkoutly/apiproxy?feature=places&q=...   (PS token-protected)
   ▼
Module front controller  ── holds the license key; browser never sees it ──┐
   │  POST https://<gateway>/api/v1/{feature}                               │
   │  Authorization: Bearer <license key>                                   │
   │  X-Checkoutly-Domain: shop.example.com                                 │
   ▼                                                                        │
Licensing server /api/v1/*                                                  │
   ├─ 1. verify license key (RSA sig + exp)          → 401 on failure       │
   ├─ 2. load license, check status/revoked          → 403 on failure       │
   ├─ 3. domain match (localhost bypass)             → 403 on mismatch      │
   ├─ 4. quota check + increment (per license/month) → 429 when exceeded    │
   ├─ 5. cache lookup (where TOS allows)                                    │
   ├─ 6. call upstream (VIES / Google) on miss                              │
   └─ 7. normalize → JSON (never raw upstream errors)                       │
```

Reuse the existing Symfony monolith: it already owns licenses, Stripe state, and
revocation. Add an authenticated `/api/v1/*` surface next to the existing
`/api/license/status` endpoint.

**Why the extra browser → shop hop:** the license key is a bearer credential. If the
browser called the gateway directly, the key would be in page source / network tab
and trivially harvested. So the module's own front controller (already has a PS
security token) is the only thing that holds the key and forwards the call. Cost: one
extra hop on latency-sensitive calls (see §9).

## 4. Authentication (license key as bearer)

The module sends its license key as `Authorization: Bearer CKLY.<payload>.<sig>`.
The gateway validates in the same spirit as `LicenseValidator::verify()`:

1. **Signature + expiry** — verify the RSA-SHA256 signature against our public key and
   check `exp`. This can be done statelessly (same code path as the module) so a
   forged/edited key is rejected without a DB hit. → `401 Unauthorized`.
2. **License record** — look up the license by `id` from the payload. Require an
   entitled status (`active` or `trialing`) and `revoked = false`. → `403 Forbidden`.
3. **Domain binding** — compare `X-Checkoutly-Domain` to the license's registered
   domain. Reuse `License::isLocalDomain()` so a vendor's dev machine
   (`localhost`, `*.test`, etc.) is allowed without consuming or tripping anything.
   Mismatch on a public domain → `403`. (A determined cracker can spoof this header;
   the real backstop for that is quota + anomaly detection in §6, not this check.)
4. **Quota** — see §5.

No separate API token to issue, rotate, or store. The license key *is* the credential;
revoking the license revokes API access in the same motion.

## 5. Quota & metering

Per-license, per-feature, per-month counter. Reset is implicit via the period key.

New table `api_usage`:

| column      | type        | notes                                  |
|-------------|-------------|----------------------------------------|
| id          | int PK      |                                        |
| license_id  | int FK      |                                        |
| period      | char(6)     | `YYYYMM`                               |
| feature     | varchar(32) | `vat`, `places`, ...                   |
| count       | int         | incremented per **billable unit**      |
| updated_at  | datetime    |                                        |

Unique key `(license_id, period, feature)`.

- "Billable unit" is per-feature: for VAT it's one validated lookup; for Places it's
  one **completed session** (see §7), not one keystroke.
- Over quota → `429` with a machine-readable body (`{"ok":false,"error":"quota_exceeded"}`)
  so the module degrades to the plain field for the rest of the period and can surface
  a back-office notice.
- Quota limits are config-driven per tier. Trial = same as paid (trial is an entitled
  state). Numbers are an open question (§12).

## 6. Cost control & leaked-key containment

Our costs are variable; a single bad actor or one huge merchant can invert the
economics. Controls:

- **Session tokens for Places** (§7) — the single biggest cost lever (5–20×).
- **Caching where allowed.** VIES/VAT results are public company data → cache freely
  (e.g. 24h–7d). **Google Places autocomplete predictions must NOT be cached** per
  Google TOS; `place_id` and limited Place Details fields have narrow caching
  allowances only. So Places cost control leans on session tokens + quota + client
  debounce, not caching. Do not design Places around a cache.
- **Per-license monthly quota** with graceful degradation (§5).
- **Anomaly detection.** Track distinct domains per license and volume vs baseline. A
  single license hitting from many domains, or 100× normal volume, auto-flags/throttles
  and can trigger revocation review. This is the real defense against a leaked key,
  since the domain header is spoofable.
- **Revocation** (already built) kills a leaked/refunded key centrally.

Unit-economics sanity: VIES-class features cost ≈ €0. Places with session tokens +
quota is cents to low single-digit euros for a normal store — comfortably under the
€16–20/mo margin. The tail risk (high-traffic store, leaked key) is contained by quota
+ anomaly detection, not by hoping.

## 7. Google Places specifics

- Use **session tokens**: the module generates one token per address-entry session and
  sends it with every autocomplete keystroke request and the final details request.
  The gateway forwards it to Google so Google bills one session instead of per
  keystroke. **Meter quota on the details/completed call**, aligning our quota with
  Google's billing.
- **Client-side hygiene** (module JS): debounce (~200–250ms), minimum query length
  (≥3 chars), abort in-flight requests on new input.
- **No prediction caching** (TOS). `place_id` may be stored; treat Place Details field
  caching conservatively.
- Pick the API explicitly: Places Autocomplete (New) vs legacy, and whether to use the
  Address Validation API for the final normalize. Open question (§12).

## 8. Endpoints (v1)

All under `/api/v1`, all `POST`, all require the bearer + domain header, all return the
normalized envelope.

Envelope:

```json
{ "ok": true, "data": { ... }, "cached": false }
{ "ok": false, "error": "quota_exceeded" }
```

Errors are normalized codes (`invalid_input`, `quota_exceeded`, `upstream_unavailable`,
`not_entitled`, `domain_mismatch`). Never forward raw upstream error bodies.

- `POST /api/v1/vat/validate` — `{ "country": "LT", "vat": "100001234567" }` →
  `{ "valid": true, "name": "UAB Example", "address": "...", "countryCode": "LT" }`
  (VIES; free; cacheable).
- `POST /api/v1/places/autocomplete` — `{ "q": "...", "session": "<uuid>", "lang": "en" }`
  → list of predictions `{ place_id, description }`.
- `POST /api/v1/places/details` — `{ "place_id": "...", "session": "<uuid>" }` →
  normalized address components. **Billable unit for Places quota.**

Version the path (`/v1`) so upstream provider swaps don't break deployed modules.

## 9. Latency

Autocomplete wants <200–300ms end-to-end; we add browser→shop→gateway→Google hops.

- Host the gateway near the merchant base (EU first) and keep the `/api/v1` path lean.
- Client debounce + min-length + request abort (§7).
- VAT is not latency-sensitive (on blur, not per keystroke) — fine over the extra hop.
- If Places latency proves bad for distant merchants, revisit with a lighter edge path
  before adding infra complexity. Not an MVP concern.

## 10. GDPR / data processing

We become a **data processor**: addresses, VAT numbers, names, and possibly emails
transit our server.

- EU hosting/region.
- **Minimal logging** — never log full addresses/PII. Log license id, feature, domain,
  counts, timestamps only. Anomaly detection uses aggregates, not payloads.
- DPA + processor disclosure in the merchant terms.
- Short/zero retention of request payloads; cache stores only what TOS permits.
- Note: company VAT data is largely non-personal, but sole-trader VAT can be personal —
  treat the VAT path with the same care.

## 11. Module-side integration

- New `Checkoutly\Api\GatewayClient` (server-side): attaches the license key + domain,
  short timeout (2–3s), **soft-fail** on any error, returns a normalized result or a
  "degraded" signal. Same philosophy as `LicenseService::refreshRemote()`.
- New token-protected front controller (`apiproxy`) that the browser hits; it calls the
  gateway server-side so the key never reaches the client.
- **Degradation matrix** (never block order placement):
  - `401/403` (invalid/revoked/domain) → disable feature, fall back to plain field,
    optional BO notice.
  - `429` (quota) → plain field for the rest of the period, BO notice.
  - timeout/`5xx` → silent fallback, retry later.
- Feature flags still gate the UI locally via `FeatureGate`; the gateway is the
  enforcement layer behind the flag.

## 12. Open questions

1. Per-tier monthly quota numbers (VAT lookups; Places sessions). Shared pool or
   per-feature?
2. Hosting region/infra for the gateway (latency vs cost).
3. Exact Google API choice: Places Autocomplete (New) vs legacy; use Address Validation
   API for the final normalize?
4. Trial quota — same as paid, or reduced to limit abuse of the 14-day trial?
5. Anomaly thresholds and the action they trigger (throttle vs flag-for-review vs
   auto-revoke).

## 13. Rollout sequence

- **Phase A — VIES VAT (zero cost, proves the pipe):** gateway `/api/v1` skeleton,
  bearer auth + license/domain/quota checks, `api_usage` table, `/vat/validate`;
  module `GatewayClient` + `apiproxy` controller + VAT field integration + fallback.
  - **Server side — BUILT & verified 2026-07-11.** `LicenseKeyVerifier` (stateless
    RSA verify, public key derived from the signing key), `ApiUsage` entity + repo +
    migration, `ApiQuota` (check-then-record), `GatewayAuthenticator` +
    `GatewayException` + `GatewayResult`, `AbstractGatewayController`, `ViesClient`
    (24h cache pool `cache.vies`, service-fault codes not cached), `VatController`
    `POST /api/v1/vat/validate`. Rate limiter `gateway_api` (120/min/IP), quota via
    `GATEWAY_QUOTA_*`. E2E verified: 401 no-auth, 403 domain-mismatch, 200 live+cached,
    400 bad-input, quota increments only on billable success.
    - ⚠️ **VIES REST field is `isValid`, not `valid`** — and `userError` fault codes
      (`MS_UNAVAILABLE`, `TIMEOUT`, …) mean "service down", not "invalid VAT"; never
      cache those. Both handled in `ViesClient`.
  - **Module side — BUILT & verified 2026-07-11.** `Checkoutly\Api\GatewayClient`
    (bearer + `X-Checkoutly-Domain`, 3s timeout, soft-fail, normalized envelope;
    base URL from `CHECKOUTLY_GATEWAY_URL` override, empty until hosted),
    `Checkoutly\Checkout\VatService` (premium + toggle + gateway-configured gate;
    resolves ISO from `id_country`; EU-only; advisory, never blocks), `vatValidate`
    action added to the existing token-protected `ajax.php` (reused instead of a
    separate `apiproxy` controller — same browser→module hop, no boilerplate dup),
    `FeatureGate::VAT_VALIDATION`, front JS validates the existing `vat_number`
    field on blur with a ✓/! status line, `CHECKOUTLY_VAT_VALIDATION` toggle +
    upgrade-0.1.27 + version 0.1.27. cs-fixer + phpstan clean.
    - E2E verified in PS9 container: `isEnabled` true with a valid license,
      `IE6388047V` → valid + "GOOGLE IRELAND LIMITED", invalid LT number → not
      valid, non-EU → skipped (no gateway call), quota metered per call.
    - **Degradation matrix implemented minimally:** any non-ok gateway response
      (401/403/429/5xx/unreachable) → `checked:false`, JS shows no verdict (fail
      open). Not yet built: BO notice on 401/403/429 (§11), and VAT validation is
      currently advisory only — enforcing valid VAT for B2B exemption is a separate
      product decision.
    - **Dev wiring:** in the compose stack the gateway is reachable from the PS
      container at `http://licensing`; `CHECKOUTLY_GATEWAY_URL` is set to that in the
      PS9 dev DB. VAT/company fields only render when PrestaShop B2B mode is on.
- **Phase B — Google Places (marquee):** session tokens, `/places/autocomplete` +
  `/places/details`, module autocomplete widget with debounce/min-length, quota + cost
  controls.
  - **BUILT & verified 2026-07-11.** Server: `GooglePlacesClient` (Google Places API
    (New), OUR key via `GOOGLE_PLACES_API_KEY`, session token forwarded on both calls,
    normalized PS-agnostic output, predictions never cached per TOS), `PlacesController`
    `/places/autocomplete` (billable:false) + `/places/details` (billable:true) — quota
    meters only the completed session, aligning with Google's session billing.
    Module: `PlacesService` refactored off the merchant's own Google key onto
    `GatewayClient`; PS country/state id resolution kept local. The merchant "Google
    Places API key" field was removed from settings (zero-setup is now real); the
    existing autocomplete widget/ajax/JS were already in place and unchanged.
    - E2E with a real Google key: `1600 Amphitheatre Pkwy` → 3 predictions
      (autocomplete, not billed), details → `{address1, city, postcode 94043,
      countryCode US, California}`, resolved in-module to `id_country=21, id_state=8`.
      Quota went 0→1 (details only). cs-fixer + phpstan clean both sides.
    - Not yet done: client-side min-length/debounce already existed (250ms, ≥3 chars);
      per-tier quota numbers still placeholder (§12); no BO degradation notice yet.
- **Phase C — more:** email/phone validation, currency/geo, as economics allow.

## 14. PrestaShop Addons marketplace compatibility (researched 2026-07-11)

Researched against PrestaShop's contributor/validation guidelines. Final call always
rests with their human validators; `addons.prestashop.com` blocks automated fetch (403),
so some findings are via the devdocs mirror + validation-team snippets.

**The gateway pivot is what makes an Addons listing viable rather than blocked**, because
it reframes the paid value as "a declared external subscription" (explicitly allowed)
instead of "premium code unlocked by a key" (the pattern PrestaShop scrutinizes hardest).

What the rules say:

- ✅ **External paid subscription/SaaS is permitted if disclosed.** Product-page guide:
  "If your module requires a subscription to an external service, you should check the
  box provided… Clients will be disappointed… if information is missing." → tick the
  external-subscription box on the product page.
- ✅ **Runtime API calls are fine.** The "no external content should be downloaded after
  installation" rule is about the *zip being self-sufficient* (code/assets), not about
  calling an API for a feature. Declare the gateway domain in `header_csp.txt`; keep the
  ajax/proxy endpoint token-secured (already done).
- ⚠️ **No external links in module code/docs; support via Marketplace.** "Don't insert
  external links into your module code or module documentation." + "Support goes through
  the PrestaShop Marketplace." → **the current in-BO "Upgrade to Pro" / "Manage license"
  portal links must be stripped from the Addons build.**
- ⚠️ **Module code must be AFL-licensed** (OSL/AFL compatibility required). Current files
  carry a `Proprietary` header → must switch to AFL for an Addons submission.

**Decision — maintain two builds:**

- **Addons build:** AFL-licensed, no portal/upgrade links in the BO UI, external
  subscription declared via the product-page checkbox, support routed through Addons.
  It connects to the gateway; Pro is obtained through a neutral "connect your account"
  flow, not a "buy here" upsell.
- **Direct build (own site):** everything current — proprietary license, "Upgrade to Pro"
  CTA, portal links, full freemium UX.

The line between a tolerated "connect your account" flow (Klaviyo/PayPal do this) and a
rejected "upsell ad" is drawn by a human validator — confirm exact packaging with the
validation team before investing in the Addons build.
