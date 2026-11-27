import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import {
  ApiError,
  consumeBatch,
  searchItems,
  type SearchResult,
} from '../api';
import { en } from '../locales/en';
import { pushToast } from '../toast';
import QuickConsumeView from './QuickConsumeView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return {
    ...actual,
    consumeBatch: vi.fn(),
    searchItems: vi.fn(),
  };
});

vi.mock('../toast', () => ({ pushToast: vi.fn() }));

function makeResult(overrides: Partial<SearchResult> = {}): SearchResult {
  return {
    id: 'item-milk',
    name: 'Whole milk',
    description: '1 L',
    batches: [
      {
        id: 'batch-milk',
        location: { id: 'loc-fridge', name: 'Fridge' },
        expires_at: '2026-02-01',
        quantity: 2,
      },
    ],
    ...overrides,
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((innerResolve) => {
    resolve = innerResolve;
  });
  return { promise, resolve };
}

let wrapper: VueWrapper;
let router: Router;

async function mountView(path = '/quick-consume'): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/quick-consume', name: 'quick-consume', component: QuickConsumeView },
      { path: '/', name: 'dashboard', component: { template: '<div />' } },
    ],
  });
  await router.push(path);
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });

  wrapper = mount(QuickConsumeView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

function resultRow(itemID: string) {
  return wrapper.find(`[data-item-id="${itemID}"]`);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  vi.setSystemTime(new Date(2026, 0, 24, 12));
  vi.mocked(searchItems).mockResolvedValue([makeResult()]);
  vi.mocked(consumeBatch).mockResolvedValue({
    item: { id: 'item-milk', name: 'Whole milk' },
    batch: { id: 'batch-milk', quantity: 1 },
    deleted: false,
  });
});

afterEach(() => {
  vi.useRealTimers();
});

