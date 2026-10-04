# Iqbal OMS: Database Design

> Extracted from the PDF the owner shared (IQS_DB_Design-2.pdf). A few Bangla labels did not survive PDF text extraction; the meaning is in the English around them.

```text
Iqbal OMS — Database Design (Order, Verification,
Payments, Tracking Events)
Save as docs/DB_DESIGN.md in the project. CLAUDE.md should say:
"Follow docs/DB_DESIGN.md for all order-related tables."
Single business. Timezone Asia/Dhaka. Money DECIMAL(12,2).
Quantities DECIMAL(12,3) (loose goods are sold by gram).
Every table has created_at, updated_at unless marked append-only (then only
created_at).
0. Design rules
1. Everything that the business may want to change is a row, not code.
Statuses, transitions, reasons, verification rules, fraud providers,
event-firing timing, delivery charges — all live in tables with admin screens.
2. Code depends only on system_keys, never on names or IDs.
Custom statuses/rules can be added freely; core gates (e.g. "no packing
without CN ID") are attached to system keys and cannot be bypassed.
3. Orders are versioned. Every edit creates a new version snapshot.
Nothing about an old version is ever overwritten.
4. Every change writes an automatic note (who, when — date and time,
which stage, what changed). Notes from the system are not editable.
5. Every automatic decision is explainable. When a rule auto-verifies an
order, the exact inputs and the rule that matched are stored.
1. Configuration tables
1.1 Custom statuses
order_statuses
  id                 bigint PK
  system_key         varchar(50) NULL UNIQUE   -- 'new','record_verified','confirmed',
                                               -- 'ready_for_packaging','packed',
Custom status rule: a custom status must belong to a stage_group and
can only be reached through transitions an Owner defines. The state machine
still enforces system gates — e.g. any transition INTO a fulfillment-group
status requires an active shipment with a CN ID.
                                               -- 'ready_for_pickup','handed_over',
                                               -- 'in_transit','delivered','partial_delivered',
                                               -- 'returned','no_answer','hold','cancelled'
                                               -- (no 'repack' status: repack is a flag, see 3.4)
                                               -- NULL for custom statuses
  name_bn            varchar(100)
  name_en            varchar(100)
  color              varchar(20)
  stage_group        enum('intake','verification','fulfillment','courier','final','side')
  is_system          bool                      -- system statuses cannot be deleted
  is_final           bool
  requires_reason    bool
  edit_policy        enum('free','approval','locked')  -- can an order be edited in this status?
  counts_as_sale     bool                      -- for reports
  sort_order         int
  is_active          bool
order_status_transitions
  id                 bigint PK
  from_status_id     FK order_statuses
  to_status_id       FK order_statuses
  permission_key     varchar(100)  -- e.g. 'orders.transition.confirm'
  requires_reason    bool
  requires_approval  bool
  system_only        bool          -- only rules/webhooks may do it (e.g. delivered)
  is_active          bool
  UNIQUE(from_status_id, to_status_id)
status_reasons
  id                 bigint PK
  status_id          FK order_statuses NULL   -- NULL = usable for amendments/returns
  reason_type        enum('status','amendment','return','cancel','hold','refund')
  label_bn           varchar(150)
  blame_stage        enum('none','sales','verification','packing','dispatch','courier','customer') NULL
  system_key         varchar(50) NULL   -- e.g. 'awaiting_stock','stock_out','scheduled'
  release_mode       enum('manual','on_restock','on_date') default 'manual'
                     -- how an order leaves Hold with this reason
  is_active          bool
1.2 Fraud-check providers (multiple vendors)
internal = our own history (delivered/returned counts in our DB). Each
driver returns a normalised result: total parcels, delivered, cancelled,
success rate, raw response. Add a new vendor = add a driver class + a row.
customer_fraud_checks            -- append-only
  id                 bigint PK
  customer_id        FK customers
  provider_id        FK fraud_check_providers
  phone              varchar(15)
  total_parcels      int NULL
  delivered          int NULL
  cancelled          int NULL
  success_rate       decimal(5,2) NULL   -- NULL when total_parcels = 0
  raw_response       json
  checked_at         timestamp
  INDEX(customer_id, provider_id, checked_at)
1.3 Auto Record Verified rules (fully configurable)
fraud_check_providers
  id                 bigint PK
  system_key         varchar(50) UNIQUE  -- 'steadfast','pathao','redx','internal', ...
  name               varchar(100)
  driver_class       varchar(200)        -- PHP class implementing FraudCheckDriver
  credentials        text (encrypted)
  cache_hours        int                 -- reuse a result for this long (rate limits)
  is_active          bool
  sort_order         int
verification_rules
  id                 bigint PK
  name               varchar(150)        -- "Good Steadfast history", "New customer, small COD"
  priority           int                 -- lower runs first
  outcome            enum('record_verified',
                          'record_verified_and_confirmed',  -- skip the call
                          'manual_review',
                          'hold_for_advance')               -- ask advance payment
  applies_to_channel enum('all','web','messenger','whatsapp','phone')
  stop_on_match      bool default true
  is_active          bool
Example rows (numbers are yours to set):
Rule Conditions Outcome
Blocked
customer customer_is_blocked = true manual_review
Good
Steadfast
history
provider_success_rate(steadfast) >= 80
AND provider_total_parcels(steadfast) >=
3
record_verified
Trusted
repeat
customer
own_delivered_count >= 2 AND
own_return_count = 0 record_verified_and_confirmed
Fully
prepaid advance_paid_percent >= 100 record_verified_and_confirmed
New
customer,
small order
is_new_customer = true AND
cod_amount <= 1500 record_verified
verification_rule_conditions     -- all conditions of a rule must pass (AND)
  id                 bigint PK
  rule_id            FK verification_rules
  field              enum(
                       'provider_success_rate',     -- needs provider_id
                       'provider_total_parcels',    -- needs provider_id
                       'any_provider_success_rate', -- best of active providers
                       'all_providers_success_rate',-- every active provider must pass
                       'is_new_customer',           -- no delivered/returned history anywhere
                       'own_delivered_count',
                       'own_return_count',
                       'order_total',
                       'cod_amount',
                       'advance_paid_amount',
                       'advance_paid_percent',
                       'phone_valid',
                       'address_thana_matched',
                       'is_duplicate_within_hours',
                       'customer_is_blocked',
                       'has_backorder_item')
  provider_id        FK fraud_check_providers NULL
  operator           enum('>=','<=','=','!=','>','<')
  value              varchar(50)        -- '80', '3', 'true'
New
customer,
big order
is_new_customer = true AND
cod_amount > 1500 hold_for_advance
Fallback (no conditions) manual_review
Why provider_total_parcels matters: 1 delivered out of 1 is "100%"
but says almost nothing. Always pair a success-rate condition with a minimum
parcel count.
verification_runs                -- append-only, explains every auto decision
  id                 bigint PK
  order_id           FK orders
  matched_rule_id    FK verification_rules NULL
  outcome            varchar(50)
  inputs_snapshot    json        -- every field value the rules saw
  ran_by             enum('system','user')
  user_id            FK users NULL   -- when someone re-runs it manually
  created_at
Rules run when the order is created and again if the customer phone or
amount changes. Staff can always override with permission (logged).
1.4 Purchase event timing (your decision, per platform)
tracking_event_settings
  id                 bigint PK
  platform           enum('meta_capi','ga4','tiktok')
  event_name         varchar(50)          -- 'Purchase'
  fire_on            enum('order_created','record_verified','confirmed','delivered')
  value_basis        enum('order_total','product_subtotal','delivered_amount')
  channels           json                 -- ['web','messenger','whatsapp','phone']
  is_active          bool
  UNIQUE(platform, event_name)
tracking_event_logs              -- one row per attempt; append-only
  id                 bigint PK
  order_id           FK orders
  platform           varchar(30)
  event_name         varchar(50)
  event_id           varchar(100)     -- shared with browser pixel for dedupe
  event_time         timestamp
  value              decimal(12,2)
  status             enum('queued','sent','failed','skipped')
Two technical facts to design around:
If the website pixel ALSO fires Purchase on the thank-you page, both must
use the same event_id, otherwise every sale is counted twice. If you
choose fire_on = delivered, turn the browser Purchase off (or rename it),
because there is nothing to deduplicate against days later.
Meta's Conversions API accepts events with an event_time only up to
7 days in the past. For delivered, send the delivery time as event_time,
not the order time.
1.5 Other configuration
2. Customers
customers
  id                 bigint PK
  name               varchar(150)
  primary_phone      varchar(15) UNIQUE      -- normalised 01XXXXXXXXX
  messenger_psid     varchar(64) NULL
  whatsapp_number    varchar(15) NULL
  risk_level         enum('normal','watch','blocked') default 'normal'
  blocked_reason     varchar(255) NULL
  request_payload    json
  response           json NULL
  created_at
  UNIQUE(order_id, platform, event_name, status='sent')  -- enforce in code: fire once
settings                          -- typed key-value for global toggles
  key                varchar(100) PK   -- 'verification.rerun_on_edit', 'orders.duplicate_window_hours'
  value              text
  type               enum('bool','int','decimal','string','json')
  updated_by         FK users
delivery_zones                    -- inside city / outside city / sub-areas
  id, name, is_active
delivery_charge_rules
  id, zone_id FK, min_weight_g, max_weight_g, min_order_total, charge, is_active, priority
payment_methods
  id, system_key ('bkash','nagad','rocket','bank','cash','card'), name, requires_trx_id bool, is_active
  orders_count       int default 0           -- denormalised counters,
  delivered_count    int default 0           -- updated by events
  returned_count     int default 0
  first_order_at     timestamp NULL
  marketing_consent  bool default false
  consent_at         timestamp NULL
customer_phones                   -- alternative numbers
  id, customer_id FK, phone UNIQUE, label
customer_addresses
  id, customer_id FK, address_line, district, thana, steadfast_thana_id NULL,
  zone_id FK delivery_zones, is_default
3. Orders
orders
  id                 bigint PK
  order_no           varchar(30) UNIQUE      -- also Steadfast invoice
  channel            enum('web','messenger','whatsapp','phone','b2b')
  external_ref       varchar(100) NULL       -- WooCommerce order ID
  customer_id        FK customers
  status_id          FK order_statuses
  owner_id           FK users NULL
  created_by         FK users NULL           -- NULL for website orders
  current_version    int default 1
  packed_version     int NULL                -- the version that is physically in the box
  edited_after_pack  bool default false      -- true once any edit happened after packing
                                             -- (drives the "edited" colour, see 3.4)
  -- delivery snapshot (copied from address at creation; editable via amendment)
  ship_name, ship_phone, ship_alt_phone, ship_address, ship_district, ship_thana,
  zone_id            FK delivery_zones
  -- money (always recomputed from items + payments, never typed)
  subtotal           decimal(12,2)
  discount_total     decimal(12,2)
  delivery_charge    decimal(12,2)
  grand_total        decimal(12,2)
  advance_verified   decimal(12,2) default 0 -- sum of verified advance payments
  cod_amount         decimal(12,2)           -- grand_total - advance_verified (min 0)
  refund_due         decimal(12,2) default 0 -- when advance > new total
  payment_status     enum('unpaid','partial_advance','fully_prepaid','cod_collected',
                          'refund_due','refunded')
3.1 Edits = versions + amendments + automatic note
  -- attribution
  source_campaign    varchar(150) NULL
  utm                json NULL
  fbp, fbc           varchar(255) NULL       -- for CAPI matching
  -- fulfilment links
  batch_id           FK batches NULL
  active_shipment_id FK shipments NULL
  -- verification
  verification_rule_id FK verification_rules NULL
  verified_at        timestamp NULL
  confirmed_at       timestamp NULL
  lock_version       int default 0           -- optimistic locking: two people
                                             -- editing the same order
  INDEX(status_id), INDEX(owner_id), INDEX(customer_id), INDEX(created_at)
order_items                       -- items of the CURRENT version only
  id                 bigint PK
  order_id           FK orders
  variant_id         FK product_variants
  name_snapshot      varchar(255)
  sku_snapshot       varchar(60)
  unit               enum('pcs','g','box')
  qty                decimal(12,3)
  unit_price         decimal(12,2)
  line_discount      decimal(12,2) default 0
  line_total         decimal(12,2)
  cost_price_snapshot decimal(12,2) NULL      -- frozen at confirmation (for P&L)
order_versions                    -- append-only full snapshot after every change
  id                 bigint PK
  order_id           FK orders
  version_no         int
  items_snapshot     json         -- all lines
  totals_snapshot    json         -- subtotal, discount, delivery, total, advance, cod
  shipping_snapshot  json
  created_by         FK users NULL
  created_at
  UNIQUE(order_id, version_no)
How an edit flows:
1. Staff changes items (add / remove / quantity / price / address).
2. System checks order_statuses.edit_policy of the current status:
free → apply now · approval → amendment pending until Manager approves
· locked → refused (partial delivery or a new order instead).
3. On apply: new order_versions row, order_items replaced, total weight,
delivery charge (from delivery_charge_rules), totals and COD recomputed,
current_version +1.
4. If total < verified advance → refund_due set + refund task.
5. If the order has a shipment → courier_action set (COD update / rebook /
reprint) and a task goes to the courier desk. If it was already packed,
the status does NOT change — the order becomes "needs repack" (red), see 3.4.
6. An automatic note is written, e.g.:
"Mahim · 05 Oct 2026, 2:32 PM · stage: Packed · KitKat 100g ×2 → ×3,
+৳1,000 · reason: customer request · approved by Pranto".
3.2 Notes and events
order_amendments
  id                 bigint PK
  order_id           FK orders
  from_version       int
  to_version         int NULL           -- set when applied
  status_at_time_id  FK order_statuses  -- which stage the order was in
  reason_id          FK status_reasons  -- customer request / stock out / entry error / price error
  changes            json               -- [{type:'added'|'removed'|'qty'|'price'|'address',
                                        --   item, from, to}]
  amount_diff        decimal(12,2)      -- + customer pays more / - less
  approval_status    enum('not_required','pending','approved','rejected')
  requested_by       FK users
  approved_by        FK users NULL
  approved_at        timestamp NULL
  courier_action     enum('none','update_cod','rebook','reprint_label') default 'none'
  courier_action_done_at timestamp NULL
  applied_at         timestamp NULL
order_notes                       -- append-only
  id                 bigint PK
  order_id           FK orders
  note_type          enum('manual','system','call','chat','rider','amendment',
                          'payment','status','courier','verification')
Every status change ALSO writes an order_notes row of type status, so the
order timeline reads as one story: status changes, edits, calls, payments,
rider notes — all with person, date and time, and stage.
order_assignments                 -- ownership history
  id, order_id FK, user_id FK, role enum('owner','temporary'),
  assigned_by FK users NULL, reason varchar(150), started_at, ended_at NULL
3.4 Edit after packing — red mark, repack, edited colour
Edit window: allowed in every status BEFORE handed_over.
From handed_over onward edit_policy = locked (Steadfast has the parcel).
Edit classes (stored on order_amendments.edit_class):
Class Examples After packing Mark
contentitem added / removed / quantity or
weight changed
open box, fix, new
label
RED
(repack)
label address, phone, COD (e.g. advance
verified)
box is fine, new
label only
ORANGE
(relabel)
internalnote, owner change nothing none
  body               text
  meta               json NULL           -- structured details (amendment id, call outcome …)
  status_at_time_id  FK order_statuses   -- stage when the note was written
  user_id            FK users NULL       -- NULL = system
  is_internal        bool default true
  created_at
  INDEX(order_id, created_at)
order_events                      -- append-only, status transitions only (KPI source)
  id                 bigint PK
  order_id           FK orders
  from_status_id     FK order_statuses NULL
  to_status_id       FK order_statuses
  source             enum('user','rule','webhook','scan','system')
  user_id            FK users NULL
  reason_id          FK status_reasons NULL
  created_at
  INDEX(order_id), INDEX(user_id, created_at), INDEX(to_status_id, created_at)
The system does not choose packaging; for content edits the packer just
sees "নতডBengআsignইuঅ_uiন কের পডBengআsignইyaাক কডBengআrইuন" + the difference + "new label".
Freeze rule: setting orders.freeze_minutes_before_pickup (e.g. 30) and
"handover scanning has started" both freeze the day. A content edit during
the freeze moves the order to the NEXT pickup (pickup_date + 1) and notifies
the owner. A Manager can override; the override is logged.
-- added columns
order_amendments.edit_class   enum('content','label','internal')
orders.pickup_date            date NULL      -- planned handover day
orders.label_version          int NULL       -- version of the active label
Mark logic: RED if packed_version < current_version and the newest unpacked
change is content; ORANGE if only label changes happened after the active
label (label_version < current_version); cleared by repack scan (RED) or
new-label scan (ORANGE).
The repack state is computed, not stored as a status:
Condition Meaning Colour on cards & scan
screens
packed_version IS NULL not packed yet normal
packed_version = current_version
AND edited_after_pack = false
packed, never
edited normal
packed_version < current_version
edited after
packing, box is
wrong
RED — needs repack
packed_version = current_version
AND edited_after_pack = true
edited and
repacked correctly
EDITED colour (e.g.
purple) + "Edited" badge
Flow:
1. Order is Packed at version 3 → packed_version = 3.
2. Someone edits it (still Packed or Ready for Pickup) → current_version = 4,
edited_after_pack = true → card turns RED in the packaging/pickup column.
Telegram alert to the shop with the difference (what to add / remove).
3. Scan at packing or handover while RED → scan FAILS with a red signal
and shows the difference between version 3 and version 4:
"এই অডডBengআrephaার edit হেয়েছ — KitKat ×২ → ×৩ কডBengআrইuন, নতডBengআsignইuঅ_uiন label লাগান".
4. Packer fixes the box, prints the new label, scans the NEW label →
packed_version = 4 → card leaves RED and shows the EDITED colour.
5. Handover scan now succeeds (EDITED colour shown so the dispatcher knows).
6. Edited again before pickup → current_version = 5 → RED again. The
difference is always shown against packed_version, not the original.
Every step writes an automatic order_notes row (who, date & time, stage).
3.5 Labels (old labels must stop working)
Scan validation (packing and handover):
Barcode not found → "Unknown label".
Label voided_at set → RED "পডBengআsignইuঅ_uiরেনা label — বদলান" (stops the old label on
an edited parcel from being handed over by mistake).
Label valid but packed_version < current_version → RED "edit হেয়েছ, repack কডBengআrইuন".
Label valid but only a label edit is pending → ORANGE "নতডBengআsignইuঅ_uiন label লাগান".
Any order_amendments.courier_action not done (COD update / rebook) →
RED "Steadfast-এ COD আপেডট বািক" (handover only). Rider must never carry
a parcel whose Steadfast COD differs from the order's COD.
Same barcode already scanned in this handover → "দডBengআsignইuঅ_uiবার scan হেয়েছ".
Otherwise → success (EDITED colour if edited_after_pack).
If the COD changes after booking and Steadfast cannot update COD for that
consignment, the courier desk cancels and rebooks: a new shipments row, the
old one is_active = false, and all its labels voided.
3.6 Advance payments
shipment_labels
  id                 bigint PK
  shipment_id        FK shipments
  order_version      int                -- which order version this label belongs to
  barcode            varchar(60) UNIQUE -- what is printed and scanned
  cod_on_label       decimal(12,2)
  printed_by         FK users
  printed_at         timestamp
  voided_at          timestamp NULL     -- set when a newer label replaces it
  void_reason        varchar(150) NULL
Rules:
Only verified advances reduce COD. A screenshot alone is pending_verification.
cod_amount = max(0, grand_total − advance_verified); recalculated on every
amendment and every payment verification; triggers update_cod if a
shipment exists.
Fully prepaid → booked with COD 0.
Advance amount/percent is available to verification rules.
Every payment action writes an order_notes row of type payment.
4. Courier, issues, raw inbox
order_payments
  id                 bigint PK
  order_id           FK orders
  payment_type       enum('advance','cod','refund','adjustment')
  method_id          FK payment_methods
  amount             decimal(12,2)
  transaction_id     varchar(100) NULL
  sender_number      varchar(15) NULL
  proof_path         varchar(255) NULL       -- screenshot
  status             enum('pending_verification','verified','rejected')
  verified_by        FK users NULL
  verified_at        timestamp NULL
  received_at        timestamp
  note               varchar(255) NULL
  UNIQUE(method_id, transaction_id)          -- same bKash TrxID cannot be used twice
shipments                         -- an order can have several (rebook, exchange)
  id                 bigint PK
  order_id           FK orders
  courier            varchar(30)          -- 'steadfast'
  consignment_id     varchar(50) NULL
  tracking_code      varchar(50) NULL
  cod_amount         decimal(12,2)
  courier_status     varchar(50) NULL
  delivery_charge    decimal(12,2) NULL
  booked_at, cancelled_at, final_at timestamp NULL
  is_active          bool
  UNIQUE(courier, consignment_id)
courier_events                    -- append-only raw webhook/pull data
4A. Availability (Out of Stock list) + website sync
Before the stock module exists, availability is a manual flag per variant.
When the stock module arrives (Step 7) the same fields are set automatically
at zero stock; manual marking stays (goods missing from the shelf).
Behaviour:
"Out of Stock" tab lists all out_of_stock variants (search, bulk mark /
unmark, expected date, who marked it and when).
Product search shows OUT OF STOCK and the variant cannot be added to an
order (Quick Order and amendments).
  id, shipment_id FK NULL, consignment_id, notification_type, payload json,
  idempotency_key UNIQUE NULL, processed_at NULL, created_at
delivery_issues
  id, order_id FK, shipment_id FK, issue_type enum('no_answer','partial','cancel',
  'exchange','address','other'), rider_phone, opened_by FK users NULL (NULL=webhook),
  assigned_to FK users, sla_due_at, resolution enum('delivered','rescheduled',
  'partial','returned','exchange_created') NULL, resolved_at NULL, created_at
integration_inbox                 -- idempotent intake of Woo webhooks etc.
  id, source ('woocommerce'), external_id, payload json, status, error NULL,
  received_at, processed_at NULL
  UNIQUE(source, external_id)
-- columns on product_variants
availability_status   enum('in_stock','backorder','out_of_stock') default 'in_stock'
                      -- backorder = "Pre-order" (internal only): still sellable, orders wait on Hold / awaiting_stock
backorder_limit_qty   decimal(12,3) NULL  -- max quantity to accept on pre-order
backorder_taken_qty   decimal(12,3) default 0
                      -- reaching the limit switches the variant to out_of_stock automatically
availability_source   enum('manual','auto') NULL
oos_marked_by         FK users NULL
oos_marked_at         timestamp NULL
expected_restock_date date NULL
oos_review_at         timestamp NULL   -- reminder: expected date, or marked_at + 7 days
availability_events               -- append-only
  id, variant_id FK, from_status, to_status, source enum('manual','auto'),
  user_id FK NULL, note, created_at
Marking a variant out of stock lists open orders containing it; orders not
yet Packed move to Hold (reason: stock out) and the owner gets a
"offer a substitute" task.
Website orders arriving with an out_of_stock variant (sync delay) go
straight to Hold with a red flag.
Permissions: ONLY Admin/Owner (or a role the Owner explicitly grants
products.availability.edit) can set In stock / Pre-order / Out of stock.
Packers and other staff cannot change availability. Reminder to the
marker at oos_review_at.
Packer finds an item missing on the shelf → presses "মাল পাওয়া যায়িন"
(report only). This creates a stock_issue_reports row and alerts Admin;
the order gets an "issue reported" flag and is skipped in packing. Admin
decides: mark Out of stock / Pre-order (order moves to Hold with the
matching reason) or dismiss ("মাল আেছ, অমডBengআsignইuঅ_uiক তােক").
stock_issue_reports
  id, order_id FK, variant_id FK, reported_by FK users, note NULL,
  status enum('open','marked_out_of_stock','marked_pre_order','dismissed'),
  resolved_by FK users NULL, resolved_at NULL, created_at
Hold (one status, behaviour by reason) + Pre-order
ONE status hold. Behaviour comes from the hold reason (status_reasons):
Reason (system_key) release_mode Exit
awaiting_stock ("Stock
আসেছ")
on_restock automatic FIFO release when stock
arrives
stock_out ("Stock ডBengআsignইeঅinitialনই") manual owner: substitute / remove item /
cancel
channel_product_links             -- our variant ↔ website product
  id, variant_id FK, channel enum('woocommerce','shopify'),
  external_product_id, external_variant_id NULL, last_synced_at NULL,
  last_sync_status enum('ok','failed') NULL
  UNIQUE(channel, external_product_id, external_variant_id)
channel_sync_jobs                 -- queued outbound updates
  id, variant_id FK, channel, field enum('stock_status','price','sale_price','status'),
  payload json, status enum('queued','sent','failed'), attempts int,
  last_error text NULL, created_at, sent_at NULL
scheduled ("িনডBengআsignইi_rephaদডBengআssইttaঅ_ui তািরেখ") on_date automatic release on
hold_expected_date
others manual owner decides
The Hold column has filter chips: All / Stock আসেছ / Stock ডBengআsignইeঅinitialনই / তািরখ.
Product availability (backorder is shown internally as "Pre-order";
the website shows it as in stock). The system decides at the moment Confirm
is pressed, from item availability:
all items in_stock → normal path (queue for Ready for Packaging)
any item backorder and none out_of_stock → hold / awaiting_stock
any item out_of_stock → hold / stock_out
Agents can also put any order on hold with reason awaiting_stock manually.
If nothing is marked and the packer cannot find the item, the packer only
reports it ("মাল পাওয়া যায়িন"); Admin updates availability, which moves the
order to hold (and creates a booking-cancel task if already booked).
The call queue shows a badge "Pre-order item · expected <date>" so the agent
tells the customer during the call. Customer refuses → amend (remove /
substitute) or cancel. If a variant becomes backorder AFTER confirmation,
unpacked confirmed orders move to hold / awaiting_stock automatically
and the owner gets a call task. Expected date passed → card turns red + alert.
Setting orders.backorder_auto_message (default off) sends an automatic
customer message; default off because the call covers it.
Hold · Stock আসেছ Hold · Stock /Beng:sign-e.initialনই
Cause Pre-order (backorder) variant, or
manual
out_of_stock variant (set by Admin),
or manual
Steadfast
booking not booked not booked (existing booking →
cancel task)
-- column on orders
hold_expected_date    date NULL      -- awaiting_stock: promised stock date; scheduled: release date
restock_releases                  -- append-only: what happened when stock arrived
  id, variant_id FK, qty_arrived decimal(12,3), released_order_ids json,
  remaining_waiting_qty decimal(12,3), user_id FK, created_at
Exit automatic FIFO release when
stock arrives
owner decides (substitute / remove /
cancel)
Owner task only if expected date passes immediately
When a variant changes status, the user chooses Out of stock or Pre-order;
open (unpacked) orders containing it go to Hold with reason stock_out or
awaiting_stock accordingly.
Restock: Store Keeper enters quantity arrived → system releases orders on
Hold / awaiting_stock for that variant FIFO (oldest first) until the quantity runs out →
released orders go
to the Confirmed queue, customers notified; the rest stay with
"still needed: X".
Expected date passed → alert owner + Manager.
Mixed order (some items available): default waits whole; owner may split
into two shipments (logged, extra delivery charge shown).
Verification rules may require advance for pre-order items
(condition field has_backorder_item).
Sync rules: one adapter per platform. The website does NOT show "pre-order":
a backorder variant is published exactly like an in-stock one.
WooCommerce: in_stock and backorder → instock; out_of_stock →
outofstock; plus prices.
Shopify: in_stock and backorder → sellable ("continue selling when out
of stock" on); out_of_stock → quantity 0 with continue selling off.
The website does not warn the customer; the confirmation call does. Price edits and
availability changes enqueue a
job; failures retry with backoff and alert the Manager after N attempts.
4B. Notifications (bell icon + delivery channels)
Every module raises notifications through ONE NotificationService; nothing
sends Telegram/SMS directly.
notification_types                -- catalogue of events (config, editable)
  id, system_key UNIQUE           -- 'stock_issue_reported','order_needs_repack',
                                  -- 'delivery_issue','hold_expected_date_passed',
                                  -- 'amendment_pending_approval','courier_action_pending',
                                  -- 'sync_failed','oos_review_due','vendor_payment_due', ...
Behaviour:
Bell icon in the top bar with unread count; dropdown lists latest items,
filter All / Unread / Urgent, "mark all read", each item opens its link.
Shared hosting has no websockets: the bell polls every 30–60 seconds
(lightweight count endpoint). On VPS this can switch to Laravel Reverb
without changing the table design.
Urgent notifications (delivery issue, stock issue, repack before pickup)
stay highlighted until the underlying task is done (acted_at), not just read.
Repeated events are grouped by group_key so the bell does not flood.
Notifications are never deleted; they drop out of the dropdown after
30 days but stay in a full history page.
5. Other things added
Addition Why
  name_bn, default_priority enum('info','normal','urgent'),
  is_active
notification_rules                -- the alert matrix: who gets what, where
  id, type_id FK, target enum('role','user','order_owner','actor_manager'),
  role_id FK NULL, user_id FK NULL,
  channel_in_app bool default true, channel_telegram bool, channel_sms bool,
  is_active
notifications                     -- one row per recipient
  id, user_id FK, type_id FK, priority,
  title, body, link_url,          -- deep link to the order / product / task
  subject_type, subject_id,       -- e.g. order 9077
  group_key NULL,                 -- same event repeated → grouped ("৫টা অড /bnReph ার লাল")
  read_at NULL, acted_at NULL,    -- acted = the underlying task was done
  created_at
  INDEX(user_id, read_at, created_at)
notification_deliveries           -- append-only log per channel
  id, notification_id FK, channel enum('telegram','sms'),
  status enum('queued','sent','failed'), error NULL, sent_at NULL
user_notification_prefs
  user_id FK, type_id FK, mute_until NULL, quiet_hours json NULL
  -- urgent types cannot be muted
lock_version on orders
Two people editing the same order at once: the
second save is refused with "order changed,
reload" instead of silently overwriting
integration_inbox WooCommerce may send the same webhook twice;
it is processed once
Duplicate detection setting
(orders.duplicate_window_hours)
Same phone within N hours → flagged, merge
option
blame_stage on reasons Returns and amendments automatically deduct KPI
from the right stage only
customer_fraud_checks cache Avoid hitting vendor rate limits; old checks kept for
history
verification_runs Answer "why did this order skip the call?" months
later
shipments as a separate table Rebooking or exchange keeps the old CN ID history
refund_due + refund payments Advance larger than the edited total never gets lost
payment_methods.requires_trx_id
+ unique TrxID
Same bKash screenshot cannot be reused for two
orders
6. Admin screens this design needs (Owner / Manager)
1. Statuses & transitions — add custom status, choose stage group, colour,
edit policy, allowed transitions and who can do them.
2. Reasons — per status / amendment / return, with blame stage.
3. Verification rules — ordered list; each rule = conditions + outcome;
"Test on an order" button that shows which rule would match and why.
4. Fraud providers — enable/disable vendors, credentials, cache hours.
5. Tracking events — per platform: fire on (created / record verified /
confirmed / delivered), value basis, channels.
6. Delivery zones & charges, payment methods, general settings.
7. Notifications — which event goes to which role/user, on which
channel (in-app / Telegram / SMS), and priority.
All changes on these screens are written to activity_log.

```
