# Agent Instructions for FreshGuard

## Core Expertise & Philosophy
- You are an expert in Laravel 12, Filament v4 and Tailwind CSS v4, with a strong emphasis on PHP best practices.
- Follow Laravel best practices, conventions, and SOLID principles.
- Use descriptive variable and method names.
- Favor dependency injection and service containers.
- Always implement code that is compatible with the versions declared in `composer.json` and `package.json`.

## PHP & Code Quality
- Use PHP 8.5 features exclusively (match expressions, named arguments, readonly properties, etc.).
- Follow PSR-12 coding standards and strict typing that meet PHPStan/Larastan level 10.
- **All PHP files MUST use `declare(strict_types=1);` as first statement after opening tag**.
  - **Known issue**: `Batch.php` and `Location.php` currently missing this - fix when editing these files.
- Use strict types and type hints in all methods, properties, and return statements.
- Document all model properties, relationships, and custom query scopes with PHPDoc:
  - Use `@property`, `@property-read`, `@method`, `@mixin` tags for PHPStan level 10 compatibility
  - For relationships, cast return types before return: `/** @var BelongsTo<Parent, Child> */ return $this->belongsTo(...);`
  - Example for Item model:
    ```php
    /**
     * @property uuid $id
     * @property string $name
     * @property-read Illuminate\Database\Eloquent\Collection<Batch> $batches
     * @method static Builder withBatchesExpiringWithinDays(int $days)
     */
    class Item extends Model
    ```
- Implement error handling and logging with Laravel's built-in features (use `Log::info()`, `Log::warning()`, `Log::error()`).
- **Never use `@phpstan-ignore-next-line`. Always fix the real PHPStan-reported errors instead.**
- **After every implementation or change, always run**: `ddev composer pint` → `ddev composer phpstan` → `ddev composer test`

## Laravel Core Practices
- Use Laravel's built-in features, helpers, and directory structure.
- Use lowercase with dashes for directories (e.g., `app/Filament/Resources/Items/`).
- Use Laravel's validation (via Filament form validation), middleware, Eloquent ORM, query builder, migrations, and seeders.
- Follow Laravel's MVC architecture and routing system.
- Use Blade for views with i18n `__('...')` for all user-facing strings.
- Implement Eloquent relationships, authentication, events, and listeners.

## Tailwind CSS
- Use Tailwind utility classes for responsive design.
- Configured via Vite with `@tailwindcss/vite` plugin (see `vite.config.js`).
- Use utility classes in Blade and Filament components.
- Optimize for production by purging unused CSS classes.

## Project Overview
**FreshGuard** is a home inventory management system for tracking food items, batches, and expiration dates. Built with Laravel 12, Filament v4 admin UI, and Tailwind CSS v4. Domain models: `Item`, `Batch`, `Location`, `User`. Uses DDEV for local development (SQLite for dev, configurable for prod).

## Architecture & Patterns

### **Domain Models**
All in `app/Models/` with UUIDs as primary keys (`HasUuids` trait), strict typing, and comprehensive PHPDoc.
- `Item`: has many `Batch`es, belongs to `Location`, stores tags as JSON array (`casts: ['tags' => 'array']`). Includes custom scope `withBatchesExpiringWithinDays()` for expiration queries.
- `Batch`: belongs to `Item`, has `expires_at` datetime (cast to 'datetime'), auto-updates parent item quantity via model events (`booted()` with `saved()` and `deleted()` callbacks).
- `Location`: self-referential (parent/children via `parent_id`), has items. Includes `expiration_notify_days` for location-level defaults.
- `User`: Uses auto-incrementing integer ID (not UUID), includes `is_admin` boolean flag. Implements `FilamentUser` and `MustVerifyEmail` interfaces. Admin check via `isAdmin()` method.
- **Relationships**: **Always cast return types** with `/** @var BelongsTo<Location, Item> */` before `return $this->belongsTo(...)`.

