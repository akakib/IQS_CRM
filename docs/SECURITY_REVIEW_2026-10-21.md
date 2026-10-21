# Security review, 21 Oct 2026

Read-only review of the whole codebase and the staging server (iqs.top5way.com). Nothing was changed.
Four angles: outside attacker (injection, XSS, uploads, SSRF), authentication and configuration, server exposure,
and insider fraud (staff abusing their own access). "Verified" = re-read in the code by hand.

## Verdict

From the outside the app is hard to break: no SQL injection, no XSS sink with user data, no unsafe upload, no open
registration, all three webhooks signed and checked in constant time, secrets encrypted at rest, prices read on the
server. The real risks are (1) the staging server configuration, (2) staff abusing their own access (money, points,
phone numbers), and (3) missing hardening layers (2FA, security headers).

## Server (staging)

| # | Severity | Finding | Fix |
|---|---|---|---|
| S1 | HIGH | `APP_DEBUG=true` on staging, which holds real website orders. Any error shows a full debug page (queries with phones and addresses, request data). Debugbar is installed (dev packages deployed) and turns on with debug. | `APP_DEBUG=false` on every internet-facing host; deploy production with `composer install --no-dev`. |
| S2 | MEDIUM | Document root is `public_html` (the whole app), protected only by a rewrite in `public_html/.htaccess`. Today `.env`, `.git`, logs and backups answer 403, but one broken `.htaccess` would expose source, `.env` and SQL backups. | Point the domain's document root at `public_html/public` in hPanel (or move the app above `public_html`). |
| S3 | MEDIUM | `.env.bak-debug`, `.env.bak-debugbar`, `.env.bak-fake` (old secrets) and `CLAUDE.md` sit in the app root. Not reachable by URL today (see S2). | Delete the `.env.bak-*` files; keep `CLAUDE.md` out of the deploy. |
| S4 | MEDIUM | `storage/backups/*.sql` are mode 644, uncompressed, ~2.2 GB in total, on the same server as the app. | `chmod 600`, gzip, keep N copies, and copy off-server (Google Drive is already connected). |
| S5 | LOW | No HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy; `X-Powered-By: PHP/8.3` is sent. Cookies are fine (Secure, HttpOnly, SameSite=Lax, 2 h). | One middleware adding the headers; `expose_php=Off`. |

## Code: outside attacker

| # | Severity | Finding | Fix |
|---|---|---|---|
| C1 | MEDIUM | SSRF: the Website account URL (`WebsiteApiController:79`, admin only) accepts any host; the check call sends the Woo key/secret to it and echoes 200 chars of the reply. | Require https, reject private/loopback/link-local IPs, do not echo the body. |
| C2 | MEDIUM | No 2FA for an app with money, access management and customer data. | TOTP 2FA, at least for Owner and managers. |
| C3 | LOW-MED | Telegram HTML injection: `OwnerSummary` puts staff names (editable by each person) into an HTML message without `e()`. A name can add a phishing link to the owner's daily report or stop it being sent. | `e()` the names. |
| C4 | LOW | Login throttle is per email+IP only (5 tries) and there are no trusted proxies, so behind the CDN the IP may be the proxy's. | Route `throttle` per IP as well; configure `trustProxies`. |
| C5 | LOW | Password reset reveals whether an email exists (different message for a fake token). | One generic message. |
| C6 | LOW | Raw webhook payloads (names, phones, addresses) kept 365 days. | 60-90 days. |
| C7 | LOW | Woo "ping" without signature sets the "connected" flag; Telegram Mini App shows any batch to any linked staff. | Require the signature; check `packaging.create`. |

## Code: insider fraud

