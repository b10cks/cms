<script setup lang="ts">
import type { AiSkillFile, AiSkillResource } from '~/api/resources/ai-knowledge'
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
import { InputField, TextField } from '~/components/ui/form'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  skill: AiSkillResource | null
  saving: boolean
}>()

const emit = defineEmits<{
  save: [files: AiSkillFile[]]
}>()

const TEMPLATE = `---
name: my-skill
description: What the skill does and when to use it.
b10cks:
  surfaces: [selection]
  output: text
---

Instructions for the model.
`

const entry = ref('')
const files = ref<AiSkillFile[]>([])

watch(open, (isOpen) => {
  if (!isOpen) return
  const current = props.skill?.files ?? []
  entry.value = current.find((file) => file.path === 'SKILL.md')?.content ?? TEMPLATE
  files.value = current.filter((file) => file.path !== 'SKILL.md').map((file) => ({ ...file }))
})

const save = () => {
  emit('save', [
    { path: 'SKILL.md', content: entry.value },
    ...files.value.filter((file) => file.path.trim() !== ''),
  ])
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-3xl">
      <DialogHeader>
        <DialogTitle>
          {{ skill ? $t('components.aiSettings.skills.edit') : $t('components.aiSettings.skills.create') }}
        </DialogTitle>
        <DialogDescription>{{ $t('components.aiSettings.skills.format') }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[60vh] space-y-4 overflow-y-auto">
        <TextField
          id="skill-entry"
          name="skill-entry"
          v-model="entry"
          label="SKILL.md"
          input-class="min-h-72 font-mono text-sm"
        />
        <div
          v-for="(file, index) in files"
          :key="index"
          class="space-y-2 rounded-lg border border-border p-3"
        >
          <div class="flex items-end gap-2">
            <InputField
              :id="`skill-file-${index}`"
              :name="`skill-file-${index}`"
              v-model="file.path"
              class="flex-1"
              :label="$t('components.aiSettings.skills.filePath')"
            />
            <Button
              type="button"
              variant="ghost"
              size="icon"
              :aria-label="$t('actions.delete')"
              @click="files.splice(index, 1)"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </div>
          <TextField
            :id="`skill-file-content-${index}`"
            :name="`skill-file-content-${index}`"
            v-model="file.content"
            :label="$t('components.aiSettings.skills.fileContent')"
            input-class="min-h-32 font-mono text-sm"
          />
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          @click="files.push({ path: '', content: '' })"
        >
          <Icon name="lucide:file-plus" />
          {{ $t('components.aiSettings.skills.addFile') }}
        </Button>
      </div>

      <DialogFooter>
        <Button
          type="button"
          variant="ghost"
          @click="open = false"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :loading="saving"
          @click="save"
        >
          {{ $t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
