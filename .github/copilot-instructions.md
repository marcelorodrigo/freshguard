
# Copilot Instructions for freshguard

## Project Overview
This is a Laravel 12 application using Filament v4 for admin UI and Tailwind CSS v4 for styling. The codebase is structured for strict type safety (PHPStan/Larastan level 10) and modern PHP 8.4 features. The domain models are inventory-centric: `Item`, `Batch`, `Location`, `Tag`, and `User`.

## Architecture & Patterns
- **Domain Models**: See `app/Models/`. All models use UUIDs as primary keys and Eloquent relationships are strictly typed and documented.
	- Example: `Item` has many `Batch`es, belongs to a `Location`, and has many `Tag`s (many-to-many).
- **Admin UI**: Built with Filament Resources (see `app/Filament/Resources/`). Each resource is modular: `Resource.php` (wires model), `Schemas/` (form fields), `Tables/` (table columns), `Pages/` (CRUD pages).
- **Testing**: Uses Pest for feature and unit tests. Factories are in `database/factories/`. Tests are in `tests/Unit/Models/` and `tests/Feature/Filament/Resources/`. Assertions can be chained together.
- **Strict Coding**: All code must use strict types, PSR-12, and be compatible with PHPStan level 10. See `phpstan.neon`.

## Developer Workflows
- **Local Dev**: Use `composer run dev` (runs PHP server, queue, logs, and Vite/Tailwind in parallel via `concurrently`).
- **Build Assets**: `npm run build` (Vite + Tailwind).
- **Testing**: `composer test` (runs Pest/PHPUnit, clears config cache). Coverage: `composer test:coverage`.
- **Static Analysis**: `composer phpstan` (runs Larastan at level 10).
- **Linting/Fixing**: `composer pint:dirty` (fixes only changed files).
- **Migrations/Seeders**: Use standard Laravel artisan commands. Factories and seeders are in `database/`.

## Project-Specific Conventions
- **UUIDs**: All models use UUIDs as primary keys (`HasUuids`).
- **Eloquent Relationships**: Always type-hint and document relationships. See `app/Models/Item.php` for examples.
- **Filament Resource Structure**: Each resource is split into `Resource.php`, `Schemas/`, `Tables/`, and `Pages/` for modularity and testability.
- **Testing Patterns**: Use Pest for all new tests. Factories are used for all model instantiations in tests.
- **Translations**: Use `__('...')` for all user-facing strings (see Filament forms).

## Integration Points
- **Filament**: All admin CRUD is via Filament. See `app/Filament/Resources/` for resource definitions.
- **Tailwind**: Configured via Vite (`vite.config.js`). Use utility classes in Blade and Filament components.
- **Livewire**: Used by Filament for dynamic admin UI (see feature tests for Livewire usage).
- **External Services**: Credentials and endpoints are managed via `config/services.php` and `.env`.

## Key Files & Directories
- `app/Models/` — Domain models with strict typing and relationships
- `app/Filament/Resources/` — Filament resource modules (admin UI)
- `database/factories/` — Model factories for tests and seeding
- `tests/` — Pest-based unit and feature tests
- `vite.config.js` — Vite + Tailwind config
- `composer.json` — Scripts for dev, test, static analysis
- `phpstan.neon` — PHPStan/Larastan config (level 10)

## Example: Adding a New Resource
1. Create Eloquent model in `app/Models/` with strict types and docblocks.
2. Scaffold Filament resource: `app/Filament/Resources/{Resource}/` with `Resource.php`, `Schemas/`, `Tables/`, `Pages/`.
3. Add factories and tests in `database/factories/` and `tests/`.
4. Register routes/pages as needed in Filament resource.

## References
- [Filament Docs](https://filamentphp.com/docs/2.x/admin/resources/overview)
- [Laravel Docs](https://laravel.com/docs/12.x/)
- [Pest Docs](https://pestphp.com/docs/introduction)
