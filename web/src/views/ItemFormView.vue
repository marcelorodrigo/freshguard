<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import {
  ApiError,
  createBatch,
  createItem,
  deleteBatch,
  getItem,
  listLocations,
  listTags,
  updateBatch,
  updateItem,
  type Batch,
  type BatchInput,
  type ItemDetail,
  type ItemInput,
  type Location,
  type Tag,
} from '../api';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import TagInput from '../components/TagInput.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
import { formatDate, formatDateTime } from '../format';
import { pushToast } from '../toast';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isCreate = computed(() => route.name === 'item-new');
const item = ref<ItemDetail | null>(null);
const suggestions = ref<Tag[]>([]);
const loading = ref(true);
const saving = ref(false);
const failure = ref('');

const form = reactive({
  name: '',
  barcode: '',
  description: '',
  tags: [] as string[],
});

const errors = reactive({
  name: '',
  barcode: '',
  description: '',
  form: '',
});

const quantity = computed(() => item.value?.quantity ?? 0);

const locations = ref<Location[]>([]);
const locationsLoading = ref(false);
const locationsError = ref('');
const showCreatedAt = ref(false);
const batchFormOpen = ref(false);
const editingBatchID = ref<string | null>(null);
const batchSaving = ref(false);
const pendingBatchDelete = ref<Batch | null>(null);
const refreshError = ref('');

const batchForm = reactive({
  location_id: '',
  expires_at: '',
  quantity: 1,
});

const batchErrors = reactive({
  location_id: '',
  expires_at: '',
  quantity: '',
  form: '',
});

/** Batches always read in expiry order, matching the old relation manager. */
const batches = computed(() =>
  [...(item.value?.batches ?? [])].sort(
    (left, right) =>
      left.expires_at.localeCompare(right.expires_at) ||
      left.id.localeCompare(right.id),
  ),
);

function toISODate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/** The old date picker defaulted to a week from today. */
function defaultExpiresAt(): string {
  const date = new Date();
  date.setDate(date.getDate() + 7);
  return toISODate(date);
}

async function loadLocations(): Promise<void> {
  locationsLoading.value = true;
  locationsError.value = '';

  try {
    const first = await listLocations({ per_page: 100 });
    const pages = [first];

    for (let page = 2; page <= first.meta.pages; page += 1) {
      pages.push(await listLocations({ per_page: 100, page }));
    }

    locations.value = pages.flatMap((result) => result.data);
  } catch (error) {
    locations.value = [];
    locationsError.value = t(errorMessageKey(error));
  } finally {
    locationsLoading.value = false;
  }
}

async function loadSuggestions(): Promise<void> {
  try {
    suggestions.value = await listTags();
  } catch {
    // Suggestions are a convenience; the tag input still accepts typed tags.
  }
}

async function load(): Promise<void> {
  loading.value = true;
  failure.value = '';
  errors.form = '';
  batchFormOpen.value = false;
  editingBatchID.value = null;
  pendingBatchDelete.value = null;

  try {
    if (isCreate.value) {
      item.value = null;
      form.name = '';
      form.barcode = '';
      form.description = '';
      form.tags = [];
    } else {
      const detail = await getItem(String(route.params.id ?? ''));
      item.value = detail;
      form.name = detail.name;
      form.barcode = detail.barcode;
      form.description = detail.description;
      form.tags = [...detail.tags];
      void loadLocations();
    }
    void loadSuggestions();
  } catch (error) {
    failure.value = t(errorMessageKey(error));
  } finally {
    loading.value = false;
  }
}

watch(() => route.fullPath, () => {
  void load();
}, { immediate: true });

function validate(): boolean {
  const name = form.name.trim();
  const barcode = form.barcode.trim();
  const description = form.description.trim();

  if (name === '') {
    errors.name = t('errors.required');
  } else if (name.length > 255) {
    errors.name = t('errors.max');
  } else {
    errors.name = '';
  }
  errors.barcode = barcode.length > 255 ? t('errors.max') : '';
  errors.description = description.length > 1000 ? t('errors.max') : '';

  return errors.name === '' && errors.barcode === '' && errors.description === '';
}

