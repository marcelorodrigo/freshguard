---
title: Parity checklist
summary: "Screen-by-screen proof that the rewrite is a port, not a redesign: fields, rules, copy, ordering and empty states."
audience: developer
section: Rewrite
specs: []
screens: [setup, login, dashboard, items, item-form, batches, locations, quick-consume, users, profile]
order: 50
---

# Parity checklist

Tick a box when the Vue screen and its Go route behave like the Filament screen. Tick the API box only when the route exists **and** its test covers the listed rule. The full manual walkthrough happens once before merge (step 5 of `REWRITE.md`).

A box that cannot be ticked is either a bug or an item for `Known deviations` at the bottom — never silently dropped.

## Navigation and chrome

- [ ] Left navigation with a **Dashboard** item and an **Inventory** group containing **Items** and **Quick Consume**; **Locations** and **Users** as their own items.
- [ ] `Users` is visible only to admins; every other item to any signed-in user.
- [ ] Nav order matches the current panel (verify side by side; Filament sorts by `navigationSort`, then label).
- [ ] Top-right user menu: profile, locale switch (English / Português do Brasil), logout.
- [ ] Mobile: the sidebar collapses into a drawer; the layout stays usable at 375 px.
- [ ] Every screen is reachable without a full page reload after login, and a hard reload keeps you on the same URL.

## Setup (first run only)

Route `/setup`. API `POST /api/v1/setup`.

- [ ] Shown only when `GET /api/v1/health` reports `setup_required: true`; any other route redirects here.
- [ ] Fields: name, email, password, password confirmation.
- [ ] Validation: name required ≤ 255; email required, valid, unique; password 8–255 characters, confirmation matches.
- [ ] Submit creates an admin, signs them in, lands on the dashboard.
- [ ] Visiting `/setup` after the first account exists redirects to `/login`.
- [ ] No link to registration anywhere: this screen cannot be reached again.

## Login

Route `/login`. API `POST /api/v1/session`, `DELETE /api/v1/session`.

