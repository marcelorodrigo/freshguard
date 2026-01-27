# Agents

This file defines how automated agents (and humans acting like agents) should build, lint, test
and modify this repository. It focuses on commands, code-style rules, testing conventions and
project-specific conventions so agents can operate predictably.

- Keep changes small and amend only when explicitly requested; prefer new commits over amending
  published commits.

Build / Lint / Test (DDEV-aware)
- Start local environment: `ddev start` then `ddev composer dev` (runs server, queue, logs, vite)
- Install/update PHP deps: `ddev composer install` (or `ddev composer update` when needed)
- Format code (all files): `ddev composer pint` ; changed-only: `ddev composer pint:dirty`
- Static analysis: `ddev composer phpstan` (2GB memory configured in composer script)
- Run full test suite: `ddev composer test` (runs `php artisan test --exclude-group disabled`)
- Run test coverage: `ddev composer test:coverage`
- Run a single test file (example):
  `ddev composer test -- --filter tests/Unit/ItemTest.php`
- Run a single test class or method (examples):
  `ddev composer test -- --filter "ItemTest::it_updates_quantity"`
  or for Pest directly: `ddev ssh` then `vendor/bin/pest tests/Unit/ItemTest.php --filter="it updates quantity"`

Composer scripts (quick reference)
- `composer test` — run tests (wrapped by DDEV in workflows)
- `composer pint` / `pint:dirty` — code formatting
- `composer phpstan` — static analysis

Project coding style and rules
- Language & target: PHP 8.5; follow PSR-12 formatting and Laravel conventions.
- Strict types: every PHP file MUST include `declare(strict_types=1);` immediately after `<?php`.
- No `@phpstan-ignore-next-line`. Always fix root causes reported by PHPStan.

Imports and namespacing
- Use `use` statements for all non-global classes; one import per line (PSR-12).
- Order import groups with a single blank line between groups: 1) PHP built-ins 2) 3rd party
  packages (vendor) 3) App namespaces (App\...). Within groups prefer alphabetical order.

Types and signatures
- Type-hint everything: scalar types, class types, and return types on methods/functions.
- Use readonly properties where appropriate (PHP 8.5 readonly features).
- Prefer union/nullable types over docblock-only types.

Models and Eloquent
- Models live in `app/Models/` and use UUIDs via `HasUuids` (except `User` which uses int id).
- Every model must have comprehensive PHPDoc: `@property`, `@property-read`, `@method`, `@mixin`.
- Relationship methods must be documented and cast before returning. Example:
  ```php
  /** @return BelongsTo<Location, Item> */
  public function location(): BelongsTo
  {
      /** @var BelongsTo<Location, Item> */
      return $this->belongsTo(Location::class);
  }
  ```

Naming conventions
- Classes, Traits, Enums: PascalCase (e.g., `Item`, `SetFirstUserAsAdmin`).
- Methods and variables: camelCase (e.g., `updateItemQuantity`, `$expirationNotifyDays`).
- Constants: UPPER_SNAKE_CASE.
- Filament directories: lowercase with dashes for subfolders (e.g., `app/Filament/Resources/items/`).

Formatting & whitespace
- Use Laravel Pint (PSR-12) as the single source of truth for formatting.
- Keep line length sensible (~120 chars); Pint will enforce most rules.

Error handling & logging
- Prefer throwing specific exceptions; avoid using generic `
  Exception` unless rethrowing or wrapping with context.
- Do not swallow exceptions silently. When catching, always log at an appropriate level and
  either rethrow or return a well-defined result.
- Use Laravel's `Log` facade for structured messages: `Log::info()`, `Log::warning()`, `Log::error()`.

Testing conventions
- Test framework: Pest with `pestphp/pest-plugin-livewire` for Filament UI tests.
- Use `use function Pest\Livewire\livewire;` in Filament tests.
- Factories: use `->make()` for form population and `->create()` for database persistence checks.
- Use SQL `orderBy()` for sorting assertions; do not rely on collection `sortBy()` in tests.
- Keep tests deterministic: seed only what's required, use `beforeEach()` to authenticate where
  necessary, and clean state between tests.

Filament-specific rules
- Forms and table schemas split into `Schemas/` and `Tables/` classes with static `configure()`.
- Computed/read-only fields (e.g., `Item.quantity`) are read-only in forms and hidden on create.
- Use `__('...')` for every user-facing string (i18n).

CI and commit guidance
- CI uses the composer scripts and runs Pint, PHPStan and tests. Follow local pre-check order:
  `ddev composer pint && ddev composer phpstan && ddev composer test` before pushing.
- Do not commit secrets (.env) or generated artifacts. If CI fails due to style or static analysis
  fix locally and re-run the three checks.

Copilot / Cursor rules
- Repository contains no `.github/copilot-instructions.md` or `.cursor` rules at time of writing.
  Agents should fall back to this file when deciding repository conventions.

When to ask questions
- Only ask if a change would be destructive (database/production), requires secrets, or when the
  requested behavior is ambiguous and cannot be inferred from code and conventions above.

Next steps for contributors
1) Run formatting and static analysis: `ddev composer pint && ddev composer phpstan`.
2) Run the single test you're working on with `ddev composer test -- --filter "YourTest::method"`.
3) Open a small PR with focused changes and the three-checks green in CI.
