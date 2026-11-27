---
title: HTTP API
summary: "The v1 REST surface: conventions, auth, every route with its payload, and the error envelope."
audience: developer
section: Reference
specs: []
screens: []
order: 40
---

# HTTP API

JSON over HTTP under `/api/v1`. The SPA is the only client in v1; the API is public and stable enough for `curl`, but there are no tokens until v2 — scripts use a session cookie.

## Conventions

- Requests and responses are `application/json; charset=utf-8`. Requests with a body must send `Content-Type: application/json`.
- Success bodies are either a resource object or `{"data": [...], "meta": {...}}` for collections.
- Errors are `{"error": {"code": "...", "message": "...", "fields": {...}}}`; `fields` is present only for `invalid_input` and holds **rule tokens**, not prose: `{"name": "required", "expires_at": "date"}`.
- Collection queries: `?page=1&per_page=10` (max 100, default 10, matching the Filament table), `?sort=<field>&dir=asc|desc`, `?q=<term>`.
- Collection responses: `{"data": [...], "meta": {"page": 1, "per_page": 10, "total": 42, "pages": 5}}`.
- Timestamps are RFC3339 UTC; `expires_at` is `YYYY-MM-DD`.
- Deleting returns `204` with no body.
- `GET`/`HEAD` are safe; `POST` creates; `PATCH` updates a subset; `PUT` is not used.

## Auth

