# T5 CRM: System Map

What the system does, where each part lives, and which piece is the one source of truth for each job.
Read this before building anything: if a page, service, component or number below already does the job,
use it or extend it. Keep this file up to date whenever a module changes.

IQS's order, customer, courier, stock, marketing-cost and team system. One business, Asia/Dhaka,
money in BDT. Laravel 12, Blade + Alpine.js + Tailwind. Database design: [DB_DESIGN.md](DB_DESIGN.md).

---

## 1. Rules of the team

### Before building anything
1. **Look for what already exists.** Search this map, the routes, services, components and tables for something
   that does the same or a similar job. If it exists, solve the problem with it (use it or extend it).
   Build new only when the existing piece truly cannot do it, and write down why.
2. **Never build the same thing twice in a different way.** One search, one customer page, one way to count a
   number. When two places already do the same job, merge them into one.
3. **Check the solution** solves the real problem, is optimized (count the queries, no N+1, paginate, load
   only what the screen shows) and secure (permissions, data scoping, validation on the server).
4. **Plan first, then build.** Explain the plan in plain words and get a yes from the product owner before
   implementing; report what was done, what is left and any blocker.

### Product and UI
- UI text is English only; no Bangla and no em-dash in labels, placeholders, notices or activity text.
- The business is called **IQS** everywhere (screens, prints, messages, docs); never "Iqbal Store". Print / PDF and
  Excel downloads carry no business name at all. Only the parcel label prints the sender name, from the
  setting "Sender name on parcel labels" (Iqbal Store).
- Confirmations use `<x-confirm-modal>`, never the browser's `confirm()`.
- Never a native `<select>`: use `<x-simple-select>` (or `<x-search-select>` for long lists).
- Lists are cards on phones and a table on desktop; real pagination with 10 / 25 / 50 / 100 a page.
- Controls side by side have one height: full size 40px, small 32px (buttons, inputs, dropdowns; set in the
  shared components, so hand-made buttons use the same `min-h-10` / `min-h-8`).
- Page width by kind: lists, tables, reports, dashboards and detail pages use the full width; forms and
  one-task screens (upload, new call list, the calling screen, scan screens) keep a reading width.
- Search runs on Enter (not while typing); filters, sort, dates and page size stay after a reload.
- Downloads are always the same two buttons: "Print / PDF" (one print page: the browser prints it or saves a PDF)
  and Excel (`<x-export-buttons>`).
- Check every changed screen at phone, tablet and desktop width before handing it over.
- A new Tailwind class needs `npm run build`, and the built assets are committed.

### Data, security and servers
- Staging is `iqs.top5way.com`; deploy there freely. Production needs a fresh yes every time.
- Writing to a live database needs explicit permission every time.
- Secrets (API keys, client secrets, passwords) are typed by the owner in Settings, stored encrypted, never
  written in code, chat, docs or logs.
- Customer phone numbers follow each role's masking (`customer_contact` field mask).
- History imported from files is shown to people but never used on its own to trust a customer (section 4).

---

## 2. Modules and pages

Menu groups are in `config/menu.php` (an item shows only with its permission).

