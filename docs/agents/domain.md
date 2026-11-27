# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase. This repo is **single-context**: one glossary and one ADR directory at the root, covering the whole application.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root.
- **`docs/adr/`**: read ADRs that touch the area you're about to work in.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## File structure

```
/
├── GLOSSARY.md
├── docs/
│   ├── adr/
│   │   ├── 0001-….md
│   │   └── 0002-….md
│   ├── agents/          ← the files this setup produced
│   └── *.md             ← the rewrite's design documents
├── app/                 ← Laravel today
├── internal/  web/  mobile/android/   ← after the Go/SQLite rewrite
└── …
```

The layout of the code changes during the rewrite (`docs/REWRITE.md`); the glossary and ADRs do not depend on it.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `GLOSSARY.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

The design documents in `docs/` already pin some vocabulary — `Item`, `Batch`, `Location`, `consume`, `expiry`, `Quick Consume`, `tiered search`. Treat those spellings as fixed.

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0007 (event-sourced orders), but worth reopening because…_
