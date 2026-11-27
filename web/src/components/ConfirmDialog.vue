<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

const props = defineProps<{
  open: boolean;
  title: string;
  body: string;
  confirmLabel: string;
  cancelLabel: string;
}>();

const emit = defineEmits<{
  confirm: [];
  cancel: [];
}>();

const dialog = ref<HTMLDialogElement | null>(null);

function sync(open: boolean): void {
  const element = dialog.value;
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

function handleClose(): void {
  if (props.open) {
    emit('cancel');
  }
}

onMounted(() => {
  sync(props.open);
});
watch(() => props.open, sync);
</script>

<template>
  <dialog
    ref="dialog"
    class="w-[min(28rem,calc(100vw-2rem))] rounded-2xl p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40"
    data-test="confirm-dialog"
    @close="handleClose"
    @cancel.prevent="emit('cancel')"
    @keydown.esc.prevent="emit('cancel')"
    @click.self="emit('cancel')"
  >
    <div class="p-6">
      <h2 class="text-lg font-semibold">
        {{ title }}
      </h2>
      <p class="mt-2 text-sm text-slate-600">
        {{ body }}
      </p>

      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          data-test="confirm-dialog-cancel"
          @click="emit('cancel')"
        >
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
          data-test="confirm-dialog-confirm"
          @click="emit('confirm')"
        >
          {{ confirmLabel }}
        </button>
      </div>
    </div>
  </dialog>
</template>