| Area | Pages (route names) | Controller | Main services |
|---|---|---|---|
| Search | `orders.search` (top bar box on every page) | OrderSearchController | Customers/CustomerFinder |
| Orders | `orders.index`, `orders.show`, `orders.create`, amendments, advance waiver | OrderController | Orders/OrderService, OrderStateMachine, OrderEditor, OrderCalculator, DeliveryCharges, DuplicateCheck, OrderMerger, OrderPresence |
| Order desk | `desk.index` (Order management), `desk.control` (Control room) | DeskController, TeamController | Orders/DeskService, Work/DeskRules |
| Order activity | `orders.activity` | OrderActivityController | Orders/OrderTimeline |
| Verification | `orders.verify`, `settings.verification*` | VerificationRuleController | Orders/VerificationEngine, Customers/FraudCheckService |
| Courier | `shipping.*` (booking, labels, resync), webhooks `webhooks.steadfast` | ShippingController, SteadfastWebhookController | Courier/BookingService, CourierUpdateProcessor, SteadfastDriver, PayoutImporter |
| Packaging | `packaging.*` (batches, scan, pack, issues) | PackagingController | Packaging/BatchService, ScanService, StockIssueService |
| Handover | `handover.*` (scan to rider, manifest) | HandoverController | Packaging/ScanService |
| Returns | `returns.*`, `returns-report.*` | ReturnController, ReturnReportController | Packaging/ReturnService |
| Parcel status (was Hotline) / delivery issues | `hotline.*`, `issues.*`, rider calls | HotlineController, RiderCallController | Orders/DeliveryIssueService, Work/RiderCallService |
| Payments | `payments.*`, `cod.*`, `refunds.*` | PaymentController, CodPayoutController, RefundController | Orders/RefundService, Courier/PayoutImporter |
| Customers | `customers.*` (list, one customer page with tabs, edit, merge, fraud check) | CustomerController | Customers/CustomerService, CustomerStats, CustomerJourney, FraudCheckService |
| Order history import | `customers.history*` (upload CSV/Excel, fix rows, undo) | CustomerImportController | Customers/HistoryImport, ImportedOrders |
| Call lists | `calls.lists*` (manager), `calls.mine`, `calls.work` (caller), `calls.proof` | CallListController | Calls/CallListService, CallProof |
| Customer calls | Communication popup (calls in), order page call box (calls out), `calls-report.index` | CustomerCallController | Calls/CallProof |
| Communication | chat mode, `chat-report.index`, `settings.chat-channels` | ChatController, ChatReportController | Work/ChatService |
| Catalog | `products.*`, `categories.*`, `products.import*`, `products.availability`, `products.stock*` | ProductController, CategoryController, ProductImportController, AvailabilityController, StockController | Catalog/ProductService, StockService, ChannelSync, WooApiImporter, WooCsvImporter |
| Website | `webhooks.woocommerce`, `orders.sync-website`, `settings.website-api*` | WooCommerceWebhookController, WebsiteApiController | Orders/WooOrderIntake, WooOrderSweep, Catalog/WooProductIntake |
| Marketing | `marketing.*`, `campaigns.*`, `usd-lots.*` | MarketingController, CampaignController, UsdLotController | Marketing/AdCostService, AdInsights, Reports/RoasReport |
| Reports | `dashboard`, `kpi.index`, `scorecard.*`, `work-time.index`, `packers-report.index`, `analysis.index` (Order P&L) | ReportController, ScorecardController, WorkTimeController, PackerReportController | Reports/KpiScorecard, MonthlyScorecard, WorkTime, PersonActivity, FreeTime, OutsideShift, OrderProfit |
| Points | `points.*`, `settings.points*` | PointsController | Points/PointsEngine, PointHooks |
| Team | `users.*`, `roles.*`, `attendance.*`, breaks | UserController, UserAccessController, RoleController, TeamController, BreakController | PermissionService, Work/BreakService, WorkCalendar, StaffHandover |
| Notifications | bell, `notifications.*`, `settings.notifications*`, Telegram | NotificationController, NotificationRuleController, TelegramController | NotificationService, Telegram/TelegramService |
| Settings | general, delivery charges, statuses and reasons, couriers, Google Drive, tracking, integrations | SettingsController, OrderSettingsController, CourierAccountController, GoogleDriveController, TrackingSettingsController, IntegrationController | SettingsService, Drive/GoogleDriveService, Tracking/TrackingService |
| Public | `legal.privacy`, `legal.terms` (no sign-in) | view routes | |

---

## 3. One source of truth for each job

Use these; never write a second version.

| Job | The one place |
|---|---|
| Change an order's status | `Orders/OrderStateMachine::transition()` (checks transitions, writes the note, runs listeners) |
| Decide Record verified / skip the call | `Orders/VerificationEngine` (rules are rows in `verification_rules`) |
| Rule: all conditions or any one; advance amount | `verification_rules.match_mode` (all / any), `advance_type` (delivery / fixed / percent) + `advance_value`, `VerificationEngine::advanceFor` |
| Website order set to Record verified on the website | `Orders/WebsiteStatusPush` (status slug `record-verified`, after the response on record_verified; `website_push_error` + Send to website on the desk, `orders.website-status`). The website then fires its own Meta Purchase, so untick Website in Settings > Tracking to avoid counting it twice |
| Customer counts (orders, delivered, returned) | `Customers/CustomerStats::refresh()` (section 4) |
| A customer's whole story (sales, timeline, channels) | `Customers/CustomerJourney::build()` |
| Find the customer a search is about | `Customers/CustomerFinder::find()` |
| Normalize or find phone numbers | `App\Support\Phone::normalize()` / `Phone::extract()` |
| Call recording or screenshot: check, keep, open | `Calls/CallProof` (`check`, `take`, `find`, `response`) |
| Move call files to Google Drive | `calls:proofs-to-drive` command (after each saved call, Move now, and the scheduler) |
| Google Drive access | `Drive/GoogleDriveService` (scope `drive.file`: only files IQS made) |
| Raise a notification | `NotificationService` |
| What a user may do | `PermissionService` (roles, overrides, field masks) |
| Settings values | `SettingsService` / `settings('key')` |
| Excel files | `App\Support\Xlsx::write()` |
| Order stages as the admin sees them | `Orders/OrderStages` |
| Delivery charge | `Orders/DeliveryCharges` (never typed by agents) |
| Stock changes | `Catalog/StockService` (a line in `stock_movements` for every change) |
| Product writes | `Catalog/ProductService` |
| Activity log | `ActivityLogger::log()` (`activity_log` table) |

