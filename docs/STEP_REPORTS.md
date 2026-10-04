# Step reports

Staging: https://iqs.top5way.com · login `akakib.work@gmail.com` (password as agreed in chat).
Every step is deployed to staging only. Production is not set up.

---

## Step 0: Foundation (done 2026-10-05)

### Built
| # | What |
|---|---|
| 0.1 | Laravel 12, Tailwind 4 + Alpine via Vite, timezone Asia/Dhaka, all UI strings wrapped in `__()` (English UI) |
| 0.2 | Login/logout with rate limit, forgot/reset password (email link), profile (name, phone), change password (logs out other devices) |
| 0.3 | Staff: list/add/edit, phone, employment type, work location, Telegram id, activate/deactivate (never delete), Owner resets passwords |
| 0.4 | Locations: warehouse / shop / virtual; seeded Riajuddin Bazar (shop) + Direct (virtual); bulk activate/deactivate |
| 0.5 | Roles & permissions engine (module × action, own/all scope, field masks, temporary roles, per-staff Allow/Deny with expiry, deny wins, new modules start not allowed, Owner = everything). Screens: Roles (matrix), Staff → Access. Owner-only management |
| 0.6 | Append-only activity log (before/after, secrets hidden) + Activity log page with filters |
| 0.7 | Courier driver interface, Fake driver (CN ids, moving status, stable fake fraud history), Steadfast stub; fake forced in testing/staging/local |
| 0.7b | Notifications: bell with unread/urgent count (45 s poll), dropdown, history page, alert matrix (Settings → Notifications), grouping, mute, Telegram/SMS queued as stubs |
| 0.8 | Settings: store name, logo, hotline, pickup cut-off times, packaging cost, duplicate window, freeze minutes, re-verify on edit |
| 0.9 | Database queue via cron, daily gzip backup (keeps 14, optional off-server disk), System health page |
| 0.10 | Component library (`/dev/components`, local only) |
| 0.11 | One index-page pattern (query-string state, filter bar → bottom sheet on phone, table/cards, sort, 25/50/100, bulk bar) |
| 0.12 | Hisab-style grouped sidebar, items hidden until the module exists and the user may view it |
| 0.13 | Lazy loading throws outside production, slow-query log (>200 ms) on staging/production, query-budget tests |

Tests: 86 passing. `php artisan migrate:fresh --seed` works.

### Skipped / needs you
- **hPanel cron (needed for queue, backups, clean-up):** hPanel → Advanced → Cron Jobs, every minute:
  `cd /home/u630385032/domains/iqs.top5way.com/public_html && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1`
  System health shows "Not running" until this exists.
- **Mailbox for password-reset emails:** create one (e.g. noreply@top5way.com); I then put SMTP in the server `.env`.
- **Off-server backup target:** tell me where (FTP, Google Drive, another server). Backups are server-only for now.
- 2FA (needs a package; you said not now). Laravel Debugbar (needs a package; query-budget tests used instead).
- Telegram/SMS sending is a stub (real Telegram bot comes in Step 4).

### 10-line manual test checklist
1. Log in on staging; log out; wrong password 5 times shows the wait message.
2. Forgot password page answers the same for any email.
3. Profile: change your phone, then your password.
4. Staff → Add staff (no role) → log in as them: only Dashboard is visible, Staff page gives 403.
5. Roles → Moderator → tick Staff: View → that person now sees Staff (no Add/Edit buttons).
6. Staff → Access → give a temporary role ending in 5 minutes; after 5 minutes access is gone.
7. Access → Deny one permission the role allows → it disappears for them.
8. Activity log shows every change above with before/after.
9. Settings → Notifications → "Send me a test" → the bell shows 1.
10. On your phone: Staff list shows cards, "Filters" opens a bottom sheet, the bell dropdown fits the screen.

---

## Step 1: Products + Customers (done 2026-10-05)

