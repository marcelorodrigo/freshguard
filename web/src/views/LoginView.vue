<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import { ApiError } from '../api';
import AuthLayout from '../components/AuthLayout.vue';
import { errorMessageKey } from '../errorMessages';
import { safeRedirect } from '../navigation';
import { signIn } from '../session';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const failure = ref('');
const submitting = ref(false);
const failedAttempts = ref(0);
const cooldown = ref(0);
const coolingDown = computed(() => cooldown.value > 0);

let cooldownTimer: ReturnType<typeof setInterval> | null = null;

function startCooldown(): void {
  cooldown.value = 60;
  if (cooldownTimer !== null) {
    return;
  }

  cooldownTimer = setInterval(() => {
    cooldown.value = Math.max(0, cooldown.value - 1);
    if (cooldown.value === 0 && cooldownTimer !== null) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
  }, 1000);
}

onBeforeUnmount(() => {
  if (cooldownTimer !== null) {
    clearInterval(cooldownTimer);
  }
});

async function submit(): Promise<void> {
  if (coolingDown.value) {
    return;
  }

  failure.value = '';
  submitting.value = true;

  try {
    await signIn(form.email.trim(), form.password);
    failedAttempts.value = 0;
    await router.replace(safeRedirect(route.query.redirect));
  } catch (error) {
    if (error instanceof ApiError && error.code === 'unauthorized') {
      failedAttempts.value += 1;
      failure.value = t('auth.invalidCredentials');
      if (failedAttempts.value >= 5) {
        startCooldown();
      }
    } else if (error instanceof ApiError && error.code === 'rate_limited') {
      startCooldown();
      failure.value = t('auth.tooManyAttempts');
    } else {
      failure.value = t(errorMessageKey(error));
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthLayout :title="t('auth.signIn')">
    <form
      class="space-y-4"
      @submit.prevent="submit"
    >
      <div>
        <label
          for="login-email"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.email') }}
        </label>
        <input
          id="login-email"
          v-model="form.email"
          type="email"
          required
          maxlength="255"
          autocomplete="email"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
      </div>

      <div>
        <label
          for="login-password"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.password') }}
        </label>
        <input
          id="login-password"
          v-model="form.password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
      </div>

      <p
        v-if="failure"
        class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        role="alert"
        data-test="login-error"
      >
        {{ failure }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="submitting || coolingDown"
      >
        {{ submitting ? t('auth.signingIn') : t('auth.signIn') }}
      </button>

      <p
        v-if="coolingDown"
        class="text-center text-sm text-amber-700"
        data-test="login-cooldown"
      >
        {{ t('auth.tooManyAttempts') }}
      </p>
    </form>
  </AuthLayout>
</template>
