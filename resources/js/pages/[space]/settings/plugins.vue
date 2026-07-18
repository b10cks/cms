<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'
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
import { useAlertDialog } from '~/composables/useAlertDialog'
import { useFieldPlugins } from '~/composables/useFieldPlugins'
import type { FieldPluginResource, FieldPluginStatus } from '~/types/field-plugins'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId })))
const canManage = computed(() => access.hasAbility('field_plugins.manage'))

const {
  useFieldPluginsQuery,
  useFieldPluginQuery,
  useCreateFieldPluginMutation,
  useUpdateFieldPluginMutation,
  useDeleteFieldPluginMutation,
} = useFieldPlugins(spaceId)

const { data: plugins, isLoading } = useFieldPluginsQuery({ per_page: 100 })
const { mutate: createPlugin, isPending: isCreating } = useCreateFieldPluginMutation()
const { mutateAsync: updatePlugin, isPending: isUpdating } = useUpdateFieldPluginMutation()
const { mutate: deletePlugin } = useDeleteFieldPluginMutation()

useSeoMeta({
  title: computed(() => t('labels.settings.fieldPlugins.title')),
})

const statusVariant = {
  draft: 'secondary',
  dev: 'warning',
  published: 'success',
} as const satisfies Record<FieldPluginStatus, string>

/* Create */
const isCreateOpen = ref(false)
const createForm = ref({ name: '', handle: '' })

const handleCreate = () => {
  createPlugin(
    { name: createForm.value.name, handle: createForm.value.handle },
    {
      onSuccess: () => {
        isCreateOpen.value = false
        createForm.value = { name: '', handle: '' }
      },
    }
  )
}

/* Edit */
const editId = ref<string | null>(null)
const { data: editPlugin } = useFieldPluginQuery(
  computed(() => editId.value ?? ''),
  computed(() => editId.value !== null)
)

const editForm = ref({
  name: '',
  description: '',
  dev_mode: false,
  dev_url: '',
  code: '',
})

watch(editPlugin, (plugin) => {
  if (!plugin) return
  editForm.value = {
    name: plugin.name,
    description: plugin.description ?? '',
    dev_mode: plugin.dev_mode,
    dev_url: plugin.dev_url ?? '',
    code: plugin.code ?? '',
  }
})

const openEdit = (plugin: FieldPluginResource) => {
  editId.value = plugin.id
}

const saveEdit = async (publishCode: boolean) => {
  if (!editId.value) return

  await updatePlugin({
    id: editId.value,
    payload: {
      name: editForm.value.name,
      description: editForm.value.description || null,
      dev_mode: editForm.value.dev_mode,
      dev_url: editForm.value.dev_url || null,
      // Sending code publishes a new bundle version; omit it to keep the current one.
      ...(publishCode && editForm.value.code ? { code: editForm.value.code } : {}),
    },
  })

  if (!publishCode) editId.value = null
}

const handleBundleUpload = (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  const reader = new FileReader()
  reader.onload = () => {
    editForm.value.code = String(reader.result ?? '')
  }
  reader.readAsText(file)
}

const { alert } = useAlertDialog()