### **Filament Resources**
Modular structure in `app/Filament/Resources/{ResourceName}/`:
- `{ResourceName}Resource.php`: Routes model to Filament, defines navigation (icons use `BackedEnum` or string), title attribute, pages, and relation managers.
- `Schemas/{FormName}.php`: Static `configure(Schema $schema)` method returns form components. Example: `ItemForm::configure($schema)`.
- `Tables/{TableName}.php`: Static `configure(Table $table)` method returns table columns/actions. Can use `modifyQueryUsing()` for subqueries (e.g., earliest batch expiration).
- `Pages/`: CRUD pages (e.g., `ManageItems`, `CreateItem`, `EditItem`).
- `RelationManagers/`: Nested resource tables (e.g., `BatchesRelationManager` on Item edit page).
- `Actions/`: Custom actions (e.g., `ToggleRegistrationsAction` for user management).

### **Critical Design Patterns**
- **No Service Layer**: Business logic lives in Eloquent models and model events, not separate classes. Use `Model::booted()` with hooks (`saved()`, `deleted()`) for side effects.
- **No Repository Pattern**: Direct Eloquent queries—no abstraction layer. Models are the data access pattern.
- **Model Events for Sync**: `Batch` model auto-syncs parent `Item.quantity` via `booted()` events—never manually update parent quantities.
- **Read-Only Fields**: Computed fields like `Item.quantity` are read-only in forms (hidden on create, read-only helper text on edit).
- **Admin by Default**: First registered user becomes admin (via `SetFirstUserAsAdmin` listener). Check `User::isAdmin()` in policies/middleware.

### **Testing**
- **Framework**: Pest-based with `pestphp/pest-plugin-livewire` for Filament.
- **Helper**: Use `use function Pest\Livewire\livewire;` for testing Filament pages/components.
- **Assertions**: Chain assertions for readability. Example: `livewire(Page::class)->fillForm([...])->call('save')->assertNotified()`.
- **Database Queries**: Use `orderBy()` for sorting (SQL), NOT `collection::sortBy()` (PHP sorts differently).
- **Factories**: All test data via factories in `database/factories/`. Use `->make()` for form population, `->create()` for database assertions.

### **Code Quality**
- **PHPStan Level 10**: Enforced in `phpstan.neon`. Run `ddev composer phpstan` before committing.
- **Formatting**: Laravel Pint enforces PSR-12. Use `ddev composer pint` (all files) or `ddev composer pint:dirty` (changed only).
- **Test Coverage**: Aim for comprehensive coverage. Run `ddev composer test:coverage` to generate report.

## Developer Workflows
- **DDEV Required**: All commands prefixed with `ddev` (e.g., `ddev composer test`, `ddev artisan migrate`, `ddev npm run dev`).
  - Start: `ddev start`, Stop: `ddev stop`, SSH: `ddev ssh`, Launch browser: `ddev launch`.
  - Email testing: `ddev mailpit` (opens Mailpit for viewing dev emails).
- **Local Dev**: `ddev composer dev` runs all services in parallel via `concurrently`:
  - **PHP Server** (port 8000): `php artisan serve`
  - **Queue Worker** (processes jobs): `php artisan queue:listen --tries=1`
  - **Logs Stream** (real-time pail): `php artisan pail --timeout=0`
  - **Vite Dev Server** (HMR for CSS/JS): `npm run dev`
  - Stop with Ctrl+C (kills all 4 processes).
- **Build Assets**: `ddev npm run build` (Vite + Tailwind production build).
- **Testing**: `ddev composer test` (Pest, excludes disabled group). Coverage: `ddev composer test:coverage`.
- **Static Analysis**: `ddev composer phpstan` (2GB memory limit).
- **Code Formatting**: `ddev composer pint` (all files) or `ddev composer pint:dirty` (changed files only).
- **Migrations**: `ddev artisan migrate`, `ddev artisan migrate:rollback`, `ddev artisan db:seed`.
- **Deployment**: `ddev composer deploy` (optimizes Laravel & Filament caches).

