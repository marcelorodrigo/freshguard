<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import {
  bulkDeleteItems,
  deleteItem,
  listItems,
  type Item,
  type ItemSort,
  type PageMeta,
} from '../api';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { errorMessageKey } from '../errorMessages';
import { formatDate } from '../format';
import { pushToast } from '../toast';

const { t } = useI18n();

const items = ref<Item[]>([]);
const meta = ref<PageMeta>({ page: 1, per_page: 10, total: 0, pages: 0 });
const loading = ref(false);
const failure = ref('');
const search = ref('');
const sort = ref<ItemSort>('name');
const dir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const selected = ref<string[]>([]);

const columns = reactive({
  description: false,
  tags: false,
  barcode: false,
  created: false,
  updated: false,
});

type PendingDelete = { kind: 'single'; id: string } | { kind: 'bulk' };
const pendingDelete = ref<PendingDelete | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | null = null;

const allSelected = computed(
  () => items.value.length > 0 && items.value.every((item) => selected.value.includes(item.id)),
);

const pageLabel = computed(
  () => `${meta.value.page} ${t('common.of')} ${meta.value.pages}`,
);

async function load(): Promise<void> {
  loading.value = true;
  failure.value = '';

  try {
    const result = await listItems({
      q: search.value.trim(),
      sort: sort.value,
      dir: dir.value,
      page: page.value,
      per_page: 10,
    });
    items.value = result.data;
    meta.value = result.meta;
    selected.value = [];
  } catch (error) {
    items.value = [];
    failure.value = t(errorMessageKey(error));
  } finally {
    loading.value = false;
  }
}

watch(search, () => {
  if (searchTimer !== null) {
    clearTimeout(searchTimer);
  }
  searchTimer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});

onMounted(load);
onBeforeUnmount(() => {
  if (searchTimer !== null) {
    clearTimeout(searchTimer);
  }
});

function sortBy(field: ItemSort): void {
  if (sort.value === field) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sort.value = field;
    dir.value = 'asc';
  }
  page.value = 1;
  void load();
}

function sortMark(field: ItemSort): string {
  if (sort.value !== field) {
    return '';
  }
  return dir.value === 'asc' ? '↑' : '↓';
}

function goTo(next: number): void {
  if (next < 1 || (meta.value.pages > 0 && next > meta.value.pages)) {
    return;
  }
  page.value = next;
  void load();
}

function toggleAll(): void {
  selected.value = allSelected.value ? [] : items.value.map((item) => item.id);
}

function toggleOne(id: string): void {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((selectedID) => selectedID !== id)
    : [...selected.value, id];
}

function askDelete(item: Item): void {
  pendingDelete.value = { kind: 'single', id: item.id };
}

function askBulkDelete(): void {
  pendingDelete.value = { kind: 'bulk' };
}

