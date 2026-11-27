<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import {
  ApiError,
  createItem,
  getItem,
  listTags,
  updateItem,
  type ItemDetail,
  type ItemInput,
  type Tag,
} from '../api';
import TagInput from '../components/TagInput.vue';
import { errorMessageKey, fieldErrorKey } from '../errorMessages';
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

  errors.name =
    name === ''
      ? t('errors.required')
      : name.length > 255
        ? t('errors.max')
        : '';
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
            <label class="block text-sm font-medium text-slate-700">
              {{ t('common.tags') }}
            </label>
            <div class="mt-1">
              <TagInput
                v-model="form.tags"
                :suggestions="suggestions"
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
  </section>
</template>
