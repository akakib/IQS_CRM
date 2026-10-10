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

---

## Step 5: Points, KPI, dashboard and order P&L (done 2026-10-05)

### Built
- **Points engine** (speed + fewer mistakes). The event types are fixed in code. **Every value is set by the admin** in Settings → Points rules: plus or minus, who gets it, when it settles, and conditions. The rules page also has "Test on an order".
- **15 starting rules**, as agreed:
  - Took an order +1.
  - Confirmed +1, kept **only if delivered**.
  - Delivered +3; a risky order delivered gives +5 more.
  - Partial delivery +1.
  - Cancelled because of a sales mistake −2.
  - Returned because of a sales mistake or a packing mistake −3.
  - A risky order the customer refused −5.
  - Packed within 30 minutes of release +1.
  - Entry error fixed −1, or −2 after packing.
  - Order taken away for the person's own mistake −1.
  - Delivery issue not handled in time −1.
  - **Fake status −10**.
- **How points are given:**
  - Points stay **pending** until the order ends.
  - Each order, rule and person is counted once.
  - Each award keeps a copy of the rule as it was, so editing a rule never changes past points.
  - If the courier later turns a delivery into a return, the delivery points are taken back.
  - There is a **monthly minus cap** (default 50).
- **Fake-status protection:**
  - The system flags a web order confirmed less than a minute after it was taken.
  - A weekly **call-back check** picks 5 random orders per person to phone the customer.
  - Points review → Flags: the big minus is added **only when a manager confirms** the flag. Nobody can review their own flag.
- **My points** (everyone, under Overview): settled, waiting and removed points by month. A minus point can be **disputed** within 3 days; a manager keeps or removes it.
- **Trial month is ON**: points are recorded and shown but not used for bonus. Turn it off in Settings → General → Points.
- **KPI scorecard** (Analysis), separate from points:
  - Volume 40 / speed 20 / quality 40. The weights are in Settings.
  - Volume = how many orders the person confirmed.
  - Speed = the median time from taking an order to confirming it.
  - Quality = delivered ÷ (delivered + returned + cancelled).
- **Order P&L** (Analysis): orders that ended in the chosen period, grouped by day, channel, person, product or category.
  - Profit = revenue − product cost (the cost recorded at confirmation) − courier charge − COD fee (1%, in settings) − packaging.
  - **Ad cost is not included yet** (Step 6).
  - Profit and cost are hidden from roles with the profit or cost mask.
- **Dashboard tiles**:
  - Orders today, waiting to be taken, confirmed, packed and delivered today.
  - Open delivery issues.
  - Points to review (managers only).
  - My points.
  - The orders you are working on now.
- **Nightly owner summary** on Telegram at 22:00 (time is in settings).
- New permissions are **off for every role** until you tick them: points (manage), kpi, analysis, marketing.

Tests: 186 passing.

### Skipped / needs you
- Give managers **points.manage**, **kpi.view** and **analysis.view** in Roles.
- Bonus money from points is not built. During the trial month we only watch the numbers.
- The P&L product cost depends on each variant's cost price being filled in. Without it the cost counts as 0.

### 10-line manual test checklist
1. Settings → Points rules: change "Order delivered" to 4 → Save.
2. Take an order (Assign to me) → Confirm it → My points shows +1 Waiting.
3. Move it to Delivered (or let the fake courier deliver it) → the points become Settled.
4. Cancel another confirmed order with the reason "Entry mistake" → −2, and its confirm point shows Removed.
5. Points rules → Test on an order → enter that order number → shows which rules match and what was given.
6. Points review → Call-back check → mark one order "Not called" → it appears under Flags.
7. Flags → Confirm fake → that person gets −10. Log in as them → My points → Dispute.
8. Points review → Disputes → Remove the point → it shows Removed in their My points.
9. Analysis → KPI: the person who confirmed and delivered is listed with a score.
10. Analysis → Order P&L → switch between By day, By product and By person; log in with a role that has the profit mask → no profit column.

---

## Step 6: Ad cost, USD lots and ROAS (done 2026-10-05)

### Built
- **USD lots** (Marketing → USD lots):
  - Each lot records date, vendor, USD, rate and the taka total.
  - It can be paid now (method + transaction ID), part-paid, or have a due date.
  - Each vendor card shows what was bought, what was paid and what is still due.
  - **Pay a vendor** records a payment. Payments are never edited.
  - A reminder goes to the bell when a balance is due by tomorrow (10:00 daily).
