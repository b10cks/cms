<script setup lang="ts">
import { Dialog, DialogContent, DialogHeaderCombined } from '~/components/ui/dialog'

const open = defineModel<boolean>('open', { default: false })

const { $t } = useI18n()

const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform)
const mod = isMac ? '⌘' : 'Ctrl'

const groups = computed(() => [
  {
    title: $t('labels.assets.shortcuts.navigation'),
    shortcuts: [
      { keys: ['↑', '↓', '←', '→'], label: $t('labels.assets.shortcuts.moveFocus') },
      { keys: ['Enter'], label: $t('labels.assets.shortcuts.open') },
      { keys: ['Space'], label: $t('labels.assets.shortcuts.openDetails') },
      { keys: ['Backspace'], label: $t('labels.assets.shortcuts.parentFolder') },
    ],
  },
  {
    title: $t('labels.assets.shortcuts.selection'),
    shortcuts: [
      { keys: [mod, 'A'], label: $t('labels.assets.shortcuts.selectAll') },
      { keys: ['Shift', '↑↓←→'], label: $t('labels.assets.shortcuts.extendSelection') },
      { keys: ['Shift', $t('labels.assets.shortcuts.click')], label: $t('labels.assets.shortcuts.rangeSelect') },
      { keys: [mod, $t('labels.assets.shortcuts.click')], label: $t('labels.assets.shortcuts.toggleSelect') },
      { keys: ['Esc'], label: $t('labels.assets.shortcuts.clearSelection') },
      { keys: [$t('labels.assets.shortcuts.typeAhead')], label: $t('labels.assets.shortcuts.typeAheadHint') },
    ],
  },
  {
    title: $t('labels.assets.shortcuts.actions'),
    shortcuts: [
      { keys: [mod, 'X'], label: $t('labels.assets.shortcuts.cut') },
      { keys: [mod, 'V'], label: $t('labels.assets.shortcuts.paste') },
      { keys: ['Delete'], label: $t('labels.assets.shortcuts.delete') },
      { keys: ['?'], label: $t('labels.assets.shortcuts.showHelp') },
    ],
  },
])
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.assets.shortcuts.title')"
        :description="$t('labels.assets.shortcuts.description')"
      />

      <div class="grid gap-5 py-2">
        <section
          v-for="group in groups"
          :key="String(group.title)"
        >
          <h3 class="mb-2 text-sm font-semibold text-primary">{{ group.title }}</h3>
          <dl class="grid gap-1.5">
            <div
              v-for="shortcut in group.shortcuts"
              :key="String(shortcut.label)"
              class="flex items-center justify-between gap-4"
            >
              <dt class="text-sm">{{ shortcut.label }}</dt>
              <dd class="flex shrink-0 items-center gap-1">
                <kbd
                  v-for="key in shortcut.keys"
                  :key="key"
                  class="rounded border border-border bg-surface px-1.5 py-0.5 font-mono text-xs"
                >
                  {{ key }}
                </kbd>
              </dd>
            </div>
          </dl>
        </section>
      </div>
    </DialogContent>
  </Dialog>
</template>
