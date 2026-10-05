# Iqbal OMS — Project Guide
## What this is
An order, courier, marketing-cost, inventory, accounting and team management
system for ONE business: Iqbal Store (dry fruits / grocery, Bangladesh).
It replaces a WhatsApp-group workflow. Single business only — do NOT build
multi-tenancy (no business_id, no business switcher).
Scale: ~7,000 products, ~200 website orders/day (growing), 1,000+
Messenger/WhatsApp messages/day, ~8 office staff (onsite + remote) + shop packers.
Peak season: Ramadan (expected early Feb 2027). Stability matters more than features.
## Priority order (build in this order)
1. Order Management + Role-based access
2. Meta / Google ad cost + USD accounting (ROAS)
3. Stock management
4. Accounting
HR and extras come after. An Analysis tab shows basic order profit/loss
from Step 5 onward and gains ad cost in Step 6.
## Stack (do not change)
- Laravel 11, PHP 8.2+, MySQL 8.0
- Blade + Alpine.js + Tailwind CSS. NO Vue, React or Bootstrap.
- Mobile list/index pages use card UI, never data tables.
- Dropdowns of configurable data (roles, locations, reasons, vendors,
  currencies) load from the database, never hardcoded.
- Timezone: Asia/Dhaka. Money: DECIMAL(12,2) BDT unless stated (USD for ad lots).
- UI language: Bangla labels via lang files (`lang/bn`), English fallback.
- Hosting starts on shared hosting: no long-running workers. Queues run via
  `schedule:run` cron + `queue:work --stop-when-empty`. Keep code VPS-ready.
- `npm run build` is done locally and committed (no npm on server).
## UI & components (look like hisab.top5way.com)
- Visual style must match hisab_t5 (hisab.top5way.com): same colours, fonts,
  spacing, card style, sidebar/top bar. If the hisab_t5 repo is available,
  read its tailwind.config.js, main layout and Blade components FIRST and
  reuse the same tokens. If not available, stop and ask for screenshots.
- Build a reusable component library BEFORE feature pages, as Blade anonymous
  components (`resources/views/components/...`) with Alpine.js for behaviour:
  button, input, select, searchable-select (server-side), date/date-range,
  badge/status-chip (colour from DB), card, modal, slide-over, confirm dialog,
  toast, tabs, dropdown, empty-state, skeleton loader, pagination,
  filter-bar, data-table (desktop), record-card (mobile), stat tile,
  barcode-scan input, notification bell. Pages are assembled from these;
  no page writes its own one-off version of a component.
- Every index (list) page uses ONE shared pattern:
  - Desktop (md and up): filter bar (status, date range, owner, channel …
    as relevant) + search box + sortable data table + bulk-action bar when
    rows are selected.
  - Mobile: the same data as cards (never a table); filters open in a
    bottom sheet; search stays on top.
  - Filters, search, sort and page live in the URL query string (shareable,
    back button works). Search is debounced (~300 ms).
  - Proper pagination: page size options (25/50/100), total count where it
    is cheap; for very large or fast-growing lists (orders, events, logs)
    use simple/cursor pagination instead of COUNT(*) on every load.
  - Loading = skeletons; empty = empty-state component with a next action.
- Interactions feel smart but light: inline status change, optimistic UI
  with rollback on error, keyboard shortcuts on scan/packaging screens,
  toasts for results. No page reload for small actions (use fetch + Alpine).
- Bangla labels from lang files; numbers and money formatted consistently
  (৳ with thousand separators).
## Performance & query rules (think BEFORE coding)
Before writing any page, endpoint or job, write a short plan (in the reply,
not in code): which data the screen really shows, the exact queries,
columns selected, indexes needed, expected number of queries, and payload
size. Choose the cheapest correct approach, then code it.
- Load only what is shown: `select()` the needed columns; no `SELECT *` on
  lists; never load relationships the view does not use.
