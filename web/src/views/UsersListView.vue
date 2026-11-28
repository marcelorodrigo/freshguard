<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import {
  ApiError,
  createUser,
  deleteUser,
  listUsers,
  updateUser,
  type PageMeta,
  type User,
  type UserSort,
} from '../api';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { formatDateTime } from '../format';
import { session } from '../session';
import { pushToast } from '../toast';

const { t } = useI18n();

const users = ref<User[]>([]);
const meta = ref<PageMeta>({ page: 1, per_page: 10, total: 0, pages: 0 });
const loading = ref(false);
const failure = ref('');
const search = ref('');
const sort = ref<UserSort>('name');
const dir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const columns = reactive({ created: false, updated: false });

const formOpen = ref(false);
const saving = ref(false);
const editingID = ref<string | null>(null);
const formDialog = ref<HTMLDialogElement | null>(null);
const pendingDelete = ref<User | null>(null);

const form = reactive({ name: '', email: '', password: '', is_admin: false });
const formErrors = reactive({ name: '', email: '', password: '', form: '' });

let searchTimer: ReturnType<typeof setTimeout> | null = null;

const currentUserID = computed(() => session.user?.id ?? '');
const editingSelf = computed(
  () => editingID.value !== null && editingID.value === currentUserID.value,
);
const formTitle = computed(() =>
  editingID.value === null ? t('users.createTitle') : t('users.editTitle'),
);
const pageLabel = computed(
  () => `${meta.value.page} ${t('common.of')} ${meta.value.pages}`,
);

async function load(): Promise<void> {
  loading.value = true;
  failure.value = '';

  try {
    const result = await listUsers({
      q: search.value.trim(),
      sort: sort.value,
      dir: dir.value,
      page: page.value,
      per_page: 10,
    });
    users.value = result.data;
    meta.value = result.meta;
  } catch (error) {
    users.value = [];
    failure.value = t(errorMessageKey(error));
  } finally {
    loading.value = false;
  }
}

watch(search, () => {
  if (searchTimer !== null) {
    clearTimeout(searchTimer);
  }
  searchTimer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});

onMounted(() => {
  syncFormDialog(formOpen.value);
  void load();
});

onBeforeUnmount(() => {
  if (searchTimer !== null) {
    clearTimeout(searchTimer);
  }
});

function sortBy(field: UserSort): void {
  if (sort.value === field) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sort.value = field;
    dir.value = 'asc';
  }
  page.value = 1;
  void load();
}

function sortMark(field: UserSort): string {
  if (sort.value !== field) {
    return '';
  }
  return dir.value === 'asc' ? '↑' : '↓';
}

function goTo(next: number): void {
  if (next < 1 || (meta.value.pages > 0 && next > meta.value.pages)) {
    return;
  }
  page.value = next;
  void load();
}

function syncFormDialog(open: boolean): void {
  const element = formDialog.value;
  if (element === null) {
    return;
  }

  if (open) {
    if (!element.open && typeof element.showModal === 'function') {
      element.showModal();
    } else if (!element.open) {
      element.setAttribute('open', '');
    }
    return;
  }

  if (element.open && typeof element.close === 'function') {
    element.close();
  } else {
    element.removeAttribute('open');
  }
}

watch(formOpen, syncFormDialog);

function resetFormErrors(): void {
  formErrors.name = '';
  formErrors.email = '';
  formErrors.password = '';
  formErrors.form = '';
}

function openCreate(): void {
  if (saving.value) {
    return;
  }
  resetFormErrors();
  editingID.value = null;
  form.name = '';
  form.email = '';
  form.password = '';
  form.is_admin = false;
  formOpen.value = true;
}

function openEdit(user: User): void {
  if (saving.value) {
    return;
  }
  resetFormErrors();
  editingID.value = user.id;
  form.name = user.name;
  form.email = user.email;
  form.password = '';
  form.is_admin = user.is_admin;
  formOpen.value = true;
}

