import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import {
  ApiError,
  bulkDeleteLocations,
  createLocation,
  deleteLocation,
  listLocations,
  updateLocation,
  type Location,
  type Page,
} from '../api';
import { en } from '../locales/en';
import { pushToast } from '../toast';
import LocationsListView from './LocationsListView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return {
    ...actual,
    bulkDeleteLocations: vi.fn(),
    createLocation: vi.fn(),
    deleteLocation: vi.fn(),
    listLocations: vi.fn(),
    updateLocation: vi.fn(),
  };
});

vi.mock('../toast', () => ({ pushToast: vi.fn() }));

const kitchen = makeLocation({ id: 'loc-kitchen', name: 'Kitchen' });
const fridge = makeLocation({
  id: 'loc-fridge',
  name: 'Fridge',
  parent: { id: kitchen.id, name: kitchen.name },
  batch_count: 2,
});
const shelf = makeLocation({
  id: 'loc-shelf',
  name: 'Shelf',
  parent: { id: fridge.id, name: fridge.name },
});

function makeLocation(overrides: Partial<Location> = {}): Location {
  return {
    id: 'loc-1',
    name: 'Location',
    description: '',
    expiration_notify_days: 0,
    parent: null,
    batch_count: 0,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    ...overrides,
  };
}

function page(data: Location[], perPage = 10): Page<Location> {
  return {
    data,
    meta: { page: 1, per_page: perPage, total: data.length, pages: data.length > 0 ? 1 : 0 },
  };
}

let all: Location[];
let listed: Location[];
let wrapper: VueWrapper;
let router: Router;