- No N+1: eager-load with `with()` / `withCount()` / `withSum()`; enable
  `Model::preventLazyLoading()` outside production so N+1 fails loudly.
- Query budget: a list page ≤ ~10 queries, a detail page ≤ ~15. Every
  filter and sort column has an index (composite where filters combine).
- Lists are always paginated server-side; never send all rows to the
  browser. Product search (7,000+ items) is a server endpoint returning at
  most ~20 matches (indexed prefix/full-text search), debounced.
- Aggregates (dashboard counts, KPIs) come from SQL aggregate queries or
  small summary tables updated by events/jobs, not by looping models in PHP.
- Cache slow-changing config (statuses, reasons, roles, settings, zones)
  and bust the cache when it is edited.
- Heavy work (bulk booking, label PDFs, exports, syncs, fraud checks) runs
  in queued jobs; the page returns immediately and shows progress.
- Exports and backfills use `chunkById()` / `lazyById()`, never `get()` on
  big tables.
- Images lazy-load; JS/CSS stay small (Alpine only, no heavy libraries
  without asking).
- Laravel Debugbar (local only) to check query count/time; slow queries
  (> 200 ms) are logged in production.
- Target: index pages respond in under ~300 ms server time with realistic
  data (seed 10,000+ orders and 7,000 products for local testing).
## Core principles (every feature must follow these)
1. Enter data once. Nothing is retyped: courier booking, labels, totals,
   accounting all read from the order.
2. Humans decide, the system executes (totals, CN ID, stock, COD, P&L are automatic).
3. Batch first. Every repeated action has a bulk version.
4. Scan, don't read. Packaging, handover, transfers are driven by barcode/QR scans.
5. Every state change is an event: who, when, from, to, reason.
   KPIs are computed ONLY from these events, never from self-reported data.
6. Nothing is deleted. Ledgers (stock, money, USD, orders) are append-only;
   corrections are reverse entries with a reason. Soft-delete only for master data.
7. This system is the SINGLE SOURCE OF TRUTH for products, prices and stock.
   WooCommerce only receives them (one-way sync). Descriptions, images and
   SEO content stay managed in WooCommerce.
## Order lifecycle (state machine)
Main path:
New → Record Verified (automatic checks: phone format, address/thana,
duplicate, fraud score) → Confirmed (call, or system auto-confirm rule)
→ Ready for Packaging (bulk Steadfast booking + label print happen HERE)
→ Packed (label scanned at packaging) → Ready for Pickup
→ Handed Over (scanned at rider handover)
→ courier statuses from Steadfast webhook (In Transit, Delivered,
  Partial Delivered, Cancelled/Returned, Hold)
Side states: No Answer (retry queue, max 3), Hold (ONE status; reason
required; behaviour comes from the reason: "Stock আসেছ" = auto FIFO release
on restock, "Stock লbnmEমinitনই" = owner decides, "তািরখ" = auto release on date,
other = manual), Cancelled (reason required).
Products have an internal availability: In stock / Pre-order / Out of stock.
Pre-order stays sellable on the website (shown as in stock); confirmed orders
with a Pre-order item go to Hold · Stock আসেছ automatically.
Only Admin/Owner changes product availability; packers can only report
"মাল পাওয়া যায়িন", which alerts Admin.
Repack is NOT a status: an order edited after packaging stays in its stage,
marked RED until repacked, then shown in an "Edited" colour (DB_DESIGN 3.4).
Rules:
- Transitions happen ONLY through one `OrderStateMachine` service.
- Every transition writes a row to `order_events`.
- No order enters Ready for Packaging without a Steadfast consignment ID.
- Handover scan detects duplicates (same parcel twice) and lists parcels that
  are Ready for Pickup but not scanned; produces a pickup manifest.
- Meta CAPI `Purchase` timing is CONFIGURABLE per platform
  (order created / record verified / confirmed / delivered) via
  `tracking_event_settings`; fires once per order, with an `event_id` shared
  with the browser pixel for deduplication.
