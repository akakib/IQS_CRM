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
3. Edit a price (online sale price) → save → price history row appears (Activity / product).
4. Products → Stock status → mark two variants Out of stock with an expected date.
5. Search box on Products finds by name, SKU or barcode.
6. Customers → New customer with +880 number → it is saved as 01XXXXXXXXX.
7. Try another customer with the same number → blocked with the owner's name.
8. Customer page → Check now → Steadfast (fake) and own history appear.
9. Merge a duplicate by its phone → numbers and addresses move over.
10. Give a role "Hide customer contact" → that person sees 017******78 and cannot edit.
