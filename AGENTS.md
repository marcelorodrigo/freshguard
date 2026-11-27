# Agents

FreshGuard is one Go binary that serves a JSON API and an embedded Vue SPA, backed by one SQLite file. There is no PHP, no Laravel, no MariaDB, no Redis and no queue worker in the target system — that tree is legacy and is deleted in step 10 of the port. Work on the Go side only.

`docs/REWRITE.md` is the contract: goals, the decision log, the freeze list, the step-by-step execution plan. Read the decision row before changing an area it covers.

## Layout

```
cmd/freshguard/      flag parsing, subcommands, signal handling, shutdown, version
internal/config/     environment to Config; every field has a default
internal/store/       Open, embedded migrations, writer/reader pools   (step 1)
internal/ids/         UUIDv7 generation                                (step 1)
internal/inventory/   items, batches, locations, tags; no net/http     (step 3)
internal/auth/        users, argon2id, sessions; no net/http           (step 2)
internal/barcode/     Open Food Facts client                           (step 4)
internal/httpapi/     routes, middleware, JSON envelope, SPA serving
internal/web/         go:embed all:dist + SPA fallback
web/                  Vue 3.5 + Vite 8 + Tailwind 4 SPA, pnpm
mobile/android/       minimal WebView shell                            (step 6)
docs/                 the spec set: rewrite, architecture, schema, api, parity, import
```

Rules of thumb: `inventory` and `auth` never import `net/http`; handlers never write SQL; the store never formats JSON. New packages need a row in `docs/architecture.md` first.

## Commands

| Command | Does |
| --- | --- |
| `make dev` | Go server on :8080 and Vite on :5174 with `/api` proxied. SPA edits hot-reload; Go edits need a restart. |
| `make web` | Build the SPA into `internal/web/dist` for `go:embed`. |
| `make server` | Build one stripped, static `bin/freshguard`. |
| `make test` | `go test ./...` and the web tests. |
| `make check` | gofmt, vet, staticcheck, Go tests, vue-tsc, ESLint, Vitest. The only gate CI runs. |
| `make size` · `make rss` · `make cold` | Enforce the budgets in `docs/REWRITE.md`; each fails over budget. |

Single Go test: `go test ./internal/httpapi -run TestHealth -v`. Single web test: `cd web && pnpm vitest run src/App.test.ts`. Format: `make fmt`. Static analysis alone: `staticcheck ./...` (install once with `go install honnef.co/go/tools/cmd/staticcheck@2026.2.2`).

Run `make check` before every commit. Do not push with a red gate.

## Go conventions

- Go 1.26+, stdlib `net/http` with method-pattern `ServeMux`. No router, no ORM, no dependency added without approval.
- Declare no mutable package-level state. Constructors take their dependencies and return concrete types; accept an interface only at a seam that needs one.
- Type everything: parameters, results, struct fields. No `any` in signatures unless the value is genuinely dynamic.
- Wrap errors with context (`fmt.Errorf("load item: %w", err)`); never swallow one silently. Log at the boundary that owns the failure, with `slog` and structured attributes.
- Every write path is one transaction. Migrations are `internal/store/migrations/NNNN_name.sql`, applied in order inside a transaction, bumping `PRAGMA user_version`; an applied file is never edited.
- Tests are table-driven where the cases differ only in input, and use `t.TempDir()` for a data directory. `httptest` covers handlers and the Open Food Facts fake; do not mock the database.
- Serve JSON through `httpapi`'s envelope helpers so `{"error":{"code":…}}` and `Content-Type` stay uniform.

## Vue and TypeScript conventions

- Vue 3.5 single-file components with `<script setup lang="ts">`. TypeScript is strict: no `any`, no `@ts-ignore`, no non-null `!` to silence a real null case.
- pnpm only (`pnpm install`, `pnpm add`, `pnpm run …`); never npm or yarn. The lockfile `web/pnpm-lock.yaml` is committed, and `packageManager` pins the version.
- Tailwind 4 utilities in the markup. Reusable primitives are shadcn-vue-style components copied into `web/src/components/ui` on `reka-ui`, not a component-library dependency.
- Every user-facing string goes through `vue-i18n` (`en` and `pt-BR`, `pt-BR` typed against the `en` schema). The API answers rule tokens, never prose.
- The API lives behind `web/src/api.ts`; screens call one function each and never `fetch` directly.
- Tests: Vitest + Vue Test Utils, API module mocked with `vi.mock`. Assert rendered text with `[data-test="…"]` hooks, not CSS classes or snapshots.

## Kotlin conventions (mobile/android)

- One `MainActivity`: a setup screen for the server address, then a `WebView` that loads it. Back goes through the WebView history before it exits.
- No JavaScript bridge in v1. When v2 adds one, it is origin-restricted and additive.
- Gradle with a version catalog; no Compose. CI produces a debug APK artifact only — no signing, no release.

## Git

Conventional commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`), scoped by area when it helps (`feat(auth): …`). Keep commits small and green; prefer a new commit over amending a published one. Never commit `.env`, `data/`, `bin/` or a built `internal/web/dist`.

## Definition of done

1. The behaviour has a test that fails without the change, and `make check` is green.
2. New or changed endpoints, screens or schema appear in `docs/api.md`, `docs/PARITY.md` or `docs/schema.md`.
3. Nothing from the freeze list in `docs/REWRITE.md` slipped in.
4. Touching the build or runtime dependencies means `make size`, `make rss` and `make cold` still pass.

## Agent skills

### Issue tracker

Issues live as GitHub issues in `marcelorodrigo/freshguard`, driven by the `gh` CLI, with blocking edges as native issue dependencies. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
