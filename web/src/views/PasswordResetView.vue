<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';

import { ApiError, completePasswordReset, getPasswordReset } from '../api';
import AuthLayout from '../components/AuthLayout.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';

const { t } = useI18n();
const route = useRoute();
const token = computed(() => String(route.params.token ?? ''));

const loading = ref(true);
const invalid = ref(false);
const done = ref(false);
const email = ref('');
const submitting = ref(false);

const form = reactive({ password: '', password_confirmation: '' });
const errors = reactive({ password: '', password_confirmation: '', form: '' });

const title = computed(() => {
  if (invalid.value) {
    return t('reset.invalidTitle');
  }
  return done.value ? t('reset.doneTitle') : t('reset.title');
});

const intro = computed(() => {
  if (invalid.value) {
    return t('reset.invalidBody');
  }
  return done.value ? t('reset.doneBody') : t('reset.intro', { email: email.value });
});

function validate(): boolean {
  if (form.password.length < 8) {
    errors.password = t('errors.min');
  } else if (form.password.length > 255) {
    errors.password = t('errors.max');
  } else {
    errors.password = '';
  }
  errors.password_confirmation =
    form.password === form.password_confirmation ? '' : t('auth.passwordMismatch');
  errors.form = '';

  return errors.password === '' && errors.password_confirmation === '';
}

function reportFailure(error: unknown): void {
  if (error instanceof ApiError && error.code === 'not_found') {
    invalid.value = true;
    return;
  }
  if (error instanceof ApiError) {
    for (const [field, rule] of Object.entries(error.fields)) {
      if (field in errors) {
        errors[field as keyof typeof errors] = t(fieldErrorKey(rule));
      }
    }
  }
  errors.form = t(errorMessageKey(error));
}

async function load(): Promise<void> {
  try {
    const reset = await getPasswordReset(token.value);
    email.value = reset.email;
  } catch (error) {
    reportFailure(error);
  } finally {
    loading.value = false;
  }
}

onMounted(load);

async function submit(): Promise<void> {
  if (submitting.value || !validate()) {
    return;
  }

  submitting.value = true;
  try {
    await completePasswordReset(token.value, form.password);
    done.value = true;
  } catch (error) {
    reportFailure(error);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthLayout
    :title="title"
    :intro="intro"
  >
    <p
      v-if="loading"
      class="text-sm text-slate-500"
      data-test="reset-loading"
    >
      {{ t('reset.loading') }}
    </p>

    <div
      v-else-if="invalid"
      data-test="reset-invalid"
    >
      <RouterLink
        to="/login"
        class="inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
      >
        {{ t('reset.signIn') }}
      </RouterLink>
    </div>

    <div
      v-else-if="done"
      data-test="reset-done"
    >
      <RouterLink
        to="/login"
        class="inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
        data-test="reset-sign-in"
      >
        {{ t('reset.signIn') }}
      </RouterLink>
    </div>

    <form
      v-else
      class="space-y-4"
      data-test="reset-form"
      @submit.prevent="submit"
    >
      <div>
        <label
          for="reset-password"
          class="block text-sm font-medium text-slate-700"
        >
          {{ t('auth.newPassword') }}
        </label>
        <input
          id="reset-password"
          v-model="form.password"
          type="password"
          required
          minlength="8"
          maxlength="255"
          autocomplete="new-password"
          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
          data-test="reset-password"
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
          data-test="reset-password-confirmation"
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
        data-test="reset-error"
      >
        {{ errors.form }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
        :disabled="submitting"
        data-test="reset-submit"
        @click="submit"
      >
        {{ submitting ? t('reset.submitting') : t('reset.submit') }}
      </button>
    </form>
  </AuthLayout>
</template>
