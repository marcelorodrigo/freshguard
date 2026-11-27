-- The v1 schema. docs/schema.md is the reference; this file is the source.
--
-- IDs are UUIDv7 text, timestamps are RFC3339 UTC text, dates are YYYY-MM-DD.
-- Nothing is soft-deleted.

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