## Project-Specific Conventions
- **UUIDs**: All models use UUIDs as primary keys (`HasUuids` trait) EXCEPT `User` which uses auto-incrementing integer IDs.
- **Eloquent Relationships**: Must type-hint with PHPDoc generics: `@return BelongsTo<Parent, Child>`. Cast before return statement.
- **Filament Resource Structure**: Separate `Schemas/` and `Tables/` classes with static `configure()` methods for reusability and testing.
- **Tags**: Stored as JSON array on `Item` model (not separate table). Use `TagsInput::make('tags')` with dynamic suggestions from existing tags (query all items, flatten, unique, sort).
- **Quantity**: Item `quantity` is computed from batches via `Batch` model events, read-only in forms (hidden on create, read-only on edit with helper text).
- **Barcode Auto-Population**: When barcode is entered/scanned in `ItemForm`, it automatically fetches product data from OpenFoodFacts API and populates empty fields (name, description, tags from categories). Shows notifications for success/failure/warnings.
- **Expiration Tracking**: Items have `expiration_notify_days` field. Tables can show `earliest_batch_expiration` via subquery in `modifyQueryUsing()`.
- **Localization**: All strings use `__('key')` for i18n. Filament forms/tables use label methods: `->label(__('Name'))`.
- **Model Events**: `Batch` model uses `booted()` with `saved()` and `deleted()` events to auto-update parent `Item` quantity via `updateItemQuantity()` method.
- **Admin System**: First registered user automatically becomes admin (via `SetFirstUserAsAdmin` listener). Admins can toggle registrations via `ToggleRegistrationsAction` (updates .env using `jackiedo/dotenv-editor`). Config stored in `config/freshguard.php`.
- **User Policies**: `UserPolicy` ensures only admins can view/create users, users can view/edit themselves, admins can't delete themselves.

## Integration Points
- **Filament**: All admin CRUD via Filament resources. Custom components: `marcelorodrigo/filament-barcode-scanner-field` for barcode input.
- **OpenFoodFacts**: `openfoodfacts/openfoodfacts-laravel` package integrated in `ItemForm` for automatic product data lookup by barcode. Fetches name, description (generic_name), and tags (from categories_hierarchy). Only populates empty fields.
- **Tailwind**: Vite plugin config in `vite.config.js` includes DDEV-specific CORS for HMR (`origin` config for `.ddev.site` domains).
- **Livewire**: Filament uses Livewire; test with `livewire(PageClass::class)` (Pest helper).
- **Barcode Scanner**: `BarcodeInput::make('barcode')` field with `live(onBlur: true)` and `afterStateUpdated()` callback triggers OpenFoodFacts lookup.
- **Dotenv Editor**: `jackiedo/dotenv-editor` used in `ToggleRegistrationsAction` to persist config changes to `.env` file dynamically.

## Key Files & Directories
- `app/Models/` — Domain models (strict types, UUIDs, relationships)
- `app/Filament/Resources/` — Modular Filament resources (Resource, Schemas, Tables, Pages, RelationManagers)
- `app/Listeners/` — Event listeners (e.g., `SetFirstUserAsAdmin` for auto-admin on first registration)
- `app/Policies/` — Authorization policies (e.g., `UserPolicy` for admin/user permissions)
- `config/freshguard.php` — Application-specific configuration (registrations_enabled)
- `database/factories/` — Model factories for tests/seeders
- `tests/Feature/Filament/Resources/` — Filament page/action tests
- `tests/Unit/Models/` — Model unit tests
- `composer.json` — Scripts: dev, test, phpstan, pint, deploy
- `package.json` — Vite dev/build, Tailwind v4, concurrently
- `vite.config.js` — DDEV-aware Vite + Tailwind config
- `phpstan.neon` — Level 10 static analysis