| # | Severity | Finding | Fix |
|---|---|---|---|
| I1 | HIGH (verified) | A person with `payments.verify` can verify the advance they recorded themselves (`OrderService::verifyPayment`, no `created_by` check). With a Cash advance (no TrxID) they can then cancel the order and refund the cash to themselves, or keep it as customer credit. Refunds record no destination and need no second person. | Block verifying your own payment; second approver and destination number for refunds and credits. |
| I2 | HIGH (verified) | +4 points per delivery issue solved, paid at once, with no check on who opened it or how long it was open: open an issue on a colleague's parcel and close it a second later. | No points when opener = solver or open under N minutes; settle on delivery. |
| I3 | HIGH | Upsell report adds every item added and never subtracts later removals; add, remove, repeat. Merging another order in also counts. | Count the net change per order; leave merges out. |
| I4 | HIGH (verified) | Full phone numbers reach masked roles in ~8 places: `/customer-calls/find` JSON, the `tel:` link on Call lists, order edit form, delivery issues, refunds, order activity card, control room, call report. Masks also differ (first 3 + last 2 vs last 3), and prefix search can rebuild a number. (Known and parked while no masked role makes calls.) | Every phone output through `Mask`; tel/QR only when the phone may be seen; 11-digit search for masked roles. |
| I5 | MEDIUM | +3 "order helped" paid at once and kept if the order is cancelled; "saved" +2 can be set up on purpose. | Settle on delivery; flag unusual setback rates. |
| I6 | MEDIUM | A Cash advance up to `payments.trust_up_to` lowers COD before anyone checks it; a later rejection on a delivered order recovers nothing. | Cash never counts at once; alert on rejections after delivery. |
| I7 | MEDIUM | Website "paid" trusted from an editable payment title and `date_paid`. | Match the exact gateway id and require a transaction id; large amounts to Payments to check. |
| I8 | MEDIUM | A manager (`staff.edit`) can change another person's Telegram id and then act as them in the bot and Mini App. | Owner only, or link by a code the person confirms. |
| I9 | MEDIUM | Stock count can be set to any number with no reason; the reporter of a missing item can decide it; returns marked damaged/missing by one person. | Reason above a threshold, no self-decide, photo for damaged. |
| I10 | MEDIUM | Excel export 5,000 rows per request with no throttle or daily cap. | Throttle + daily cap per user. |
| I11 | MEDIUM | COD "Updated at the courier" confirmed on the moderator's word. | `payments.verify` or check with the courier API. |
| I12 | LOW | Activity log append-only in the app only; staff phones not compared with order phones; blank cost counts as 0 profit; vendor opening balance edits keep no before; points rules editable by their beneficiaries. | DB grants, staff-phone flag, block blank cost, log before, Owner-only rules. |

Not in the activity log today: payment add/verify/reject, refunds, credit, COD updated, courier deleted (order notes
only, no before/after); stock counts; points settle/revoke; failed logins; opening an unmasked phone.

## Done right (can be trusted)

SQL built only from constants, int casts or whitelists; `ListState` whitelists sort/filter; no `{!! !!}` with user data;
uploads validated, random names, private disk for call proofs with auth + nosniff + sandbox CSP; XLSX export cannot run
formulas; WooCommerce HMAC, Steadfast bearer, Telegram secret and initData all `hash_equals` and fail closed; CSRF
exempt only for those; secrets encrypted and masked in Settings; no `Log::` of payloads or tokens; prices, discounts
and delivery charges set on the server; payments cannot be edited or deleted; orders never hard-deleted; deactivation
kills sessions and remember-me; only the Owner changes another person's password or email; no impersonation route;
bonus, flag, dispute and QA screens block deciding your own case; registration closed; reset links expire in 60 min.

## Keeping it secure (routine)

Daily: read failed-login and integrity-flag counts in the Owner summary; look at Payments to check.
Weekly: review activity log for money, access and export actions; check staging and production `APP_DEBUG=false`.
Monthly: `composer audit` and `npm audit`, update Laravel patch versions; test restoring a backup; remove staff who left
(deactivate the same day); review who holds `payments.verify`, `access.manage`, `points.manage`, unmasked phones.
On every change: no raw SQL with request values, no `{!! !!}`, every new money/stock action logged with before/after,
every new phone output through `Mask`, nobody decides their own request.
Accounts: unique passwords of 12+ characters, 2FA for Owner and managers, never share logins, separate Hostinger,
GitHub, WooCommerce and Steadfast accounts protected by 2FA; rotate Steadfast/Woo/Telegram keys when someone leaves.
AI agents and bots: the only open doors are login, password reset, three signed webhooks and the Telegram Mini App;
keep them throttled, keep the admin behind 2FA, and never paste production keys into chats or tickets.
