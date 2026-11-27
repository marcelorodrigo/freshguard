<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import {
  ApiError,
  consumeBatch,
  searchItems,
  type SearchBatch,
  type SearchResult,
} from '../api';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { errorMessageKey } from '../errorMessages';
import { expiryStatus, formatDateSlashed, type ExpiryStatus } from '../format';
import { pushToast } from '../toast';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

/** Reads the `?search=` value, which may be absent or repeated. */
function queryTerm(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

/** Counts characters, not UTF-16 units, matching the server's rune count. */
function characters(term: string): number {
  return Array.from(term).length;
}

const search = ref(queryTerm(route.query.search));
const results = ref<SearchResult[]>([]);
const loading = ref(false);
const failure = ref('');
const pendingConsume = ref<{ batch: SearchBatch; itemName: string } | null>(null);

const trimmed = computed(() => search.value.trim());
const canSearch = computed(() => characters(trimmed.value) >= 2);
const showEmpty = computed(
  () => canSearch.value && !loading.value && results.value.length === 0 && failure.value === '',
);

const expiryClasses: Record<ExpiryStatus, string> = {
  none: 'text-slate-400',
  past: 'text-red-600',
  soon: 'text-amber-500',
  later: 'text-emerald-600',
};

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let requestID = 0;
let applyingRoute = false;

function cancelDebounce(): void {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer);
    debounceTimer = null;
  }
}

/** Runs one search and drops the answer when a newer one has started. */
async function runSearch(term: string): Promise<void> {
  const current = ++requestID;

  if (characters(term) < 2) {
    results.value = [];
    failure.value = '';
    loading.value = false;
    return;
  }

  loading.value = true;
  failure.value = '';

  try {
    const found = await searchItems(term);
    if (current === requestID) {
      results.value = found;
    }
  } catch (error) {
    if (current === requestID) {
      results.value = [];
      failure.value = t(errorMessageKey(error));
    }
  } finally {
    if (current === requestID) {
      loading.value = false;
    }
  }
}

/** Mirrors the trimmed term into `?search=`, dropping it when short. */
async function syncQuery(term: string): Promise<void> {
  if (queryTerm(route.query.search) === term) {
    return;
  }

  const next = { ...route.query };
  if (term === '') {
    delete next.search;
  } else {
    next.search = term;
  }
  await router.replace({ query: next });
}

function scheduleSearch(): void {
  cancelDebounce();
  debounceTimer = setTimeout(() => {
    debounceTimer = null;
    const term = canSearch.value ? trimmed.value : '';
    void syncQuery(term).then(() => runSearch(term));
  }, 200);
}

/** Back/forward changes the URL; load its term without another debounce. */
function applyRouteTerm(value: unknown): void {
  const term = queryTerm(value);
  if (term === trimmed.value) {
    return;
  }

  applyingRoute = true;
  search.value = term;
  void runSearch(term);
}

function askConsume(item: SearchResult, batch: SearchBatch): void {
  pendingConsume.value = { batch, itemName: item.name };
}

async function confirmConsume(): Promise<void> {
  const pending = pendingConsume.value;
  if (pending === null) {
    return;
  }
  pendingConsume.value = null;

  try {
    await consumeBatch(pending.batch.id);
    pushToast({
      level: 'success',
      title: t('quickConsume.consumedTitle'),
      body: t('quickConsume.consumedBody', { item: pending.itemName }),
    });
  } catch (error) {
    if (error instanceof ApiError && error.code === 'batch_unavailable') {
      pushToast({
        level: 'warning',
        title: t('quickConsume.unavailableTitle'),
        body: t('quickConsume.unavailableBody'),
      });
    } else {
      pushToast({ level: 'error', title: t(errorMessageKey(error)) });
    }
  }

  await runSearch(trimmed.value);
}

watch(search, () => {
  if (applyingRoute) {
    applyingRoute = false;
    return;
  }
  scheduleSearch();
});

watch(() => route.query.search, applyRouteTerm);

onMounted(() => {
  if (canSearch.value) {
    void runSearch(trimmed.value);
    return;
  }
  void syncQuery('');
});

onBeforeUnmount(cancelDebounce);
</script>

