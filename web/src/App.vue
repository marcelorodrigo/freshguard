<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { getHealth, type Health } from './api';
import { errorMessageKey, type ErrorMessageKey } from './errorMessages';

const { t } = useI18n();

const health = ref<Health | null>(null);
const failure = ref<ErrorMessageKey | null>(null);
const loading = ref(true);

const errorText = computed(() => (failure.value === null ? '' : t(failure.value)));

async function load(): Promise<void> {
  loading.value = true;
  failure.value = null;

  try {
    health.value = await getHealth();
  } catch (error) {
    failure.value = errorMessageKey(error);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
    <section
      class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200"
    >
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
        {{ t('app.title') }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ t('app.tagline') }}
      </p>

      <p
        v-if="loading"
        class="mt-6 text-sm text-slate-600"
      >
        {{ t('app.loading') }}
      </p>

      <p
        v-else-if="failure"
        class="mt-6 text-sm text-red-600"
        role="alert"
        data-test="error"
      >
        {{ t('errors.title') }}: {{ errorText }}
      </p>

      <dl
        v-else-if="health"
        class="mt-6 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm"
      >
        <dt class="text-slate-500">
          {{ t('app.status') }}
        </dt>
        <dd
          class="font-medium text-emerald-700"
          data-test="status"
        >
          {{ health.status }}
        </dd>

        <dt class="text-slate-500">
          {{ t('app.version') }}
        </dt>
        <dd
          class="font-medium text-slate-900"
          data-test="version"
        >
          {{ health.version }}
        </dd>

        <dt class="text-slate-500">
          {{ t('app.serverTime') }}
        </dt>
        <dd
          class="font-medium text-slate-900"
          data-test="time"
        >
          {{ health.time }}
        </dd>
      </dl>
    </section>
  </main>
</template>
