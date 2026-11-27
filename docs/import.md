---
title: Data migration
summary: "Export the one production install from MariaDB, import it into SQLite, verify it, and delete both tools after cutover."
audience: developer
section: Rewrite
specs: []
screens: []
order: 60
---

# Data migration

The local DDEV database is empty; the only data that exists lives in one production install running the Laravel app against MariaDB. Migration is a one-shot transfer from that install into a fresh `freshguard.db`, done during the cutover window. Both tools — the Laravel export command and the Go import subcommand — are deleted after cutover (step 10 of `REWRITE.md`).

Password hashes are exported for reference but **not imported**: the household resets through `freshguard user password`.

## Export (Laravel side)

A throwaway command on the rewrite branch, `app/Console/Commands/ExportFreshGuardData.php`:

```sh
php artisan freshguard:export --dir=storage/export
```

Writes five files and prints a summary. Run it on the production host with the app in maintenance mode (`php artisan down`) so nothing changes mid-export.

### `manifest.json`

```json
{
  "format": 1,
  "app": "freshguard",
  "app_version": "1.4.0",
  "exported_at": "2026-01-24T08:27:26Z",
  "tables": {"users": 2, "locations": 6, "items": 41, "batches": 73, "tags": 18}
}
```

`app_version` comes from `config('app.version')` or `composer.json`; it is informational.

### `users.json`

```json
[{"id":"0190…","name":"Marcelo","email":"m@example.com","is_admin":true,
  "email_verified_at":"2025-06-01T10:00:00Z","password_hash":"$argon2id$…",
  "created_at":"…","updated_at":"…"}]
```

`password_hash` is exported (so the old data is not lost before the Laravel app is deleted) and ignored by the importer.

### `locations.json`

```json
[{"id":"0190…","name":"Fridge","description":"Top shelf","expiration_notify_days":3,
  "parent_id":"0190…","created_at":"…","updated_at":"…"}]
```

### `items.json`

```json
[{"id":"0190…","name":"Whole milk","barcode":"7891000100103","description":"1 L",
  "tags":["dairy","fridge"],"quantity":3,"created_at":"…","updated_at":"…"}]
```

`tags` is the decoded JSON column (a list, or `null`). `quantity` is the stored column, exported **only** so the importer can verify `SUM(batches.quantity)` against it.

### `batches.json`

```json
[{"id":"0190…","item_id":"0190…","location_id":"0190…","expires_at":"2026-02-01",
  "quantity":2,"created_at":"…","updated_at":"…"}]
```

`expires_at` is exported as `YYYY-MM-DD` even when the underlying column is a `DATE`. Timestamps are RFC3339 UTC.

## Import (Go side)

A real subcommand in v1, removed in the final PR:

```sh
# always first
bin/freshguard import --dir=/tmp/freshguard-export --dry-run

# for real, into a fresh data directory
FRESHGUARD_DATA_DIR=/srv/freshguard/data bin/freshguard import --dir=/tmp/freshguard-export
```

Flags: `--dir` (required), `--dry-run`, `--force`. `--force` is required when the target database already has users; without it the command refuses, so a stray retry cannot seed a live install twice.

### Phases

Everything happens in one transaction in this order, because of the foreign keys:

1. **Validate** `manifest.json` (format 1) and that all four JSON files parse.
2. **Tags**: collect every distinct tag across items, trim, drop empties, merge case-insensitively (first-seen casing wins), insert into `tags`.
3. **Locations**: insert all rows with `parent_id = NULL`.
4. **Locations, second pass**: set `parent_id` where the parent exists and no cycle would form; otherwise leave `NULL` and count it.
5. **Users**: insert with `must_reset = 1` and a random password hash. `is_admin` is preserved. **No** hashes are imported.
6. **Items**: insert with original ids and timestamps.
7. **Item tags**: resolve tag names to ids, insert `item_tags`, deduplicate.
8. **Batches**: insert with `quantity > 0` only; drop and count the rest.
9. **Verification**: compute the report below; roll back on `--dry-run`, and roll back on failure.
10. **Optimize**: `PRAGMA optimize` and `PRAGMA wal_checkpoint(TRUNCATE)`.

