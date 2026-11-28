<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import { ApiError, updateMe, type ProfilePatch } from '../api';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { setLocale, type Locale } from '../i18n';
import { session, signOut } from '../session';
import { pushToast } from '../toast';

const { t, locale } = useI18n();
const router = useRouter();

const form = reactive({
  name: session.user?.name ?? '',
  email: session.user?.email ?? '',
  current_password: '',
  password: '',
  password_confirmation: '',
});

const errors = reactive({
  name: '',
  email: '',
  current_password: '',
  password: '',
  password_confirmation: '',
  form: '',
});

const saving = ref(false);

function validateName(): string {
  const name = form.name.trim();
  if (name === '') {
    return t('errors.required');
  }
  return name.length > 255 ? t('errors.max') : '';
}

function validateEmail(): string {
  const email = form.email.trim();
  if (email === '') {
    return t('errors.required');
  }
  if (email.length > 255) {
    return t('errors.max');
  }
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? '' : t('errors.email');
}

function validatePassword(): boolean {
  if (
    form.password === '' &&
    form.password_confirmation === '' &&
    form.current_password === ''
  ) {
    errors.current_password = '';
    errors.password = '';
    errors.password_confirmation = '';
    return true;
  }

  errors.current_password =
    form.current_password === '' ? t('errors.required') : '';
  if (form.password === '') {
    errors.password = t('errors.required');
  } else if (form.password.length < 8) {
    errors.password = t('errors.min');
  } else if (form.password.length > 255) {
    errors.password = t('errors.max');
  } else {
    errors.password = '';
  }
  errors.password_confirmation =
    form.password === form.password_confirmation ? '' : t('auth.passwordMismatch');

  return (
    errors.current_password === '' &&
    errors.password === '' &&
    errors.password_confirmation === ''
  );
}

function validate(): boolean {
  errors.name = validateName();
  errors.email = validateEmail();
  errors.form = '';

  return validatePassword() && errors.name === '' && errors.email === '';
}

function applyFieldErrors(error: ApiError): void {
  for (const [field, rule] of Object.entries(error.fields)) {
    if (field in errors) {
      errors[field as keyof typeof errors] = t(fieldErrorKey(rule));
    }
  }
}

async function submit(): Promise<void> {
  if (saving.value || !validate()) {
    return;
  }

  const patch: ProfilePatch = {
    name: form.name.trim(),
    email: form.email.trim(),
  };
  if (form.password !== '') {
    patch.current_password = form.current_password;
    patch.password = form.password;
  }

  saving.value = true;
  try {
    session.user = await updateMe(patch);
    form.current_password = '';
    form.password = '';
    form.password_confirmation = '';
    pushToast({ level: 'success', title: t('profile.updated') });
  } catch (error) {
    if (error instanceof ApiError) {
      applyFieldErrors(error);
    }
    errors.form = t(errorMessageKey(error));
  } finally {
    saving.value = false;
  }
}

function changeLocale(next: Locale): void {
  setLocale(next);
}

async function handleSignOut(): Promise<void> {
  try {
    await signOut();
  } catch {
    // Local state is already cleared; signing out must still leave the screen.
  }
  await router.replace('/login');
}
</script>

<template>
  <section class="max-w-2xl">
    <h1 class="text-2xl font-semibold tracking-tight">
      {{ t('profile.title') }}
    </h1>

    <form
      class="mt-5 space-y-6"
      @submit.prevent="submit"
    >
      <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold">
          {{ t('users.profileInformation') }}
        </h2>

        <div class="mt-4 space-y-4">
          <div>
            <label
              for="profile-name"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.name') }}
            </label>
            <input
              id="profile-name"
              v-model="form.name"
              type="text"
              required
              maxlength="255"
              autocomplete="name"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="profile-name"
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
              for="profile-email"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.email') }}
            </label>
            <input
              id="profile-email"
              v-model="form.email"
              type="email"
              required
              maxlength="255"
              autocomplete="email"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="profile-email"
            >
            <p
              v-if="errors.email"
              class="mt-1 text-sm text-red-600"
            >
              {{ errors.email }}
            </p>
          </div>
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold">
          {{ t('profile.passwordSection') }}
        </h2>
        <p class="mt-1 text-sm text-slate-500">
          {{ t('profile.passwordHelp') }}
        </p>

        <div class="mt-4 space-y-4">
          <div>
            <label
              for="profile-current-password"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('profile.currentPassword') }}
            </label>
            <input
              id="profile-current-password"
              v-model="form.current_password"
              type="password"
              autocomplete="current-password"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="profile-current-password"
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
              for="profile-new-password"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('auth.newPassword') }}
            </label>
            <input
              id="profile-new-password"
              v-model="form.password"
              type="password"
              minlength="8"
              maxlength="255"
              autocomplete="new-password"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="profile-new-password"
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
              for="profile-password-confirmation"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('auth.confirmPassword') }}
            </label>
            <input
              id="profile-password-confirmation"
              v-model="form.password_confirmation"
              type="password"
              minlength="8"
              maxlength="255"
              autocomplete="new-password"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="profile-password-confirmation"
            >
            <p
              v-if="errors.password_confirmation"
              class="mt-1 text-sm text-red-600"
            >
              {{ errors.password_confirmation }}
            </p>
          </div>
        </div>
      </div>

      <p
        v-if="errors.form"
        class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        role="alert"
        data-test="profile-error"
      >
        {{ errors.form }}
      </p>

      <button
        type="submit"
        class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
        :disabled="saving"
        data-test="profile-save"
        @click="submit"
      >
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
    </form>

    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
      <h2 class="text-base font-semibold">
        {{ t('profile.language') }}
      </h2>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-lg border px-3 py-1.5 text-sm font-medium"
          :class="locale === 'en' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
          data-test="profile-language-en"
          @click="changeLocale('en')"
        >
          {{ t('language.en') }}
        </button>
        <button
          type="button"
          class="rounded-lg border px-3 py-1.5 text-sm font-medium"
          :class="locale === 'pt-BR' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
          data-test="profile-language-pt"
          @click="changeLocale('pt-BR')"
        >
          {{ t('language.ptBR') }}
        </button>
      </div>

      <button
        type="button"
        class="mt-5 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
        data-test="profile-sign-out"
        @click="handleSignOut"
      >
        {{ t('auth.signOut') }}
      </button>
    </div>
  </section>
</template>