- **Daily ad spend** per ad account (Meta or Google, days in the account's timezone):
  - **Meta**: pulled every 3 hours, always re-pulling the last 3 days. Without a token the numbers are **fake** (steady made-up figures).
  - **Google**: **CSV import** of the daily report (Day/Cost columns). It works for a Meta export too.
  - **Add a day by hand** is available for anything else.
- **FIFO**: spend uses the **oldest dollars first**, so each day gets its real taka cost. When a day changes (re-pull, import or a new lot), everything is re-costed from the start, so it never drifts.
  - Spend with no dollars left is shown in **red as "no lot"**, costed at the fallback rate (125 in settings) until you add the lot.
- **ROAS dashboard** (Marketing → Ad spend & ROAS), per day:
  - Spend in USD and real taka cost.
  - Messages.
  - Orders by channel (web / chat / other).
  - Delivered revenue.
  - Meta ROAS (what Meta reports) vs confirmed ROAS vs **delivered ROAS** (the real one).
  - MER (all placed revenue ÷ cost).
  - Cost per order and cost per message.
  - **Dollar balance**: bought − spent = left.
- **Order P&L now includes ad cost**:
  - A day's ad cost is shared equally over the orders placed that day (B2B excluded).
  - New tiles: Ad cost, Profit before ads, **Profit after ads** with margin %.
- The nightly owner summary now shows the day's ad cost and profit after ads.

Tests: 191 passing.

### Skipped / needs you
- **Meta ads token**: a System User token with `ads_read` → put it in the server `.env` as `META_ADS_TOKEN`. Then add the ad account with its `act_` ID. Until then spend is fake.
- **Google Ads API** needs an approved developer token. Until then, import the daily CSV.
- Give the marketing person **marketing.view** (and **marketing.create** to add lots and spend) in Roles.
- The **hPanel cron** (`schedule:run` every minute) is still not added. Spend pulls, reminders and summaries only run with it.
- Staging has no ad account yet. Add one on the Ad spend page and press **Pull spend now** to see fake numbers.

### 10-line manual test checklist
1. Marketing → USD lots → Add a vendor.
2. Add a lot: $100 at 122, paid ৳5,000 now by bKash with a transaction ID, rest due tomorrow → the vendor card shows ৳7,200 due.
3. Pay a vendor ৳7,200 → card says Paid up.
4. Marketing → Ad spend → Add account (Meta, any name) → Pull spend now → 3 days of fake spend appear.
5. Taka cost per day = USD × 122 while the lot lasts; when it runs out, "no lot" shows in red.
6. Add a second lot dated earlier → the days re-cost oldest dollars first.
7. Import CSV: a file with `Day,Cost` rows → those days appear (source import).
8. Create a few orders today → the day row shows the order count and the cost per order.
9. Deliver one → delivered revenue and delivered ROAS go up.
10. Analysis → Order P&L → Ad cost and Profit after ads tiles; a role with the profit mask does not see profit.

---

## Order management desk, packer portal, breaks (done 2026-10-05)

Built outside the numbered steps at the owner's request. It replaces the Call queue and "Assign to me".

### Built
- **Order management** (top of the Orders menu), in the app's own theme:
  - **Take next** gives the oldest waiting website order. Nobody picks from a list.
  - Tabs: Verify (only orders the rules sent to manual review), Call, Call again, On hold, To send, Packing.
  - Left: my orders in that stage. Middle: the order (phone with copy and QR to dial from a PC, customer history, duplicate check, items with stock, full history). Bottom: the actions for that stage.
  - Keys on a PC: J/K next and previous, the letter on a button presses it, T takes next, number keys pick a reason. On a phone: list, tap, detail.
- **Rules the desk enforces**
  - At most 5 website orders in hand (setting). Chat orders belong to whoever created them, with no timer, and do not use up the limit.
  - **10-minute action timer** on one order at a time (the oldest). Missed: the order goes back to New, it counts as "timed out" and costs a point.
  - **No response**: the order leaves the Call tab and comes back to the same moderator after 30 minutes, then 5 hours, then 24 hours (at the next shift start if that falls outside office hours). After the last one it is cancelled as "could not reach customer".
  - An order nobody takes in 15 minutes is given to the active moderator with the fewest orders. Nobody active: it waits and managers get an alert.
  - **Send to packing** is one button. The courier is booked right after the click (fake courier until the Steadfast key is set); the order reaches the packers only once it has a CN. Three failed bookings: the moderator and managers are told and a retry button shows.
- **Packer portal** (Packing in the menu), built for a phone or tablet:
  - Admin sets today's on-duty packers. All of them share one queue: repack (red) first, then new label (orange), then oldest.
  - Scanning the label makes the order yours and opens a big checklist. **Packed works only when every item is ticked** (checked on the server too).
  - The moderator sees "Packing: name, since time" and then "Packed by name at time".
  - Cannot finish it? **Hold** with a reason. The moderator and managers are alerted and the order shows who held it.
  - "Print new labels" prints every label not printed yet. "My day": packed count, average time, scan errors.
  - Packers never see phone numbers, full addresses or prices on screen.
  - Handover: to hand over / scanned / left, with sound. Finishing moves unscanned parcels to the next pickup.
- **Breaks** (button in the header, for everyone):
  - Choose a reason (Lunch, Prayer, Washroom, Shop visit, Task from admin, Other; editable in Settings, each marked "counts as break" or "work away").
  - The whole screen is covered with the name, ID and a running timer until **Start work**. The server refuses any action meanwhile.
  - Orders not called yet go back to New without a penalty.
  - Over the daily limit (60 minutes, setting) the screen and the reports turn red; nobody is blocked.
  - Left on break and went home: closed at the end of that person's shift, marked "did not come back", managers alerted. An admin can correct the time with a note.
- **Office days per person** (Staff, edit page) or the office default (Settings, Working hours). Using the system on an off day is recorded as an **extra day**, counted for pay only after approval.
- **Attendance & breaks** (Team menu): day view (first and last seen, every break) and month view (days worked, extra days, break time, days over the limit).
- **Control room** (Orders menu): waiting orders by age, orders per stage with the oldest untouched, and per moderator: holding, oldest, No response, on hold, timed out today, breaks today.
- **Scoring** is by outcome: delivered +3, saved order (had No response or Hold, then delivered) +2 more, moderator-caused cancel -1, moderator-caused return -3, missed timer -1 (-2 from the 4th in a day). Nothing for taking or confirming. Rules can now depend on the **channel**, so chat orders can earn or lose more. All values stay editable.
- **KPI** is counts and rates per moderator with a team average: delivered, delivery %, cancel %, return %, saved, No response now, timed out. Two views (ended in the period / taken in the period). Monthly **targets** per person. A moderator sees only their own row.
- Telegram: one packing digest every 30 minutes plus exceptions (cancelled, on hold, repack). No per-order messages. The nightly owner summary lists each moderator's delivered count and break minutes.
- Permissions granted automatically where nobody had them yet: orders.take (Moderator), packing.manage, attendance, points.manage, kpi.view, analysis.view (Manager).

Tests: 198 passing.

### Assumptions made (say if any should change)
- Four call attempts in total: the first call plus the three returns. The cancel after the last one gives 0 points.
- The timer runs on one order at a time, not on all five at once.
- "Active" means used the system in the last 10 minutes (an open but idle tab does not count).
- The risky-order plus/minus rules are switched off. Packer +1, entry-error minus and fake status -10 stay.
- If no on-duty list is set for the day, everyone with packing access can pack.
- Batches and the Telegram pick list are no longer in the menu (the shared queue replaces them).

### Needs you
- **Add the cron** in hPanel (Advanced, Cron Jobs), every minute: `cd ~/domains/iqs.top5way.com/public_html && php artisan schedule:run >> /dev/null 2>&1`. Without it: auto-assign, break auto-close, booking retries and the digests do not run. Taking orders, timers on Take next, No response returns and booking still work.
- SMS to the customer after the first No response needs an SMS gateway account. The setting exists and is off.
- Staff need their office days set if they differ from the default (Friday off, 9:00 to 22:00).

### 10-line manual test checklist
1. Order management: press Take next. The oldest order opens with a 10-minute timer.
2. Press Record OK (if it is in Verify), then Call verified, then Send to packing. Within seconds the Packing tab shows the CN.
3. Take another, press No response. It moves to Call again with the return time.
4. Take another and wait 10 minutes (or set the timer to 1 minute in Settings). Press Take next again: the missed order is back in New and My points shows -1.
5. Press Break, choose Lunch. The screen locks with the timer. Press Start work.
6. Packing: scan (or type) the label, tick the items, press Packed. The moderator's Packing tab shows "Packed by".
7. Packing: scan another order and press Hold with a reason. The order page shows who held it.
8. Control room: check waiting orders, stages and the moderator table.
9. Attendance & breaks: today's break is listed; open Month.
10. KPI: set a target for one person and see it under their delivered count.

## Complaints, refunds and the sales graph (done 2026-10-10)

### Built
- **Complaints** (Orders menu): opened against an order (type the order number) or just a phone number, with a type, where it came from (phone / Messenger / WhatsApp / website / rider), the customer's words and up to 6 photos.
  - Photos are stored privately and served only to people with complaints access.
  - Assigned to the order's moderator by default; managers can give it to anyone.
  - **SLA**: 24 hours (Settings, General, "complaints.sla_hours"). Overdue complaints turn red, are sent to managers once (`complaints:escalate`, every 15 minutes) and get an in-app alert.
  - **Resolve** = how it ended (solved / replacement / refunded / not valid) + **whose stage caused it** (sales, verification, packing, dispatch, courier, customer, nobody) + the person at fault (automatic: the order's moderator for sales, the packer for packing). "Refunded" needs an approved refund first.
  - Everything is a timeline row (opened, note, given to, photo added, refund events, resolved, reopened, escalated); nothing is edited in place. A note also lands on the order's timeline.
  - Complaint types are **reasons** (Settings, Statuses & reasons, "Complaint categories"), each with a default blame stage. Eight are seeded.
  - List: Open / Mine / All tabs, filters by type, stage, person, dates; search by order no, phone, name or #id. Moderators see only complaints assigned to them (own scope).
  - Order page: a Complaints card with a "+ New" link.
- **Refunds** (Accounting menu): request from a complaint or from the order page (amount, bKash/Nagad/… method, number to send to, reason, note).
  - **Two people**: the requester can never approve; approve/reject needs `refunds.approve` (Manager). Then whoever sends the money presses "Money sent" with the transaction ID (required for bKash/Nagad/Rocket/bank/card).
  - Paid = a verified `refund` row in order_payments, so the order's refund due and payment status update automatically; a fully refunded prepaid order shows "refunded". The same TrxID cannot be used twice.
  - The amount is capped at what the customer actually paid (verified advance + COD collected on delivered orders) minus refunds already approved or paid.
  - Tabs: Waiting / To pay / Paid / Rejected / All. Dashboard tile "Refunds to approve" for managers.
  - Refund reasons are reasons too (Settings, "Refund reasons").
- **Sales report** (Analysis menu, `reports.view`): pick any date range (presets: today, yesterday, 7 days, this month; default last 30 days).
  - Tiles: orders placed (+ ৳), completed (+ ৳ delivered), cancelled, returned, completion rate (completed ÷ ended), average per day.
  - Line graph (plain SVG, no JS library): placed / completed / cancelled / returned per day; tap or hover a day to read its numbers. Ranges over 3 months are shown per week; at most one year. **One day (or "Today") is shown per hour** (24 rows, plus a "Busiest hour" tile).
  - Table (desktop) / cards (phone) per day with the same numbers.
  - Completed and cancelled count on the day the courier/agent ended them, placed counts on the day the order came in.
  - Dashboard: the same graph for the last 14 days, with a link to the report.
- Permissions: `complaints` (view / create / edit), `refunds` (view / create / approve), `reports.view`. Default grants: Moderator gets complaints (own) and can request refunds; Manager gets everything; Dollar Keeper sees and pays refunds.
- Notifications: complaint opened (moderator + managers), complaint overdue (managers, urgent), refund waiting for approval (managers), refund decided/paid (the requester). Types added later now receive their default rules even on an existing install.

Tests: 213 passing (15 new).

### Assumptions made (say if any should change)
- A complaint does not take points away yet. The blame stage and person are stored, so a points rule can be added later (e.g. "complaint, packing at fault: −2").
- Photos go to `storage/app/private/complaints/<id>/`; keep that folder in the backups.
- Refund money goes out by hand (bKash app); the system only records it.
- Cash refunds need no transaction ID.

### Needs you
- Give the roles that should see the Sales graph `reports.view` (Manager has it; Owner always has it).
- The cron (same line as before) is needed for complaint escalation.

### 10-line manual test checklist
1. Complaints, + New complaint: type an order number, pick "Damaged or leaking", add a photo, Open. The order's moderator gets a bell alert.
2. Open the complaint: the photo shows, Stage at fault says Packing, Due in 24 hours.
3. Add a note; it appears in the timeline and on the order page.
4. Request a refund (amount above what was paid is refused).
5. Log in as a Manager: Refunds, Waiting: Approve. Log in as the requester: approving your own is refused.
6. Refunds, To pay: enter a TrxID, Money sent. The order page shows the refund as a verified payment.
7. Back on the complaint: Resolve as Refunded, stage Packing, person automatic. It closes and the order's timeline notes it.
8. Settings, General: set complaints.sla_hours to 1; open another complaint; after an hour `php artisan complaints:escalate` marks it Overdue and alerts managers.
9. Analysis, Sales: choose This month; hover the graph; check the table totals against All orders.
10. Dashboard: Open complaints, Refunds to approve tiles and the 14-day graph.
