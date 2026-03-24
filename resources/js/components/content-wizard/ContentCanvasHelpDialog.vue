<script setup lang="ts">
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

const open = defineModel<boolean>('open', { default: false })
const { t } = useI18n()


const sections = computed(() => [
  {
    title: t('labels.contents.canvas.help.sections.navigation'),
    items: [
      {
        keys: ['Arrow keys'],
        description: t('labels.contents.canvas.help.items.moveFocus'),
      },
      {
        keys: ['Node action'],
        description: t('labels.contents.canvas.help.items.toggleCollapse'),
      },
    ],
  },
  {
    title: t('labels.contents.canvas.help.sections.editing'),
    items: [
      {
        keys: ['F2', 'Type'],
        description: t('labels.contents.canvas.help.items.rename'),
      },
      {
        keys: ['Enter'],
        description: t('labels.contents.canvas.help.items.createChild'),
      },
      {
        keys: ['Tab'],
        description: t('labels.contents.canvas.help.items.createSibling'),
      },
      {
        keys: ['Delete', 'Backspace'],
        description: t('labels.contents.canvas.help.items.toggleDelete'),
      },
    ],
  },
  {
    title: t('labels.contents.canvas.help.sections.history'),
    items: [
      {
        keys: ['Ctrl/Cmd', 'Z'],
        description: t('labels.contents.canvas.help.items.undo'),
      },
      {
        keys: ['Shift', 'Ctrl/Cmd', 'Z'],
        description: t('labels.contents.canvas.help.items.redo'),
      },
      {
        keys: ['Ctrl/Cmd', 'Y'],
        description: t('labels.contents.canvas.help.items.redoAlternative'),
      },
    ],
  },
  {
    title: t('labels.contents.canvas.help.sections.viewport'),
    items: [
      {
        keys: ['Drag'],
        description: t('labels.contents.canvas.help.items.dragPan'),
      },
      {
        keys: ['Scroll'],
        description: t('labels.contents.canvas.help.items.scrollPan'),
      },
      {
        keys: ['Ctrl/Cmd', 'Wheel'],
        description: t('labels.contents.canvas.help.items.modifiedZoom'),
      },
      {
        keys: ['Toolbar'],
        description: t('labels.contents.canvas.help.items.toolbarZoom'),
      },
    ],
  },
])
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-4xl">
      <DialogHeader>
        <DialogTitle>{{ $t('labels.contents.canvas.help.title') }}</DialogTitle>
        <DialogDescription>
          {{ $t('labels.contents.canvas.help.description') }}
        </DialogDescription>
      </DialogHeader>

      <div class="bg-surface grid gap-4 p-3 rounded-xl">
        <section
          v-for="section in sections"
          :key="section.title"
        >
          <h3 class="text-sm font-semibold text-primary">
            {{ section.title }}
          </h3>
          <div class="flex flex-col gap-2">
            <div
              v-for="item in section.items"
              :key="`${section.title}:${item.description}`"
              class="flex gap-1 items-center"
            >
              <div class="flex flex-wrap gap-1.5">
                <kbd
                  v-for="key in item.keys"
                  :key="key"
                  class="text-xs font-mono bg-card rounded flex items-center px-2 py-1"
                >
                  {{ key }}
                </kbd>
              </div>
              <p class="text-sm leading-relaxed">: {{ item.description }}</p>
            </div>
          </div>
        </section>
      </div>
    </DialogContent>
  </Dialog>
</template>