---

## 4. Customer counts: what people see and what decides

Each customer row keeps two kinds of counts, both written only by `CustomerStats::refresh()`:

| Columns | Counts | Used by |
|---|---|---|
| `orders_count`, `delivered_count`, `returned_count` | IQS orders **and** orders imported from files | People: customer page, search card, order page, desk, customer list |
| `iqs_orders_count`, `iqs_delivered_count`, `iqs_returned_count` | Only what IQS saw itself (courier confirmed) | Decisions: verification rules, the internal fraud check, "new customer" |

`refresh()` runs after every status change (in the state machine), a new order, a merge, an import, an import fix,
an imported order edit or delete, and an Undo. Imported counts are also offered to the verification rules as
separate conditions ("Delivered / Returned orders in imported files") that an admin may choose to add.
**Why:** rows typed into a file must never make a customer look trusted.

---

## 5. Shared components

Blade components in `resources/views/components` (behaviour in Alpine). Use them; do not write one-off versions.

| Component | For |
|---|---|
| `x-layouts.app` | Page frame: sidebar, top bar with the one search box, toaster, call player |
| `x-list.filter-bar`, `x-list.table`, `x-list.cards`, `x-list.sort`, `x-list.pager` | Index pages (search on Enter, filters, sort, cards on phones) |
| `x-simple-select`, `x-search-select`, `x-dropdown` | Every dropdown (`x-simple-select` can show a channel icon per option and filter options with `only`) |
| `x-date-input`, `x-date-range` | Dates and date ranges with presets |
| `x-modal`, `x-confirm-modal`, `x-slide-over` | Popups and confirmations |
| `x-tabs` (with `tabs('key')`) | Underlined tabs; in-page tabs keep `#key` in the address |
| `x-card`, `x-stat-tile`, `x-badge`, `x-status-badge`, `x-order-status`, `x-empty-state`, `x-skeleton`, `x-record-card` | Building blocks |
| `x-export-buttons` + `x-print-page` | "Print / PDF" and Excel for any list (one clean print page, no browser header or footer) |
| `x-call-proof` | The one upload box for a call's recording or screenshot (all four call screens) |
| `x-play-call` + `x-call-player` | "Play call": recording or screenshot in a popup on the same page |
| `x-phone-dial` | Call a customer from an order (tap, copy, QR) and log the call |
| `x-call-live-bar` | The yellow bar of a started, unsaved call, on every page (in the layout) |
| `x-parcel-track` | A booked parcel: steps (Booked, With courier, Delivered / Returned), stuck states, Copy tracking link, Open (Parcel status, order page) |
| `x-courier-word` | What the courier last said ("Steadfast: Unknown") beside the IQS status: All orders, Order activity, Order management |
| `x-courier-resync` | Ask the courier now about every parcel on its way, with when it was last asked (once a minute) |
| `x-chat-control` | Communication popup: chats, customer calls in, rider calls |
| `x-channel-icon` | A chat channel's icon (WhatsApp, Messenger, Instagram...), `size="xs"` inside dropdowns |
| `x-new-order-popup` | The popup for New order and for looking at an order (`orders.show?embed=1`, Open in new tab) |
| `x-timeline` | An order's history notes |
| `x-scan-input` | Barcode scanning on packaging and handover screens |

---

## 6. Main tables

Full design in [DB_DESIGN.md](DB_DESIGN.md). Groups:

- **Orders:** `orders`, `order_items`, `order_versions`, `order_amendments`, `order_notes`, `order_events`, `order_assignments`, `order_payments`, `order_work_logs`
- **Configuration:** `order_statuses`, `order_status_transitions`, `status_reasons`, `delivery_zones`, `delivery_charge_rules`, `payment_methods`, `settings`
- **Verification:** `verification_rules`, `verification_rule_conditions`, `verification_runs`, `fraud_check_providers`, `customer_fraud_checks`
- **Customers:** `customers`, `customer_phones`, `customer_addresses`, `customer_credits`, `customer_imports`, `customer_past_orders`, `import_templates`
- **Calls:** `call_lists`, `call_list_user`, `call_list_items`, `customer_calls`, `rider_calls`, `google_drive`
- **Courier and delivery:** `shipments`, `shipment_labels`, `courier_events`, `courier_accounts`, `courier_payouts`, `delivery_issues`, `riders`, `handover_sessions`, `scan_logs`, `return_items`
- **Packaging:** `batches`, `stock_issue_reports`, `restock_releases`, `packer_shifts`
- **Catalog and stock:** `products`, `product_variants`, `variant_prices`, `price_lists`, `price_history`, `categories`, `availability_events`, `stock_movements`, `locations`, `product_imports`, `channel_product_links`, `channel_sync_jobs`, `website_accounts`, `integration_inbox`
- **Marketing:** `ad_accounts`, `ad_spend_daily`, `ad_insights`, `ad_vendors`, `usd_lots`, `usd_lot_allocations`, `vendor_payments`, `ad_cost_days`, `tracking_event_settings`, `tracking_event_logs`
- **Team:** `users`, `roles`, `permissions`, `role_permissions`, `role_field_masks`, `user_roles`, `user_permissions`, `work_schedules`, `office_schedules`, `work_days`, `staff_breaks`, `staff_salaries`, `staff_bonuses`, `kpi_targets`, `scorecard_closes`, `chat_channels`, `chat_channel_user`, `chat_sessions`, `chat_events`
- **Points and quality:** `point_rules`, `point_rule_conditions`, `point_ledger`, `integrity_flags`, `qa_reviews`
- **Notifications and audit:** `notification_types`, `notification_rules`, `app_notifications`, `notification_deliveries`, `user_notification_prefs`, `activity_log`

---

## 7. Main flows

**An order.** Website webhook (`WooOrderIntake`, with `WooOrderSweep` as the safety net) or a person
(`OrderService::create`) makes it New. `VerificationEngine` runs the rules: Record verified, Hold (advance),
or manual. The moderator calls (desk), then Confirmed. Courier booking (`BookingService`) gives the CN;
Packaging (batches, scan, pack); Handover scans it to the rider; the Steadfast webhook
(`CourierUpdateProcessor`) moves it to In transit, Delivered, Partial or Returned. Every status change goes
through `OrderStateMachine`, writes a note, fires listeners (points, notifications, tracking) and recounts
the customer.

**A customer.** Found by the one search (any phone form, CN, name). One page with tabs: Overview, Journey
(IQS orders, imported orders and calls in time order), Orders, Calls. Duplicates are merged on that page.
Past orders come in from files (Customers > Import order history) with fix, delete and Undo.

**A call list.** A manager makes a list from an imported file (filters: status, dates) and gives it to
callers with a daily target. Callers share the pool: Start calling gives the next number (held 30 minutes),
they record what happened, satisfaction and topics, and upload the recording or screenshot (`x-call-proof`,
checked by `CallProof`). The file goes to Google Drive right after saving. Managers follow the list in tabs
(Overview by dates, Numbers with filters, Settings) and download Print / PDF or Excel. Callers see today and
their call-backs under My calls; their history is its own page (My call history).

**One way to log a call, everywhere.** The call list, the order page (`x-phone-dial`, a panel over the page) and
Communication > Customer calls (a customer called or I will call; the number from the dial pad, Paste or a recent customer; a new caller can be saved as a customer) ask the same things: what happened
(or why they called), how happy, the recording or screenshot (any file, any result), a note. Nobody types a
duration: it is the recording's own length, else the clock since the call started. A started, unsaved call
follows the caller to every page (`x-call-live-bar`, kept in the browser) until it is saved.

**Sales report** (`SalesReport`, Analysis): Confirmed, Packed and Delivered side by side for the dates (each on its
own day), orders, money and kg / litres, up or down against the same number of days before, and what became of
them since (now cancelled, on hold, returned; an order held and confirmed again counts once). Below, for the chosen
step: where the orders came from (`channels()`: per channel, returning = an earlier order of the customer was
delivered, else new), bars by day (by month past 62 days), top items (out-of-stock flagged), categories. Every bar
and row opens its orders in one popup (`sales-report.orders` → `orders()`), an order opens inside it (desk embed);
All items opens there too. Print / PDF and Excel give the step's items. Tab **My list** (`?tab=mine`,
`sales_watch_items`, one list per person): watched products and categories with orders, pieces and money today, last 7
and last 30 days per channel and in total, plus stock now (`SalesReport::watch`, two grouped queries). How much
an item holds is `product_variants.size_qty / size_unit` (grams or ml), read from the names (`Units::sizeFromName`).