All inserts are `INSERT … ON CONFLICT(id) DO UPDATE`, so a failed run can be retried against the same file. Data is never deleted by the importer.

### Repairs

`schema.md` lists the states the new schema forbids. The importer drops or repairs them and counts every case:

| Condition | Action |
| --- | --- |
| `batches.quantity <= 0` | Drop the batch. |
| `batches.item_id` or `location_id` does not exist | Drop the batch. |
| `locations.parent_id` missing, or would create a cycle | Set `NULL`. |
| Duplicate tag names differing by case | Merge. |
| `expires_at` not `YYYY-MM-DD` | Drop the batch (the column refuses anything else). |
| Item with no batches | Keep, quantity 0. |

### Verification report

Printed by `--dry-run` and by the real run; the command exits non-zero if any line fails.

```
table      exported  imported  dropped  status
users             2         2        0  ok
locations         6         6        0  ok
items            41        41        0  ok
tags             18        18        0  ok
batches          73        73        0  ok

quantity    checked  mismatched  status
items            41           0  ok

orphans                    count  status
batches without item           0  ok
batches without location       0  ok
items without batches          2  inform

per location               exported  imported  status
Fridge   (batches)                12        12  ok
Freezer  (batches)                 9         9  ok
Pantry   (batches)                51        51  ok
```

The `quantity` check compares, for every item id present in both sides, the exported `items.quantity` with `SELECT COALESCE(SUM(quantity),0) FROM batches WHERE item_id = ?`. A mismatch means the old observer had drifted, which is exactly the bug the new schema removes — it is reported as `inform`, not `fail`, and listed per item so it can be inspected by hand.

## Cutover runbook

Run in this order. Nothing here is reversible except by restoring the Laravel database, which stays untouched throughout.

1. **Announce.** The household stops using the app for ~15 minutes.
2. **Freeze the old app.** `php artisan down` on the Laravel install; confirm the page is the maintenance screen.
3. **Export.** `php artisan freshguard:export --dir=storage/export`; copy the four JSON files and `manifest.json` off the host.
4. **Start the new server on an unused port.** `FRESHGUARD_DATA_DIR=/srv/freshguard/data FRESHGUARD_ADDR=:8081 bin/freshguard serve`.
5. **Import with `--dry-run`.** Review every line of the report; investigate any `dropped` row before continuing.
6. **Import for real.** Same command without `--dry-run`.
7. **Smoke-test on :8081.** Log in via the CLI reset link, walk `PARITY.md`'s screens, open one item with several batches, consume one unit, delete a location that holds batches and confirm the refusal.
8. **Switch the proxy.** Point Coolify/nginx at the Go listener on `:8081`; confirm the health check and a smoke request through the proxy, then stop the Laravel container. Keep its volume and image.
9. **Watch for a week.** Logs at `info`, the SQLite file size, and a daily check that `GET /api/v1/health` answers.
10. **Delete.** Follow `REWRITE.md` step 10 in one PR: Laravel tree, DDEV config, PHP workflows, PHP Dockerfile, and the Go `import` subcommand. Tag `v1.0.0` on the merge commit and `v1.0.0-laravel` on the last Laravel release for the record.

### Rollback

Within the week: stop the Go container, start the stopped Laravel container, run `php artisan up` to clear maintenance mode, and verify Laravel health before pointing the proxy back. The Laravel database was never written to, so rollback loses only the writes made in the new app during that window — acceptable, because the household knows about the cutover.

## After cutover

`freshguard import` is deleted along with the export command. The evidence that the migration happened stays in the repository as this document and as the `v1.0.0-laravel` tag.
