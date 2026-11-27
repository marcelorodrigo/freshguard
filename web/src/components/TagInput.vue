<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import type { Tag } from '../api';

const props = defineProps<{
  modelValue: string[];
  suggestions: Tag[];
  inputId: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string[]];
}>();

const { t } = useI18n();

const draft = ref('');
const focused = ref(false);

const filtered = computed(() => {
  const query = draft.value.trim().toLowerCase();

  return props.suggestions
    .filter((tag) => {
      const alreadySelected = props.modelValue.some(
        (selected) => selected.toLowerCase() === tag.name.toLowerCase(),
      );
      return !alreadySelected && (query === '' || tag.name.toLowerCase().includes(query));
    })
    .slice(0, 8);
});

function add(value: string): void {
  const tag = value.trim();
  if (tag === '') {
    return;
  }

  const exists = props.modelValue.some(
    (selected) => selected.toLowerCase() === tag.toLowerCase(),
  );
  if (!exists) {
    emit('update:modelValue', [...props.modelValue, tag]);
  }

  draft.value = '';
  focused.value = false;
}

function remove(index: number): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, current) => current !== index),
  );
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault();
    add(draft.value);
    return;
  }

  if (event.key === 'Backspace' && draft.value === '' && props.modelValue.length > 0) {
    remove(props.modelValue.length - 1);
  }
}

function onBlur(): void {
  setTimeout(() => {
    focused.value = false;
  }, 150);
}
</script>

<template>
  <div class="relative">
    <div
      class="flex min-h-11 flex-wrap items-center gap-2 rounded-lg border border-slate-300 px-2 py-1.5 focus-within:border-emerald-500"
    >
      <span
        v-for="(tag, index) in modelValue"
        :key="tag"
        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800"
      >
        {{ tag }}
        <button
          type="button"
          class="rounded-full p-0.5 text-emerald-700 hover:bg-emerald-100"
          :aria-label="`${t('common.delete')} ${tag}`"
          @click="remove(index)"
        >
          <svg
            class="size-3"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
          >
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </span>

      <input
        :id="inputId"
        v-model="draft"
        type="text"
        class="min-w-32 flex-1 border-0 bg-transparent px-1 py-1 text-sm focus:outline-none"
        :placeholder="t('items.addTags')"
        @keydown="onKeydown"
        @focus="focused = true"
        @blur="onBlur"
      >
    </div>

    <ul
      v-if="focused && filtered.length > 0"
      class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
    >
      <li
        v-for="tag in filtered"
        :key="tag.id"
      >
        <button
          type="button"
          class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50"
          @mousedown.prevent="add(tag.name)"
        >
          <span>{{ tag.name }}</span>
          <span class="text-xs text-slate-400">{{ tag.item_count }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
