<script setup lang="ts">
import { Dialog, DialogContent, DialogHeaderCombined } from '~/components/ui/dialog'

const { t, $t } = useI18n()
const { open } = useShortcutHelp()
const { groups } = useShortcutRegistry()

const scopeLabels: Record<string, () => string> = {
  global: () => t('shortcuts.scopes.global'),
  editor: () => t('shortcuts.scopes.editor'),
  'content-editor': () => t('shortcuts.scopes.content-editor'),
  'content-tree': () => t('shortcuts.scopes.content-tree'),
  canvas: () => t('shortcuts.scopes.canvas'),
  assets: () => t('shortcuts.scopes.assets'),
  table: () => t('shortcuts.scopes.table'),
  dialog: () => t('shortcuts.scopes.dialog'),
  fields: () => t('shortcuts.scopes.fields'),
}

const sections = computed(() =>
  groups.value.map((group) => {
    const seen = new Set<string>()

    return {
      scope: group.scope,
      title: scopeLabels[group.scope]?.() ?? group.scope,
      items: group.items.filter((item) => {
        const signature = `${item.keys}|${item.description}`
        if (seen.has(signature)) return false
        seen.add(signature)
        return true
      }),
    }
  })
)

useShortcut({
  keys: '?',
  description: () => t('shortcuts.global.help'),
  handler: () => {
    open.value = true
  },
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('shortcuts.title')"
        :description="$t('shortcuts.description')"
      />

      <div class="grid max-h-[65vh] gap-5 overflow-y-auto py-2">
        <p
          v-if="sections.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('shortcuts.empty') }}
        </p>
        <section
          v-for="section in sections"
          :key="section.scope"
        >
          <h3 class="mb-2 text-sm font-semibold text-primary">{{ section.title }}</h3>
          <dl class="grid gap-1.5">
            <div
              v-for="item in section.items"
              :key="`${section.scope}:${item.keys}:${item.description}`"
              class="flex items-center justify-between gap-4"
            >
              <dt class="text-sm">{{ item.description }}</dt>
              <dd class="flex shrink-0 items-center gap-1">
                <kbd
                  v-for="token in item.tokens"
                  :key="token"
                  class="rounded border border-border bg-surface px-1.5 py-0.5 font-mono text-xs"
                >
                  {{ token }}
                </kbd>
              </dd>
            </div>
          </dl>
        </section>
      </div>
    </DialogContent>
  </Dialog>
</template>