<template>
  <section>
    <h1 class="text-2xl font-semibold tracking-tight">
      {{ t('quickConsume.title') }}
    </h1>

    <div class="mt-5 max-w-xl">
      <label
        for="quick-consume-search"
        class="sr-only"
      >
        {{ t('quickConsume.searchPlaceholder') }}
      </label>
      <div class="relative">
        <svg
          class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            fill-rule="evenodd"
            d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z"
            clip-rule="evenodd"
          />
        </svg>
        <input
          id="quick-consume-search"
          v-model="search"
          type="search"
          autocomplete="off"
          :placeholder="t('quickConsume.searchPlaceholder')"
          class="w-full rounded-lg border border-slate-300 bg-white py-2 pr-3 pl-10 text-sm focus:border-emerald-500 focus:outline-none"
          data-test="quick-consume-search"
        >
      </div>
      <p
        class="mt-2 text-xs text-slate-500"
        data-test="quick-consume-help"
      >
        {{ t('quickConsume.searchHelp') }}
      </p>
    </div>

    <p
      v-if="failure"
      class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
      data-test="quick-consume-error"
    >
      {{ failure }}
    </p>

    <p
      v-else-if="loading"
      class="mt-4 text-sm text-slate-500"
      data-test="quick-consume-loading"
    >
      {{ t('common.loading') }}
    </p>

    <div
      v-else-if="showEmpty"
      class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"
      data-test="quick-consume-empty"
    >
      <p class="font-medium text-slate-700">
        {{ t('quickConsume.emptyTitle') }}
      </p>
      <p class="mt-1 text-sm text-slate-500">
        {{ t('quickConsume.emptyBody') }}
      </p>
    </div>

    <div
      v-else-if="!canSearch"
      class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"
      data-test="quick-consume-initial"
    >
      <p class="font-medium text-slate-700">
        {{ t('quickConsume.initialTitle') }}
      </p>
      <p class="mt-1 text-sm text-slate-500">
        {{ t('quickConsume.initialBody') }}
      </p>
    </div>

    <ul
      v-else
      class="mt-4 space-y-4"
    >
      <li
        v-for="result in results"
        :key="result.id"
        class="rounded-xl border border-slate-200 bg-white"
        data-test="search-result"
        :data-item-id="result.id"
      >
        <h2
          class="px-4 pt-4 text-lg font-bold text-slate-900"
          data-test="result-name"
          :title="result.description"
        >
          {{ result.name }}
        </h2>

        <div class="mt-2 overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th
                  scope="col"
                  class="px-4 py-2 text-left font-semibold text-slate-600"
                >
                  {{ t('quickConsume.location') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-2 text-left font-semibold text-slate-600"
                >
                  {{ t('quickConsume.expires') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-2 text-left font-semibold text-slate-600"
                >
                  {{ t('quickConsume.qty') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-2 text-right font-semibold text-slate-600"
                >
                  <span class="sr-only">{{ t('common.actions') }}</span>
                </th>
              </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="batch in result.batches"
                :key="batch.id"
                data-test="batch-row"
              >
                <td
                  class="px-4 py-2 whitespace-nowrap"
                  data-test="batch-location"
                >
                  {{ batch.location.name }}
                </td>
                <td class="px-4 py-2 whitespace-nowrap">
                  <span class="inline-flex items-center gap-1.5">
                    <span
                      class="inline-flex"
                      data-test="expiry-icon"
                      :data-status="expiryStatus(batch.expires_at)"
                      aria-hidden="true"
                    >
                      <svg
                        v-if="expiryStatus(batch.expires_at) === 'past'"
                        class="size-4"
                        :class="expiryClasses.past"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                          clip-rule="evenodd"
                        />
                      </svg>
                      <svg
                        v-else-if="expiryStatus(batch.expires_at) === 'soon'"
                        class="size-4"
                        :class="expiryClasses.soon"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z"
                          clip-rule="evenodd"
                        />
                      </svg>
                      <svg
                        v-else-if="expiryStatus(batch.expires_at) === 'later'"
                        class="size-4"
                        :class="expiryClasses.later"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
                          clip-rule="evenodd"
                        />
                      </svg>
                      <svg
                        v-else
                        class="size-4"
                        :class="expiryClasses.none"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0ZM8.94 6.94a.75.75 0 1 1 1.061-1.061 4.5 4.5 0 0 1 1.28 3.182c0 1.09-.52 1.832-1.122 2.31-.168.134-.328.28-.468.408a.75.75 0 0 1-1.002-1.116c.178-.16.369-.333.559-.485.394-.313.533-.604.533-.963a3 3 0 0 0-.84-2.121ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                          clip-rule="evenodd"
                        />
                      </svg>
                    </span>
                    <time
                      class="tabular-nums"
                      data-test="batch-expires"
                      :datetime="batch.expires_at"
                    >{{ formatDateSlashed(batch.expires_at) }}</time>
                  </span>
                </td>
                <td
                  class="px-4 py-2 tabular-nums"
                  data-test="batch-quantity"
                >
                  {{ batch.quantity }}
                </td>
                <td class="px-4 py-2 text-right">
                  <button
                    type="button"
                    class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                    data-test="consume-batch"
                    @click="askConsume(result, batch)"
                  >
                    {{ t('quickConsume.consume') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </li>
    </ul>

    <ConfirmDialog
      :open="pendingConsume !== null"
      :title="t('quickConsume.confirmTitle')"
      :body="t('quickConsume.confirmBody')"
      :confirm-label="t('quickConsume.consume')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmConsume"
      @cancel="pendingConsume = null"
    />
  </section>
</template>