## Example: Adding a New Resource
1. **Model**: Create in `app/Models/` with `declare(strict_types=1);`, `HasUuids`, PHPDoc (@property, @property-read, @method).
2. **Factory**: Add to `database/factories/` with typed return values.
3. **Migration**: Use `$table->uuid('id')->primary();` for UUID primary key.
4. **Filament Resource**:
   - Create `app/Filament/Resources/{Name}/{Name}Resource.php` (navigation, model, pages).
   - Add `Schemas/{Name}Form.php` with static `configure(Schema $schema)`.
   - Add `Tables/{Name}Table.php` with static `configure(Table $table)`.
   - Create `Pages/` (List, Create, Edit) extending Filament base pages.
5. **Tests**: Feature tests in `tests/Feature/Filament/Resources/{Name}/` using `livewire()` and chained assertions.
6. **Run**: `ddev composer pint`, `ddev composer phpstan`, `ddev composer test`.

## Filament Testing Best Practices

### Testing Helpers & Setup
- Use Pest's `livewire()` helper (not `Livewire::test()`) for testing Filament components: `use function Pest\Livewire\livewire;`
- Use `$this->assertDatabaseHas()` and `$this->assertDatabaseMissing()` for database assertions
- Always authenticate before testing: Use `beforeEach()` with `actingAs()` or `User::factory()->create()`
- Chain assertions for cleaner, more readable tests

### Testing Resource Pages
- **List Pages**: Use `assertSuccessful()`, `assertCanSeeTableRecords()`, `searchTable()`, `sortTable()`, `filterTable()`, `callTableAction()`, `callTableBulkAction()`
- **Create Pages**: Use `fillForm()`, `call('create')`, `assertNotified()`, `assertHasFormErrors()` for validation
- **Edit Pages**: Use `assertSchemaStateSet()` to verify form data, `fillForm()`, `call('save')`, `callAction(DeleteAction::class)`
- **Validation**: Use Pest datasets (`->with([...])`) to test multiple validation rules concisely without repeating code

### Testing Tables
- **Record Visibility**: `assertCanSeeTableRecords()`, `assertCanNotSeeTableRecords()`, `assertCountTableRecords()`
- **Columns**: `assertCanRenderTableColumn()`, `assertCanNotRenderTableColumn()`, `assertTableColumnExists()`
- **Search**: Use `searchTable()` for global search; use `searchTableColumns(['column' => 'value'])` for column-specific search
- **Sorting**: Use **database `orderBy()` NOT collection `sortBy()`** - SQL and PHP sort differently! Example:
  ```php
  $sorted = Model::query()->orderBy('name')->get();
  livewire(ListPage::class)->sortTable('name')->assertCanSeeTableRecords($sorted, inOrder: true);
  ```
- **Filtering**: Use `filterTable()` and chain with `assertCanSeeTableRecords()` / `assertCanNotSeeTableRecords()`
- **Pagination**: If table uses pagination, `assertCanSeeTableRecords()` only checks page 1; call `call('gotoPage', 2)` to switch pages
- **Deferred Loading**: If table uses `deferLoading()`, call `loadTable()` before `assertCanSeeTableRecords()`

### Testing Schemas (Forms & Infos)
- **Form Data**: Use `fillForm([...])` to populate fields; use `assertSchemaStateSet([...])` to verify state
- **Form Existence**: `assertFormExists()`, `assertFormFieldExists()`, `assertFormFieldDoesNotExist()`
- **Field Visibility**: `assertFormFieldVisible()`, `assertFormFieldHidden()`
- **Field State**: `assertFormFieldEnabled()`, `assertFormFieldDisabled()`
- **Schema Components**: Use `assertSchemaComponentExists('key')` to verify components like Sections; pass a callback for truth tests
- **Validation**: `assertHasFormErrors(['field' => 'rule'])`, `assertHasNoFormErrors()`
- **Repeaters**: Use `Repeater::fake()` at test start to disable UUIDs; test state with `assertSchemaStateSet()` function callback
- **Builders**: Similar to repeaters; use `Builder::fake()` for UUID-free testing