async function submit(): Promise<void> {
  errors.form = '';
  if (!validate()) {
    return;
  }

  saving.value = true;
  const input: ItemInput = {
    name: form.name.trim(),
    barcode: form.barcode.trim(),
    description: form.description.trim(),
    tags: [...form.tags],
  };

  try {
    if (isCreate.value) {
      await createItem(input);
      pushToast({ level: 'success', title: t('items.created') });
      await router.push({ name: 'items' });
      return;
    }

    const updated = await updateItem(String(route.params.id ?? ''), input);
    item.value = updated;
    form.name = updated.name;
    form.barcode = updated.barcode;
    form.description = updated.description;
    form.tags = [...updated.tags];
    pushToast({ level: 'success', title: t('items.updated') });
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
    saving.value = false;
  }
}

function resetBatchErrors(): void {
  batchErrors.location_id = '';
  batchErrors.expires_at = '';
  batchErrors.quantity = '';
  batchErrors.form = '';
}

function openCreateBatch(): void {
  if (batchSaving.value) {
    return;
  }
  resetBatchErrors();
  editingBatchID.value = null;
  batchForm.location_id = locations.value[0]?.id ?? '';
  batchForm.expires_at = defaultExpiresAt();
  batchForm.quantity = 1;
  batchFormOpen.value = true;
}

function openEditBatch(batch: Batch): void {
  if (batchSaving.value) {
    return;
  }
  resetBatchErrors();
  editingBatchID.value = batch.id;
  batchForm.location_id = batch.location.id;
  batchForm.expires_at = batch.expires_at;
  batchForm.quantity = batch.quantity;
  batchFormOpen.value = true;
}

function closeBatchForm(): void {
  batchFormOpen.value = false;
  editingBatchID.value = null;
  resetBatchErrors();
}

function cancelBatchForm(): void {
  if (batchSaving.value) {
    return;
  }
  closeBatchForm();
}

function askDeleteBatch(batch: Batch): void {
  if (batchSaving.value) {
    return;
  }
  pendingBatchDelete.value = batch;
}

async function refreshItem(): Promise<void> {
  const id = String(route.params.id ?? '');
  if (id === '') {
    return;
  }

  const detail = await getItem(id);
  if (String(route.params.id ?? '') !== id) {
    return;
  }

  item.value = detail;
  refreshError.value = '';
}

/** A failed refresh leaves a read-only retry path; it never repeats a write. */
async function refreshAfterMutation(): Promise<void> {
  try {
    await refreshItem();
  } catch (error) {
    refreshError.value = t(errorMessageKey(error));
  }
}

/** Drops the deleted row immediately so a failed refresh cannot repeat DELETE. */
function removeBatchLocally(batchID: string): void {
  const detail = item.value;
  if (detail === null) {
    return;
  }

  const remaining = detail.batches.filter((batch) => batch.id !== batchID);
  item.value = {
    ...detail,
    batches: remaining,
    quantity: remaining.reduce((total, batch) => total + batch.quantity, 0),
    batch_count: remaining.length,
    earliest_expiration: remaining.reduce(
      (earliest, batch) =>
        earliest === '' || batch.expires_at < earliest ? batch.expires_at : earliest,
      '',
    ),
  };
}

function validateBatch(): boolean {
  batchErrors.location_id = batchForm.location_id === '' ? t('errors.required') : '';
  batchErrors.expires_at = batchForm.expires_at === '' ? t('errors.required') : '';

  const quantity = Number(batchForm.quantity);
  batchErrors.quantity = !Number.isInteger(quantity) || quantity < 1 ? t('errors.min') : '';

  return batchErrors.location_id === '' &&
    batchErrors.expires_at === '' &&
    batchErrors.quantity === '';
}

