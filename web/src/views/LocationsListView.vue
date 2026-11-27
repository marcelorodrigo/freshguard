<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import {
  ApiError,
  bulkDeleteLocations,
  createLocation,
  deleteLocation,
  listLocations,
  updateLocation,
  type Location,
  type LocationInput,
  type LocationSort,
  type PageMeta,
} from '../api';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { formatDate } from '../format';
import { pushToast } from '../toast';

const { t } = useI18n();

const locations = ref<Location[]>([]);
const allLocations = ref<Location[]>([]);
const meta = ref<PageMeta>({ page: 1, per_page: 10, total: 0, pages: 0 });
const loading = ref(false);
const failure = ref('');
const search = ref('');
const sort = ref<LocationSort>('name');
const dir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const selected = ref<string[]>([]);

const columns = reactive({
  created: false,
  updated: false,
});

const optionsLoading = ref(false);
const optionsError = ref('');

const formOpen = ref(false);
const saving = ref(false);
const editingID = ref<string | null>(null);
const parentSearch = ref('');
const formDialog = ref<HTMLDialogElement | null>(null);

const form = reactive({
  name: '',
  description: '',
  expiration_notify_days: 0,
  parent_id: '',
});

const formErrors = reactive({
  name: '',
  description: '',
  expiration_notify_days: '',
  parent_id: '',
  form: '',
});

type PendingDelete = { kind: 'single'; location: Location } | { kind: 'bulk' };
const pendingDelete = ref<PendingDelete | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | null = null;

const allSelected = computed(
  () =>
    locations.value.length > 0 &&
    locations.value.every((location) => selected.value.includes(location.id)),
);

const pageLabel = computed(
  () => `${meta.value.page} ${t('common.of')} ${meta.value.pages}`,
);

/** The parent search filters the select's options; the selected one stays. */
const parentOptions = computed(() => {
  const term = parentSearch.value.trim().toLowerCase();
  const options = allLocations.value.filter(
    (location) => term === '' || location.name.toLowerCase().includes(term),
  );

  if (form.parent_id !== '' && !options.some((location) => location.id === form.parent_id)) {
    const chosen = allLocations.value.find((location) => location.id === form.parent_id);
    if (chosen !== undefined) {
      options.unshift(chosen);
    }
  }

  return options;
});

const deleteTitle = computed(() =>
  pendingDelete.value?.kind === 'bulk'
    ? t('locations.bulkDeleteTitle')
    : t('locations.deleteTitle'),
);

const deleteBody = computed(() =>
  pendingDelete.value?.kind === 'bulk'
    ? t('locations.bulkDeleteModal')
    : t('locations.deleteModal'),
);

async function load(): Promise<void> {
  loading.value = true;
  failure.value = '';

  try {
    const result = await listLocations({
      q: search.value.trim(),
      sort: sort.value,
      dir: dir.value,
      page: page.value,
      per_page: 10,
    });
    locations.value = result.data;
    meta.value = result.meta;
    selected.value = [];
  } catch (error) {
    locations.value = [];
    failure.value = t(errorMessageKey(error));
  } finally {
    loading.value = false;
  }
}

/** The parent select needs every location, not just the visible page. */
async function loadAllLocations(): Promise<void> {
  optionsLoading.value = true;
  optionsError.value = '';

  try {
    const first = await listLocations({ per_page: 100 });
    const pages = [first];

    for (let next = 2; next <= first.meta.pages; next += 1) {
      pages.push(await listLocations({ per_page: 100, page: next }));
    }

    allLocations.value = pages.flatMap((result) => result.data);
  } catch (error) {
    allLocations.value = [];
    optionsError.value = t(errorMessageKey(error));
  } finally {
    optionsLoading.value = false;
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
  void loadAllLocations();
});

onBeforeUnmount(() => {
  if (searchTimer !== null) {
    clearTimeout(searchTimer);
  }
});

function sortBy(field: LocationSort): void {
  if (sort.value === field) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sort.value = field;
    dir.value = 'asc';
  }
  page.value = 1;
  void load();
}

