<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { InputField, Label, TextField } from '~/components/ui/form'
import { useAlertDialog } from '~/composables/useAlertDialog'
import type { IconResource, UpdateIconPayload } from '~/types/icons'
import { replaceColorsWithCurrentColor } from '~/utils/svg'

const props = defineProps<{
  spaceId: string
  icon: IconResource | null
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { alert } = useAlertDialog()
const { useUpdateIconMutation, useDeleteIconMutation } = useIcons(props.spaceId)
const updateMutation = useUpdateIconMutation()
const deleteMutation = useDeleteIconMutation()

type PreviewBg = 'light' | 'dark' | 'checkered'

const BG_CLASSES: Record<PreviewBg, string> = {
  light: 'bg-white',
  dark: 'bg-gray-900',
  checkered: 'bg-checkered',
}

const COLOR_SWATCHES = [
  { color: 'text-slate-500', label: 'slate' },
  { color: 'text-blue-500', label: 'blue' },
  { color: 'text-rose-500', label: 'rose' },
  { color: 'text-emerald-500', label: 'emerald' },
  { color: 'text-amber-500', label: 'amber' },
]

const form = reactive({
  key: '',
  name: '',
  description: '',
  tags: '',
  body: '',
  width: 24,
  height: 24,
})

const replacementFile = ref<File | null>(null)
const svgEditorOpen = ref(false)
const previewBg = ref<PreviewBg>('light')

const resetForm = () => {
  form.key = props.icon?.key ?? ''
  form.name = props.icon?.name ?? ''
  form.description = props.icon?.description ?? ''
  form.tags = (props.icon?.tags ?? []).join(', ')
  form.body = props.icon?.body ?? ''
  form.width = props.icon?.width ?? 24
  form.height = props.icon?.height ?? 24
  replacementFile.value = null
  svgEditorOpen.value = false
}

watch(() => props.icon, resetForm, { immediate: true })
watch(open, (value) => {
  if (value) resetForm()
})

watch(replacementFile, async (file) => {
  if (!file) return
  form.body = await file.text()
  svgEditorOpen.value = false
})

const previewBody = computed(() => form.body || props.icon?.body || '')
const bodyChanged = computed(() => !!props.icon && form.body !== props.icon.body)

const applyCurrentColor = () => {
  form.body = replaceColorsWithCurrentColor(previewBody.value)
  svgEditorOpen.value = true
}

const parsedTags = computed(() =>
  form.tags
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean),
)

const save = async () => {
  if (!props.icon) return

  const payload: UpdateIconPayload = {
    key: form.key,
    name: form.name,
    description: form.description || null,
    tags: parsedTags.value,
  }

  if (bodyChanged.value) {
    // The parser expects a full SVG document; the stored body is only the inner content.
    payload.body = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${form.width} ${form.height}">${form.body}</svg>`
  }

  if (form.width !== props.icon.width) payload.width = form.width
  if (form.height !== props.icon.height) payload.height = form.height

  await updateMutation.mutateAsync({ id: props.icon.id, payload })
  open.value = false
}

