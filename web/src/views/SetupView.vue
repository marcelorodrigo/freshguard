<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import { ApiError } from '../api';
import AuthLayout from '../components/AuthLayout.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { createFirstAdmin } from '../session';

const { t } = useI18n();
const router = useRouter();

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
});

const errors = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  form: '',
});

const submitting = ref(false);

function validate(): boolean {
  errors.name = form.name.trim() === '' ? t('errors.required') : '';
  errors.email = form.email.trim() === '' ? t('errors.required') : '';
  errors.password =
    form.password.length < 8 ? t('errors.min') : form.password.length > 255 ? t('errors.max') : '';
  errors.password_confirmation =
    form.password === form.password_confirmation ? '' : t('auth.passwordMismatch');

  return (
    errors.name === '' &&
    errors.email === '' &&
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
    await createFirstAdmin({
      name: form.name.trim(),
      email: form.email.trim(),
      password: form.password,
    });
    await router.replace('/items');
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.code === 'conflict') {
        await router.replace('/login');
        return;
      }
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
</script>

<template>
  <AuthLayout
    :title="t('setup.title')"
    :intro="t('setup.intro')"
  >
    <form
      class="space-y-4"
      @submit.prevent="submit"
    >
      <div>
        <label
          for="setup-name"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('setup.name') }}
        </label>
        <input
          id="setup-name"
          v-model="form.name"
          type="text"
          required
          maxlength="255"
          autocomplete="name"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
        <p
          v-if="errors.name"
          class="mt-1 text-sm text-red-600"
        >
          {{ errors.name }}
        </p>
      </div>

      <div>
        <label
          for="setup-email"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('setup.email') }}
        </label>
        <input
          id="setup-email"
          v-model="form.email"
          type="email"
          required
          maxlength="255"
          autocomplete="email"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        >
        <p
          v-if="errors.email"
          class="mt-1 text-sm text-red-600"
        >
          {{ errors.email }}
        </p>
      </div>

      <div>
        <label
          for="setup-password"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('setup.password') }}
        </label>
        <input
          id="setup-password"
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
          for="setup-password-confirmation"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('setup.passwordConfirmation') }}
        </label>
        <input
          id="setup-password-confirmation"
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
        data-test="setup-error"
      >
        {{ errors.form }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
        :disabled="submitting"
      >
        {{ submitting ? t('setup.creating') : t('setup.createAdmin') }}
      </button>
    </form>
  </AuthLayout>
</template>
