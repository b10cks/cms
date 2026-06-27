<script setup lang="ts">
import DiffViewer from '~/components/content/DiffViewer.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { computeObjectDiff } from '~/utils/object-diff'

const props = defineProps<{
  open: boolean
  serverVersion: ContentVersionListResource & { content?: Record<string, unknown> | null }
  serverContent: Record<string, unknown> | null
  myContent: Record<string, unknown>
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'save-branch': []
  reload: []
}>()

const { t } = useI18n()
const showDiff = ref(false)

const diffChanges = computed(() =>
  computeObjectDiff(props.serverContent ?? {}, props.myContent),
)

const formattedDate = computed(() => {
  if (!props.serverVersion.created_at) return ''
  return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(
    Math.round(
      (new Date(props.serverVersion.created_at).getTime() - Date.now()) / 60000,
    ),
    'minutes',
  )
})
</script>

<template>
  <Dialog
    :open="open"
    @update:open="emit('update:open', $event)"
  >
    <DialogContent class="max-w-2xl">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Icon
            name="lucide:git-branch"
            class="text-warning"
          />
          {{ t('content.conflict.dialogTitle') }}
        </DialogTitle>
        <DialogDescription>
          {{ t('content.conflict.dialogBody') }}
          <span
            v-if="serverVersion.author"
            class="font-medium"
          >
            {{ serverVersion.author.name }}
          </span>
          <span
            v-if="serverVersion.created_at"
            class="text-muted-foreground"
          >
            · {{ formattedDate }}
          </span>
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3">
        <Button
          variant="ghost"
          size="sm"
          class="gap-1.5 text-xs"
          @click="showDiff = !showDiff"
        >
          <Icon :name="showDiff ? 'lucide:chevron-up' : 'lucide:chevron-down'" />
          {{ t('content.conflict.diffToggle') }}
          <span
            v-if="diffChanges.length"
            class="ml-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs"
          >
            {{ diffChanges.length }}
          </span>
        </Button>

        <div
          v-if="showDiff"
          class="max-h-80 overflow-y-auto rounded-md border"
        >
          <DiffViewer
            v-if="diffChanges.length"
            :changes="diffChanges"
          />
          <p
            v-else
            class="p-4 text-sm text-muted-foreground"
          >
            {{ t('content.conflict.noDiff') }}
          </p>
        </div>
      </div>

      <DialogFooter class="gap-2 sm:justify-between">
        <Button
          variant="outline"
          @click="emit('reload')"
        >
          <Icon name="lucide:refresh-cw" />
          {{ t('content.conflict.reloadServer') }}
        </Button>
        <div class="flex gap-2">
          <Button
            variant="ghost"
            @click="emit('update:open', false)"
          >
            {{ t('actions.cancel') }}
          </Button>
          <Button
            variant="default"
            @click="emit('save-branch')"
          >
            <Icon name="lucide:git-branch" />
            {{ t('content.conflict.saveBranch') }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
