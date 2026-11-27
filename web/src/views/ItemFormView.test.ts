import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import {
  createBatch,
  deleteBatch,
  getItem,
  listLocations,
  listTags,
  updateBatch,
  type Batch,
  type ItemDetail,
  type Location,
} from '../api';
import { en } from '../locales/en';
import { pushToast } from '../toast';
import ItemFormView from './ItemFormView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return {
    ...actual,
    createBatch: vi.fn(),
    createItem: vi.fn(),
    deleteBatch: vi.fn(),
    getItem: vi.fn(),
    listLocations: vi.fn(),
    listTags: vi.fn(),
    updateBatch: vi.fn(),
    updateItem: vi.fn(),
  };
});

vi.mock('../toast', () => ({ pushToast: vi.fn() }));

const location: Location = {
  id: 'loc-fridge',
  name: 'Fridge',
  description: '',
  expiration_notify_days: 0,
  parent: null,
  batch_count: 0,
  created_at: '2026-01-24T08:27:26Z',
  updated_at: '2026-01-24T08:27:26Z',
};

function makeBatch(id: string, expiresAt: string, quantity: number): Batch {
  return {
    id,
    item_id: 'item-1',
    location: { id: location.id, name: location.name },
    expires_at: expiresAt,
    quantity,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
  };
}

function detail(batches: Batch[]): ItemDetail {
  return {
    id: 'item-1',
    name: 'Whole milk',
    barcode: '',
    description: '',
    tags: [],
    quantity: batches.reduce((total, batch) => total + batch.quantity, 0),
    earliest_expiration: batches[0]?.expires_at ?? '',
    batch_count: batches.length,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    batches,
  };
}

let current: ItemDetail;
let wrapper: VueWrapper;
let router: Router;