| Credential | Transport | Notes |
| --- | --- | --- |
| Session | `fg_session` cookie, `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS | 30-day sliding TTL. |
| Bearer token | `Authorization: Bearer fg_…` | **Not in v1.** The table and the middleware branch land with v2 reminders. |

Cookie-authenticated **non-GET** requests must carry an `Origin` header (or `Sec-Fetch-Site: same-origin`) whose host equals the request host. A request without it is `403 forbidden` / `bad_origin`.

`must_reset = 1` blocks everything except `GET /api/v1/me`, `PATCH /api/v1/me` and `DELETE /api/v1/session` with `403 must_reset`. Signing out stays reachable: a flagged account must not be trapped in the session it cannot use.

Rate limits: login is 10 per 15 min per IP+email; every other API route except health is 600 per minute per IP. The barcode route adds its own 60-per-minute per-user bucket when it lands (#490). Exceeding one is `429 rate_limited`.

## Routes

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | none | Liveness, version, `setup_required`. |
| POST | `/api/v1/setup` | none, only while zero users | Create the first admin. |
| POST | `/api/v1/session` | none | Log in. |
| DELETE | `/api/v1/session` | session | Log out. |
| GET | `/api/v1/me` | session | Current user. |
| PATCH | `/api/v1/me` | session | Profile, email, password. |
| GET | `/api/v1/password-resets/{token}` | none | Validate a reset link. |
| POST | `/api/v1/password-resets/{token}` | none | Set the new password. |
| GET | `/api/v1/items` | session | Paginated list, computed quantity. |
| POST | `/api/v1/items` | session | Create. |
| GET | `/api/v1/items/{id}` | session | Item with its batches. |
| PATCH | `/api/v1/items/{id}` | session | Update. |
| DELETE | `/api/v1/items/{id}` | session | Delete (batches cascade). |
| POST | `/api/v1/items/bulk-delete` | session | Delete many. |
| GET | `/api/v1/items/{id}/batches` | session | Batches of one item. |
| POST | `/api/v1/items/{id}/batches` | session | Add a batch. |
| PATCH | `/api/v1/batches/{id}` | session | Update a batch. |
| DELETE | `/api/v1/batches/{id}` | session | Delete a batch. |
| POST | `/api/v1/batches/{id}/consume` | session | Consume one unit. |
| GET | `/api/v1/locations` | session | Paginated list, parent name, batch count. |
| POST | `/api/v1/locations` | session | Create. |
| PATCH | `/api/v1/locations/{id}` | session | Update. |
| DELETE | `/api/v1/locations/{id}` | session | Delete, `409` when it holds batches. |
| POST | `/api/v1/locations/bulk-delete` | session | Atomic bulk delete. |
| GET | `/api/v1/tags` | session | Tag suggestions. |
| GET | `/api/v1/search` | session | Quick Consume tiered search. |
| GET | `/api/v1/dashboard/recently-expired` | session | Five most expired batches. |
| GET | `/api/v1/users` | admin | Paginated list. |
| POST | `/api/v1/users` | admin | Create a member. |
| PATCH | `/api/v1/users/{id}` | admin | Update. |
| DELETE | `/api/v1/users/{id}` | admin | Delete (not self, not the last admin). |
| POST | `/api/v1/users/{id}/password` | admin | Set a temporary password (`must_reset = 1`). |
| GET | `/api/v1/barcode/{code}` | session | Open Food Facts lookup for form prefill. |

Anything else under `/api/*` is `404 not_found` as JSON; anything else is the SPA.

## Resources

**User**

```json
{"id":"0190a1b2-…","name":"Marcelo","email":"m@example.com","is_admin":true,
 "must_reset":false,"created_at":"2026-01-24T08:27:26Z","updated_at":"2026-01-24T08:27:26Z"}
```

**Item (list)**

```json
{"id":"0190a1b2-…","name":"Whole milk","barcode":"7891000100103","description":"1 L",
 "tags":["dairy","fridge"],"quantity":3,"earliest_expiration":"2026-02-01",
 "batch_count":2,"created_at":"…","updated_at":"…"}
```

**Item (detail)** — the same object plus `batches`.

**Batch**

```json
{"id":"0190a1b2-…","item_id":"0190a1b2-…","location":{"id":"0190a1b2-…","name":"Fridge"},
 "expires_at":"2026-02-01","quantity":2,"created_at":"…","updated_at":"…"}
```

**Location**

```json
{"id":"0190a1b2-…","name":"Fridge","description":"Top shelf",
 "expiration_notify_days":3,"parent":{"id":"0190a1b2-…","name":"Kitchen"},
 "batch_count":4,"created_at":"…","updated_at":"…"}
```

## Endpoints

### `GET /api/v1/health`

```json
{"status":"ok","version":"1.0.0","setup_required":true,"time":"2026-01-24T08:27:26Z"}
```

Never authenticated and never rate-limited. Reads only the user count. `setup_required` is true while there are no accounts.

### `POST /api/v1/setup`

Only while `users` is empty; otherwise `409 conflict`.

```json
{"name":"Marcelo","email":"m@example.com","password":"correct horse battery staple"}
```

`201` `{"user": {…}}` plus a `fg_session` cookie (the admin is logged in). Validation tokens: `name: required|max`, `email: required|email|max|unique`, `password: min|max`; the password is 8–255 characters.

### `POST /api/v1/session`

```json
{"email":"m@example.com","password":"…"}
```

`200` `{"user": {…}}` + `Set-Cookie`. `401 unauthorized` for a wrong email or password (same body for both, no user enumeration). `403 must_reset` is not returned here — the user is logged in and the SPA shows the change-password screen.

### `DELETE /api/v1/session`

`204`, clears the cookie and deletes the session row.

### `GET /api/v1/me` · `PATCH /api/v1/me`

`PATCH` accepts any subset of `name`, `email`, `password` (+ `current_password` when `password` is present). Setting a password clears `must_reset`.

```json
{"name":"Marcelo","email":"m@example.com","current_password":"…","password":"…"}
```

A missing `current_password` is `422 invalid_input` with `{"current_password":"required"}`; a wrong one is `{"current_password":"mismatch"}`. Email collisions are `422 invalid_input` with `{"email":"unique"}`.

### `GET|POST /api/v1/password-resets/{token}`

`GET` → `200 {"email":"m@example.com"}` or `404 not_found` (unknown, used or expired). `POST {"password":"…"}` → `204`, marks the token used, clears `must_reset`, and deletes every other reset token and session of that user.

### Items

`GET /api/v1/items?q=milk&sort=quantity&dir=desc&page=1&per_page=10`

- `q` matches `name`, `description`, `barcode` and tag names (substring, case-insensitive).
- `sort` ∈ `name`, `quantity`, `earliest_expiration`, `created_at`, `updated_at` (default `name asc`).

`POST /api/v1/items`

```json
{"name":"Whole milk","barcode":"7891000100103","description":"1 L","tags":["dairy"]}
```

`201` with the detail object (empty `batches`). `name` required, ≤ 255; `barcode` ≤ 255; `description` ≤ 1000; tags are trimmed, emptied ones dropped, duplicates merged case-insensitively.

`PATCH /api/v1/items/{id}` accepts the same fields, `tags` replaces the whole set.

`DELETE /api/v1/items/{id}` cascades to batches and `item_tags`.

`POST /api/v1/items/bulk-delete`

```json
{"ids":["0190…","0191…"]}
```

`200 {"deleted": 2}`; missing ids are ignored. Always atomic.

### Batches

`GET /api/v1/items/{id}/batches?sort=expires_at&dir=asc` — default `expires_at asc`, matching the relation manager.

`POST /api/v1/items/{id}/batches`

```json
{"location_id":"0190…","expires_at":"2026-02-01","quantity":2}
```

`location_id` required and must exist; `expires_at` required, `YYYY-MM-DD`; `quantity` required, integer ≥ 1.

`PATCH /api/v1/batches/{id}` accepts `location_id`, `expires_at`, `quantity` (≥ 1).

`DELETE /api/v1/batches/{id}` → `204`.

`POST /api/v1/batches/{id}/consume`

```json
{"item":{"id":"0190…","name":"Whole milk"},"batch":{"id":"0190…","quantity":1},"deleted":false}
```

`batch` is `null` and `deleted` is `true` when the last unit was consumed. `409 batch_unavailable` when the batch is gone or its quantity reached zero between the read and the write (the old "Item no longer available" toast).

### Locations

`GET /api/v1/locations?q=fridge&sort=name&dir=asc` — `sort` ∈ `name`, `created_at`, `updated_at`; `q` matches name, description and parent name.

`POST /api/v1/locations`

```json
{"name":"Fridge","description":"Top shelf","expiration_notify_days":3,"parent_id":"0190…"}
```

`name` required ≤ 255; `description` ≤ 255; `expiration_notify_days` integer ≥ 0, default 0; `parent_id` nullable, must exist, must not create a cycle (`{"parent_id":"cycle"}`).

`PATCH /api/v1/locations/{id}` accepts the same fields.

`DELETE /api/v1/locations/{id}` → `204`; `409 location_has_batches` when any batch points at it. Children become root locations.

`POST /api/v1/locations/bulk-delete`

```json
{"ids":["0190…","0191…"]}
```

Atomic, matching the old bulk action: if **any** selected location holds batches, none is deleted and the whole request is `409 location_has_batches` with `{"error":{"code":"location_has_batches","message":"…","fields":{"ids":"0190…"}}}`. Otherwise `200 {"deleted": 3}`.

### Tags

`GET /api/v1/tags` → `{"data":[{"id":"0190…","name":"dairy","item_count":12}]}` — 50 most used, then by name.

### Search (Quick Consume)

`GET /api/v1/search?q=mil`

- Terms shorter than 2 characters after trimming → `200 {"data":[]}` (the SPA does not even call it).
- Fixed limit 10; tiered ordering per `schema.md`.

```json
{"data":[{"id":"0190…","name":"Whole milk","description":"1 L",
  "batches":[{"id":"0190…","location":{"id":"0190…","name":"Fridge"},
              "expires_at":"2026-02-01","quantity":2}]}]}
```

Batches inside a result are only those with quantity > 0, ordered by `expires_at, id`.

### Dashboard

`GET /api/v1/dashboard/recently-expired` → up to five batches with `expires_at < today`, ordered `expires_at asc`, each with `item` and `location` (same shape as a batch, plus `item`).

### Users (admin)

`GET /api/v1/users?q=&sort=name&dir=asc` — `sort` ∈ `name`, `email`, `is_admin`, `created_at`; default `name asc`.

`POST /api/v1/users`

```json
{"name":"Ana","email":"ana@example.com","password":"…","is_admin":false}
```

`201`. The password is validated like setup. `is_admin` may only be set by an admin (which is the only caller).

`PATCH /api/v1/users/{id}` accepts `name`, `email`, `is_admin`, `password`. Rules ported from `UserPolicy`: an admin cannot demote themselves (the old toggle was disabled on self), email stays unique, changing `is_admin` requires admin.

`DELETE /api/v1/users/{id}` → `204`; `409 cannot_delete_self`; `409 last_admin` when the target is the only admin.

`POST /api/v1/users/{id}/password`

```json
{"password":"…"}
```

`204`, sets `must_reset = 1`, and deletes that user's sessions except the caller's own if they are the same user.

### Barcode

`GET /api/v1/barcode/7891000100103`

| Case | Response |
| --- | --- |
| Found | `200 {"status":"success","name":"Whole milk","description":"…","tags":["dairy"]}` |
| Valid, unknown | `200 {"status":"not_found"}` |
| Not 8–14 digits | `422 {"error":{"code":"invalid_barcode","message":"…"}}` |
| OFF unreachable or 5xx | `502 {"error":{"code":"upstream_unavailable","message":"…"}}` |

The mapping to UI toasts is in `PARITY.md`. `name`/`description`/`tags` are the same three fields the old form pre-filled, from `product_name`, `generic_name` and `categories_hierarchy` (with the `en:` prefix stripped); missing fields are omitted so the form only fills what is empty.

## Error codes

| Status | Code | When |
| --- | --- | --- |
| 401 | `unauthorized` | No session, expired session, wrong credentials. |
| 403 | `forbidden` | Admin-only route, non-admin caller. |
| 403 | `bad_origin` | Cookie-authenticated non-GET without a matching `Origin`. |
| 403 | `must_reset` | Password change required. |
| 404 | `not_found` | Unknown id or route, including a known API path with the wrong method (the API catch-all answers JSON). |
| 409 | `conflict` | Setup already complete. |
| 409 | `location_has_batches` | Delete blocked; `fields.ids` names the offenders. |
| 409 | `batch_unavailable` | Consume lost the race. |
| 409 | `last_admin` | Deleting the only admin. |
| 409 | `cannot_delete_self` | Self-delete attempt. |
| 422 | `invalid_input` | Validation; `fields` holds rule tokens. |
| 422 | `invalid_barcode` | Barcode is not 8–14 digits. |
| 429 | `rate_limited` | A bucket is empty. |
| 502 | `upstream_unavailable` | Open Food Facts failed after retries. |
| 500 | `internal` | Anything unexpected; logged with a request id, never detailed to the client. |

`500` bodies never include the wrapped error; the message is a fixed string and the request id is returned in `X-Request-Id` for log lookup.

## v2 additions (not implemented now)

`GET /api/v1/reminders` (next 8 days, grouped by location), `api_tokens` CRUD, and a `read` scope on bearer tokens. The envelope, pagination and error codes do not change.
