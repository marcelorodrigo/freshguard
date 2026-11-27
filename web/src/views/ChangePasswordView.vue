<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import { ApiError } from '../api';
import AuthLayout from '../components/AuthLayout.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { changePassword, signOut } from '../session';
import { pushToast } from '../toast';

const { t } = useI18n();
const router = useRouter();

const form = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
});

const errors = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
  form: '',
});

const submitting = ref(false);

function validate(): boolean {
  errors.current_password =
    form.current_password === '' ? t('errors.required') : '';
  errors.password =
    form.password.length < 8 ? t('errors.min') : form.password.length > 255 ? t('errors.max') : '';
  errors.password_confirmation =
    form.password === form.password_confirmation ? '' : t('auth.passwordMismatch');

  return (
    errors.current_password === '' &&
    errors.password === '' &&
    errors.password_confirmation === ''
  );
}

async function submit(): Promise<void> {
  errors.form = '';
  if (!validate()) {
    return;
  }

  submitting.value = true;
  try {
    await changePassword(form.current_password, form.password);
    pushToast({ level: 'success', title: t('auth.passwordChanged') });
    await router.replace('/items');
  } catch (error) {
    if (error instanceof ApiError) {
      for (const [field, rule] of Object.entries(error.fields)) {
        if (field in errors) {
          errors[field as keyof typeof errors] = t(fieldErrorKey(rule));
        }
      }
      errors.form = t(errorMessageKey(error));
    } else {
      errors.form = t(errorMessageKey(error));
    }
  } finally {
    submitting.value = false;
  }
}

async function handleSignOut(): Promise<void> {
  try {
    await signOut();
  } catch {
    // Local state is already cleared; signing out must still leave the form.
  }
  await router.replace('/login');
}
</script>

<template>
  <AuthLayout
    :title="t('auth.changePassword')"
    :intro="t('auth.mustReset')"
  >
    <form
      class="space-y-4"
      @submit.prevent="submit"
    >
      <div>
        <label
          for="reset-current-password"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.currentPassword') }}
        </label>
        <input
          id="reset-current-password"
          v-model="form.current_password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
        <p
          v-if="errors.current_password"
          class="mt-1 text-sm text-red-600"
        >
          {{ errors.current_password }}
        </p>
      </div>

      <div>
        <label
          for="reset-new-password"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.newPassword') }}
        </label>
        <input
          id="reset-new-password"
          v-model="form.password"
          type="password"
          required
          minlength="8"
          maxlength="255"
          autocomplete="new-password"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
        <p
          v-if="errors.password"
          class="mt-1 text-sm text-red-600"
        >
          {{ errors.password }}
        </p>
      </div>

      <div>
        <label
          for="reset-password-confirmation"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.confirmPassword') }}
        </label>
        <input
          id="reset-password-confirmation"
          v-model="form.password_confirmation"
          type="password"
          required
          minlength="8"
          maxlength="255"
          autocomplete="new-password"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
        <p
          v-if="errors.password_confirmation"
          class="mt-1 text-sm text-red-600"
        >
          {{ errors.password_confirmation }}
        </p>
      </div>

      <p
        v-if="errors.form"
        class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        role="alert"
        data-test="change-password-error"
      >
        {{ errors.form }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
        :disabled="submitting"
      >
        {{ submitting ? t('common.saving') : t('auth.changePassword') }}
      </button>

      <button
        type="button"
        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
        @click="handleSignOut"
      >
        {{ t('auth.signOut') }}
      </button>
    </form>
  </AuthLayout>
</template>