**Pause packaging** (`Packaging/PackagingPause`, `packaging_pauses`): a manager stops new orders reaching the packers'
list; calls, confirming and booking go on. Orders booked while paused stay Ready for packaging but are hidden from the
packers' list and To pack count (the packers are not told). Resume sets their packaging_sent_at to now.

**One definition per number** (audit of 19 Oct, `docs/AUDIT_2026-10-19.md`): confirmed / packed / delivered counts come
from `SalesReport::count` (first event per order; packed by packed_at), per-person delivered and return rates from
`KpiScorecard` (credited to who confirmed; partial counts as delivered), "waiting to be taken" from
`DeskService::whereWaiting`, the packers' list from `Packaging/PackingQueue`, status lists from `OrderStatus::group`,
phone search from `Phone::searchPrefix`, "at work" from `DeskService::atWork`. Do not write a second copy of any of these.

**Packing sheet + label on** (`/packaging/sheet`, `shipment_labels.print_run/applied_at`): each label print gets a
print_run; the A4 sheet lists those orders in sticker order (label ID, items, COD, Packed and Label on boxes). The first
scan of a label marks it applied. Edits while packing need no manager (edit_policy free for ready_for_packaging, packed,
ready_for_pickup); OrderEditor sends an urgent order_needs_repack telling the packer: repack / replace the label /
print the new label, with the old and new label IDs. Scanning an old label names the new one.
Printing from the packaging list: Print on a row (one order, again if needed), tick rows + Print selected (several,
one print_run so the A4 sheet matches), or Print new labels (every label not printed yet). Every print, including
Courier booking's shipping.labels, goes through `Packaging/LabelPrinter` (current label first, one print_run).
The pause hides paused orders everywhere the packers look: list, JSON preview, A4 sheet, batch pick list, shop
Telegram digest and end-of-day summary. A packer may hold only the order they scanned (packaging group only);
hold-limit and advance-waiver requests are never decided by the person who asked.

**Upsell** (`Reports/UpsellReport`, `/upsell`): on a website order, while it is verified by call (New, Record verified,
No answer at the time of the edit), items added or more of one and the total went up; it counts for whoever made the
edit (order_amendments.requested_by). Entry and price fixes are not upsells. Earned = delivered; cancelled or returned
= lost. Managers (analysis.view) see everyone; staff see their own (also on My points).

**Delivery issues, who has them** (`DeliveryIssueService::whoHandles`, `reroute`): the order's person at work, else the
colleague at work with the fewest open issues, else managers; on a break or off shift they move on. Anyone working orders
may solve any open issue; solving a colleague's order's issue earns +4 (`issue_solved`).

**More holds** (`HoldLimitController`, `users.desk_hold_limit`): at the hold limit a person asks with a reason, a manager
sets their own number (More holds page, or Staff page).

**Ending a hold, one rule** (`OrderService::resumeTarget`): advance paid or allowed, stock back, or the button in Order
management. Confirmed when the order was confirmed before the hold, or was taken in a chat or call (confirmed in that
talk) with no rule asking for a person and no other open order of the customer; else Call (a website order nobody
has confirmed with the customer is never booked before a call).

**Communication stops by itself** after 15 minutes with no chat tap, call or order action (`ChatService::IDLE_MINUTES`),
counted to the last action + 5 minutes. It is checked when the person's page opens and before a tap, so it
works without cron; the cron (`desk:tick`) checks everyone too.

---

## 8. Known overlaps to review

Places that may do the same job twice; to be checked and merged where they are real duplicates:
Order activity / Order management / Control room / All orders; Parcel status (was Hotline) / Delivery issues / rider calls;
Returns / Return report; KPI / Scorecard / Team Activity / Packers / My points; Stock count / Stock status;
the call screens now share one way of logging a call (see section 7). Rider calls: Parcel status and Communication > Rider calls save through the same RiderCallService (one parcel lookup, one rider_calls table).

---

## Credits

Built by the **Top5Way** team.

| Role | Name |
|---|---|
| Idea and product owner | Abdul Kadher Akib |
| Lead developer | Hijbullah |
| Developer | Enan |
| Junior developers | Mitun, Tonmoy |
