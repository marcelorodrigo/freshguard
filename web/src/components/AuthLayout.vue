<script setup lang="ts">
defineProps<{
  title: string;
  intro?: string;
}>();
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
    <section
      class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200"
      data-test="auth-card"
    >
      <p class="text-sm font-medium text-emerald-700">
        {{ $t('app.title') }}
      </p>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">
        {{ title }}
      </h1>
      <p
        v-if="intro"
        class="mt-2 text-sm text-slate-500"
      >
        {{ intro }}
      </p>

      <div class="mt-6">
        <slot />
      </div>
    </section>
  </main>
</template>
