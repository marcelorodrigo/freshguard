---
title: Architecture
summary: "Packages, request flow, SQLite concurrency, auth, embedded assets, configuration, the dev loop and how the acceptance numbers are measured."
audience: developer
section: Internals
specs: []
screens: []
order: 20
---

# Architecture

One static Go binary serves a JSON API and an embedded Vue SPA. State is one SQLite file. There is no message queue, no background worker, no cache server and no second process.

```
┌──────────────── browser / Android WebView ────────────────┐
│  Vue 3 SPA (embedded)  ──fetch──►  /api/v1/*              │
└───────────────────────────────┬───────────────────────────┘
                                │  JSON over HTTP
┌───────────────────────────────▼───────────────────────────┐
│  freshguard (single static binary)                        │
│                                                           │
│  httpapi ── auth middleware ── handlers ── inventory      │
│      │                                       │            │
│      ├── barcode (Open Food Facts client)    │            │
│      └── web (go:embed all:dist)             │            │
│                                              ▼            │
│                                   store: writer (1 conn)  │
│                                          readers (4 conns)│
└───────────────────────────────┬───────────────────────────┘
                                │
                        data/freshguard.db (WAL)
```

## Packages

| Package | Responsibility | Depends on |
| --- | --- | --- |
| `cmd/freshguard` | Flag parsing, subcommand dispatch, signal handling, shutdown, `-ldflags` version. | everything below |
| `internal/config` | Environment to `Config`; every field has a default, nothing is required. | stdlib |
| `internal/store` | `Open(dataDir)`, embedded migrations, `PRAGMA user_version`, writer/reader pools, `Optimize`. | `modernc.org/sqlite` |
| `internal/ids` | UUIDv7 generation (`google/uuid` `NewV7`). | `google/uuid` |
| `internal/inventory` | Items, batches, locations, tags; computed quantity; tiered search; consume; location deletion; cycle checks. Pure `database/sql`, no HTTP. | `store`, `ids` |
| `internal/auth` | Users, argon2id, session create/verify/revoke, must-reset, password reset tokens, admin rules. | `store`, `ids` |
| `internal/barcode` | Open Food Facts lookup: validation, cache, retries, timeouts, result classes. | stdlib |
| `internal/httpapi` | Routes, middleware (auth, origin, rate limit), JSON envelope, pagination, SPA serving. | all of the above |
| `internal/web` | `//go:embed all:dist`, static file handler, SPA fallback, cache headers. | stdlib |
| `internal/import` | The one-shot JSON import and its verification report. Deleted after cutover (step 10). | `store`, `ids` |

Rules of thumb: `inventory` and `auth` never import `net/http`; handlers never write SQL; the store never formats JSON. `internal/web/dist/.gitkeep` is committed so `go build` works before the first `make web`.

## Request flow

`GET /api/v1/items/0190…` → `httpapi` mux (`"GET /api/v1/items/{id}"`) → `auth` middleware resolves the cookie or bearer token into a `User` in the request context → handler validates and calls `inventory` → `inventory` reads through the reader pool and writes through the writer → handler writes JSON or the error envelope.

Every write path is one transaction. There is no cross-request state, no cache to invalidate, and no event bus.

## SQLite

- Two `*sql.DB` handles: **writer** (`SetMaxOpenConns(1)`) and **readers** (`mode=ro`, `SetMaxOpenConns(4)`).
- Connection pragmas: `journal_mode=WAL`, `busy_timeout=5000`, `foreign_keys=ON`, `synchronous=NORMAL`, `temp_store=MEMORY`.
- `PRAGMA optimize` on start and after import; `analysis_limit=1000`.
- Migrations: `internal/store/migrations/NNNN_name.sql`, applied in numeric order inside a transaction, each bumping `PRAGMA user_version`. An applied file is never edited.
- Backups: `PRAGMA wal_checkpoint(TRUNCATE)` then copy `freshguard.db`; documented in the README.

## Auth

- **Passwords**: argon2id, `time=2`, `memory=64 MiB`, `threads=4`, `keyLen=32`, 16-byte salt, PHC string in `users.password_hash`.
- **Sessions**: `fg_session` cookie, 32 random bytes base64url, `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS. Only `sha256(secret)` is stored. TTL 30 days, sliding, refreshed at most once per hour to avoid a write per request.
- **Origin check**: non-GET requests authenticated by cookie must carry `Origin` (or `Sec-Fetch-Site: same-origin`) whose host equals the request host. Bearer requests are exempt; v1 has no bearer clients.
- **Must reset**: an admin-set password or a CLI reset link sets `must_reset = 1`. Until the user changes it, every endpoint except `GET /me` and `PATCH /me` answers `403 must_reset`.
- **Rate limits**: in-memory token buckets — 10 login attempts per 15 minutes per IP+email, 60 barcode lookups per minute per user, 600 API requests per minute per IP.
- **Setup**: `POST /api/v1/setup` succeeds only while `users` is empty; `GET /api/v1/health` reports `setup_required`.

## Embedded assets

`internal/web` embeds `dist/` with `//go:embed all:dist`. Serving rules:

- `/assets/*` → `Cache-Control: public, max-age=31536000, immutable` (Vite hashes names).
- `index.html` → `Cache-Control: no-cache`.
- Any `GET`/`HEAD` that is not `/api/*` and does not name a file → `index.html` (SPA history fallback).
- Unknown `/api/*` → `404` with the JSON envelope, never `index.html`.

Compression is left to the reverse proxy; if a deployment has none, add gzip in a later change (not v1).

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `FRESHGUARD_ADDR` | `:8080` | Listen address. |
| `FRESHGUARD_DATA_DIR` | `./data` | Directory holding `freshguard.db` (and WAL files). Created with `0750`. |
| `FRESHGUARD_BASE_URL` | unset | Absolute URL for reset links printed by the CLI. |
| `FRESHGUARD_LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error`. |
| `FRESHGUARD_SESSION_TTL` | `720h` | Sliding session lifetime. |
| `FRESHGUARD_OFF_BASE_URL` | `https://world.openfoodfacts.org` | Open Food Facts endpoint; tests point this at a fake. |
| `FRESHGUARD_TZ` | system | Zone used by date-only logic and log timestamps. |

Nothing is required: `./freshguard` in an empty directory starts a working server and prints the setup URL.

## Subcommands

| Command | Purpose |
| --- | --- |
| `freshguard` / `freshguard serve` | Run the server. |
| `freshguard healthcheck` | Exit 0 when `GET /health` answers; the container probe. |
| `freshguard version` | Print the build version from `-ldflags`. |
| `freshguard user create --name --email [--admin]` | Create an account when the setup page is not an option. |
| `freshguard user password <email>` | Print a single-use reset link (uses `FRESHGUARD_BASE_URL`). |
| `freshguard import --dir --dry-run [--force]` | One-shot migration from the Laravel export; removed in step 10. |

## Dev loop

Prerequisites: Go 1.26+, Node 26+, pnpm 12+.

| Target | What it does |
| --- | --- |
| `make dev` | `vite` on :5174 proxying `/api` to `go run ./cmd/freshguard` on :8080 (`FRESHGUARD_DATA_DIR=./data-dev`). |
| `make web` | `cd web && pnpm install --frozen-lockfile && pnpm run build` → `internal/web/dist`. |
| `make server` | `CGO_ENABLED=0 go build -trimpath -ldflags="-s -w -X main.version=$(git describe …)" -o bin/freshguard ./cmd/freshguard`. |
| `make test` | `go test ./...` and `cd web && pnpm run test`. |
| `make check` | `gofmt -l`, `go vet`, `staticcheck`, `go test ./...`, `vue-tsc`, ESLint, Vitest. The gate CI runs. |
| `make cover` | `go test -coverprofile=coverage.out` and `pnpm run test:coverage`; CI scans those reports with Sonar. |
| `make size` | Strip and report binary size; fails above 30 MB. |
| `make rss` | Start the binary on a temp data dir, `curl /health`, report `ps -o rss=`. Fails above 50 MB. |
| `make cold` | Time the first response on a fresh data dir. Fails above 200 ms. |
| `make docker` | Multi-stage build to a distroless non-root image. |

No DDEV, no dev container, no PHP, no Composer. Docker is packaging only.

## Testing

| Layer | Tool | Covers |
| --- | --- | --- |
| `internal/store` | `go test` with `t.TempDir()` | Migration idempotency, pragmas, `user_version`. |
| `internal/inventory` | table-driven `go test` | Search tiers and determinism, consume, computed quantity, atomic bulk delete, cycle rejection, tag upsert. |
| `internal/auth` | table-driven `go test` | argon2 verify, session expiry, reset token single use, last-admin rules. |
| `internal/barcode` | `httptest` fake OFF | Success, not found, invalid, upstream failure, timeout, retry, cache TTLs. |
| `internal/httpapi` | `httptest` + temp data dir | Auth, origin rejection, `must_reset`, validation rule tokens, pagination, error envelope, SPA fallback. |
| `web/` | Vitest + Vue Test Utils | API client, error-to-message mapping, locale detection, screen states. Locale key parity is a `vue-tsc` failure, not a test. |

No browser E2E in v1. The manual walkthrough is the `PARITY.md` checklist, executed once per release.

## Measuring the acceptance numbers

```sh
make size   # ls -l bin/freshguard after strip
make rss    # ps -o rss= after GET /health on a fresh data dir
make cold   # curl -w '%{time_starttransfer}' on a fresh data dir
```

All three fail the build when they exceed their budget, so the numbers cannot rot silently.