async function saveBatch(): Promise<void> {
  if (batchSaving.value) {
    return;
  }
  batchErrors.form = '';
  if (!validateBatch()) {
    return;
  }

  const input: BatchInput = {
    location_id: batchForm.location_id,
    expires_at: batchForm.expires_at,
    quantity: Number(batchForm.quantity),
  };

  const itemID = String(route.params.id ?? '');
  const editingID = editingBatchID.value;

  batchSaving.value = true;
  try {
    if (editingID === null) {
      await createBatch(itemID, input);
      pushToast({ level: 'success', title: t('items.batchCreated') });
    } else {
      await updateBatch(editingID, input);
      pushToast({ level: 'success', title: t('items.batchUpdated') });
    }
  } catch (error) {
    if (error instanceof ApiError) {
      for (const [field, rule] of Object.entries(error.fields)) {
        if (field in batchErrors) {
          batchErrors[field as keyof typeof batchErrors] = t(fieldErrorKey(rule));
        }
      }
    }
    batchErrors.form = t(errorMessageKey(error));
    return;
  } finally {
    batchSaving.value = false;
  }

  if (String(route.params.id ?? '') === itemID) {
    closeBatchForm();
    await refreshAfterMutation();
  }
}

async function confirmDeleteBatch(): Promise<void> {
  const batch = pendingBatchDelete.value;
  pendingBatchDelete.value = null;
  if (batch === null) {
    return;
  }

  try {
    await deleteBatch(batch.id);
  } catch (error) {
    pushToast({ level: 'error', title: t(errorMessageKey(error)) });
    return;
  }

  removeBatchLocally(batch.id);
  pushToast({ level: 'success', title: t('items.batchDeleted') });
  await refreshAfterMutation();
}
</script>

