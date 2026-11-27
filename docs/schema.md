---
title: Schema
summary: "The SQLite tables, their invariants, the computed queries that replace the old columns, and the migration policy."
audience: developer
section: Internals
specs: []
screens: []
order: 30
---

# Schema

One SQLite file, six domain tables and three auth tables. IDs are UUIDv7 text, timestamps are RFC3339 UTC text (`2026-01-24T08:27:26Z`), dates are `YYYY-MM-DD`. Nothing is soft-deleted.

## DDL

`internal/store/migrations/0001_init.sql`:

```sql
CREATE TABLE users (
    id            TEXT PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,                 -- argon2id PHC string
    is_admin      INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    must_reset    INTEGER NOT NULL DEFAULT 0 CHECK (must_reset IN (0, 1)),
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE sessions (
    id           TEXT PRIMARY KEY,               -- sha256(secret), hex
    user_id      TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    user_agent   TEXT,
    created_at   TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at   TEXT NOT NULL
);
CREATE INDEX sessions_user_id ON sessions(user_id);
CREATE INDEX sessions_expires_at ON sessions(expires_at);

CREATE TABLE password_resets (
    id         TEXT PRIMARY KEY,                 -- sha256(token), hex
    user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at    TEXT
);
CREATE INDEX password_resets_user_id ON password_resets(user_id);

CREATE TABLE locations (
    id                     TEXT PRIMARY KEY,
    name                   TEXT NOT NULL,
    description            TEXT,
    expiration_notify_days INTEGER NOT NULL DEFAULT 0
                           CHECK (expiration_notify_days >= 0),
    parent_id              TEXT REFERENCES locations(id) ON DELETE SET NULL,
    created_at             TEXT NOT NULL,
    updated_at             TEXT NOT NULL
);
CREATE INDEX locations_parent_id ON locations(parent_id);

CREATE TABLE items (
    id          TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    barcode     TEXT,
    description TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);
CREATE INDEX items_name ON items(name);
CREATE INDEX items_barcode ON items(barcode);
CREATE INDEX items_description ON items(description);

CREATE TABLE tags (
    id   TEXT PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE
);

CREATE TABLE item_tags (
    item_id TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    tag_id  TEXT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (item_id, tag_id)
);
CREATE INDEX item_tags_tag_id ON item_tags(tag_id);

CREATE TABLE batches (
    id          TEXT PRIMARY KEY,
    item_id     TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    location_id TEXT NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    expires_at  TEXT NOT NULL
                CHECK (expires_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
    quantity    INTEGER NOT NULL CHECK (quantity > 0),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);
CREATE INDEX batches_item_id_expires_at ON batches(item_id, expires_at);
CREATE INDEX batches_expires_at ON batches(expires_at);
CREATE INDEX batches_location_id ON batches(location_id);
```

v2 adds `locations.last_reminder_sent_at` and `api_tokens`; neither belongs in `0001_init.sql`.

## Invariants

1. **A batch always has stock.** `quantity > 0`; consuming the last unit deletes the row. This removes the "zero-quantity batch" state the old schema allowed.
2. **A location with batches cannot be deleted.** Enforced by `ON DELETE RESTRICT`, not only by a handler. The message and the UI come from the conflict, not the other way round.
3. **Deleting a location keeps its children.** `parent_id … ON DELETE SET NULL` makes them root locations, matching the old modal text.
4. **No parent cycles.** Before setting `parent_id`, walk the proposed parent's ancestors; if the location being edited appears, reject with `invalid_input` / `parent_id: cycle`.
5. **Emails are unique case-insensitively.** `COLLATE NOCASE UNIQUE`.
6. **Tag names are unique case-insensitively, stored with first-seen casing.** "Dairy" and "dairy" are one tag.
7. **`expires_at` is a date with no time and no zone.** `GLOB` check, `YYYY-MM-DD`, compared as text (lexicographic order is chronological order).
8. **The last admin cannot be deleted, and nobody can delete themselves.** Application-level, ported from `UserPolicy`.
9. **Quantity is never stored on an item.** It is `SUM(batches.quantity)`; there is no column to drift.
10. **Timestamps are UTC text.** The server formats and parses them; the browser renders them in the user's zone.

## Computed queries

Item list (one row per item, no N+1):

```sql
SELECT i.id, i.name, i.barcode, i.description,
       COALESCE(SUM(b.quantity), 0) AS quantity,
       MIN(b.expires_at)           AS earliest_expiration,
       COUNT(b.id)                 AS batch_count
FROM items i
LEFT JOIN batches b ON b.item_id = i.id
GROUP BY i.id
ORDER BY i.name, i.id
LIMIT :limit OFFSET :offset;
```

Tags for a page of items, one extra query:

```sql
SELECT it.item_id, t.name
FROM item_tags it
JOIN tags t ON t.id = it.tag_id
WHERE it.item_id IN (:ids)
ORDER BY t.name;
```

Tag suggestions — the 50 most used tags, replacing the old "tags of the 50 most recently updated items" cache:

```sql
SELECT t.id, t.name, COUNT(it.item_id) AS item_count
FROM tags t
LEFT JOIN item_tags it ON it.tag_id = t.id
GROUP BY t.id
ORDER BY item_count DESC, t.name
LIMIT 50;
```

Unused tags are deleted when the last `item_tags` row referring to them is removed (delete-then-sweep inside the item write transaction).

## Search

`inventory.Search(term, limit)` keeps the tiering and ordering of `App\Actions\SearchItems` exactly:

1. exact `barcode`
2. `barcode` prefix
3. `name` prefix
4. `description` prefix
5. `name` or `description` substring, only while fewer than `limit` results exist

Each tier excludes ids already collected, orders by `name, id`, and takes `limit - len(collected)`. Terms shorter than 2 characters return nothing; `%`, `_` and `\` are escaped and matched literally (`LIKE … ESCAPE '\'`); only items with at least one batch are considered. Default limit 10.

The prefix tiers use `items_name`, `items_barcode` and `items_description`; the substring fallback is a scan, exactly as today. No FTS5 in v1.

## Migration policy

- Files are `NNNN_name.sql` under `internal/store/migrations/`, embedded with `//go:embed migrations/*.sql`.
- The runner sorts by numeric prefix, applies every file whose number is greater than `PRAGMA user_version`, each in its own transaction, and sets `user_version` to that number at the end of the transaction.
- An applied migration is never edited; fixes are a new file.
- `down` migrations do not exist. Rollback is restoring the file from backup, which is one file.
- `PRAGMA foreign_keys=ON` during migrations; no v1 migration needs a table rebuild.

## Import repairs

The Laravel export can carry states the new schema forbids. `freshguard import` **drops and reports** rather than invents:

| State in the export | Handling |
| --- | --- |
| `batches.quantity <= 0` | Dropped, counted in the report. |
| `batches.location_id` pointing at a missing location | Dropped, counted. |
| `batches.item_id` pointing at a missing item | Dropped, counted. |
| `locations.parent_id` pointing at a missing location or forming a cycle | Set to `NULL` (becomes a root location), counted. |
| Duplicate tag names differing only by case | Merged into the first seen, counted. |
| `items` with no batches | Kept, quantity 0. |

Details and verification queries are in `import.md`.
