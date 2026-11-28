import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import { ApiError, getRecentlyExpired, type ExpiredBatch } from '../api';
import { en } from '../locales/en';
import DashboardView from './DashboardView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return { ...actual, getRecentlyExpired: vi.fn() };
});

function makeBatch(overrides: Partial<ExpiredBatch> = {}): ExpiredBatch {
  return {
    id: 'batch-1',
    item_id: 'item-1',
    item: { id: 'item-1', name: 'Whole milk' },
    location: { id: 'loc-1', name: 'Fridge' },
    expires_at: '2026-01-20',
    quantity: 2,
    created_at: '2026-01-01T08:00:00Z',
    updated_at: '2026-01-01T08:00:00Z',
    ...overrides,
  };
}

let batches: ExpiredBatch[];
let wrapper: VueWrapper;
let router: Router;

async function mountView(): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: DashboardView },
      { path: '/items/:id', name: 'item-edit', component: { template: '<div />' } },
    ],
  });
  await router.push('/');
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });
  wrapper = mount(DashboardView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

beforeEach(() => {
  vi.clearAllMocks();
  batches = [
    makeBatch({ id: 'b-1', item: { id: 'item-1', name: 'Milk' } }),
    makeBatch({
      id: 'b-2',
      item: { id: 'item-2', name: 'Yogurt' },
      location: { id: 'loc-2', name: 'Pantry' },
      expires_at: '2026-01-21',
      quantity: 3,
    }),
  ];
  vi.mocked(getRecentlyExpired).mockImplementation(async () => batches);
});

afterEach(() => {
  wrapper?.unmount();
});

function row(index: number) {
  return wrapper.findAll('[data-test="dashboard-row"]')[index];
}

describe('DashboardView', () => {
  it('shows the expired batches with item, location, quantity and date', async () => {
    await mountView();

    expect(wrapper.find('h1').text()).toBe(en.dashboard.recentlyExpired);
    expect(wrapper.findAll('[data-test="dashboard-row"]')).toHaveLength(2);

    expect(row(0)?.find('[data-test="dashboard-item"]').text()).toBe('Milk');
    expect(row(0)?.find('[data-test="dashboard-location"]').text()).toBe('Fridge');
    expect(row(0)?.find('[data-test="dashboard-quantity"]').text()).toBe('2');
    expect(row(0)?.find('[data-test="dashboard-expires"]').text()).toBe('20-01-2026');

    expect(row(1)?.find('[data-test="dashboard-item"]').text()).toBe('Yogurt');
    expect(row(1)?.find('[data-test="dashboard-location"]').text()).toBe('Pantry');
    expect(row(1)?.find('[data-test="dashboard-quantity"]').text()).toBe('3');
    expect(row(1)?.find('[data-test="dashboard-expires"]').text()).toBe('21-01-2026');
  });

  it('opens the item when a row is clicked', async () => {
    await mountView();

    await row(1)?.trigger('click');
    await flushPromises();

    expect(router.currentRoute.value.name).toBe('item-edit');
    expect(router.currentRoute.value.params.id).toBe('item-2');
  });

  it('opens the item from the keyboard', async () => {
    await mountView();

    await row(0)?.trigger('keydown', { key: 'Enter' });
    await flushPromises();

    expect(router.currentRoute.value.name).toBe('item-edit');
    expect(router.currentRoute.value.params.id).toBe('item-1');
  });

  it('shows the empty state when nothing has expired', async () => {
    batches = [];
    await mountView();

    expect(wrapper.find('[data-test="dashboard-empty"]').text()).toContain(
      en.dashboard.empty,
    );
    expect(wrapper.findAll('[data-test="dashboard-row"]')).toHaveLength(0);
  });

  it('reports a failure and retries', async () => {
    vi.mocked(getRecentlyExpired).mockRejectedValueOnce(
      new ApiError(500, 'internal', 'internal error'),
    );
    await mountView();

    expect(wrapper.find('[data-test="dashboard-error"]').exists()).toBe(true);

    await wrapper.find('[data-test="dashboard-retry"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-test="dashboard-error"]').exists()).toBe(false);
    expect(wrapper.findAll('[data-test="dashboard-row"]')).toHaveLength(2);
  });
});
