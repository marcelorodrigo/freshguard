<script setup lang="ts">
import { useI18n } from 'vue-i18n';

import { dismissToast, useToasts, type ToastLevel } from '../toast';

const { t } = useI18n();
const toasts = useToasts();

function toneClass(level: ToastLevel): string {
  switch (level) {
    case 'success':
      return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    case 'warning':
      return 'border-amber-200 bg-amber-50 text-amber-900';
    case 'error':
      return 'border-red-200 bg-red-50 text-red-900';
  }
}
</script>

<template>
  <div
    class="pointer-events-none fixed inset-x-4 top-4 z-[60] flex flex-col items-end gap-2 sm:left-auto sm:w-96"
    aria-live="polite"
  >
    <div
      v-for="toast in toasts.items"
      :key="toast.id"
      class="pointer-events-auto w-full rounded-xl border p-4 shadow-lg"
      :class="toneClass(toast.level)"
      data-test="toast"
    >
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-semibold">
            {{ toast.title }}
          </p>
          <p
            v-if="toast.body"
            class="mt-1 text-sm opacity-80"
          >
            {{ toast.body }}
          </p>
        </div>
        <button
          type="button"
          class="rounded p-1 opacity-60 hover:opacity-100"
          :aria-label="t('common.close')"
          @click="dismissToast(toast.id)"
        >
          <svg
            class="size-4"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
          >
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>
