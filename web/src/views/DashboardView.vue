<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import { getRecentlyExpired, type ExpiredBatch } from '../api';
import { errorMessageKey } from '../errorMessages';
import { formatDate } from '../format';

const { t } = useI18n();
const router = useRouter();

const batches = ref<ExpiredBatch[]>([]);
const loading = ref(false);
const failure = ref('');

async function load(): Promise<void> {
  loading.value = true;
  failure.value = '';

  try {
    batches.value = await getRecentlyExpired();
  } catch (error) {
    batches.value = [];
    failure.value = t(errorMessageKey(error));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

async function openItem(itemID: string): Promise<void> {
  await router.push(`/items/${encodeURIComponent(itemID)}`);
}
</script>

<template>
  <section>
    <h1 class="text-2xl font-semibold tracking-tight">
      {{ t('dashboard.recentlyExpired') }}
    </h1>

    <p
      v-if="loading"
      class="mt-4 text-sm text-slate-500"
      data-test="dashboard-loading"
    >
      {{ t('common.loading') }}
    </p>

    <div
      v-if="failure"
      class="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
      data-test="dashboard-error"
    >
      <span>{{ failure }}</span>
      <button
        type="button"
        class="rounded border border-red-300 px-2 py-1 font-medium hover:bg-red-100"
        data-test="dashboard-retry"
        @click="load"
      >
        {{ t('common.retry') }}
      </button>
    </div>

    <div
      v-if="!loading && !failure && batches.length === 0"
      class="mt-4 rounded-xl border border-slate-200 bg-white px-6 py-12 text-center"
      data-test="dashboard-empty"
    >
      <svg
        class="mx-auto size-10 text-emerald-600"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        aria-hidden="true"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
        />
      </svg>
      <p class="mt-3 text-base font-semibold">
        {{ t('dashboard.empty') }}
      </p>
    </div>

    <div
      v-if="!loading && batches.length > 0"
      class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white"
    >
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              {{ t('dashboard.item') }}
            </th>
            <th class="hidden px-3 py-3 text-left font-semibold text-slate-600 lg:table-cell">
              {{ t('common.location') }}
            </th>
            <th class="hidden px-3 py-3 text-left font-semibold text-slate-600 sm:table-cell">
              {{ t('common.quantity') }}
            </th>
            <th class="hidden px-3 py-3 text-left font-semibold text-slate-600 md:table-cell">
              {{ t('items.expirationDate') }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="batch in batches"
            :key="batch.id"
            :data-item-id="batch.item.id"
            class="cursor-pointer hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
            tabindex="0"
            data-test="dashboard-row"
            @click="openItem(batch.item.id)"
            @keydown.enter="openItem(batch.item.id)"
            @keydown.space.prevent="openItem(batch.item.id)"
          >
            <td
              class="px-3 py-3 font-medium text-slate-900"
              data-test="dashboard-item"
            >
              {{ batch.item.name }}
            </td>
            <td
              class="hidden px-3 py-3 text-slate-600 lg:table-cell"
              data-test="dashboard-location"
            >
              {{ batch.location.name }}
            </td>
            <td
              class="hidden px-3 py-3 text-slate-600 sm:table-cell"
              data-test="dashboard-quantity"
            >
              {{ batch.quantity }}
            </td>
            <td
              class="hidden px-3 py-3 text-slate-600 md:table-cell"
              data-test="dashboard-expires"
            >
              {{ formatDate(batch.expires_at) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