async function confirmDelete(): Promise<void> {
  const pending = pendingDelete.value;
  if (pending === null) {
    return;
  }
  pendingDelete.value = null;

  try {
    if (pending.kind === 'single') {
      await deleteItem(pending.id);
      if (items.value.length === 1 && page.value > 1) {
        page.value -= 1;
      }
      pushToast({ level: 'success', title: t('items.deleted') });
    } else {
      const ids = [...selected.value];
      await bulkDeleteItems(ids);
      if (ids.length === items.value.length && page.value > 1) {
        page.value -= 1;
      }
      pushToast({ level: 'success', title: t('items.bulkDeleted') });
    }
    await load();
  } catch (error) {
    pushToast({ level: 'error', title: t(errorMessageKey(error)) });
  }
}
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold tracking-tight">
        {{ t('items.title') }}
      </h1>
      <RouterLink
        :to="{ name: 'item-new' }"
        class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
        data-test="new-item"
      >
        <svg
          class="size-4"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"
          />
        </svg>
        {{ t('items.new') }}
      </RouterLink>
    </div>

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <input
        v-model="search"
        type="search"
        :placeholder="t('items.searchPlaceholder')"
        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:max-w-sm"
        data-test="items-search"
      >

      <div class="flex items-center gap-2">
        <details class="relative">
          <summary
            class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            {{ t('common.columns') }}
          </summary>
          <div
            class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-2 shadow-lg"
          >
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.description"
                type="checkbox"
                class="rounded border-slate-300"
              >
              {{ t('common.description') }}
            </label>
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.tags"
                type="checkbox"
                class="rounded border-slate-300"
              >
              {{ t('common.tags') }}
            </label>
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.barcode"
                type="checkbox"
                class="rounded border-slate-300"
              >
              {{ t('common.barcode') }}
            </label>
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.created"
                type="checkbox"
                class="rounded border-slate-300"
              >
              {{ t('common.created') }}
            </label>
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.updated"
                type="checkbox"
                class="rounded border-slate-300"
              >
              {{ t('common.updated') }}
            </label>
          </div>
        </details>

        <button
          v-if="selected.length > 0"
          type="button"
          class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700"
          data-test="bulk-delete"
          @click="askBulkDelete"
        >
          {{ t('items.bulkDelete') }} ({{ selected.length }})
        </button>
      </div>
    </div>

    <p
      v-if="failure"
      class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
      data-test="items-error"
    >
      {{ failure }}
    </p>

    <div
      v-if="!loading && items.length > 0"
      class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white"
    >
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="w-10 px-3 py-3 text-left">
              <input
                type="checkbox"
                class="rounded border-slate-300"
                :checked="allSelected"
                :aria-label="t('items.selectAll')"
                @change="toggleAll"
              >
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                @click="sortBy('name')"
              >
                {{ t('common.name') }} {{ sortMark('name') }}
              </button>
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                @click="sortBy('quantity')"
              >
                {{ t('common.quantity') }} {{ sortMark('quantity') }}
              </button>
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                @click="sortBy('earliest_expiration')"
              >
                {{ t('items.firstBatchExpiration') }} {{ sortMark('earliest_expiration') }}
              </button>
            </th>
            <th
              v-if="columns.description"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              {{ t('common.description') }}
            </th>
            <th
              v-if="columns.tags"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              {{ t('common.tags') }}
            </th>
            <th
              v-if="columns.barcode"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              {{ t('common.barcode') }}
            </th>
            <th
              v-if="columns.created"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              <button
                type="button"
                class="inline-flex items-center gap-1"
                @click="sortBy('created_at')"
              >
                {{ t('common.created') }} {{ sortMark('created_at') }}
              </button>
            </th>
            <th
              v-if="columns.updated"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              <button
                type="button"
                class="inline-flex items-center gap-1"
                @click="sortBy('updated_at')"
              >
                {{ t('common.updated') }} {{ sortMark('updated_at') }}
              </button>
            </th>
            <th class="px-3 py-3 text-right font-semibold text-slate-600">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="item in items"
            :key="item.id"
            class="hover:bg-slate-50"
          >
            <td class="px-3 py-3">
              <input
                type="checkbox"
                class="rounded border-slate-300"
                :checked="selected.includes(item.id)"
                :aria-label="item.name"
                @change="toggleOne(item.id)"
              >
            </td>
            <td class="px-3 py-3 font-medium">
              <RouterLink
                :to="{ name: 'item-edit', params: { id: item.id } }"
                class="text-emerald-700 hover:underline"
              >
                {{ item.name }}
              </RouterLink>
            </td>
            <td class="px-3 py-3 tabular-nums">
              {{ item.quantity }}
            </td>
            <td class="px-3 py-3 whitespace-nowrap">
              {{ formatDate(item.earliest_expiration) }}
            </td>
            <td
              v-if="columns.description"
              class="max-w-64 truncate px-3 py-3 text-slate-600"
            >
              {{ item.description }}
            </td>
            <td
              v-if="columns.tags"
              class="px-3 py-3"
            >
              <span class="flex flex-wrap gap-1">
                <span
                  v-for="tag in item.tags"
                  :key="tag"
                  class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800"
                >
                  {{ tag }}
                </span>
              </span>
            </td>
            <td
              v-if="columns.barcode"
              class="px-3 py-3 whitespace-nowrap text-slate-600"
            >
              {{ item.barcode }}
            </td>
            <td
              v-if="columns.created"
              class="px-3 py-3 whitespace-nowrap"
            >
              {{ formatDate(item.created_at) }}
            </td>
            <td
              v-if="columns.updated"
              class="px-3 py-3 whitespace-nowrap"
            >
              {{ formatDate(item.updated_at) }}
            </td>
            <td class="px-3 py-3">
              <div class="flex justify-end gap-2">
                <RouterLink
                  :to="{ name: 'item-edit', params: { id: item.id } }"
                  class="rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100"
                >
                  {{ t('common.edit') }}
                </RouterLink>
                <button
                  type="button"
                  class="rounded px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                  @click="askDelete(item)"
                >
                  {{ t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p
      v-if="loading"
      class="mt-4 text-sm text-slate-500"
    >
      {{ t('common.loading') }}
    </p>

    <div
      v-else-if="failure === '' && items.length === 0"
      class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"
      data-test="items-empty"
    >
      <template v-if="search.trim() !== ''">
        <p class="font-medium text-slate-700">
          {{ t('items.noMatches') }}
        </p>
      </template>
      <template v-else>
        <p class="font-medium text-slate-700">
          {{ t('items.emptyTitle') }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
          {{ t('items.emptyBody') }}
        </p>
        <RouterLink
          :to="{ name: 'item-new' }"
          class="mt-4 inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
        >
          {{ t('items.new') }}
        </RouterLink>
      </template>
    </div>

    <div
      v-if="!loading && meta.total > 0"
      class="mt-4 flex items-center justify-between text-sm text-slate-600"
    >
      <p>{{ pageLabel }}</p>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-40"
          :disabled="meta.page <= 1"
          @click="goTo(meta.page - 1)"
        >
          {{ t('common.previous') }}
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-40"
          :disabled="meta.pages === 0 || meta.page >= meta.pages"
          @click="goTo(meta.page + 1)"
        >
          {{ t('common.next') }}
        </button>
      </div>
    </div>

    <ConfirmDialog
      :open="pendingDelete !== null"
      :title="pendingDelete?.kind === 'bulk' ? t('items.bulkDeleteTitle') : t('items.deleteTitle')"
      :body="pendingDelete?.kind === 'bulk' ? t('items.bulkDeleteConfirm') : t('items.deleteConfirm')"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
    />
  </section>
</template>