### Built
| Part | What |
|---|---|
| 1a | Categories (tree), Products (description, short description, **SEO title, meta description, focus keyword, slug**, image, gallery, tags, status), Variants (SKU, barcode, unit pcs/gram/box, pack size, ship weight, **cost price**), **3 price lists** (Online, Shop counter, B2B) each with **regular + sale (discount) price**, append-only **price history**, **Stock status** tab (In stock / Pre-order / Out of stock, bulk, expected date, review reminder, append-only events), product search for order entry (max 20, exact SKU/barcode first) |
| 1a | **One-way website sync**: every price / stock-status / content change queues an update for linked variants; latest values sent, repeated edits merged, Pre-order published as in stock, retry with backoff, Manager alert after 5 failures. Staging uses a fake website driver (nothing is sent to your real shop) |
| 1b | **Import from WooCommerce export CSV** (Products → Import from website): simple + variable + variations, Rank Math/Yoast SEO, categories, images, prices, stock status; matched by website id then SKU; runs in steps with a progress bar (no cron needed); never pushes back to the website |
| 1c | **Customers**: phone number is the key (+880/880/01 formats normalised), several numbers and addresses (district list, delivery zone), one number = one customer, **merge duplicates**, risk level (normal/watch/blocked), marketing consent, **fraud check** per provider (own history + Steadfast; fake on staging; cached 24 h; every check kept) |

Tests: 108 passing.

### Decisions I took (tell me if you want them different)
- Descriptions and SEO live here and go to the website (your instruction), so the website sync carries them.
- A role that hides **cost price** can neither see nor change it; a role that hides **customer contact** sees masked numbers and cannot edit customers.
- Delivery zones seeded as Inside Dhaka / Dhaka sub-area / Outside Dhaka (charges come in Step 2).
- Re-importing the CSV overwrites names, descriptions, SEO and online prices from the file, but keeps IQS-only fields (unit, pack size, barcode, stock status).

### Skipped / needs you
- Real WooCommerce push needs `WOO_URL`, `WOO_KEY`, `WOO_SECRET` in a production `.env` (staging stays fake on purpose).
- Real Steadfast fraud check needs the endpoint confirmed in Steadfast's in-panel API guide (spec: do not guess). Fake on staging.
- Thana list is free text (64 districts are suggested); Steadfast thana ids come with Step 3.

### 10-line manual test checklist
1. Products → Import from website → upload your Woo export CSV → watch the progress bar reach Finished.
2. Open an imported variable product: variants, SEO, category and images are there.
3. Edit a price (online sale price) → save → the "Price history" box at the bottom of the product shows old → new.
4. Products → Stock status → mark two variants Out of stock with an expected date.
5. Search box on Products finds by name, SKU or barcode.
6. Customers → New customer with +880 number → it is saved as 01XXXXXXXXX.
7. Try another customer with the same number → blocked with the owner's name.
8. Customer page → Check now → Steadfast (fake) and own history appear.
9. Merge a duplicate by its phone → numbers and addresses move over.
10. Give a role "Hide customer contact" → that person sees 017******78 and cannot edit.

---

## Step 2: Order intake (done 2026-10-05)

### Built
| Part | What |
|---|---|
| 2a | Statuses, transitions, reasons (with "whose fault"), delivery-charge rules and payment methods are **database rows** with defaults. **One state machine** is the only way a status changes: checks permission, required reason, system-only steps (packing/handover/courier) and the gate "no packing without a consignment ID"; every change writes an event (for KPI/points) and a timeline note. Optimistic lock: two people on one order cannot overwrite each other |
| 2b | **Quick order** for Messenger/WhatsApp/phone/B2B: phone lookup fills the customer and saved addresses, product search (Enter to add), list prices (agents cannot change prices), discount above the limit needs a manager, live total + delivery charge from rules, advance payment (counts only after a manager verifies; same TrxID cannot be used twice), possible-duplicate flag. **Orders list**: To take / Mine / All, filters, search by order no/phone/name. **Order page**: next-step buttons with reasons, timeline (calls, chats, notes, status changes), payments, owner change |
| 2b | **Your claim rule**: a new website order notifies Moderators; the first to press **Assign to me** owns it; one person works one order at a time (setting, default 1) until they confirm / hold / no-answer / cancel it; only a manager can change the owner, with a reason (history kept) |
| 2c | **Website orders by webhook** (signature-checked, duplicates ignored, works without a background worker): customer by phone, address, website prices and delivery charge, Facebook/UTM attribution; out-of-stock item → Hold. Failures show in Settings → Website connection with Retry |
| 2d | **Order edits**: every change is an amendment with reason and a new version; Confirmed = edit now, after booking = manager approval, after handover = locked. Edited after packing → **RED repack** (content) or relabel (address only) + urgent alert. Advance larger than new total → refund due |
| 2d | Settings → **Delivery charges** (zones, weight bands, free shipping over a total) and **Statuses & reasons** |