const remove = async () => {
  if (!props.icon) return

  const confirmed = await alert.confirm(t('messages.icons.confirmDelete'), {
    title: t('labels.icons.deleteTitle'),
    confirmLabel: t('actions.delete'),
    cancelLabel: t('actions.cancel'),
  })

  if (!confirmed) return

  await deleteMutation.mutateAsync(props.icon.id)
  open.value = false
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent
      v-if="icon"
      class="!max-w-2xl"
    >
      <DialogHeader>
        <DialogTitle>{{ t('labels.icons.editTitle') }}</DialogTitle>
      </DialogHeader>

      <!-- Preview area -->
      <div class="overflow-hidden rounded-lg border border-input">
        <div class="flex gap-4 p-4">
          <!-- Main preview on selected background -->
          <div
            class="flex size-24 shrink-0 items-center justify-center rounded-md transition-colors"
            :class="BG_CLASSES[previewBg]"
          >
            <IconPreview
              :body="previewBody"
              :width="form.width"
              :height="form.height"
              size="64"
            />
          </div>

          <!-- Controls column -->
          <div class="flex flex-1 flex-col justify-between gap-3">
            <!-- Background switcher -->
            <div class="flex items-center gap-2">
              <span class="text-xs text-muted">{{ t('labels.icons.previewBg') }}</span>
              <div class="flex gap-1">
                <button
                  v-for="(cls, bg) in BG_CLASSES"
                  :key="bg"
                  type="button"
                  :title="t(`labels.icons.bg.${bg}`)"
                  :class="[
                    'size-5 rounded border-2 transition-colors',
                    cls,
                    previewBg === bg ? 'border-primary' : 'border-input hover:border-muted',
                    bg === 'checkered' ? 'bg-checkered' : '',
                  ]"
                  @click="previewBg = bg as PreviewBg"
                />
              </div>
            </div>

            <!-- Color swatches: visual currentColor check -->
            <div class="flex items-center gap-2">
              <span class="text-xs text-muted">{{ t('labels.icons.colorCheck') }}</span>
              <div class="flex gap-1">
                <div
                  v-for="swatch in COLOR_SWATCHES"
                  :key="swatch.color"
                  :title="swatch.label"
                  :class="['flex size-7 items-center justify-center rounded border border-input bg-white', swatch.color]"
                >
                  <IconPreview
                    :body="previewBody"
                    :width="form.width"
                    :height="form.height"
                    size="18"
                  />
                </div>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                @click="applyCurrentColor"
              >
                <Icon name="lucide:pipette" />
                {{ t('labels.icons.useCurrentColor') }}
              </Button>
              <button
                type="button"
                class="flex items-center gap-1 text-xs text-muted hover:text-primary"
                @click="svgEditorOpen = !svgEditorOpen"
              >
                <Icon
                  :name="svgEditorOpen ? 'lucide:chevron-up' : 'lucide:chevron-down'"
                  size="14"
                />
                {{ t('labels.icons.editSvgSource') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Size bar -->
        <div class="flex items-center gap-1.5 border-t border-input bg-surface px-4 py-1.5 text-xs text-muted">
          <Icon
            name="lucide:ruler"
            size="12"
          />
          <span>{{ form.width }}×{{ form.height }}</span>
        </div>
      </div>

      <Transition name="slide-down">
        <div
          v-if="svgEditorOpen"
          class="grid gap-1"
        >
          <Label :label="t('labels.icons.svgSource')" />
          <textarea
            v-model="form.body"
            rows="8"
            spellcheck="false"
            class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 font-mono text-xs text-primary focus:outline-none focus:ring-2 focus:ring-ring"
          />
        </div>
      </Transition>

      <div class="grid grid-cols-2 gap-3">
        <InputField
          v-model="form.key"
          name="key"
          :label="t('labels.icons.key')"
        />
        <InputField
          v-model="form.name"
          name="name"
          :label="t('labels.icons.name')"
        />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <InputField
          v-model.number="form.width"
          name="width"
          type="number"
          :label="t('labels.icons.width')"
        />
        <InputField
          v-model.number="form.height"
          name="height"
          type="number"
          :label="t('labels.icons.height')"
        />
      </div>

      <TextField
        v-model="form.description"
        name="description"
        :label="t('labels.icons.description')"
        :rows="2"
      />

      <InputField
        v-model="form.tags"
        name="tags"
        :label="t('labels.icons.tags')"
        :placeholder="t('labels.icons.tagsPlaceholder')"
      />

      <div class="grid gap-2">
        <Label :label="t('labels.icons.replaceSvg')" />
        <FileDropZone
          v-model="replacementFile"
          accept=".svg"
          :hint="t('labels.icons.replaceHint')"
        />
      </div>

      <DialogFooter class="justify-between">
        <Button
          variant="destructive"
          :disabled="deleteMutation.isPending.value"
          @click="remove"
        >
          <Icon name="lucide:trash-2" />
          {{ t('actions.delete') }}
        </Button>
        <div class="flex items-center gap-2">
          <Button
            variant="outline"
            @click="open = false"
          >
            {{ t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            :disabled="updateMutation.isPending.value"
            @click="save"
          >
            {{ t('actions.save') }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