const handleDelete = async (plugin: FieldPluginResource) => {
  const confirmed = await alert.confirm(
    t('labels.fieldPlugins.deleteConfirmMessage', { name: plugin.name }),
    {
      title: t('labels.fieldPlugins.deleteConfirmTitle'),
      confirmLabel: t('actions.fieldPlugins.delete'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    deletePlugin(plugin.id)
  }
}

const { formatFileSize } = useFormat()
const formatSize = (bytes: number | null) => (bytes ? formatFileSize(bytes) : '—')
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.fieldPlugins.title')"
      :description="$t('labels.settings.fieldPlugins.description')"
    >
      <template #actions>
        <Button
          v-if="canManage"
          @click="isCreateOpen = true"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.fieldPlugins.create') }}
        </Button>
      </template>
    </ContentHeader>

    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('labels.fieldPlugins.columns.name') }}</TableHead>
          <TableHead>{{ $t('labels.fieldPlugins.columns.handle') }}</TableHead>
          <TableHead>{{ $t('labels.fieldPlugins.columns.status') }}</TableHead>
          <TableHead>{{ $t('labels.fieldPlugins.columns.size') }}</TableHead>
          <TableHead />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableLoadingRow
          v-if="isLoading"
          :colspan="5"
        />
        <TableEmptyRow
          v-else-if="!plugins?.data.length"
          :colspan="5"
          :message="$t('labels.fieldPlugins.empty')"
        />
        <TableRow
          v-for="plugin in plugins?.data ?? []"
          :key="plugin.id"
          class="cursor-pointer"
          @click="openEdit(plugin)"
        >
          <TableCell class="font-medium">{{ plugin.name }}</TableCell>
          <TableCell><code class="text-sm">{{ plugin.handle }}</code></TableCell>
          <TableCell>
            <Badge :variant="statusVariant[plugin.status]">
              {{ $t(`labels.fieldPlugins.status.${plugin.status}`) }}
            </Badge>
          </TableCell>
          <TableCell>{{ formatSize(plugin.code_size) }}</TableCell>
          <TableCell class="text-right">
            <Button
              v-if="canManage"
              variant="ghost"
              size="icon"
              @click.stop="handleDelete(plugin)"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <!-- Create -->
    <Dialog v-model:open="isCreateOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ $t('actions.fieldPlugins.create') }}</DialogTitle>
          <DialogDescription>{{ $t('labels.fieldPlugins.createDescription') }}</DialogDescription>
        </DialogHeader>
        <div class="flex flex-col gap-4">
          <InputField
            v-model="createForm.name"
            name="name"
            :label="$t('labels.fieldPlugins.form.name')"
            required
          />
          <InputField
            v-model="createForm.handle"
            name="handle"
            :label="$t('labels.fieldPlugins.form.handle')"
            :description="$t('labels.fieldPlugins.form.handleDescription')"
            required
          />
        </div>
        <DialogFooter>
          <Button
            :disabled="!createForm.name || !createForm.handle"
            :loading="isCreating"
            @click="handleCreate"
          >
            {{ $t('actions.fieldPlugins.create') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Edit -->
    <Dialog
      :open="editId !== null"
      @update:open="(open: boolean) => { if (!open) editId = null }"
    >
      <DialogContent class="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{{ editPlugin?.name }}</DialogTitle>
          <DialogDescription>
            <code>{{ editPlugin?.handle }}</code>
            <template v-if="editPlugin?.code_hash">
              · {{ $t('labels.fieldPlugins.form.currentVersion') }}
              <code>{{ editPlugin.code_hash.slice(0, 8) }}</code>
              ({{ formatSize(editPlugin.code_size) }})
            </template>
          </DialogDescription>
        </DialogHeader>
        <div class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto">
          <InputField
            v-model="editForm.name"
            name="name"
            :label="$t('labels.fieldPlugins.form.name')"
          />
          <TextField
            v-model="editForm.description"
            name="description"
            :rows="2"
            :label="$t('labels.fieldPlugins.form.description')"
          />
          <CheckboxField
            v-model="editForm.dev_mode"
            name="dev_mode"
            :label="$t('labels.fieldPlugins.form.devMode')"
            :description="$t('labels.fieldPlugins.form.devModeDescription')"
          />
          <InputField
            v-if="editForm.dev_mode"
            v-model="editForm.dev_url"
            name="dev_url"
            type="url"
            :label="$t('labels.fieldPlugins.form.devUrl')"
            placeholder="http://localhost:5173/plugin"
          />
          <TextField
            v-model="editForm.code"
            name="code"
            :rows="10"
            input-class="font-mono text-xs"
            :label="$t('labels.fieldPlugins.form.bundle')"
            :description="$t('labels.fieldPlugins.form.bundleDescription')"
          />
          <input
            type="file"
            accept=".js,text/javascript"
            class="text-sm"
            @change="handleBundleUpload"
          >
        </div>
        <DialogFooter>
          <Button
            variant="outline"
            :loading="isUpdating"
            @click="saveEdit(false)"
          >
            {{ $t('actions.fieldPlugins.save') }}
          </Button>
          <Button
            :disabled="!editForm.code"
            :loading="isUpdating"
            @click="saveEdit(true)"
          >
            {{ $t('actions.fieldPlugins.publish') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