Tests: 141 passing.

### Decisions I took
- Order numbers look like **IQ10001**.
- Website orders keep the website's prices, discount and delivery charge (what the customer saw); chat orders use IQS prices and rules.
- "Working on" an order = owning one in New or Record verified. Change the limit in Settings.
- Default delivery charges seeded (Inside Dhaka 70/90/110, sub-area 100/130, outside 130/150/180 by weight). **Please check them** in Settings → Delivery charges.
- Moderators see their own orders plus orders waiting to be taken.

### Needs you
- To receive real website orders on staging: WooCommerce → Settings → Advanced → Webhooks → Add (Order created), URL and secret from **Settings → Website connection**. Only do this when you want test orders flowing in from the live site.

### 10-line manual test checklist
1. Settings → Delivery charges: check the prices; add "free over ৳3000" if you offer it.
2. Orders → Quick order: type an existing customer's phone → name and address fill in.
3. Add 2 products by search, see delivery charge change with weight, create the order.
4. Order page: Record verified (as Owner) → Confirmed; see each step in the timeline.
5. Add a call note; it appears in the timeline with your name and time.
6. Make a Moderator, create a website-style order (or use the webhook), log in as the Moderator: it is in "To take"; Assign to me; a second one is refused until the first is confirmed.
7. Edit a confirmed order: change quantity with reason "Customer request" → version 2, note shows the difference.
8. Try a discount above ৳200 as a Moderator → refused; as Owner → allowed.
9. Add an advance with bKash TrxID → COD changes only after Verify.
10. Settings → Statuses & reasons: rename "No answer" → the new name shows on orders.

---

## Step 3: Confirmation and courier (done 2026-10-05)

### Built
| Part | What |
|---|---|
| 3a | **Verification rules** (Settings → Verification rules): every new order is checked automatically (courier history per provider with a minimum parcel count, own history, COD, advance %, duplicate, blocked, pre-order). Outcomes: Record verified / **auto-confirm (skip the call)** / manual review / Hold for advance. Every decision stores its inputs ("why was this auto-confirmed?"). Test any order without changing it. On Confirmed: cost price frozen; out-of-stock → Hold; pre-order → Hold with expected date, pre-order limit enforced |
| 3b | **Call queue**: tap-to-call, Take next, one-click outcomes. A website order can only be confirmed by hand after a logged call. No answer stops after 3 tries (setting) |
| 3c | **Meta Conversions API Purchase**: you choose when it fires (created / verified / confirmed / delivered), value and channels; fires once per order; event ID matches the website pixel (setting). Test mode until production + keys |
| 3d | **Courier booking**: select confirmed orders → Book and print. Shipment + label (barcode = order no + version) → Ready for packaging. 4×3 inch labels with Code 128 barcode, COD, recipient, items. New label voids the old one |
| 3e | **Steadfast webhook** (Bearer token) + re-sync every 30 min: in transit / delivered / partial / returned update orders and customer counts; hold and approval-pending open a delivery issue for the owner |
| 3f | **Rider hotline** (find by CN / phone / order no / name; solve small things here) and **Delivery issues** for the owner with SLA; overdue issues go to managers |

Tests: 168 passing.