- Record Verified and skipping the call are decided ONLY by configurable
  verification rules (fraud-check success rate + minimum parcels per provider,
  new-customer policy, own history, advance %, COD amount) — never by staff choice.
  Fraud-check vendors are pluggable drivers (Steadfast, Pathao, others).
- Statuses, transitions and reasons are database rows; Owner can add custom
  statuses. Code depends only on system_keys; core gates cannot be bypassed.
- Orders are versioned; every edit at any stage writes an automatic note
  (who, date & time, stage, what changed).
- Full table design: see docs/DB_DESIGN.md — follow it exactly.
- Advance payment is an order attribute (amount, method, transaction ID),
  not a status.
- Delivery charge comes from a rules table (zone, weight, free-shipping
  threshold), not typed by agents.
- Discounts above a role's limit need Manager approval.
## Order ownership
Every order has exactly one owner (an employee) at any time.
- Chat orders: the agent who created it.
- Website orders: assigned round-robin among active agents at confirmation.
- Owner on leave / off shift: duty person becomes temporary owner.
Owner is responsible end-to-end, including delivery issues.
All calls/chats about an order are logged as order notes.
## Amendments (product/price change after Confirmed)
Never edit silently. Every change is an `order_amendments` row with old vs new
items, amount difference, reason (dropdown: customer request / stock out,
substitute given / entry error / price error), user.
- Confirmed, not batched: direct edit, totals recalculated, customer notified.
- Ready for Packaging (has CN ID): Manager approval; COD update task for
  courier desk; label reprint; Telegram alert to shop.
- Packed / Ready for Pickup: editable (approval per edit_policy). Order stays
  in its stage but turns RED; any scan fails and shows what to change; after
  the packer repacks and scans the NEW label it shows the "Edited" colour and
  handover scan succeeds. Old labels are voided and refuse to scan.
