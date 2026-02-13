<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import type { ContentVersionListResource } from '~/types/contents'
import type { Release } from '~/types/releases'

const props = defineProps<{
  open: boolean
  release: Release | null
  currentVersion: ContentVersionListResource | null
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  assign: [versionIds: string[]]
}>()

const handleAssign = () => {
  if (props.currentVersion) {
    emit('assign', [props.currentVersion.id])
    emit('update:open', false)
  }
}

const handleOpenChange = (newOpen: boolean) => {
  if (!props.loading) {
    emit('update:open', newOpen)
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Add to Release</DialogTitle>
        <DialogDescription> Add the current version to the release </DialogDescription>
      </DialogHeader>

      <div class="space-y-4">
        <div
          v-if="release"
          class="rounded-lg border border-border p-3"
        >
          <p class="text-sm text-muted">Release</p>
          <p class="font-semibold">{{ release.name }}</p>
          <p
            v-if="release.description"
            class="line-clamp-2 text-sm text-muted"
          >
            {{ release.description }}
          </p>
        </div>

        <div
          v-if="currentVersion"
          class="rounded-lg border border-border p-3"
        >
          <p class="text-sm text-muted">Version</p>
          <div class="flex items-center gap-2">
            <p class="truncate font-semibold">
              {{ currentVersion.message || 'No message' }}
            </p>
            <Badge
              v-if="currentVersion.published_at"
              size="sm"
              variant="success"
            >
              Published
            </Badge>
            <Badge
              v-else-if="currentVersion.scheduled_at"
              size="sm"
              variant="warning"
            >
              Scheduled
            </Badge>
          </div>
          <p class="mt-2 text-xs text-muted">
            {{
              new Date(currentVersion.created_at).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
              })
            }}
          </p>
        </div>

        <p class="text-sm text-muted">
          This will add the current version to the release for future publishing.
        </p>
      </div>

      <div class="flex justify-end gap-2">
        <Button
          variant="outline"
          :disabled="loading"
          @click="handleOpenChange(false)"
        >
          Cancel
        </Button>
        <Button
          :disabled="loading || !currentVersion"
          :loading="loading"
          @click="handleAssign"
        >
          Add to Release
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