### Testing Relation Managers
- Test directly with `ownerRecord` and `pageClass` parameters:
  ```php
  livewire(RelationManagerClass::class, [
      'ownerRecord' => $parent,
      'pageClass' => ParentEditPage::class,
  ])->assertSuccessful()->assertCanSeeTableRecords($relatedRecords);
  ```
- Create/edit related records: `callAction(CreateAction::class, [...data...])` or `callTableAction(EditAction::class, $record, [...data...])`
- Use `callTableAction(DeleteAction::class, $record)` to delete related records

### Testing Actions
- **Header/Page Actions**: Use `callAction('actionName')` or `callAction(ActionClass::class)`
- **Table Actions**: Use `callTableAction(ActionClass::class, $record)` or `TestAction::make(ActionClass::class)->table($record)`
- **Bulk Actions**: Select records with `selectTableRecords($records)`, then `callAction(TestAction::make(ActionClass::class)->table()->bulk())`
- **Action Existence**: `assertActionExists()`, `assertActionHidden()`, `assertActionVisible()`, `assertActionDisabled()`, `assertActionEnabled()`
- **Action Configuration**: Pass a callback to `assertActionExists(function() {})` for truth tests
- **Modal Actions**: Use `fillForm()` with action data, chain with `call()` or `callMountedAction()`

### Key Assertions & Methods
| Purpose | Method |
|---------|--------|
| Load page success | `assertSuccessful()` or `assertOk()` |
| Records visible | `assertCanSeeTableRecords($records)` |
| Records hidden | `assertCanNotSeeTableRecords($records)` |
| Column renders | `assertCanRenderTableColumn('name')` |
| Column hidden | `assertCanNotRenderTableColumn('name')` |
| Form data matches | `assertSchemaStateSet([...])` |
| Form errors | `assertHasFormErrors([...])` |
| Notification shown | `assertNotified()` |
| Redirect occurred | `assertRedirect()` |
| Action exists | `assertActionExists('name')` |
| Field exists | `assertFormFieldExists('name')` |

### Common Test Patterns
1. **CRUD Operations**: Create with `call('create')` or `fillForm()` + `call('create')`; update with `fillForm()` + `call('save')`; delete with `callAction(DeleteAction::class)`.
2. **Search/Sort/Filter**: Chain methods after loading page: `->searchTable(...)->assertCanSeeTableRecords(...)->sortTable(...)->assertCanSeeTableRecords(...)`
3. **Validation Datasets**: Use Pest's `->with([...])` to test multiple scenarios:
   ```php
   it('validates', function (array $data, array $errors) {
       livewire(Page::class)->fillForm([...$data])->call('save')->assertHasFormErrors($errors);
   })->with([
       'field required' => [['field' => null], ['field' => 'required']],
   ]);
   ```
4. **Database Assertions**: After UI actions, verify database state: `assertDatabaseHas(Model::class, [...])` or `assertDatabaseMissing(Model::class, [...])`

### Best Practices Summary
- ✅ Keep tests focused on Filament UI behavior, not business logic (move model tests to Unit tests)
- ✅ Use dataset tests (`->with([])`) to reduce boilerplate for validation/edge cases
- ✅ Use database `orderBy()` for sorting assertions, not collection `sortBy()`
- ✅ Chain assertions for readability
- ✅ Test happy paths and validation errors separately
- ✅ Remove redundant assertions (e.g., don't test that standard Filament actions exist if not customized)
- ✅ Use factory data (`->make()`) for form population
- ✅ Combine multiple logical tests into single chained test (e.g., search, sort, filter in one test)

## References
- [Filament Testing Docs](https://filamentphp.com/docs/3.x/admin/testing) (Tables, Schemas, Resources, Actions)
- [Filament Docs](https://filamentphp.com/docs/2.x/admin/resources/overview)
- [Laravel Docs](https://laravel.com/docs/12.x/)
- [Pest Docs](https://pestphp.com/docs/introduction)

