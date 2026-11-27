<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';

const { t } = useI18n();
const route = useRoute();

const titles = {
  dashboard: 'nav.dashboard',
  'quick-consume': 'nav.quickConsume',
  locations: 'nav.locations',
  users: 'nav.users',
} as const;

const title = computed(() => {
  const key = titles[route.name as keyof typeof titles];
  return key === undefined ? t('app.comingSoon') : t(key);
});
</script>

<template>
  <section>
    <h1 class="text-2xl font-semibold tracking-tight">
      {{ title }}
    </h1>
    <p class="mt-2 text-sm text-slate-500">
      {{ t('app.comingSoon') }}
    </p>
  </section>
</template>
