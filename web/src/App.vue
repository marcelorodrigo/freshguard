<script setup lang="ts">
import { onMounted, ref } from 'vue';

import { getHealth, type Health } from './api';

const health = ref<Health | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    health.value = await getHealth();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : String(failure);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
    <section class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
        FreshGuard
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Household inventory, one binary.
      </p>

      <p
        v-if="loading"
        class="mt-6 text-sm text-slate-600"
      >
        Checking the server…
      </p>

      <p
        v-else-if="error"
        class="mt-6 text-sm text-red-600"
        role="alert"
        data-test="error"
      >
        Server unreachable: {{ error }}
      </p>

      <dl
        v-else-if="health"
        class="mt-6 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm"
      >
        <dt class="text-slate-500">
          Status
        </dt>
        <dd
          class="font-medium text-emerald-700"
          data-test="status"
        >
          {{ health.status }}
        </dd>

        <dt class="text-slate-500">
          Version
        </dt>
        <dd
          class="font-medium text-slate-900"
          data-test="version"
        >
          {{ health.version }}
        </dd>

        <dt class="text-slate-500">
          Server time
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