async function mountView(): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/locations', name: 'locations', component: LocationsListView },
    ],
  });
  await router.push('/locations');
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });

  wrapper = mount(LocationsListView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

async function openCreate(): Promise<void> {
  await wrapper.find('[data-test="new-location"]').trigger('click');
}

function row(locationID: string) {
  return wrapper.find(`[data-location-id="${locationID}"]`);
}

beforeEach(() => {
  vi.clearAllMocks();
  all = [kitchen, fridge, shelf];
  listed = [...all];

  vi.mocked(listLocations).mockImplementation(async (filters = {}) => {
    if (filters.per_page === 100) {
      return page(all, 100);
    }
    return page(listed);
  });
  vi.mocked(createLocation).mockResolvedValue(kitchen);
  vi.mocked(updateLocation).mockResolvedValue(kitchen);
  vi.mocked(deleteLocation).mockResolvedValue(undefined);
  vi.mocked(bulkDeleteLocations).mockResolvedValue({ deleted: 3 });
});

describe('LocationsListView', () => {
  it('lists the locations with parent and batch count, hiding timestamps by default', async () => {
    await mountView();

    expect(wrapper.findAll('[data-test="location-row"]')).toHaveLength(3);
    expect(row(kitchen.id).find('[data-test="location-name"]').text()).toBe('Kitchen');
    expect(row(kitchen.id).find('[data-test="location-parent"]').text()).toBe('');
    expect(row(fridge.id).find('[data-test="location-parent"]').text()).toBe('Kitchen');

    expect(wrapper.findAll('[data-test="location-created"]')).toHaveLength(0);
    expect(wrapper.findAll('[data-test="location-updated"]')).toHaveLength(0);

    await wrapper.find('[data-test="location-column-created"]').setValue(true);
    await wrapper.find('[data-test="location-column-updated"]').setValue(true);
    expect(wrapper.findAll('[data-test="location-created"]')).toHaveLength(3);
    expect(wrapper.findAll('[data-test="location-updated"]')).toHaveLength(3);
  });

  it('shows a location whose parent is gone as a root location', async () => {
    listed = [makeLocation({ id: 'loc-orphan', name: 'Fridge', parent: null })];
    all = listed;
    await mountView();

    expect(row('loc-orphan').find('[data-test="location-parent"]').text()).toBe('');
    expect(wrapper.find('[data-test="locations-error"]').exists()).toBe(false);
  });

  it('creates a location from the modal with every field', async () => {
    await mountView();
    await openCreate();

    expect(wrapper.find('[data-test="location-form-title"]').text()).toBe(
      en.locations.createTitle,
    );

    await wrapper.find('[data-test="location-name-input"]').setValue('Garage');
    await wrapper
      .find('[data-test="location-description-input"]')
      .setValue('Bikes');
    await wrapper.find('[data-test="location-days-input"]').setValue(5);
    await wrapper.find('[data-test="location-parent-select"]').setValue(kitchen.id);
    await wrapper.find('[data-test="location-save"]').trigger('click');
    await flushPromises();

    expect(createLocation).toHaveBeenCalledWith({
      name: 'Garage',
      description: 'Bikes',
      expiration_notify_days: 5,
      parent_id: kitchen.id,
    });
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.locations.created,
    });
    expect(listLocations).toHaveBeenCalled();
  });

  it('refuses itself, a child and a deeper descendant as parent', async () => {
    await mountView();
    await row(kitchen.id).find('[data-test="location-edit"]').trigger('click');

    for (const parentID of [fridge.id, shelf.id, kitchen.id]) {
      await wrapper.find('[data-test="location-parent-select"]').setValue(parentID);
      await wrapper.find('[data-test="location-save"]').trigger('click');

      expect(wrapper.find('[data-test="location-parent-error"]').text()).toBe(
        en.errors.cycle,
      );
    }

    expect(updateLocation).not.toHaveBeenCalled();
  });

  it('sends a valid parent and clears it with null', async () => {
    await mountView();
    await row(fridge.id).find('[data-test="location-edit"]').trigger('click');

    await wrapper.find('[data-test="location-parent-select"]').setValue('');
    await wrapper.find('[data-test="location-save"]').trigger('click');
    await flushPromises();

    expect(updateLocation).toHaveBeenCalledWith(
      fridge.id,
      expect.objectContaining({ parent_id: null }),
    );
  });

  it('disables delete for a location holding batches and shows the tooltip', async () => {
    await mountView();

    const button = row(fridge.id).find('[data-test="location-delete"]');
    expect((button.element as HTMLButtonElement).disabled).toBe(true);
    expect(
      row(fridge.id).find('[data-test="location-delete-disabled"]').attributes('title'),
    ).toBe(en.locations.deleteDisabledTooltip);

    const empty = row(kitchen.id).find('[data-test="location-delete"]');
    expect((empty.element as HTMLButtonElement).disabled).toBe(false);
  });

  it('deletes an empty location after showing the child-promotion copy', async () => {
    await mountView();
    await row(kitchen.id).find('[data-test="location-delete"]').trigger('click');

    expect(
      wrapper.find('[data-test="confirm-dialog"]').text(),
    ).toContain(en.locations.deleteModal);

    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(deleteLocation).toHaveBeenCalledWith(kitchen.id);
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.locations.deleted,
    });
  });

  it('reports a delete blocked by a race with the parity copy', async () => {
    vi.mocked(deleteLocation).mockRejectedValue(
      new ApiError(409, 'location_has_batches', 'blocked', { ids: kitchen.id }),
    );
    await mountView();
    await row(kitchen.id).find('[data-test="location-delete"]').trigger('click');
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(pushToast).toHaveBeenCalledWith({
      level: 'error',
      title: en.locations.deleteBlockedTitle,
      body: en.locations.deleteBlockedBody,
    });
    expect(pushToast).not.toHaveBeenCalledWith(
      expect.objectContaining({ title: en.locations.deleted }),
    );
  });

  it('bulk deletes every selected location atomically', async () => {
    await mountView();
    await wrapper.find('[data-test="locations-select-all"]').setValue(true);
    await wrapper.find('[data-test="locations-bulk-delete"]').trigger('click');

    expect(
      wrapper.find('[data-test="confirm-dialog"]').text(),
    ).toContain(en.locations.bulkDeleteModal);

    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(bulkDeleteLocations).toHaveBeenCalledWith([
      kitchen.id,
      fridge.id,
      shelf.id,
    ]);
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.locations.bulkDeleted,
    });
  });

  it('deletes nothing when a bulk selection contains inventory', async () => {
    vi.mocked(bulkDeleteLocations).mockRejectedValue(
      new ApiError(409, 'location_has_batches', 'blocked', { ids: fridge.id }),
    );
    await mountView();
    await wrapper.find('[data-test="locations-select-all"]').setValue(true);
    await wrapper.find('[data-test="locations-bulk-delete"]').trigger('click');
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(pushToast).toHaveBeenCalledWith({
      level: 'error',
      title: en.locations.bulkBlockedTitle,
      body: en.locations.bulkBlockedBody,
    });
    expect(pushToast).not.toHaveBeenCalledWith(
      expect.objectContaining({ title: en.locations.bulkDeleted }),
    );
  });

  it('searches and sorts through the collection query', async () => {
    await mountView();

    await wrapper.find('[data-test="sort-name"]').trigger('click');
    expect(listLocations).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'name', dir: 'desc' }),
    );

    await wrapper.find('[data-test="location-column-created"]').setValue(true);
    await wrapper.find('[data-test="sort-created_at"]').trigger('click');
    expect(listLocations).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'created_at', dir: 'asc' }),
    );

    vi.useFakeTimers();
    await wrapper.find('[data-test="locations-search"]').setValue('fridge');
    await vi.advanceTimersByTimeAsync(300);
    expect(listLocations).toHaveBeenLastCalledWith(
      expect.objectContaining({ q: 'fridge', page: 1 }),
    );
    vi.useRealTimers();
  });
});

afterEach(() => {
  vi.useRealTimers();
});
