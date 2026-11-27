---
title: The Go/SQLite rewrite
summary: "Goals, the full decision log, what v1 contains, what is deliberately frozen, and how the Laravel app is retired."
audience: developer
section: Rewrite
specs: []
screens: []
order: 10
---

# The Go/SQLite rewrite

FreshGuard moves from Laravel 13 + Filament 5 + MariaDB + DDEV to one Go binary with SQLite and a Vue SPA embedded in it. The model is [OpenTodo](https://github.com/OpenTodoApp/OpenTodo) — the same architecture at roughly one fortieth of the size.

This document is the contract for the port: the goals, every decision with its rationale, what v1 ships, what is frozen, and how the Laravel app is deleted. `architecture.md`, `schema.md`, `api.md`, `PARITY.md` and `import.md` are its appendices.

## Why

| Goal | Target | Measured by |
| --- | --- | --- |
| Smaller server process | one static binary ≤ 30 MB stripped, `CGO_ENABLED=0` | `make size` |
| Small memory footprint | ≤ 50 MB RSS idle with the SPA served | `make rss` |
| One container | no MariaDB, no Redis, no queue worker | `docker compose up` starts the app only |
| Fast start | ≤ 200 ms to first byte on `GET /api/v1/health` | `make cold` |
| One-file backup | copying `data/freshguard.db` is a complete backup | documented, no extra tooling |
| Android app | installable shell that loads your own server | debug APK artifact in CI |
| Agent-friendly stack | Go + TypeScript + Kotlin, conventions written down | `make check` is the only gate |

## What v1 is

- A **server-authoritative REST** application. No sync model, no offline cache, no IndexedDB, no service worker, no conflict resolution. The client is online or it waits.
- **One household**, several accounts. First run creates the admin; the admin creates the rest. Sessions live in a cookie. There is no SMTP anywhere in the system.
- The same **screens** as the Filament panel (see `PARITY.md`) plus a first-run setup screen, with the same labels, validation, debounce and ordering. `PARITY.md` is the checklist that proves it.
- A **minimal Android shell** (setup screen + WebView + back handling). No bridge, no notifications — those are v2.

## Freeze list

Not in v1, and not in scope by accident:

- Reports, statistics, charts, history of consumed items.
- Import/export in the UI. (`freshguard import` is a CLI migration tool, deleted after cutover.)
- Shopping lists, recipes, meal plans, photos of items, expiry prediction.
- A batch search page; batches are only visible inside their item.
- Notifications of any kind, including the expiry reminders the README promises (v2).
- API tokens (v2, when the Android scheduler needs one).
- Widgets, biometric unlock, share-sheet receivers, geofences, microphone, haptics (OpenTodo has them; FreshGuard has no feature that needs them).
- Dark mode toggle beyond what the UI needs to render.
- GraphQL, gRPC, MCP, OpenAPI UI, backups UI, multi-tenancy.

Anything not in `PARITY.md` and not listed as an explicit deviation below is out of scope for v1.

## Deviations from today

Deliberate differences a user can see. Each one exists because a decision removed a dependency:

| Change | Reason |
| --- | --- |
| No open registration. Setup works only while there are zero users; afterwards only an admin creates accounts. | Removes the `FRESHGUARD_REGISTRATIONS_ENABLED` flag and its failure modes. |
| Email verification, email-change verification and password-reset mail are gone. The "Verified" column and "Email Verified At" field are dropped. | No SMTP in the system. A feature that cannot send mail is a lie. |
| Password reset is `freshguard user password <email>` (single-use link printed to stdout) or an admin setting a temporary password. | Replaces mail without adding a mail server. |
| "Remember me" is gone; every session slides for 30 days. | One behaviour, no flag nobody understands. |
| Tag suggestions are ordered by usage, not by the recency of the 50 most recently updated items. | Falls out of normalized tags; keeps the same 50-entry cap. |
| Choosing a location's parent rejects cycles (self, child, deeper descendant). | The old form let a location become its own grandchild. |
| The barcode camera uses `vue-qrcode-reader`, not `html5-qrcode`. | `html5-qrcode` has had no release since April 2023. |
| Locale switcher (English, Português do Brasil) in the profile menu. | The app now ships two locales. |

## Decision log

| # | Decision | Why | Rejected |
| --- | --- | --- | --- |
| D1 | Rewrite in place on `rewrite/go-sqlite`, merged once; Laravel deleted in a follow-up PR after a week in production. | Keeps git history, the 200 tests that encode rules, and the issue log; a rollback window costs one tag. | New repository; sidecar Go service next to Laravel. |
| D2 | Go 1.26, stdlib `net/http` with method-pattern `ServeMux`, `modernc.org/sqlite`, embedded SQL migrations, `slog`. | The OpenTodo pattern; no router, no ORM, no cgo. | chi/echo, GORM/sqlc, mattn/go-sqlite3 (cgo). |
| D3 | Server-authoritative REST; no op log, no LWW, no offline writes. | Conflicts are "did I already eat the yogurt"; row-level last write wins. | OpenTodo's full local-first sync. |
| D4 | Vue 3.5 + Vite 8 + Tailwind 4 SPA built to `web/dist` and embedded with `go:embed`. | Matches the `canastra` recipe; Nuxt's server half is dead weight when Go serves the files; no React anywhere in the author's repos. | Nuxt SPA, React, Go templates + htmx, keeping Filament. |
| D5 | Single household: setup-while-empty, admin-created members, `fg_session` cookie, argon2id, no SMTP. | Self-hosted by definition; email adds a config surface for zero value. | Open registration, OIDC, passkeys, TOTP. |
| D6 | UUIDv7 text primary keys. | Sortable, stable through import, no sequence clashes, works as a URL id. | Auto-increment integers, UUIDv4. |
| D7 | Tags normalized into `tags` + `item_tags`. | Tag filters, counts and suggestions become SQL; the `Cache::forget('item_tag_suggestions')` observer disappears. | JSON column (today's shape). |
| D8 | No `items.quantity` column; quantity is computed from batches. | Hundreds of rows make an aggregate free; deletes the observer, the double-write bug class and the item-then-batch lock ordering. | Stored column maintained in the same transaction; SQLite triggers. |
| D9 | `batches.location_id` is `ON DELETE RESTRICT`; location parents are cycle-checked; `parent_id` is `ON DELETE SET NULL`. | Makes "location contains inventory" and "child locations become root locations" true in the database, not just in a handler. | Handler-only checks. |
| D10 | One-shot JSON export from Laravel, `freshguard import` in Go, both deleted after cutover. `password_hash` is exported but not imported. | The export runs where the model layer knows the quirks; hash formats would be a compatibility burden for one household. | `mysqldump` transforms, manual re-entry, keeping bcrypt. |
| D11 | Expiry reminders are v2. | The only background job in the system; shipping it out of order makes v1 larger than the port needs. | Shipping v1 with the job runner and notifications. |
| D12 | v1 Android is a minimal shell: server address, WebView, back handling; debug APK in CI, no signing or releases. | ~400 lines that prove Gradle/WebView/CI while nothing depends on it; v2 becomes "add one capability". | Deferring the APK entirely; stubbing the bridge now. |
| D13 | UI components are shadcn-vue-style, copied into `web/src/components/ui`, on `reka-ui` primitives. | Accessible dialog/combobox/popover behaviour is the hard part and the part agents get wrong; the markup stays ours and Tailwind-native. | All hand-rolled; PrimeVue; Vuetify. |
| D14 | `vue-qrcode-reader` behind one `<BarcodeScanner>` wrapper. | Vue-native component over zxing, supports EAN-13/CODE-128, owns camera lifecycle, permissions and torch. | `html5-qrcode` (unmaintained), `barcode-detector` (newer, but the whole camera component becomes ours), native ML Kit. |
| D15 | `vue-i18n` 11 with `en` and `pt-BR`; `pt-BR` typed against the `en` schema. | Two locales ship now; a missing key must fail `tsc`, not fall back to English in production. | `en` only; a hand-rolled `t()`; hardcoded strings. |
| D16 | AGPL-3.0. | Self-hosted software with a network clause, and it makes inheriting OpenTodo's patterns legally clean. | MIT. |
| D17 | Mirror OpenTodo's package shape, not its size: `config`, `store`, `inventory`, `auth`, `httpapi`, `barcode`, `web`, `ids`. No tenancy, OTel, MCP, backup, OpenSpec, codesize guards. | Same mental model for anyone who knows OpenTodo, without 80 packages. | One flat `internal/app` package. |
| D18 | Server errors carry rule tokens (`"fields":{"name":"required"}`), not prose. | The UI localizes; the API stays locale-free. | Translated server messages, Laravel-style envelopes. |
| D19 | Cookie auth with `SameSite=Lax` + origin check on non-GET; no CSRF token. | Matches OpenTodo; an SPA with an explicit origin check needs no double-submit dance. | Laravel's CSRF token, token-only auth. |
| D20 | No `api_tokens` table in v1. | Nothing consumes the API in v1; the Android shell uses the WebView session. | Shipping tokens ahead of a client. |
| D21 | No background work in v1: no `jobs` package, no scheduler. | Reminders were the only job. | Scaffolding a runner for v2. |
| D22 | These documents are the first commit on `rewrite/go-sqlite`; `master` stays untouched until the rewrite merges. | The plan changes as the port reveals things; it belongs with the code it describes, and `master` keeps a working Laravel app until cutover is proven. | Landing the docs on `master` first. |
| D23 | pnpm 12 with a committed `web/pnpm-lock.yaml`. | A strict, content-addressed store: no phantom dependencies, lifecycle scripts blocked by default, one lockfile per app. | npm (hoisted, permissive resolution), yarn, Bun. |

## v2: expiry reminders

The whole mechanism, in one place, so v1 can ignore it:

1. Schema: `locations.last_reminder_sent_at` (nullable RFC3339) in a new migration.
2. `internal/jobs` ported from OpenTodo (`Job{Name,Next,Run,Check}` + `Runner`, 30 s check interval, no overlapping runs).
3. `internal/notify`: one digest message per day to the configured ntfy/Gotify URL, plus the shared `Reminders()` query (batches due within `expiration_notify_days` of each location, grouped by location, quantity > 0).
4. `GET /api/v1/reminders` returning the next 8 days for the shell.
5. Android bridge v1: origin-restricted `window.freshguardShell` via `WebViewCompat.addWebMessageListener` + `addDocumentStartJavaScript`, capability list `['reminders']`, `scheduleReminders` hand-over on every app open, plus a daily WorkManager refresh with a `read` token so the horizon extends without push.
6. `api_tokens` table and the tokens UI in the profile.
7. APK signing, reproducible builds and GitHub releases.

Candidates that stay out until someone asks: home-screen widget of expiring items, biometric unlock, share-sheet capture.

## Execution plan

Each step ends with `make check` green and a conventional commit.

| Step | Deliverable | Exit criterion |
| --- | --- | --- |
| 0 | `rewrite/go-sqlite` with this plan as its first commit, rewritten `AGENTS.md` (Go/Vue/Kotlin), `go.mod`, `Makefile`, `cmd/freshguard` skeleton, `internal/web/dist/.gitkeep` | `make check` passes on an empty server; `freshguard serve` answers `GET /api/v1/health`. |
| 1 | `internal/store`: `Open`, embedded migrations `0001_init.sql`, writer/reader pools, `PRAGMA optimize` | Fresh DB migrates twice without error; `user_version` matches the file count. |
| 2 | `internal/auth` + `internal/httpapi/auth.go`: setup, login, logout, `GET/PATCH /me`, password change, `freshguard user create\|password` | httptest suite covers setup-once, cookie lifetime, origin rejection, must-reset flow, last-admin rules. |
| 3 | `internal/inventory` + `httpapi`: items, batches, locations, tags, search, dashboard | Ported table tests for search tiers, consume, atomic bulk delete, cycle rejection; all green. |
| 4 | `internal/barcode`: OFF client with the same validation, caching, retries, timeouts and error classes | Fake-OFF httptest covers success/not-found/invalid/upstream/timeout/cache. |
| 5 | `web/`: shell, i18n, API client, seven screens, barcode component | Every `PARITY.md` box ticked; `npm run type-check && npm run lint && npm run test` green. |
| 6 | `mobile/android`: setup screen, WebView, back handling, CI debug APK | APK installs, loads a LAN/HTTPS server, survives rotation and back. |
| 7 | CI, multi-stage Dockerfile, compose, README, deployment docs | Image ≤ 30 MB; `make size`, `make rss`, `make cold` meet the targets. |
| 8 | `docs/import.md` implemented end to end | `--dry-run` against the production export reports zero mismatches. |
| 9 | Cutover | Production runs the Go binary for seven days. |
| 10 | Deletion PR | Laravel tree, DDEV config, PHP workflows and the Go `import` subcommand are gone. |

## Cutover

The runbook lives in `import.md`. In short: put the Laravel app in maintenance mode, export JSON, import into a fresh SQLite file, verify the report, point the proxy at the Go binary, smoke-test the screens, keep the Laravel container stopped but deployable for a week.

## Risks

| Risk | Mitigation |
| --- | --- |
| Camera scanning behaves differently inside Android WebView | `vue-qrcode-reader` behind one component; manual entry always available; native ML Kit bridge is a drop-in later. |
| Filament behaviours are subtler than `PARITY.md` captures | Port the Pest tests first as Go tests, and walk both UIs side by side during step 5. |
| Import data is dirtier than the local empty database suggests | `--dry-run` reports drops (zero-quantity batches, orphan parents) before anything is written. |
| Open Food Facts changes its API or rate limits | One client behind an interface, a fake server in tests, and 502 `upstream_unavailable` as the only failure the UI sees. |
| Agents write Laravel/PHP out of habit | `AGENTS.md` is rewritten in step 0, before any code, and `make check` is the only gate. |
| AGPL and a future hosted offering | Deliberate: if FreshGuard is ever offered as a service, its source must be available. |
