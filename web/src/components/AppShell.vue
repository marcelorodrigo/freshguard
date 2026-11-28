<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';

import { setLocale, type Locale } from '../i18n';
import { session, signOut } from '../session';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();

const navOpen = ref(false);
const user = computed(() => session.user);

watch(
  () => route.fullPath,
  () => {
    navOpen.value = false;
  },
);

function changeLocale(next: Locale): void {
  setLocale(next);
}

async function handleSignOut(): Promise<void> {
  try {
    await signOut();
  } catch {
    // Local state is already cleared; the redirect must still happen.
  }
  await router.replace('/login');
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900 lg:flex">
    <button
      v-if="navOpen"
      type="button"
      class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
      :aria-label="t('app.closeMenu')"
      @click="navOpen = false"
    />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:static lg:translate-x-0"
      :class="navOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-16 items-center justify-between border-b border-slate-200 px-5">
        <RouterLink
          to="/items"
          class="text-lg font-semibold tracking-tight text-emerald-700"
        >
          {{ t('app.title') }}
        </RouterLink>
        <button
          type="button"
          class="rounded p-1 text-slate-500 hover:bg-slate-100 lg:hidden"
          :aria-label="t('app.closeMenu')"
          @click="navOpen = false"
        >
          <svg
            class="size-5"
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

      <nav class="flex-1 space-y-1 overflow-y-auto p-3">
        <RouterLink
          to="/"
          class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
          active-class="bg-emerald-50 text-emerald-800"
        >
          {{ t('nav.dashboard') }}
        </RouterLink>

        <p class="px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">
          {{ t('nav.inventory') }}
        </p>
        <RouterLink
          to="/quick-consume"
          class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
          active-class="bg-emerald-50 text-emerald-800"
        >
          {{ t('nav.quickConsume') }}
        </RouterLink>
        <RouterLink
          to="/items"
          class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
          active-class="bg-emerald-50 text-emerald-800"
        >
          {{ t('nav.items') }}
        </RouterLink>

        <RouterLink
          to="/locations"
          class="mt-2 block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
          active-class="bg-emerald-50 text-emerald-800"
        >
          {{ t('nav.locations') }}
        </RouterLink>
        <RouterLink
          v-if="user?.is_admin"
          to="/users"
          class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
          active-class="bg-emerald-50 text-emerald-800"
        >
          {{ t('nav.users') }}
        </RouterLink>
      </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
        <button
          type="button"
          class="rounded p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
          :aria-label="t('app.menu')"
          @click="navOpen = true"
        >
          <svg
            class="size-5"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
          >
            <path
              fill-rule="evenodd"
              d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75Zm0 5A.75.75 0 0 1 2.75 9h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 9.75Zm0 5a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z"
              clip-rule="evenodd"
            />
          </svg>
        </button>

        <div class="lg:ml-auto">
          <details class="relative">
            <summary
              class="flex cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
              <span class="max-w-40 truncate">{{ user?.name }}</span>
              <svg
                class="size-4 text-slate-400"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
              >
                <path
                  fill-rule="evenodd"
                  d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
                  clip-rule="evenodd"
                />
              </svg>
            </summary>

            <div
              class="absolute right-0 z-50 mt-2 w-64 rounded-xl border border-slate-200 bg-white py-2 shadow-xl"
            >
              <div class="border-b border-slate-100 px-4 pb-3">
                <p class="truncate text-sm font-semibold">
                  {{ user?.name }}
                </p>
                <p class="truncate text-xs text-slate-500">
                  {{ user?.email }}
                </p>
              </div>

              <div class="border-b border-slate-100 px-2 py-2">
                <RouterLink
                  to="/profile"
                  class="block rounded px-2 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
                  data-test="profile-link"
                >
                  {{ t('profile.title') }}
                </RouterLink>
              </div>

              <div class="border-b border-slate-100 px-4 py-3">
                <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                  {{ t('profile.language') }}
                </p>
                <button
                  type="button"
                  class="mt-2 block w-full rounded px-2 py-1 text-left text-sm hover:bg-slate-100"
                  :class="locale === 'en' ? 'font-semibold text-emerald-700' : 'text-slate-700'"
                  @click="changeLocale('en')"
                >
                  {{ t('language.en') }}
                </button>
                <button
                  type="button"
                  class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-slate-100"
                  :class="locale === 'pt-BR' ? 'font-semibold text-emerald-700' : 'text-slate-700'"
                  @click="changeLocale('pt-BR')"
                >
                  {{ t('language.ptBR') }}
                </button>
              </div>

              <div class="px-2 pt-2">
                <button
                  type="button"
                  class="block w-full rounded px-2 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50"
                  @click="handleSignOut"
                >
                  {{ t('auth.signOut') }}
                </button>
              </div>
            </div>
          </details>
        </div>
      </header>

      <main class="min-w-0 flex-1 p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
