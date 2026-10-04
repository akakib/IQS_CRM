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