function closeForm(): void {
  if (saving.value) {
    return;
  }
  formOpen.value = false;
  editingID.value = null;
  resetFormErrors();
}

function handleFormClose(): void {
  if (formOpen.value) {
    closeForm();
  }
}

function validateName(name: string): string {
  if (name === '') {
    return t('errors.required');
  }
  return name.length > 255 ? t('errors.max') : '';
}

function validateEmail(email: string): string {
  if (email === '') {
    return t('errors.required');
  }
  if (email.length > 255) {
    return t('errors.max');
  }
  return /^[^\s@]+@[^\s@]+$/.test(email) ? '' : t('errors.email');
}

function validatePassword(password: string): string {
  if (password === '') {
    return editingID.value === null ? t('errors.required') : '';
  }
  if (password.length < 8) {
    return t('errors.min');
  }
  return password.length > 255 ? t('errors.max') : '';
}

function validateForm(): boolean {
  formErrors.name = validateName(form.name.trim());
  formErrors.email = validateEmail(form.email.trim());
  formErrors.password = validatePassword(form.password);
  formErrors.form = '';

  return (
    formErrors.name === '' && formErrors.email === '' && formErrors.password === ''
  );
}

function applyFieldErrors(error: ApiError): void {
  for (const [field, rule] of Object.entries(error.fields)) {
    if (field in formErrors) {
      formErrors[field as keyof typeof formErrors] = t(fieldErrorKey(rule));
    }
  }
}

async function saveNewUser(): Promise<void> {
  await createUser({
    name: form.name.trim(),
    email: form.email.trim(),
    password: form.password,
    is_admin: form.is_admin,
  });
  pushToast({ level: 'success', title: t('users.created') });
}

async function saveEditedUser(id: string): Promise<void> {
  const patch = {
    name: form.name.trim(),
    email: form.email.trim(),
    is_admin: form.is_admin,
    ...(form.password === '' ? {} : { password: form.password }),
  };
  const updated = await updateUser(id, patch);
  if (id === currentUserID.value) {
    session.user = updated;
  }
  pushToast({ level: 'success', title: t('users.updated') });
}

async function submit(): Promise<void> {
  if (saving.value || !validateForm()) {
    return;
  }

  const editing = editingID.value;
  saving.value = true;
  try {
    if (editing === null) {
      await saveNewUser();
    } else {
      await saveEditedUser(editing);
    }
  } catch (error) {
    if (error instanceof ApiError) {
      applyFieldErrors(error);
    }
    formErrors.form = t(errorMessageKey(error));
    return;
  } finally {
    saving.value = false;
  }

  formOpen.value = false;
  editingID.value = null;
  await load();
}

function canDelete(user: User): boolean {
  return user.id !== currentUserID.value;
}

function askDelete(user: User): void {
  if (canDelete(user)) {
    pendingDelete.value = user;
  }
}

function reportDeleteFailure(error: unknown): void {
  if (error instanceof ApiError && error.code === 'cannot_delete_self') {
    pushToast({
      level: 'error',
      title: t('users.deleteBlockedTitle'),
      body: t('users.selfDeleteBody'),
    });
    return;
  }
  if (error instanceof ApiError && error.code === 'last_admin') {
    pushToast({
      level: 'error',
      title: t('users.deleteBlockedTitle'),
      body: t('users.lastAdminBody'),
    });
    return;
  }
  pushToast({ level: 'error', title: t(errorMessageKey(error)) });
}