function sortMark(field: LocationSort): string {
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

function toggleAll(): void {
  selected.value = allSelected.value
    ? []
    : locations.value.map((location) => location.id);
}

function toggleOne(id: string): void {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((selectedID) => selectedID !== id)
    : [...selected.value, id];
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
  formErrors.description = '';
  formErrors.expiration_notify_days = '';
  formErrors.parent_id = '';
  formErrors.form = '';
}

function openCreate(): void {
  if (saving.value) {
    return;
  }
  resetFormErrors();
  editingID.value = null;
  form.name = '';
  form.description = '';
  form.expiration_notify_days = 0;
  form.parent_id = '';
  parentSearch.value = '';
  formOpen.value = true;
}

function openEdit(location: Location): void {
  if (saving.value) {
    return;
  }
  resetFormErrors();
  editingID.value = location.id;
  form.name = location.name;
  form.description = location.description;
  form.expiration_notify_days = location.expiration_notify_days;
  form.parent_id = location.parent?.id ?? '';
  parentSearch.value = '';
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

/** Walks the candidate's ancestors; the edited location must not appear. */
function isSelfOrDescendant(candidateID: string, locationID: string): boolean {
  const parents = new Map(
    allLocations.value.map((location) => [location.id, location.parent?.id ?? '']),
  );
  const seen = new Set<string>();

  for (let current = candidateID; current !== ''; ) {
    if (current === locationID || seen.has(current)) {
      return true;
    }
    seen.add(current);
    current = parents.get(current) ?? '';
  }

  return false;
}

function validateForm(): boolean {
  const name = form.name.trim();
  const description = form.description.trim();
  const days = Number(form.expiration_notify_days);

  if (name === '') {
    formErrors.name = t('errors.required');
  } else if (name.length > 255) {
    formErrors.name = t('errors.max');
  } else {
    formErrors.name = '';
  }

  formErrors.description = description.length > 255 ? t('errors.max') : '';

  if (!Number.isInteger(days)) {
    formErrors.expiration_notify_days = t('errors.integer');
  } else if (days < 0) {
    formErrors.expiration_notify_days = t('errors.min');
  } else {
    formErrors.expiration_notify_days = '';
  }

  if (form.parent_id === '') {
    formErrors.parent_id = '';
  } else if (
    editingID.value !== null &&
    isSelfOrDescendant(form.parent_id, editingID.value)
  ) {
    formErrors.parent_id = t('errors.cycle');
  } else if (!allLocations.value.some((location) => location.id === form.parent_id)) {
    formErrors.parent_id = t('errors.exists');
  } else {
    formErrors.parent_id = '';
  }

  formErrors.form = '';

  return (
    formErrors.name === '' &&
    formErrors.description === '' &&
    formErrors.expiration_notify_days === '' &&
    formErrors.parent_id === ''
  );
}

async function submit(): Promise<void> {
  if (saving.value) {
    return;
  }
  if (!validateForm()) {
    return;
  }

  const input: LocationInput = {
    name: form.name.trim(),
    description: form.description.trim(),
    expiration_notify_days: Number(form.expiration_notify_days),
    parent_id: form.parent_id === '' ? null : form.parent_id,
  };
  const editing = editingID.value;

  saving.value = true;
  try {
    if (editing === null) {
      await createLocation(input);
      pushToast({ level: 'success', title: t('locations.created') });
    } else {
      await updateLocation(editing, input);
      pushToast({ level: 'success', title: t('locations.updated') });
    }
  } catch (error) {
    if (error instanceof ApiError) {
      for (const [field, rule] of Object.entries(error.fields)) {
        if (field in formErrors) {
          formErrors[field as keyof typeof formErrors] = t(fieldErrorKey(rule));
        }
      }
    }
    formErrors.form = t(errorMessageKey(error));
    return;
  } finally {
    saving.value = false;
  }

  formOpen.value = false;
  editingID.value = null;
  await Promise.all([load(), loadAllLocations()]);
}

function askDelete(location: Location): void {
  if (location.batch_count > 0) {
    return;
  }
  pendingDelete.value = { kind: 'single', location };
}

function askBulkDelete(): void {
  pendingDelete.value = { kind: 'bulk' };
}

async function confirmDelete(): Promise<void> {
  const pending = pendingDelete.value;
  if (pending === null) {
    return;
  }
  pendingDelete.value = null;

  const singles = pending.kind === 'single';
  try {
    if (pending.kind === 'single') {
      await deleteLocation(pending.location.id);
      if (locations.value.length === 1 && page.value > 1) {
        page.value -= 1;
      }
      pushToast({ level: 'success', title: t('locations.deleted') });
    } else {
      const ids = [...selected.value];
      await bulkDeleteLocations(ids);
      if (ids.length === locations.value.length && page.value > 1) {
        page.value -= 1;
      }
      pushToast({ level: 'success', title: t('locations.bulkDeleted') });
    }
  } catch (error) {
    if (error instanceof ApiError && error.code === 'location_has_batches') {
      pushToast({
        level: 'error',
        title: t(singles ? 'locations.deleteBlockedTitle' : 'locations.bulkBlockedTitle'),
        body: t(singles ? 'locations.deleteBlockedBody' : 'locations.bulkBlockedBody'),
      });
      await Promise.all([load(), loadAllLocations()]);
      return;
    }

    pushToast({ level: 'error', title: t(errorMessageKey(error)) });
    return;
  }

  await Promise.all([load(), loadAllLocations()]);
}
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold tracking-tight">
        {{ t('locations.title') }}
      </h1>
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
        data-test="new-location"
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
        {{ t('locations.new') }}
      </button>
    </div>

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <label
        for="locations-search"
        class="sr-only"
      >
        {{ t('locations.searchPlaceholder') }}
      </label>
      <input
        id="locations-search"
        v-model="search"
        type="search"
        :placeholder="t('locations.searchPlaceholder')"
        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:max-w-sm"
        data-test="locations-search"
      >

      <div class="flex items-center gap-2">
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
                data-test="location-column-created"
              >
              {{ t('common.created') }}
            </label>
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
              <input
                v-model="columns.updated"
                type="checkbox"
                class="rounded border-slate-300"
                data-test="location-column-updated"
              >
              {{ t('common.updated') }}
            </label>
          </div>
        </details>

        <button
          v-if="selected.length > 0"
          type="button"
          class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700"
          data-test="locations-bulk-delete"
          @click="askBulkDelete"
        >
          {{ t('locations.bulkDelete') }} ({{ selected.length }})
        </button>
      </div>
    </div>

    <p
      v-if="failure"
      class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
      data-test="locations-error"
    >
      {{ failure }}
    </p>

    <div
      v-if="!loading && locations.length > 0"
      class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white"
    >
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="w-10 px-3 py-3 text-left">
              <label
                for="select-all-locations"
                class="sr-only"
              >
                {{ t('locations.selectAll') }}
              </label>
              <input
                id="select-all-locations"
                type="checkbox"
                class="rounded border-slate-300"
                :checked="allSelected"
                data-test="locations-select-all"
                @change="toggleAll"
              >
            </th>
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
              {{ t('common.description') }}
            </th>
            <th class="px-3 py-3 text-left font-semibold text-slate-600">
              {{ t('common.parent') }}
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
                {{ t('common.created') }} {{ sortMark('created_at') }}
              </button>
            </th>
            <th
              v-if="columns.updated"
              class="px-3 py-3 text-left font-semibold text-slate-600"
            >
              <button
                type="button"
                class="inline-flex items-center gap-1"
                data-test="sort-updated_at"
                @click="sortBy('updated_at')"
              >
                {{ t('common.updated') }} {{ sortMark('updated_at') }}
              </button>
            </th>
            <th class="px-3 py-3 text-right font-semibold text-slate-600">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="location in locations"
            :key="location.id"
            class="hover:bg-slate-50"
            data-test="location-row"
            :data-location-id="location.id"
          >
            <td class="px-3 py-3">
              <input
                type="checkbox"
                class="rounded border-slate-300"
                :checked="selected.includes(location.id)"
                :aria-label="location.name"
                data-test="location-select"
                @change="toggleOne(location.id)"
              >
            </td>
            <td class="px-3 py-3 font-medium">
              <button
                type="button"
                class="text-left text-emerald-700 hover:underline"
                data-test="location-name"
                @click="openEdit(location)"
              >
                {{ location.name }}
              </button>
            </td>
            <td
              class="max-w-64 truncate px-3 py-3 text-slate-600"
              data-test="location-description"
            >
              {{ location.description }}
            </td>
            <td
              class="px-3 py-3 text-slate-600"
              data-test="location-parent"
            >
              {{ location.parent?.name ?? '' }}
            </td>
            <td
              v-if="columns.created"
              class="px-3 py-3 whitespace-nowrap"
              data-test="location-created"
            >
              {{ formatDate(location.created_at) }}
            </td>
            <td
              v-if="columns.updated"
              class="px-3 py-3 whitespace-nowrap"
              data-test="location-updated"
            >
              {{ formatDate(location.updated_at) }}
            </td>
            <td class="px-3 py-3">
              <div class="flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100"
                  data-test="location-edit"
                  @click="openEdit(location)"
                >
                  {{ t('common.edit') }}
                </button>
                <span
                  v-if="location.batch_count > 0"
                  :title="t('locations.deleteDisabledTooltip')"
                  data-test="location-delete-disabled"
                >
                  <button
                    type="button"
                    class="rounded px-2 py-1 text-xs font-medium text-red-700 opacity-40"
                    disabled
                    data-test="location-delete"
                  >
                    {{ t('common.delete') }}
                  </button>
                </span>
                <button
                  v-else
                  type="button"
                  class="rounded px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                  data-test="location-delete"
                  @click="askDelete(location)"
                >
                  {{ t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p
      v-if="loading"
      class="mt-4 text-sm text-slate-500"
    >
      {{ t('common.loading') }}
    </p>

    <div
      v-else-if="failure === '' && locations.length === 0"
      class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"
      data-test="locations-empty"
    >
      <template v-if="search.trim() !== ''">
        <p class="font-medium text-slate-700">
          {{ t('locations.noMatches') }}
        </p>
      </template>
      <template v-else>
        <p class="font-medium text-slate-700">
          {{ t('locations.emptyTitle') }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
          {{ t('locations.emptyBody') }}
        </p>
        <button
          type="button"
          class="mt-4 inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
          @click="openCreate"
        >
          {{ t('locations.new') }}
        </button>
      </template>
    </div>

    <div
      v-if="!loading && meta.total > 0"
      class="mt-4 flex items-center justify-between text-sm text-slate-600"
    >
      <p>{{ pageLabel }}</p>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-40"
          :disabled="meta.page <= 1"
          @click="goTo(meta.page - 1)"
        >
          {{ t('common.previous') }}
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-40"
          :disabled="meta.pages === 0 || meta.page >= meta.pages"
          @click="goTo(meta.page + 1)"
        >
          {{ t('common.next') }}
        </button>
      </div>
    </div>

    <dialog
      ref="formDialog"
      class="w-[min(36rem,calc(100vw-2rem))] rounded-2xl p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40"
      data-test="location-form"
      @close="handleFormClose"
      @cancel.prevent="closeForm"
      @keydown.esc.prevent="closeForm"
      @click.self="closeForm"
    >
      <form
        class="p-6"
        @submit.prevent="submit"
        @keydown.enter.prevent="submit"
      >
        <h2
          class="text-lg font-semibold"
          data-test="location-form-title"
        >
          {{ editingID === null ? t('locations.createTitle') : t('locations.editTitle') }}
        </h2>

        <div class="mt-4 space-y-5">
          <div>
            <h3 class="text-sm font-semibold text-slate-700">
              {{ t('locations.details') }}
            </h3>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
              <div>
                <label
                  for="location-name"
                  class="block text-sm font-medium text-slate-700"
                >
                  {{ t('common.name') }} <span class="text-red-600">*</span>
                </label>
                <input
                  id="location-name"
                  v-model="form.name"
                  type="text"
                  required
                  maxlength="255"
                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                  data-test="location-name-input"
                  @input="formErrors.name = ''"
                >
                <p
                  v-if="formErrors.name"
                  class="mt-1 text-sm text-red-600"
                  data-test="location-name-error"
                >
                  {{ formErrors.name }}
                </p>
              </div>

              <div>
                <label
                  for="location-description"
                  class="block text-sm font-medium text-slate-700"
                >
                  {{ t('common.description') }}
                </label>
                <input
                  id="location-description"
                  v-model="form.description"
                  type="text"
                  maxlength="255"
                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                  data-test="location-description-input"
                  @input="formErrors.description = ''"
                >
                <p
                  v-if="formErrors.description"
                  class="mt-1 text-sm text-red-600"
                  data-test="location-description-error"
                >
                  {{ formErrors.description }}
                </p>
              </div>
            </div>
          </div>

          <div>
            <h3 class="text-sm font-semibold text-slate-700">
              {{ t('locations.settings') }}
            </h3>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
              <div>
                <label
                  for="location-days"
                  class="block text-sm font-medium text-slate-700"
                >
                  {{ t('locations.notifyDays') }}
                </label>
                <div class="mt-1 flex items-center gap-2">
                  <input
                    id="location-days"
                    v-model.number="form.expiration_notify_days"
                    type="number"
                    min="0"
                    step="1"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                    data-test="location-days-input"
                    @input="formErrors.expiration_notify_days = ''"
                  >
                  <span class="text-sm text-slate-500">{{ t('locations.days') }}</span>
                </div>
                <p
                  v-if="formErrors.expiration_notify_days"
                  class="mt-1 text-sm text-red-600"
                  data-test="location-days-error"
                >
                  {{ formErrors.expiration_notify_days }}
                </p>
              </div>

              <div>
                <label
                  for="location-parent"
                  class="block text-sm font-medium text-slate-700"
                >
                  {{ t('locations.parent') }}
                </label>
                <input
                  v-model="parentSearch"
                  type="search"
                  :placeholder="t('locations.searchParent')"
                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                  data-test="location-parent-search"
                >
                <select
                  id="location-parent"
                  v-model="form.parent_id"
                  class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                  data-test="location-parent-select"
                  @change="formErrors.parent_id = ''"
                >
                  <option value="">
                    {{ t('locations.selectParent') }}
                  </option>
                  <option
                    v-for="option in parentOptions"
                    :key="option.id"
                    :value="option.id"
                  >
                    {{ option.name }}
                  </option>
                </select>
                <p
                  v-if="optionsLoading"
                  class="mt-1 text-xs text-slate-500"
                  data-test="locations-options-loading"
                >
                  {{ t('common.loading') }}
                </p>
                <p
                  v-else-if="optionsError"
                  class="mt-1 flex flex-wrap items-center gap-2 text-xs text-red-700"
                  data-test="locations-options-error"
                >
                  {{ optionsError }}
                  <button
                    type="button"
                    class="font-semibold underline"
                    @click="loadAllLocations"
                  >
                    {{ t('common.retry') }}
                  </button>
                </p>
                <p
                  v-if="formErrors.parent_id"
                  class="mt-1 text-sm text-red-600"
                  data-test="location-parent-error"
                >
                  {{ formErrors.parent_id }}
                </p>
              </div>
            </div>
          </div>

          <p
            v-if="formErrors.form"
            class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
            role="alert"
            data-test="location-form-error"
          >
            {{ formErrors.form }}
          </p>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            :disabled="saving"
            data-test="location-cancel"
            @click="closeForm"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
            :disabled="saving || optionsLoading"
            data-test="location-save"
            @click="submit"
          >
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </dialog>

    <ConfirmDialog
      :open="pendingDelete !== null"
      :title="deleteTitle"
      :body="deleteBody"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
    />
  </section>
</template>