- [ ] Fields: email, password.
- [ ] Wrong email or password → one message, `"These credentials do not match our records."`, no indication of which was wrong.
- [ ] Submit signs in and lands on the dashboard; a deep link you were sent to wins over the dashboard.
- [ ] Signing out clears the cookie and returns to `/login`.
- [ ] After five failed attempts the button disables briefly with `"Too many attempts. Try again in a minute."` (the server's bucket is 10 per 15 minutes; the UI backs off sooner).
- [ ] A user with `must_reset` lands on the change-password form before anything else.
- [ ] No "remember me" (deviation) and no "forgot password" link (deviation).

## Dashboard

Route `/`. API `GET /api/v1/dashboard/recently-expired`.

- [ ] Heading **Recently Expired**.
- [ ] Table of expired batches: **Item**, **Location**, **Quantity**, **Expiration Date** (`d-m-Y`).
- [ ] Ordered by expiry ascending; at most five rows; no pagination.
- [ ] Clicking a row opens that item's edit screen.
- [ ] Empty state: **Congratulations, no expired items** with a check icon.
- [ ] Location, Quantity and Expiration Date hide progressively on narrow screens (Filament `visibleFrom`: lg, sm, md).

## Items list

Route `/items`. API `GET /api/v1/items`, `POST /api/v1/items/bulk-delete`, `DELETE /api/v1/items/{id}`.

- [ ] Columns, in order: **Name**, **Quantity**, **First Batch Expiration** (Filament's default date display — verify against the panel when porting).
- [ ] Toggleable, hidden by default: **Description**, **Tags** (as badges), **Barcode**, **Created**, **Updated**.
- [ ] Search box filters by name, description, tags and barcode (substring).
- [ ] Sortable: Name, Quantity, First Batch Expiration, Created, Updated.
- [ ] Pagination, 10 per page (verify the panel's page options and copy them).
- [ ] Row actions: **Edit**, **Delete** (with confirmation).
- [ ] Bulk select + **Delete** (with confirmation and a success toast).
- [ ] Header action: **New Item** with a plus icon.
- [ ] Item with no batches shows quantity `0` and an empty First Batch Expiration.
- [ ] Empty state when the household has no items yet.

## Item form (create and edit)

Routes `/items/new`, `/items/{id}`. API `GET|POST /api/v1/items`, `PATCH /api/v1/items/{id}`, `GET /api/v1/barcode/{code}`, `GET /api/v1/tags`.

**Basic Information**

- [ ] **Name**: required, ≤ 255.
- [ ] **Barcode**: ≤ 255, optional, with the camera scan button.
- [ ] Scanning or blurring a changed barcode calls the lookup.
- [ ] Lookup pre-fills **only empty fields**: name from `product_name`, description from `generic_name`, tags from `categories_hierarchy` with the `en:` prefix stripped.
- [ ] Toasts: `Product not found` / `No product information found for this barcode.`; `Invalid barcode` / `The barcode format is not recognized. Please check the digits and try again.`; `Product lookup unavailable` / `We could not reach the product database. You can still enter the details manually.`; `Product data loaded` / `Populated: name, tags`; `Product found` / `No empty fields to populate.`
- [ ] No lookup when the barcode is unchanged or empty.
- [ ] **Description**: textarea, ≤ 1000, optional, full width.

**Inventory Details**

- [ ] **Quantity**: read-only, hidden on create, helper text `The quantity is computed from all batches`.
- [ ] **Tags**: chip input, placeholder `Add tags...`, suggestions from `GET /api/v1/tags` (50 most used).
- [ ] Tags are trimmed, empty ones dropped, duplicates merged case-insensitively.

**Create vs edit**

- [ ] Create: the batches section is absent; saving returns to the items list with a success toast.
- [ ] Edit: the batches section is present (below); saving keeps you on the page with a success toast.

## Batches (inside the item edit screen)

API `GET|POST /api/v1/items/{id}/batches`, `PATCH|DELETE /api/v1/batches/{id}`.

- [x] Section title **Batches**; header action **New Batch** with a plus icon.
- [x] Table columns: **Location**, **Expiration Date** (`d-m-Y`), **Quantity**, and **Created At** (`d-m-Y H:i`) hidden by default.
- [x] Default sort: expiration ascending.
- [ ] Searchable by location; sortable by location, expiration, quantity.
- [ ] Row actions **Edit** and **Delete**, each with its icon.
- [ ] Batch form: **Location** (searchable select, required), **Expiration Date** (date, required, defaults to today + 7 days), **Quantity** (integer, required, min 1).
- [x] Saving a batch updates the item's read-only quantity immediately.
- [x] Consuming the last unit elsewhere leaves no zero-quantity row here.

## Locations

Route `/locations`. API `GET|POST /api/v1/locations`, `PATCH|DELETE /api/v1/locations/{id}`, `POST /api/v1/locations/bulk-delete`.

- [x] Columns: **Name**, **Description**, **Parent**; **Created** and **Updated** toggleable and hidden by default.
- [x] Searchable by name, description and parent name; sortable by name, created, updated.
- [x] Create and edit open a modal with: **Name** (required ≤ 255), **Description** (≤ 255), **Notify Expiration (Days)** (integer ≥ 0, default 0, suffix `days`), **Parent Location** (searchable select, optional).
- [x] Choosing itself, a child or any deeper descendant as parent is refused with a field error.
- [x] Delete is disabled when the location holds batches, with tooltip `Locations containing inventory cannot be deleted.`
- [x] Delete modal text: `Child locations will be kept and become root locations.`
- [x] Blocked delete (race) shows `Location cannot be deleted` / `This location contains inventory. Remove all batches before deleting it.`
- [x] Bulk delete modal text: `This deletion is atomic: if any selected location contains inventory, no locations will be deleted. Child locations will be kept and become root locations.`
- [x] Blocked bulk delete shows `Locations were not deleted` / `No locations were deleted because at least one selected location contains inventory.` and deletes none.
- [x] If a child location is displayed with its parent deleted elsewhere, it appears as a root location, not as an error.
- [x] Header action **New Location** with a plus icon.

## Quick Consume

Route `/quick-consume?search=`. API `GET /api/v1/search`, `POST /api/v1/batches/{id}/consume`.

- [x] Heading **Quick Consume**; navigation group **Inventory**, sorted above the other inventory items.
- [x] Search field placeholder `Search items by name, barcode, or description...`, helper `Type at least 2 characters to search`, magnifier prefix icon.
- [x] Typing debounces for 200 ms; fewer than 2 trimmed characters clears the results.
- [x] The search term lives in the URL as `?search=` and survives reload and back/forward.
- [x] Initial empty state: `Start typing to search` / `Search for items by name, barcode, or description`.
- [x] No results: `No items found` / `Try adjusting your search terms`.
- [x] Each result: item name in bold, large, with the description as a tooltip.
- [x] Batch rows: **Location**, **Expires**, **Qty** and a consume action.
- [x] Expiry rendering (`d/m/Y`) and status: past → red warning triangle; today to 7 days → amber clock; later → green check; no date → grey question mark.
- [x] **Consume** asks `Consume 1 unit?` / `This will reduce the batch quantity by 1. If it reaches zero, the batch will be deleted.`
- [x] Success toast: `Item consumed` / `1 unit has been removed from {item}`.
- [x] Race toast: `Item no longer available` / `This batch has already been consumed or is no longer available.`
- [x] Results refresh after every consume; the term stays in the field.
- [x] Only items with at least one batch are returned; at most 10 results, deterministically ordered.

## Users (admin only)

Route `/users`. API `GET|POST /api/v1/users`, `PATCH|DELETE /api/v1/users/{id}`, `POST /api/v1/users/{id}/password`.

- [ ] Columns: **Name**, **Email**, **Admin**, plus **Created At** and **Updated At** hidden by default.
- [ ] Searchable by name and email; sortable by name, email, admin, created; default sort name ascending.
- [ ] Row actions **Edit** and **Delete**; bulk delete.
- [ ] Create modal: **Name** (required ≤ 255), **Email** (required, valid, unique ≤ 255), **Password** (required on create, 8–255), **Administrator** toggle with helper `Administrators can manage all users and access admin features.`
- [ ] Edit modal: password optional with helper `Leave empty to keep current password when editing.`; changing another user's password sets a temporary one and forces a change on their next sign-in.
- [ ] The **Administrator** toggle is disabled when editing yourself.
- [ ] Delete is unavailable for yourself; deleting the only admin is refused with `409 last_admin`.
- [ ] Admins can edit their own profile here; non-admins cannot reach the screen at all.
- [ ] The old **Verified** column and **Email Verified At** field are gone (deviation).

## Profile

Route `/profile`. API `GET|PATCH /api/v1/me`.

- [ ] Fields: **Name** (required), **Email** (required, unique), **Password** (optional, with current-password field when set).
- [ ] Changing the email does not require re-verification (there is none).
- [ ] Locale switch (English / Português do Brasil) persists across sessions.
- [ ] Sign out action.
- [ ] A `must_reset` user can only reach this screen and the password form.

## Cross-cutting

- [ ] Every user-facing string comes from `web/src/i18n`, never from a template literal.
- [ ] Both locales exist, and `pt-BR` is typed against the English key set (a missing key fails `vue-tsc`).
- [ ] Dates: `d-m-Y` in tables, `d/m/Y` in Quick Consume, matching the table above.
- [ ] Toasts appear top-right, stack, and auto-dismiss; success green, warning amber, error red — the same three levels Filament used.
- [ ] Every destructive action confirms first.
- [ ] Every list has a search box, a sort control, pagination and an empty state.
- [ ] Forms mark required fields; server validation errors land on the field, using the rule tokens from `api.md`.
- [ ] Keyboard: tab order follows the visual order; Escape closes modals; Enter submits forms.
- [ ] Focus is trapped in modals and returned to the trigger on close.
- [ ] `prefers-reduced-motion` disables non-essential animation.
- [ ] No screen scrolls horizontally at 375 px except tables, which scroll inside their container.
- [ ] Session expiry (30 days idle) returns to `/login` with `"Your session expired. Sign in again."`

## Copy deck

The English source for `web/src/i18n/en.ts`. `pt-BR.ts` mirrors it key for key.

| Key | English |
| --- | --- |
| `nav.dashboard` | Dashboard |
| `nav.inventory` | Inventory |
| `nav.items` | Items |
| `nav.locations` | Locations |
| `nav.quickConsume` | Quick Consume |
| `nav.users` | Users |
| `common.name` | Name |
| `common.email` | Email |
| `common.description` | Description |
| `common.barcode` | Barcode |
| `common.tags` | Tags |
| `common.quantity` | Quantity |
| `common.createdAt` | Created At |
| `common.updatedAt` | Updated At |
| `common.created` | Created |
| `common.updated` | Updated |
| `common.admin` | Admin |
| `common.parent` | Parent |
| `common.new` | New |
| `common.edit` | Edit |
| `common.delete` | Delete |
| `common.save` | Save |
| `common.cancel` | Cancel |
| `items.new` | New Item |
| `items.firstBatchExpiration` | First Batch Expiration |
| `items.basicInformation` | Basic Information |
| `items.inventoryDetails` | Inventory Details |
| `items.quantityHelp` | The quantity is computed from all batches |
| `items.addTags` | Add tags... |
| `items.batches` | Batches |
| `items.newBatch` | New Batch |
| `items.batchDetails` | Batch Details |
| `items.expirationDate` | Expiration Date |
| `items.deleted` | Item deleted |
| `items.deleteConfirm` | Delete this item and all its batches? |
| `locations.new` | New Location |
| `locations.details` | Location Details |
| `locations.settings` | Settings |
| `locations.notifyDays` | Notify Expiration (Days) |
| `locations.days` | days |
| `locations.parent` | Parent Location |
| `locations.deleteDisabledTooltip` | Locations containing inventory cannot be deleted. |
| `locations.deleteBlockedTitle` | Location cannot be deleted |
| `locations.deleteBlockedBody` | This location contains inventory. Remove all batches before deleting it. |
| `locations.deleteModal` | Child locations will be kept and become root locations. |
| `locations.bulkDeleteModal` | This deletion is atomic: if any selected location contains inventory, no locations will be deleted. Child locations will be kept and become root locations. |
| `locations.bulkBlockedTitle` | Locations were not deleted |
| `locations.bulkBlockedBody` | No locations were deleted because at least one selected location contains inventory. |
| `quickConsume.title` | Quick Consume |
| `quickConsume.searchPlaceholder` | Search items by name, barcode, or description... |
| `quickConsume.searchHelp` | Type at least 2 characters to search |
| `quickConsume.emptyTitle` | No items found |
| `quickConsume.emptyBody` | Try adjusting your search terms |
| `quickConsume.initialTitle` | Start typing to search |
| `quickConsume.initialBody` | Search for items by name, barcode, or description |
| `quickConsume.location` | Location |
| `quickConsume.expires` | Expires |
| `quickConsume.qty` | Qty |
| `quickConsume.consume` | Consume |
| `quickConsume.confirmTitle` | Consume 1 unit? |
| `quickConsume.confirmBody` | This will reduce the batch quantity by 1. If it reaches zero, the batch will be deleted. |
| `quickConsume.consumedTitle` | Item consumed |
| `quickConsume.consumedBody` | 1 unit has been removed from {item} |
| `quickConsume.unavailableTitle` | Item no longer available |
| `quickConsume.unavailableBody` | This batch has already been consumed or is no longer available. |
| `dashboard.recentlyExpired` | Recently Expired |
| `dashboard.empty` | Congratulations, no expired items |
| `barcode.notFoundTitle` | Product not found |
| `barcode.notFoundBody` | No product information found for this barcode. |
| `barcode.invalidTitle` | Invalid barcode |
| `barcode.invalidBody` | The barcode format is not recognized. Please check the digits and try again. |
| `barcode.upstreamTitle` | Product lookup unavailable |
| `barcode.upstreamBody` | We could not reach the product database. You can still enter the details manually. |
| `barcode.loadedTitle` | Product data loaded |
| `barcode.loadedBody` | Populated: :fields |
| `barcode.foundTitle` | Product found |
| `barcode.foundBody` | No empty fields to populate. |
| `users.new` | New User |
| `users.profileInformation` | Profile Information |
| `users.security` | Security |
| `users.password` | Password |
| `users.passwordKeep` | Leave empty to keep current password when editing. |
| `users.permissions` | Permissions |
| `users.administrator` | Administrator |
| `users.administratorHelp` | Administrators can manage all users and access admin features. |
| `auth.signIn` | Sign in |
| `auth.signOut` | Sign out |
| `auth.email` | Email |
| `auth.password` | Password |
| `auth.invalidCredentials` | These credentials do not match our records. |
| `auth.tooManyAttempts` | Too many attempts. Try again in a minute. |
| `auth.sessionExpired` | Your session expired. Sign in again. |
| `auth.mustReset` | Choose a new password to continue. |
| `setup.title` | Welcome to FreshGuard |
| `setup.name` | Name |
| `setup.passwordConfirmation` | Confirm password |
| `setup.createAdmin` | Create administrator account |
| `profile.title` | Profile |
| `profile.language` | Language |
| `profile.currentPassword` | Current password |

## Known deviations

Not bugs; do not file them.

1. No registration screen after setup, no email verification, no email-change verification, no password-reset mail, no **Verified** column or field.
2. No "remember me"; sessions always slide for 30 days.
3. No "forgot password" link; recovery is `freshguard user password` or an admin-set temporary password.
4. Tag suggestions are ordered by usage, not by the recency of the last 50 updated items.
5. Parent selects reject cycles, which the old form allowed.
6. The barcode camera is `vue-qrcode-reader` instead of `html5-qrcode`; the modal, the button and the prefill rules are identical.
7. The profile screen gains a locale switch; a `must_reset` user sees the change-password form before anything else.
8. Filament's generic modal wording ("Are you sure you want to delete…") is reproduced in spirit, not verbatim.