describe('QuickConsumeView', () => {
  it('debounces typing by 200 ms and keeps the term in the URL', async () => {
    await mountView();

    expect(wrapper.find('[data-test="quick-consume-initial"]').text()).toContain(
      en.quickConsume.initialTitle,
    );

    await wrapper.find('[data-test="quick-consume-search"]').setValue('milk');
    vi.advanceTimersByTime(199);
    await flushPromises();
    expect(searchItems).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    await flushPromises();

    expect(searchItems).toHaveBeenCalledWith('milk');
    expect(router.currentRoute.value.query.search).toBe('milk');
    expect(wrapper.findAll('[data-test="search-result"]')).toHaveLength(1);
  });

  it('searches for a term already in the URL on load', async () => {
    await mountView('/quick-consume?search=milk');

    expect(searchItems).toHaveBeenCalledWith('milk');
    expect(resultRow('item-milk').find('[data-test="result-name"]').text()).toBe(
      'Whole milk',
    );
  });

  it('renders location, d/m/Y expiry, quantity and the expiry status', async () => {
    vi.mocked(searchItems).mockResolvedValue([
      makeResult({
        name: 'Milk with three batches',
        description: 'Dairy',
        batches: [
          {
            id: 'batch-past',
            location: { id: 'loc-fridge', name: 'Fridge' },
            expires_at: '2026-01-23',
            quantity: 1,
          },
          {
            id: 'batch-soon',
            location: { id: 'loc-freezer', name: 'Freezer' },
            expires_at: '2026-01-26',
            quantity: 2,
          },
          {
            id: 'batch-later',
            location: { id: 'loc-pantry', name: 'Pantry' },
            expires_at: '2026-02-01',
            quantity: 3,
          },
        ],
      }),
    ]);

    await mountView('/quick-consume?search=milk');

    const row = resultRow('item-milk');
    expect(row.find('[data-test="result-name"]').text()).toBe('Milk with three batches');
    expect(row.find('[data-test="result-name"]').attributes('title')).toBe('Dairy');

    const rows = row.findAll('[data-test="batch-row"]');
    expect(rows).toHaveLength(3);
    expect(rows[0]?.find('[data-test="batch-location"]').text()).toBe('Fridge');
    expect(rows[0]?.find('[data-test="batch-expires"]').text()).toBe('23/01/2026');
    expect(rows[0]?.find('[data-test="batch-quantity"]').text()).toBe('1');
    expect(rows[0]?.find('[data-test="expiry-icon"]').attributes('data-status')).toBe('past');
    expect(rows[1]?.find('[data-test="batch-expires"]').text()).toBe('26/01/2026');
    expect(rows[1]?.find('[data-test="expiry-icon"]').attributes('data-status')).toBe('soon');
    expect(rows[2]?.find('[data-test="expiry-icon"]').attributes('data-status')).toBe('later');
  });

  it('clears the results and the URL below two trimmed characters', async () => {
    await mountView('/quick-consume?search=milk');
    vi.mocked(searchItems).mockClear();

    await wrapper.find('[data-test="quick-consume-search"]').setValue(' m ');
    vi.advanceTimersByTime(200);
    await flushPromises();

    expect(searchItems).not.toHaveBeenCalled();
    expect(router.currentRoute.value.query.search).toBeUndefined();
    expect(wrapper.find('[data-test="quick-consume-initial"]').exists()).toBe(true);
    expect(wrapper.findAll('[data-test="search-result"]')).toHaveLength(0);
  });

  it('keeps the newest response when an older search resolves late', async () => {
    const older = deferred<SearchResult[]>();
    const newer = deferred<SearchResult[]>();
    vi.mocked(searchItems)
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);

    await mountView();
    await wrapper.find('[data-test="quick-consume-search"]').setValue('mil');
    vi.advanceTimersByTime(200);
    await flushPromises();

    await wrapper.find('[data-test="quick-consume-search"]').setValue('milk');
    vi.advanceTimersByTime(200);
    await flushPromises();

    newer.resolve([makeResult({ name: 'Whole milk' })]);
    await flushPromises();
    older.resolve([makeResult({ id: 'item-old', name: 'Old milk' })]);
    await flushPromises();

    expect(wrapper.findAll('[data-test="search-result"]')).toHaveLength(1);
    expect(resultRow('item-milk').find('[data-test="result-name"]').text()).toBe('Whole milk');
    expect(resultRow('item-old').exists()).toBe(false);
  });

  it('shows the no-results state for a term without matches', async () => {
    vi.mocked(searchItems).mockResolvedValue([]);

    await mountView('/quick-consume?search=nothing');

    expect(wrapper.find('[data-test="quick-consume-empty"]').text()).toContain(
      en.quickConsume.emptyTitle,
    );
  });

  it('asks before consuming, then toasts and refreshes the results', async () => {
    await mountView('/quick-consume?search=milk');

    await resultRow('item-milk').find('[data-test="consume-batch"]').trigger('click');

    const dialog = wrapper.find('[data-test="confirm-dialog"]');
    expect(dialog.text()).toContain(en.quickConsume.confirmTitle);
    expect(dialog.text()).toContain(en.quickConsume.confirmBody);
    expect(consumeBatch).not.toHaveBeenCalled();

    vi.mocked(searchItems).mockClear();
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(consumeBatch).toHaveBeenCalledWith('batch-milk');
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.quickConsume.consumedTitle,
      body: '1 unit has been removed from Whole milk',
    });
    expect(searchItems).toHaveBeenCalledWith('milk');
    expect(
      (wrapper.find('[data-test="quick-consume-search"]').element as HTMLInputElement).value,
    ).toBe('milk');
  });

  it('shows the unavailable toast when the batch disappeared first', async () => {
    vi.mocked(consumeBatch).mockRejectedValue(
      new ApiError(409, 'batch_unavailable', 'no longer available'),
    );

    await mountView('/quick-consume?search=milk');
    vi.mocked(searchItems).mockClear();

    await resultRow('item-milk').find('[data-test="consume-batch"]').trigger('click');
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(pushToast).toHaveBeenCalledWith({
      level: 'warning',
      title: en.quickConsume.unavailableTitle,
      body: en.quickConsume.unavailableBody,
    });
    expect(searchItems).toHaveBeenCalledWith('milk');
  });

  it('reports a failed search instead of rendering an empty list', async () => {
    vi.mocked(searchItems).mockRejectedValue(new ApiError(500, 'internal', 'boom'));

    await mountView('/quick-consume?search=milk');

    expect(wrapper.find('[data-test="quick-consume-error"]').text()).toBe(
      en.errors.unexpected,
    );
    expect(wrapper.find('[data-test="quick-consume-empty"]').exists()).toBe(false);
  });

  it('searches again when the URL changes through history', async () => {
    await mountView('/quick-consume?search=milk');
    vi.mocked(searchItems).mockClear();

    await router.push('/quick-consume?search=bread');
    await flushPromises();

    expect(searchItems).toHaveBeenCalledWith('bread');
    expect(
      (wrapper.find('[data-test="quick-consume-search"]').element as HTMLInputElement).value,
    ).toBe('bread');
  });
});