async function mountView(): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/items', name: 'items', component: { template: '<div />' } },
      { path: '/items/:id', name: 'item-edit', component: ItemFormView },
    ],
  });
  await router.push('/items/item-1');
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });

  wrapper = mount(ItemFormView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

beforeEach(() => {
  vi.clearAllMocks();

  current = detail([makeBatch('batch-2', '2026-02-10', 3), makeBatch('batch-1', '2026-02-01', 2)]);

  vi.mocked(getItem).mockImplementation(async () => current);
  vi.mocked(listTags).mockResolvedValue([]);
  vi.mocked(listLocations).mockResolvedValue({
    data: [location],
    meta: { page: 1, per_page: 100, total: 1, pages: 1 },
  });
});

describe('ItemFormView batches', () => {
  it('lists batches by expiry ascending with location and quantity', async () => {
    await mountView();

    const rows = wrapper.findAll('[data-test="batch-row"]');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.find('[data-test="batch-location"]').text()).toBe('Fridge');
    expect(rows[0]?.find('[data-test="batch-expires"]').text()).toBe('01-02-2026');
    expect(rows[0]?.find('[data-test="batch-quantity"]').text()).toBe('2');
    expect(rows[1]?.find('[data-test="batch-expires"]').text()).toBe('10-02-2026');

    expect(wrapper.findAll('[data-test="batch-created"]')).toHaveLength(0);
    await wrapper.find('[data-test="batch-created-toggle"]').setValue(true);
    expect(wrapper.findAll('[data-test="batch-created"]')).toHaveLength(2);
  });

  it('rejects a quantity below one before calling the API', async () => {
    await mountView();

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    await wrapper.find('[data-test="batch-quantity"]').setValue(0);
    await wrapper.find('[data-test="batch-save"]').trigger('click');

    expect(createBatch).not.toHaveBeenCalled();
    expect(wrapper.find('[data-test="batch-save"]').exists()).toBe(true);
    expect(wrapper.text()).toContain(en.errors.min);
  });

  it('creates a batch with the default expiry and refreshes the item quantity', async () => {
    await mountView();

    await wrapper.find('[data-test="new-batch"]').trigger('click');

    const select = wrapper.find('[data-test="batch-location"]');
    expect((select.element as HTMLSelectElement).value).toBe(location.id);

    const expected = new Date();
    expected.setDate(expected.getDate() + 7);
    const expectedExpiry = [
      expected.getFullYear(),
      String(expected.getMonth() + 1).padStart(2, '0'),
      String(expected.getDate()).padStart(2, '0'),
    ].join('-');
    expect((wrapper.find('[data-test="batch-expires"]').element as HTMLInputElement).value).toBe(
      expectedExpiry,
    );

    await wrapper.find('[data-test="batch-quantity"]').setValue(1);
    vi.mocked(createBatch).mockImplementation(async () => {
      const created = makeBatch('batch-3', '2026-02-20', 1);
      current = detail([...current.batches, created]);
      return created;
    });

    await wrapper.find('[data-test="batch-save"]').trigger('click');
    await flushPromises();

    expect(createBatch).toHaveBeenCalledWith('item-1', {
      location_id: location.id,
      expires_at: expectedExpiry,
      quantity: 1,
    });
    expect(getItem).toHaveBeenCalledTimes(2);
    expect(
      (wrapper.find('[data-test="item-quantity"]').element as HTMLInputElement).value,
    ).toBe('6');
    expect(wrapper.findAll('[data-test="batch-row"]')).toHaveLength(3);
    expect(pushToast).toHaveBeenCalledWith({ level: 'success', title: en.items.batchCreated });
  });

  it('deletes a batch and recomputes the item quantity', async () => {
    await mountView();

    await wrapper.findAll('[data-test="batch-delete"]')[0]?.trigger('click');
    vi.mocked(deleteBatch).mockImplementation(async () => {
      current = detail([makeBatch('batch-2', '2026-02-10', 3)]);
    });

    const dialog = wrapper.find('dialog');
    const buttons = dialog.findAll('button');
    await buttons[buttons.length - 1]?.trigger('click');
    await flushPromises();

    expect(deleteBatch).toHaveBeenCalledWith('batch-1');
    expect(
      (wrapper.find('[data-test="item-quantity"]').element as HTMLInputElement).value,
    ).toBe('3');
    expect(wrapper.findAll('[data-test="batch-row"]')).toHaveLength(1);
  });

  it('loads every page of locations for the picker', async () => {
    const freezer: Location = { ...location, id: 'loc-freezer', name: 'Freezer' };
    vi.mocked(listLocations)
      .mockResolvedValueOnce({
        data: [location],
        meta: { page: 1, per_page: 100, total: 2, pages: 2 },
      })
      .mockResolvedValueOnce({
        data: [freezer],
        meta: { page: 2, per_page: 100, total: 2, pages: 2 },
      });

    await mountView();

    expect(listLocations).toHaveBeenNthCalledWith(1, { per_page: 100 });
    expect(listLocations).toHaveBeenNthCalledWith(2, { per_page: 100, page: 2 });

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    const options = wrapper.findAll('[data-test="batch-location"] option').map((option) => option.text());
    expect(options).toContain('Freezer');
  });

  it('separates a failed location load from an empty one and retries', async () => {
    vi.mocked(listLocations).mockRejectedValueOnce(new Error('down'));
    await mountView();

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    expect(wrapper.find('[data-test="batches-locations-error"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="batches-no-locations"]').exists()).toBe(false);
    expect(
      (wrapper.find('[data-test="batch-save"]').element as HTMLButtonElement).disabled,
    ).toBe(true);

    vi.mocked(listLocations).mockResolvedValueOnce({
      data: [],
      meta: { page: 1, per_page: 100, total: 0, pages: 0 },
    });
    await wrapper.find('[data-test="batches-locations-retry"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-test="batches-locations-error"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="batches-no-locations"]').exists()).toBe(true);
  });

  it('does not repeat a successful create when the refresh fails', async () => {
    await mountView();

    const created = makeBatch('batch-3', '2026-02-20', 1);
    vi.mocked(createBatch).mockResolvedValue(created);
    vi.mocked(getItem)
      .mockRejectedValueOnce(new Error('down'))
      .mockResolvedValueOnce(detail([...current.batches, created]));

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    await wrapper.find('[data-test="batch-quantity"]').setValue(1);
    await wrapper.find('[data-test="batch-save"]').trigger('click');
    await flushPromises();

    expect(createBatch).toHaveBeenCalledTimes(1);
    expect(wrapper.find('[data-test="batch-save"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="batches-refresh-error"]').exists()).toBe(true);

    await wrapper.find('[data-test="batches-refresh-retry"]').trigger('click');
    await flushPromises();

    expect(createBatch).toHaveBeenCalledTimes(1);
    expect(getItem).toHaveBeenCalledTimes(3);
    expect(wrapper.find('[data-test="batches-refresh-error"]').exists()).toBe(false);
    expect(wrapper.findAll('[data-test="batch-row"]')).toHaveLength(3);
  });

  it('does not repeat a successful delete when the refresh fails', async () => {
    await mountView();

    const remaining = detail([makeBatch('batch-2', '2026-02-10', 3)]);
    vi.mocked(deleteBatch).mockResolvedValue(undefined);
    vi.mocked(getItem)
      .mockRejectedValueOnce(new Error('down'))
      .mockResolvedValueOnce(remaining);

    await wrapper.findAll('[data-test="batch-delete"]')[0]?.trigger('click');
    const dialog = wrapper.find('dialog');
    const buttons = dialog.findAll('button');
    await buttons[buttons.length - 1]?.trigger('click');
    await flushPromises();

    expect(deleteBatch).toHaveBeenCalledTimes(1);
    expect(wrapper.findAll('[data-test="batch-row"]')).toHaveLength(1);
    expect(
      (wrapper.find('[data-test="item-quantity"]').element as HTMLInputElement).value,
    ).toBe('3');
    expect(wrapper.find('[data-test="batches-refresh-error"]').exists()).toBe(true);

    await wrapper.find('[data-test="batches-refresh-retry"]').trigger('click');
    await flushPromises();

    expect(deleteBatch).toHaveBeenCalledTimes(1);
    expect(getItem).toHaveBeenCalledTimes(3);
    expect(wrapper.findAll('[data-test="batch-row"]')).toHaveLength(1);
  });

  it('disables form switching while a save is in flight', async () => {
    await mountView();

    let resolveCreate: (batch: Batch) => void = () => {};
    vi.mocked(createBatch).mockImplementation(
      () =>
        new Promise<Batch>((resolve) => {
          resolveCreate = resolve;
        }),
    );

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    await wrapper.find('[data-test="batch-save"]').trigger('click');
    await flushPromises();

    expect(
      (wrapper.find('[data-test="new-batch"]').element as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (wrapper.find('[data-test="batch-cancel"]').element as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (wrapper.find('[data-test="batch-save"]').element as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (wrapper.find('[data-test="batch-edit"]').element as HTMLButtonElement).disabled,
    ).toBe(true);

    resolveCreate(makeBatch('batch-3', '2026-02-20', 1));
    await flushPromises();

    expect(wrapper.find('[data-test="batch-save"]').exists()).toBe(false);
  });

  it('ignores a refresh response for a route that is no longer active', async () => {
    await mountView();

    const other: ItemDetail = {
      ...detail([]),
      id: 'item-2',
      name: 'Bread',
    };
    let resolveOld: (value: ItemDetail) => void = () => {};

    vi.mocked(createBatch).mockResolvedValue(makeBatch('batch-3', '2026-02-20', 1));
    vi.mocked(getItem).mockImplementation((id) => {
      if (id === 'item-2') {
        return Promise.resolve(other);
      }
      return new Promise<ItemDetail>((resolve) => {
        resolveOld = resolve;
      });
    });

    await wrapper.find('[data-test="new-batch"]').trigger('click');
    await wrapper.find('[data-test="batch-save"]').trigger('click');
    await flushPromises();

    await router.push('/items/item-2');
    await flushPromises();

    expect(
      (wrapper.find('[data-test="item-name"]').element as HTMLInputElement).value,
    ).toBe('Bread');

    resolveOld(detail([...current.batches, makeBatch('batch-3', '2026-02-20', 1)]));
    await flushPromises();

    expect(
      (wrapper.find('[data-test="item-name"]').element as HTMLInputElement).value,
    ).toBe('Bread');
  });

  it('edits a batch and pre-fills the form', async () => {
    await mountView();

    await wrapper.findAll('[data-test="batch-edit"]')[0]?.trigger('click');
    expect((wrapper.find('[data-test="batch-quantity"]').element as HTMLInputElement).value).toBe('2');
    expect((wrapper.find('[data-test="batch-expires"]').element as HTMLInputElement).value).toBe(
      '2026-02-01',
    );

    await wrapper.find('[data-test="batch-quantity"]').setValue(5);
    vi.mocked(updateBatch).mockResolvedValue(makeBatch('batch-1', '2026-02-01', 5));

    await wrapper.find('[data-test="batch-save"]').trigger('click');
    await flushPromises();

    expect(updateBatch).toHaveBeenCalledWith('batch-1', {
      location_id: location.id,
      expires_at: '2026-02-01',
      quantity: 5,
    });
  });
});
