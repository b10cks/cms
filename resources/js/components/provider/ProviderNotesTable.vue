<script setup lang="ts">
import NotesIcon from '~/assets/images/space.svg?component'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

import ProviderNoteDialog from './ProviderNoteDialog.vue'

const { formatRelativeTime } = useFormat()
const { t } = useI18n()
const { alert } = useAlertDialog()
const {
  useProviderNotesQuery,
  useCreateProviderNoteMutation,
  useUpdateProviderNoteMutation,
  useDeleteProviderNoteMutation,
} = useProvider()

const noteDialogOpen = ref(false)
const noteToEdit = ref<ProviderNote | null>(null)

const { data: notesResponse, isLoading } = useProviderNotesQuery({ per_page: 50 })
const createMutation = useCreateProviderNoteMutation()
const updateMutation = useUpdateProviderNoteMutation()
const deleteMutation = useDeleteProviderNoteMutation()

const noteRows = computed(() => notesResponse.value?.data ?? [])
const isNoteDialogSubmitting = computed(
  () => createMutation.isPending.value || updateMutation.isPending.value
)

const resolveNoteIcon = (icon?: string | null) => {
  if (!icon) {
    return 'lucide:notebook-pen'
  }

  return icon.startsWith('lucide:') ? icon : `lucide:${icon}`
}

const openCreateDialog = () => {
  noteToEdit.value = null
  noteDialogOpen.value = true
}

const openEditDialog = (note: ProviderNote) => {
  noteToEdit.value = note
  noteDialogOpen.value = true
}

const handleNoteDialogOpenChange = (value: boolean) => {
  noteDialogOpen.value = value

  if (!value) {
    noteToEdit.value = null
  }
}

const handleCreate = async (payload: ProviderNotePayload) => {
  try {
    await createMutation.mutateAsync(payload)
    noteDialogOpen.value = false
  } catch {
    // Shared mutation toasts already communicate the failure.
  }
}

const handleUpdate = async (id: string, payload: Partial<ProviderNotePayload>) => {
  try {
    await updateMutation.mutateAsync({ id, payload })
    noteDialogOpen.value = false
    noteToEdit.value = null
  } catch {
    // Shared mutation toasts already communicate the failure.
  }
}

const handleDelete = async (note: ProviderNote) => {
  const confirmed = await alert.confirm(t('labels.provider.notes.confirmDelete.message', {
    title: note.title,
  }) as string, {
    title: t('labels.provider.notes.confirmDelete.title') as string,
    confirmLabel: t('actions.delete') as string,
    cancelLabel: t('actions.cancel') as string,
    variant: 'destructive',
  })

  if (!confirmed) {
    return
  }

  await deleteMutation.mutateAsync(note.id)
}

defineExpose({
  openCreateDialog,
})
</script>

<template>
  <div class="space-y-4">
    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ t('labels.provider.notes.columns.note') }}</TableHead>
            <TableHead>{{ t('labels.provider.notes.columns.link') }}</TableHead>
            <TableHead>{{ t('labels.provider.notes.columns.updated') }}</TableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="4"
          />

          <template v-else-if="noteRows.length > 0">
            <TableRow
              v-for="note in noteRows"
              :key="note.id"
              class="hover:bg-muted/50"
            >
              <TableCell class="align-top">
                <div class="flex items-start gap-3">
                  <div
                    class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-input"
                    :style="note.color ? { color: note.color } : undefined"
                  >
                    <Icon :name="resolveNoteIcon(note.icon)" />
                  </div>
                  <div class="min-w-0 space-y-1">
                    <div class="flex items-center gap-2">
                      <span class="font-medium text-primary">{{ note.title }}</span>
                      <span
                        v-if="note.is_pinned"
                        class="rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-primary"
                      >
                        {{ t('labels.provider.notes.pinned') }}
                      </span>
                    </div>
                    <p
                      v-if="note.content"
                      class="line-clamp-3 text-sm text-muted"
                    >
                      {{ note.content }}
                    </p>
                  </div>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <a
                  v-if="note.url"
                  :href="note.url"
                  target="_blank"
                  rel="noreferrer"
                  class="text-sm text-info underline-offset-2 hover:underline"
                >
                  {{ note.url }}
                </a>
                <span
                  v-else
                  class="text-sm text-muted"
                >
                  {{ t('labels.provider.notes.noLink') }}
                </span>
              </TableCell>

              <TableCell class="align-top text-sm text-muted">
                {{ formatRelativeTime(note.updated_at) }}
              </TableCell>

              <TableCell class="align-top">
                <div class="flex items-center justify-end gap-1">
                  <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8"
                    @click="openEditDialog(note)"
                  >
                    <Icon name="lucide:pencil" />
                    <span class="sr-only">{{ t('actions.edit') }}</span>
                  </Button>

                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ t('labels.provider.notes.openMenu') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                      <DropdownMenuItem @click="openEditDialog(note)">
                        <Icon
                          name="lucide:pencil"
                          class="mr-2"
                        />
                        {{ t('actions.edit') }}
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click="handleDelete(note)"
                      >
                        <Icon
                          name="lucide:trash-2"
                          class="mr-2"
                        />
                        {{ t('actions.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>

          <TableEmptyRow
            v-else
            :colspan="4"
            :icon="NotesIcon"
            :label="t('labels.provider.notes.empty')"
          />
        </TableBody>
      </Table>
    </div>

    <ProviderNoteDialog
      :open="noteDialogOpen"
      :loading="isNoteDialogSubmitting"
      :note-to-edit="noteToEdit"
      @update:open="handleNoteDialogOpenChange"
      @create="handleCreate"
      @update="handleUpdate"
    />
  </div>
</template>