<template>
  <section class="max-w-3xl">
    <RouterLink
      :to="{ name: 'items' }"
      class="text-sm font-medium text-emerald-700 hover:underline"
    >
      {{ t('items.backToItems') }}
    </RouterLink>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight">
      {{ isCreate ? t('items.createTitle') : t('items.editTitle') }}
    </h1>

    <p
      v-if="loading"
      class="mt-6 text-sm text-slate-500"
    >
      {{ t('common.loading') }}
    </p>

    <p
      v-else-if="failure"
      class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
    >
      {{ failure }}
    </p>

    <form
      v-else
      class="mt-6 space-y-6"
      @submit.prevent="submit"
    >
      <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold">
          {{ t('items.basicInformation') }}
        </h2>

        <div class="mt-4 space-y-4">
          <div>
            <label
              for="item-name"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.name') }} <span class="text-red-600">*</span>
            </label>
            <input
              id="item-name"
              v-model="form.name"
              type="text"
              required
              maxlength="255"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              data-test="item-name"
              @input="errors.name = ''"
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
              for="item-barcode"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.barcode') }}
            </label>
            <input
              id="item-barcode"
              v-model="form.barcode"
              type="text"
              maxlength="255"
              inputmode="numeric"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              @input="errors.barcode = ''"
            >
            <p
              v-if="errors.barcode"
              class="mt-1 text-sm text-red-600"
            >
              {{ errors.barcode }}
            </p>
          </div>

          <div>
            <label
              for="item-description"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.description') }}
            </label>
            <textarea
              id="item-description"
              v-model="form.description"
              rows="4"
              maxlength="1000"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              @input="errors.description = ''"
            />
            <p
              v-if="errors.description"
              class="mt-1 text-sm text-red-600"
            >
              {{ errors.description }}
            </p>
          </div>
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold">
          {{ t('items.inventoryDetails') }}
        </h2>

        <div class="mt-4 space-y-4">
          <div v-if="!isCreate">
            <label
              for="item-quantity"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.quantity') }}
            </label>
            <input
              id="item-quantity"
              :value="quantity"
              type="text"
              readonly
              class="mt-1 w-32 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700"
              data-test="item-quantity"
            >
            <p class="mt-1 text-xs text-slate-500">
              {{ t('items.quantityHelp') }}
            </p>
          </div>

          <div>
            <label
              for="item-tags"
              class="block text-sm font-medium text-slate-700"
            >
              {{ t('common.tags') }}
            </label>
            <div class="mt-1">
              <TagInput
                v-model="form.tags"
                :suggestions="suggestions"
                input-id="item-tags"
              />
            </div>
          </div>
        </div>
      </div>

      <p
        v-if="errors.form"
        class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
        role="alert"
        data-test="item-error"
      >
        {{ errors.form }}
      </p>

      <div class="flex items-center justify-end gap-3">
        <RouterLink
          :to="{ name: 'items' }"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          {{ t('common.cancel') }}
        </RouterLink>
        <button
          type="submit"
          class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
          :disabled="saving"
          data-test="item-save"
        >
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>

    <section
      v-if="!isCreate && !loading && !failure"
      class="mt-6 rounded-xl border border-slate-200 bg-white p-6"
      data-test="batches-section"
    >
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">
          {{ t('items.batches') }}
        </h2>

        <div class="flex items-center gap-2">
          <details class="relative">
            <summary
              class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              {{ t('common.columns') }}
            </summary>
            <div class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-2 shadow-lg">
              <label class="flex items-center gap-2 px-3 py-1.5 text-sm">
                <input
                  v-model="showCreatedAt"
                  type="checkbox"
                  class="rounded border-slate-300"
                  data-test="batch-created-toggle"
                >
                {{ t('common.createdAt') }}
              </label>
            </div>
          </details>

          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
            :disabled="batchSaving"
            data-test="new-batch"
            @click="openCreateBatch"
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
            {{ t('items.newBatch') }}
          </button>
        </div>
      </div>

      <p
        v-if="batchFormOpen"
        class="mt-4 text-sm font-semibold text-slate-700"
        data-test="batch-form-title"
      >
        {{ editingBatchID === null ? t('items.newBatch') : t('items.editBatch') }}
      </p>

      <div
        v-if="batchFormOpen"
        class="mt-3 grid gap-4 rounded-lg bg-slate-50 p-4 sm:grid-cols-3"
        @keydown.enter.prevent="saveBatch"
      >
        <div>
          <label
            for="batch-location"
            class="block text-sm font-medium text-slate-700"
          >
            {{ t('common.location') }} <span class="text-red-600">*</span>
          </label>
          <select
            id="batch-location"
            v-model="batchForm.location_id"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
            data-test="batch-location"
            @change="batchErrors.location_id = ''"
          >
            <option value="">
              {{ t('items.selectLocation') }}
            </option>
            <option
              v-for="location in locations"
              :key="location.id"
              :value="location.id"
            >
              {{ location.name }}
            </option>
          </select>
          <p
            v-if="batchErrors.location_id"
            class="mt-1 text-sm text-red-600"
          >
            {{ batchErrors.location_id }}
          </p>
        </div>

        <div>
          <label
            for="batch-expires"
            class="block text-sm font-medium text-slate-700"
          >
            {{ t('items.expirationDate') }} <span class="text-red-600">*</span>
          </label>
          <input
            id="batch-expires"
            v-model="batchForm.expires_at"
            type="date"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
            data-test="batch-expires"
            @input="batchErrors.expires_at = ''"
          >
          <p
            v-if="batchErrors.expires_at"
            class="mt-1 text-sm text-red-600"
          >
            {{ batchErrors.expires_at }}
          </p>
        </div>

        <div>
          <label
            for="batch-quantity"
            class="block text-sm font-medium text-slate-700"
          >
            {{ t('common.quantity') }} <span class="text-red-600">*</span>
          </label>
          <input
            id="batch-quantity"
            v-model.number="batchForm.quantity"
            type="number"
            required
            min="1"
            step="1"
            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
            data-test="batch-quantity"
            @input="batchErrors.quantity = ''"
          >
          <p
            v-if="batchErrors.quantity"
            class="mt-1 text-sm text-red-600"
          >
            {{ batchErrors.quantity }}
          </p>
        </div>

        <p
          v-if="locationsLoading"
          class="text-sm text-slate-600 sm:col-span-3"
          data-test="batches-locations-loading"
        >
          {{ t('common.loading') }}
        </p>

        <div
          v-else-if="locationsError"
          class="flex flex-wrap items-center gap-3 text-sm text-red-700 sm:col-span-3"
          data-test="batches-locations-error"
        >
          <span>{{ locationsError }}</span>
          <button
            type="button"
            class="font-semibold underline"
            data-test="batches-locations-retry"
            @click="loadLocations"
          >
            {{ t('common.retry') }}
          </button>
        </div>

        <p
          v-else-if="locations.length === 0"
          class="text-sm text-slate-600 sm:col-span-3"
          data-test="batches-no-locations"
        >
          {{ t('items.noLocations') }}
        </p>

        <p
          v-if="batchErrors.form"
          class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 sm:col-span-3"
          role="alert"
          data-test="batch-error"
        >
          {{ batchErrors.form }}
        </p>

        <div class="flex items-center justify-end gap-3 sm:col-span-3">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            :disabled="batchSaving"
            data-test="batch-cancel"
            @click="cancelBatchForm"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
            :disabled="batchSaving || locationsLoading || locationsError !== '' || locations.length === 0"
            data-test="batch-save"
            @click="saveBatch"
          >
            {{ batchSaving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>

      <p
        v-if="batches.length === 0"
        class="mt-4 text-sm text-slate-500"
        data-test="batches-empty"
      >
        {{ t('items.noBatches') }}
      </p>

      <div
        v-else
        class="mt-4 overflow-x-auto rounded-xl border border-slate-200"
      >
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-3 py-3 text-left font-semibold text-slate-600">
                {{ t('common.location') }}
              </th>
              <th class="px-3 py-3 text-left font-semibold text-slate-600">
                {{ t('items.expirationDate') }}
              </th>
              <th class="px-3 py-3 text-left font-semibold text-slate-600">
                {{ t('common.quantity') }}
              </th>
              <th
                v-if="showCreatedAt"
                class="px-3 py-3 text-left font-semibold text-slate-600"
              >
                {{ t('common.createdAt') }}
              </th>
              <th class="px-3 py-3 text-right font-semibold text-slate-600">
                {{ t('common.actions') }}
              </th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="batch in batches"
              :key="batch.id"
              class="hover:bg-slate-50"
              data-test="batch-row"
            >
              <td
                class="px-3 py-3 font-medium"
                data-test="batch-location"
              >
                {{ batch.location.name }}
              </td>
              <td
                class="px-3 py-3 whitespace-nowrap"
                data-test="batch-expires"
              >
                {{ formatDate(batch.expires_at) }}
              </td>
              <td
                class="px-3 py-3 tabular-nums"
                data-test="batch-quantity"
              >
                {{ batch.quantity }}
              </td>
              <td
                v-if="showCreatedAt"
                class="px-3 py-3 whitespace-nowrap"
                data-test="batch-created"
              >
                {{ formatDateTime(batch.created_at) }}
              </td>
              <td class="px-3 py-3">
                <div class="flex justify-end gap-2">
                  <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                    :disabled="batchSaving"
                    data-test="batch-edit"
                    @click="openEditBatch(batch)"
                  >
                    {{ t('common.edit') }}
                  </button>
                  <button
                    type="button"
                    class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-60"
                    :disabled="batchSaving"
                    data-test="batch-delete"
                    @click="askDeleteBatch(batch)"
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
        v-if="refreshError"
        class="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800"
        role="alert"
        data-test="batches-refresh-error"
      >
        {{ refreshError }}
        <button
          type="button"
          class="font-semibold underline"
          data-test="batches-refresh-retry"
          @click="refreshAfterMutation"
        >
          {{ t('common.retry') }}
        </button>
      </p>
    </section>

    <ConfirmDialog
      :open="pendingBatchDelete !== null"
      :title="t('items.deleteBatchTitle')"
      :body="t('items.deleteBatchConfirm')"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmDeleteBatch"
      @cancel="pendingBatchDelete = null"
    />
  </section>
</template>