### Decisions I took
- If the handover scan is skipped, a courier pick-up still moves the order to In transit (with a note) instead of leaving it stuck.
- A courier "cancelled" becomes Returned with "reason not set yet" plus an issue for the owner to record the real reason.
- Seeded verification rules are the examples from your design; **please review the numbers** (80%, 3 parcels, ৳1,500).

### Needs you
- Real Steadfast: `STEADFAST_API_KEY`, `STEADFAST_SECRET_KEY` (production only), and the webhook URL + token from Settings → Website connection in the Steadfast panel. The Steadfast **fraud-check** endpoint must be confirmed from their in-panel API guide before I wire it (spec rule).
- Meta: `META_PIXEL_ID`, `META_CAPI_TOKEN` in production, and the event ID your website pixel uses (Settings → General → Tracking).

### 10-line manual test checklist
1. Settings → Verification rules → Test on an order: see which rule matches and why.
2. Create a chat order for a phone ending in 9 with total under ৳1,500 → it becomes Record verified automatically.
3. Orders → Call queue → Take next → Confirmed.
4. Courier booking → tick the order → Book and print → label prints with barcode.
5. Edit that order's address → Courier booking shows "Label out of date" → New label.
6. Courier booking → Re-sync (fake courier moves parcels along over ~2 hours) → order becomes Delivered.
7. Hotline → search the CN → open a "Partial delivery" issue → the owner gets an urgent bell.
8. Delivery issues → close it as Delivered → bell highlight clears.
9. Settings → Ad tracking → see the Purchase event logged (test mode).
10. Settings → Website connection → Steadfast URL and token are there for the Steadfast panel.

---

## Step 4: Packing and dispatch (done 2026-10-05)

### Built
- **Batches + pick list**: Packing → Release batch. One list per item, totals across orders, **sorted by shelf** (new "Shelf" field on each variant). The bot posts it to the shop Telegram group with **Picked** and **Item missing** buttons. **No customer phone or address** reaches the shop.
- **Scan to pack**: scan the label → Packed. Refuses unknown/old labels, cancelled or held orders, and orders with a missing-item report. **Edited after packing → RED with the exact difference** (e.g. "Dates ×2 → ×3"). After repacking and scanning the **new** label → purple "Edited".
- **Handover**: start with rider name/phone, scan every parcel. Catches **scanned twice**, repack needed, new label needed, **COD not updated at Steadfast**. **Printable manifest** with COD total, signatures, and parcels ready but **not** handed over.
- **Missing item**: packers only report (web or Telegram). The order is skipped. Admin chooses Out of stock / Pre-order / "it is there".
- **Automatic holds**: marking an item Out of stock or Pre-order puts open, unpacked orders on Hold and tells the owner. Back in stock → waiting orders are released **first come, first served**.
- **Telegram**: button presses count for the person whose Telegram ID is on their staff profile. Mini App sign-in is checked with Telegram's signature. Urgent alerts can go to Telegram (Settings → Notifications). End-of-day shop summary at 21:00.

Tests: 176 passing.

### Skipped / needs you
- **Telegram bot**: create one with @BotFather and send me the token, the shop group chat id and your own chat id. Until then everything runs in fake mode (logged only).
- Staff need their **Telegram user ID** on their profile (Staff → Edit) for buttons to count.
- Not built yet: the 30-minute pre-pickup **freeze** rule, and the pinned live summary that the bot edits in place.

### 10-line manual test checklist
1. Products → edit a product → give each variant a Shelf (A1, B2…).
2. Book two orders (Courier booking) → Packing → Release batch → open the batch: pick list sorted by shelf.
3. Print labels → Packing → Open scan station → scan (or type) a label + Enter → green "Packed".
4. Scan it again → orange "Already packed".
5. Edit that order's quantity (manager) → scan the old label → RED with the difference.
6. Courier booking → New label → scan the new label → purple "Repack done".
7. Handover → Start → scan both → scan one twice → "Scanned twice".
8. Finish → manifest shows the COD total and anything not handed over.
9. Batch page → "Not found" on an item → Packing → Missing-item reports → Out of stock → that order goes on Hold.
10. Products → Stock status → mark it In stock again → the held pre-order orders return to Confirmed.