- Handed Over or later: no edit (partial delivery or new order instead).
"Entry error" amendments count against the creator's quality score.
## Rider hotline & delivery issues
Riders call ONE hotline number (shop-owned SIM). Hotline screen: search by
CN ID / phone / name → shows items, COD, owner, full notes timeline.
- Small issue (customer not answering): hotline person resolves directly.
- Bigger issue (partial, cancel, exchange): one click creates a
  `delivery_issue` task for the owner (Telegram + screen alert, SLA timer,
  rider's phone saved). Not handled in SLA → returns to duty person, counted
  in owner's KPI.
- Steadfast webhook statuses Hold / Partial / Cancelled-approval-pending also
  auto-create a delivery_issue task.
- Exchange = new linked order of type "exchange".
- KPI "saved delivery": an issue that ended Delivered.
## Telegram (shop team)
A bot, not humans, posts: batch released (consolidated pick list sorted by
shelf), amendment/repack alerts, cancellations, a pinned live summary that the
bot edits in place, end-of-day report. Inline buttons ("Picked", "মাল পাওয়া যায়িন" = report to Admin only,
"Repack done") update the system; each staff Telegram account is linked to an
employee so button presses count in their KPI. Packers use a Telegram Mini App
for scan-to-pack. Shop staff never see customer phone/full address.
Owner gets a nightly summary (orders, delivered, ad spend, ROAS, stuck orders).
An alert matrix (which alert → which role, urgency) is configurable.
In-app notifications: a bell icon with unread count in the top bar, backed by
one NotificationService and an editable alert matrix (event → role/user →
in-app / Telegram / SMS, priority). Polls every 30–60 s on shared hosting.
Urgent items stay highlighted until the task is done. See DB_DESIGN 4B.
## Steadfast integration
- Base URL: https://portal.packzy.com/api/v1 (API key + secret key headers).
- THERE IS NO SANDBOX. Every live booking is a real parcel.
  → `CourierDriver` interface with `SteadfastDriver` and `FakeCourierDriver`.
    Tests and staging ALWAYS use the fake driver.
- Invoice = our order number; bookings are idempotent by invoice.
- Bulk booking for batches. Our booking note tells riders to call the hotline.
- Webhook: verify `Authorization: Bearer <token>`. Store the full raw payload
  in `courier_events` before processing. Respond 200 fast; process in a job.
  Handle `delivery_status` and `tracking_update` if present; show
  tracking_message / rider notes in the order timeline when available.
- Re-sync button: pull status for all active parcels (webhook fallback).
- Use the Steadfast fraud-check endpoint in Record Verified.
- Only use endpoints confirmed in Steadfast's in-panel API guide. If unsure
  whether an endpoint/field exists, stop and ask instead of guessing.
## Roles & permissions
Custom roles, created/edited by Owner. A permission =
- action on a module (view / create / edit / approve / delete / export),
- data scope (own / team / all),
- location scope (specific locations or all),
- field masks (customer contact, cost price, profit, salary).
Temporary role assignments with expiry date (duty rotation).
Role/permission changes are logged. Export is Owner-only by default.
Large money/stock corrections need a second person's approval.
Admin login uses 2FA. Deactivating a user reassigns their open orders.
Default roles: Owner/Admin, Manager (Ops lead), Moderator (sales/confirmation),
Dollar Keeper, Packaging, Store Keeper, HR.
## Scoring & KPI
Points per order per stage go to the person responsible for that stage;
deductions only to the stage whose error caused a return (mandatory return
reason). Courier-caused failures deduct from no one. Scores are "pending"
until a final courier status, then "final". Show totals AND rates (delivered %,
error %), and output per working hour (uses attendance). Monthly scorecard:
volume 40% / speed 20% / quality 40% (weights editable). Weekly random QA of
5 orders per agent. Credit rule: chat agent gets credit if same phone orders
on website within 7 days. Detect suspicious patterns (repeated cancels on the
same phone, staff-linked numbers).
## Analysis tab — order profit & loss
Per order (and rolled up by day / product / category / channel / owner):
  revenue (delivered amount after discounts)
− product cost (cost price SNAPSHOT stored on order_items at confirmation)
− Steadfast delivery + collection charges (from webhook/payment data)
− return delivery cost (returned orders)
− packaging cost per order (setting)
− ad cost share (from Step 6: daily BDT ad cost allocated to that day's orders)
= profit. Show margin %, and loss-making orders/products. Placed vs
Confirmed vs Delivered views. Only roles without the profit mask can see it.
## Meta / Google cost & USD (Step 6)
- USD lots: date, vendor, USD, rate, BDT total, paid now or due date,
  payment method + transaction ID. Vendor ledger with dues and reminders.
- Ad spend consumes USD lots FIFO → real BDT cost per day.
- Daily pull of Meta Insights (spend, clicks, purchases, messaging
  conversations started) re-pulling the last 3 days; Google Ads cost
  (API, or scheduled report import until developer token is approved).
- Align days to the ad account timezone.
- Dashboard per day: spend USD, real BDT cost, messages, orders by channel,
  confirmed & delivered revenue, Meta ROAS vs confirmed ROAS vs delivered
  ROAS, blended ROAS (MER), cost per order, cost per message.
- Reconcile: USD bought − spend = remaining balance.
## Stock management (Step 7)
- Stock per product-variant per location (stores e.g. Lovelane, Newmarket;
  Shop; Online as location or as reservation — configurable). Virtual
  location "Direct" for supplier→party shipments.
- Base unit (gram or piece); sealed box variants (e.g. 5kg/3kg/1kg) counted
  in units; loose stock in grams. "Open box" transaction moves a box into
  loose stock (optionally recording measured weight). Picker is told which box
  to open: the smallest box that covers the shortfall.
- Repack transaction (loose → packets) and Bill of Materials for combos.
- Tracking level per product: level 1 (counts) or level 2 (each box has a
  barcode ID with full history: received by, transferred by, opened by,
  measured weight, sold, variance).
- Transfers are two-step: send (stock leaves, "in transit") → receive (scan,
  enter received qty; mismatch alert). Sender can cancel before receive;
  after receive only a reverse transfer.
- Receive slips (supplier, qty, cost price, expiry, destination per line,
  including Direct → party). Delivery challan with photo proof for direct.
- Available to sell = on hand − reserved. Stock search shows every location,
  reserved, in transit, color badges. Manual "Stock out" by Admin only (packers just report missing items) → instantly
  hidden on website + count task; cleared by next receive.
- Weekly loose-stock weigh-in; variance logged; alert above threshold.
- Expiry tracking with FEFO and 30-day alert. Reorder points, days-of-stock
  forecast, dead-stock (90 days no sale) report, cycle counts (ABC).
- Shop counter sales via simple POS (or end-of-day sales entry), daily cash
  drawer reconciliation.
## Accounting (Step 8)
Supplier ledger (payables), B2B party ledger (receivables, credit limits,
party price lists), Steadfast payout reconciliation, refunds (bKash/Nagad
with approval), complaint costs, cash, expenses, profit & loss by
shop / online / store and overall.
## HR (Step 9)
Employee profile: employment type (onsite / remote / part-time), pay type
(monthly fixed / per shift-hour / fixed + performance), shift, location.
Attendance: onsite = rotating QR scan (optional fingerprint device);
remote = shift start/end + output-based activity (no screenshots or
invasive tracking). Leave requests → Manager approval → roster shows cover.
Payroll draft: base ± attendance per written policy + KPI bonus + team bonus
+ overtime − advances; HR reviews, Owner approves; staff see only own payslip.
## Extras (Step 10)
Complaints/tickets with photos and stage blame, coupons/promotions tracking,
review request after delivery, customer order-status page, loyalty points,
corporate gift orders with multiple addresses, marketing consent records,
holiday/courier-closure calendar, second courier (Pathao/RedX).
## Code conventions
- Business logic in Service classes; controllers stay thin.
- PHP enums for statuses, reasons and built-in role keys.
- Every state transition and every money/stock movement has a feature test.
- Migrations are additive. Never drop or rename a column with data without asking.
- Never call live external APIs (Steadfast, Meta, Google, WooCommerce,
  Telegram) in tests.
- Secrets only in .env. Never commit or print keys or tokens.
- Daily off-server backups; staging site with fake courier driver.
## How to work
- Build ONE step at a time. Do not start the next step.
- Think first: for every feature, plan the data model, queries, indexes and
  components before coding (see Performance & query rules). Reuse existing
  components and services before creating new ones.
- At the end of a step: run all tests, list what was built, list anything not
  done, and give manual test instructions.
- If a fix is taking too long or you keep hitting the same error, stop pushing
  the same approach. Check whether a different approach solves it without new
  side-effects, and propose it before continuing.
- Ask before: destructive migrations, adding a new package, changing the stack.
## Build Steps
| Step | Scope | Priority |
|---|---|---|
| 0 | Foundation + roles & permissions, event log, courier driver + fake, staging, backups | 1 |
| 1 | Products (CRUD, variants, units, 3 price lists, cost price, price history, CSV import from Woo, one-way sync) + Customers (phone key, duplicates, fraud score) | 1 |
| 2 | Order intake: Woo webhook, Quick Order + product search, ownership, state machine, amendments, delivery-charge rules | 1 |
| 3 | Confirmation & courier: call queue, auto-confirm, CAPI Purchase, bulk Steadfast booking, labels, webhooks, delivery issues, hotline screen | 1 |
| 4 | Packaging & dispatch: batches, pick lists, scan-to-pack, handover scan + manifest, Telegram bot & Mini App | 1 |
| 5 | Dashboards, KPI scoring, Analysis tab (order P&L without ad cost), nightly summary | 1 |
| 6 | Meta/Google cost: USD lots FIFO, vendor dues, daily spend, ROAS; ad cost added to Analysis P&L | 2 |
| 7 | Stock management (full scope above) | 3 |
| 8 | Accounting (full scope above) | 4 |
| 9 | HR: attendance, leave, payroll | 5 |
| 10 | Extras | 6 |

## Project decisions (these OVERRIDE anything above)
Agreed with the owner on 2026-10-05:
- Laravel 12 (not 11). Server DB is MariaDB 11.8 (Hostinger), local is XAMPP MariaDB.
- UI is English only. Still wrap every string in __() so Bangla can be added later.
- No 2FA for now (no google2fa package until the owner asks).
- Locations: types warehouse / shop / virtual. Riajuddin Bazar is the only shop
  and ALL packaging happens there. "Online" is a department and a sales channel,
  never a stock location.
- Products keep description, regular/sale/discount price, SEO title and
  description and every other needed field IN THIS SYSTEM; the one-way sync to
  WooCommerce carries them too (overrides "descriptions/SEO stay in Woo").
- Words: the person working an order is its MODERATOR (`orders.moderator_id`;
  UI says "Assigned to"). "Owner" means only the business Owner role.
- Order management desk (2026-10-05, replaces "Assign to me" and the Call queue):
  - New website orders are never picked: "Take next" gives the OLDEST waiting
    order. Nobody takes it in 15 min -> auto-assigned to the active moderator
    (seen in the last 10 min, not on break, inside their shift) with the fewest.
  - Limit 5 website orders per moderator (new + record verified). Chat orders
    belong to their creator, have no timer and do not count.
  - Action timer 10 min, on ONE order at a time (the oldest); missed -> order
    goes back to New, counted as "released", minus point.
  - No response returns to the same moderator after 30 min, 5 h, 24 h (moved
    to the next shift start if outside hours); after the last one the order
    is cancelled as unreachable (0 points, counts in the KPI cancel rate).
  - "Send to packaging" books the courier after the response; the order reaches
    the packaging queue only with a CN. Cron (`desk:tick`) retries and sweeps.
  - Timers are columns checked at click time; cron only catches what nobody
    touched. Keep it that way (shared hosting).
- Packaging: one shared queue for today's on-duty packers; scanning the label
  claims the order; Packed only when every item is ticked (server-checked).
  A packer who cannot finish puts the order on Hold with a reason. Packers
  never see phone, full address or prices. Telegram: digest + exceptions only.
- Breaks: everyone. Reason required (admin-editable, each flagged "counts as
  break" or "work away"); full-screen lock until "Start work"; server refuses
  actions meanwhile; un-called orders return to New without penalty. Left
  open -> closed at shift end and flagged. Daily limit only warns (red).
- Working days: per person (`work_schedules`), else the office default in
  Settings. Using the system on an off day = an "extra day" (needs approval
  before it counts for pay). Full HR/payroll is a later step.
- Points: every value is admin-configurable (point_rules); pending until the
  order is final; reverted statuses claw points back; fake-status penalties
  only after a Manager confirms. Scoring is outcome-based: nothing for
  taking/confirming; delivered +3, saved order +2 more, moderator-caused
  cancel -1 / return -3, missed timer -1. Rules can depend on the channel.
- KPI = counts and rates per moderator (no weighted score), two views (ended
  in the period / cohort), monthly targets per person (`kpi_targets`).
  Points and KPI stay SEPARATE.
- Roles: Owner has everything; role = shared rules; per-staff Allow/Deny on
  top (deny wins); temporary roles/permissions with expiry; new modules start
  NOT allowed. Managing roles/access is Owner-only.
- Notifications table is `app_notifications` (Laravel reserves `notifications`).
- Staging: https://iqs.top5way.com (APP_ENV=staging, courier forced to fake).
  No production domain yet. Never touch hisab.top5way.com or thisab.top5way.com.
- Shared hosting: exec/shell_exec are disabled (proc_open works); one hPanel
  cron runs `php artisan schedule:run` every minute.