async function confirmDelete(): Promise<void> {
  const pending = pendingDelete.value;
  if (pending === null) {
    return;
  }
  pendingDelete.value = null;

  try {
    await deleteUser(pending.id);
    if (users.value.length === 1 && page.value > 1) {
      page.value -= 1;
    }
    pushToast({ level: 'success', title: t('users.deleted') });
  } catch (error) {
    reportDeleteFailure(error);
  }

  await load();
}
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold tracking-tight">
        {{ t('users.title') }}
      </h1>
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
        data-test="new-user"
        @click="openCreate"
      >
        <svg
          class="size-4"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"
          />
        </svg>
        {{ t('users.new') }}
      </button>
    </div>

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <label
        for="users-search"
        class="sr-only"
      >
        {{ t('users.searchPlaceholder') }}
      </label>
      <input
        id="users-search"
        v-model="search"
        type="search"
        :placeholder="t('users.searchPlaceholder')"
        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:max-w-sm"
        data-test="users-search"
      >

      <details class="relative">
        <summary
          class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          {{ t('common.columns') }}
        </summary>
        <div
          class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-2 shadow-lg"
        >
          <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
            <input
              v-model="columns.created"
              type="checkbox"
              class="rounded border-slate-300"
              data-test="user-column-created"
            >
            {{ t('common.created') }}
          </label>
          <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
            <input
              v-model="columns.updated"
              type="checkbox"
              class="rounded border-slate-300"
              data-test="user-column-updated"
            >
            {{ t('common.updated') }}
          </label>
        </div>
      </details>
    </div>

    <p
      v-if="failure"
      class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
      data-test="users-error"
    >
      {{ failure }}
    </p>

    <p
      v-if="loading"
      class="mt-4 text-sm text-slate-500"
    >
      {{ t('common.loading') }}
    </p>

    <div
      v-if="!loading && users.length > 0"
      class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white"
    >
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                data-test="sort-name"
                @click="sortBy('name')"
              >
                {{ t('common.name') }} {{ sortMark('name') }}
              </button>
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                data-test="sort-email"
                @click="sortBy('email')"
              >
                {{ t('common.email') }} {{ sortMark('email') }}
              </button>
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              <button
                type="button"
                class="inline-flex items-center gap-1"
                data-test="sort-is_admin"
                @click="sortBy('is_admin')"
              >
                {{ t('common.admin') }} {{ sortMark('is_admin') }}
              </button>
            </th>
            <th
              v-if="columns.created"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              <button
                type="button"
                class="inline-flex items-center gap-1"
                data-test="sort-created_at"
                @click="sortBy('created_at')"
              >
                {{ t('common.createdAt') }} {{ sortMark('created_at') }}
              </button>
            </th>
            <th
              v-if="columns.updated"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              {{ t('common.updatedAt') }}
            </th>
            <th class="px-3 py-3 text-right font-semibold text-slate-600">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="user in users"
            :key="user.id"
            :data-user-id="user.id"
            data-test="user-row"
          >
            <td
              class="px-3 py-3 font-medium text-slate-900"
              data-test="user-name"
            >
              {{ user.name }}
            </td>
            <td
              class="px-3 py-3 text-slate-600"
              data-test="user-email"
            >
              {{ user.email }}
            </td>
            <td class="px-3 py-3">
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                :class="user.is_admin ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                data-test="user-admin"
              >
                {{ user.is_admin ? t('common.admin') : t('users.member') }}
              </span>
            </td>
            <td
              v-if="columns.created"
              class="px-3 py-3 text-slate-600"
              data-test="user-created"
            >
              {{ formatDateTime(user.created_at) }}
            </td>
            <td
              v-if="columns.updated"
              class="px-3 py-3 text-slate-600"
              data-test="user-updated"
            >
              {{ formatDateTime(user.updated_at) }}
            </td>
            <td class="px-3 py-3">
              <div class="flex items-center justify-end gap-2">
                <button
                  type="button"
                  class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  data-test="user-edit"
                  @click="openEdit(user)"
                >
                  {{ t('common.edit') }}
                </button>
                <button
                  v-if="canDelete(user)"
                  type="button"
                  class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50"
                  data-test="user-delete"
                  @click="askDelete(user)"
                >
                  {{ t('common.delete') }}
                </button>
                <button
                  v-else
                  type="button"
                  class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-400"
                  :title="t('users.selfDeleteTooltip')"
                  :aria-label="t('users.selfDeleteTooltip')"
                  disabled
                  data-test="user-delete-disabled"
                >
                  {{ t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="!loading && users.length === 0"
      class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center"
      data-test="users-empty"
    >
      <p class="text-base font-semibold">
        {{ search.trim() === '' ? t('users.emptyTitle') : t('users.noMatches') }}
      </p>
      <p
        v-if="search.trim() === ''"
        class="mt-1 text-sm text-slate-500"
      >
        {{ t('users.emptyBody') }}
      </p>
    </div>

    <div
      v-if="meta.pages > 1"
      class="mt-4 flex items-center justify-between text-sm text-slate-600"
    >
      <button
        type="button"
        class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
        :disabled="meta.page <= 1"
        data-test="users-previous"
        @click="goTo(meta.page - 1)"
      >
        {{ t('common.previous') }}
      </button>
      <span data-test="users-page">{{ pageLabel }}</span>
      <button
        type="button"
        class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
        :disabled="meta.page >= meta.pages"
        data-test="users-next"
        @click="goTo(meta.page + 1)"
      >
        {{ t('common.next') }}
      </button>
    </div>

    <dialog
      ref="formDialog"
      class="w-[min(32rem,calc(100vw-2rem))] rounded-2xl p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40"
      data-test="user-form"
      @close="handleFormClose"
      @cancel.prevent="handleFormClose"
      @keydown.esc.prevent="handleFormClose"
      @click.self="handleFormClose"
    >
      <form
        class="p-6"
        @submit.prevent="submit"
        @keydown.enter.prevent="submit"
      >
        <h2
          class="text-lg font-semibold"
          data-test="user-form-title"
        >
          {{ formTitle }}
        </h2>

        <div class="mt-4 space-y-4">
          <div>
            <label
              for="user-name"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.name') }}
            </label>
            <input
              id="user-name"
              v-model="form.name"
              type="text"
              required
              maxlength="255"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="user-name-input"
            >
            <p
              v-if="formErrors.name"
              class="mt-1 text-sm text-red-600"
            >
              {{ formErrors.name }}
            </p>
          </div>

          <div>
            <label
              for="user-email"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.email') }}
            </label>
            <input
              id="user-email"
              v-model="form.email"
              type="email"
              required
              maxlength="255"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="user-email-input"
            >
            <p
              v-if="formErrors.email"
              class="mt-1 text-sm text-red-600"
            >
              {{ formErrors.email }}
            </p>
          </div>

          <div>
            <label
              for="user-password"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('users.password') }}
            </label>
            <input
              id="user-password"
              v-model="form.password"
              type="password"
              :required="editingID === null"
              minlength="8"
              maxlength="255"
              autocomplete="new-password"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="user-password-input"
            >
            <p
              v-if="formErrors.password"
              class="mt-1 text-sm text-red-600"
            >
              {{ formErrors.password }}
            </p>
            <p
              v-else-if="editingID !== null"
              class="mt-1 text-xs text-slate-500"
            >
              {{ t('users.passwordKeep') }}
            </p>
            <p
              v-if="editingID !== null && !editingSelf"
              class="mt-1 text-xs text-slate-500"
            >
              {{ t('users.temporaryPasswordHelp') }}
            </p>
          </div>

          <div>
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input
                v-model="form.is_admin"
                type="checkbox"
                class="rounded border-slate-300"
                :disabled="editingSelf"
                data-test="user-admin-input"
              >
              {{ t('users.administrator') }}
            </label>
            <p class="mt-1 text-xs text-slate-500">
              {{ t('users.administratorHelp') }}
            </p>
          </div>
        </div>

        <p
          v-if="formErrors.form"
          class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
          role="alert"
          data-test="user-form-error"
        >
          {{ formErrors.form }}
        </p>

        <div class="mt-6 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="closeForm"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
            :disabled="saving"
            data-test="user-save"
            @click="submit"
          >
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </dialog>

    <ConfirmDialog
      :open="pendingDelete !== null"
      :title="t('users.deleteTitle')"
      :body="t('users.deleteConfirm')"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
    />
  </section>
</template>
