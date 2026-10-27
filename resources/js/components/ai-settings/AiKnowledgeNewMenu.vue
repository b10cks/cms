<script setup lang="ts">
import type { AiOwner } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { InputField } from '~/components/ui/form'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'

/** "New" menu of the knowledge list: a style guide or document opens its editor, a glossary asks for a name first. */
const props = withDefaults(
  defineProps<{
    owner: AiOwner
    size?: 'default' | 'sm'
  }>(),
  { size: 'default' }
)

const router = useRouter()
const knowledge = useAiKnowledge(() => props.owner)
const { mutateAsync: createGlossary, isPending } = knowledge.useCreateGlossaryMutation()
const routes = computed(() => aiSettingsRoutes(props.owner))

const glossaryDialogOpen = ref(false)
const glossaryName = ref('')

const newDocument = (type: 'style' | 'reference') =>
  void router.push(routes.value.document('new', { type }))

const openGlossaryDialog = () => {
  glossaryName.value = ''
  glossaryDialogOpen.value = true
}

const submitGlossary = async () => {
  const name = glossaryName.value.trim()
  if (!name) return
  const { data } = await createGlossary(name)
  glossaryDialogOpen.value = false
  void router.push(routes.value.glossary(data.id))
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button
        variant="primary"
        :size="size"
      >
        <Icon name="lucide:plus" />
        {{ $t('components.aiSettings.knowledge.new') }}
        <Icon name="lucide:chevron-down" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuItem @select="newDocument('style')">
        <Icon name="lucide:pen-line" />
        {{ $t('components.aiSettings.knowledge.types.style') }}
      </DropdownMenuItem>
      <DropdownMenuItem @select="newDocument('reference')">
        <Icon name="lucide:file-text" />
        {{ $t('components.aiSettings.knowledge.types.reference') }}
      </DropdownMenuItem>
      <DropdownMenuItem @select="openGlossaryDialog">
        <Icon name="lucide:book-a" />
        {{ $t('components.aiSettings.knowledge.types.glossary') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <Dialog v-model:open="glossaryDialogOpen">
    <DialogContent class="max-w-md">
      <form
        class="grid gap-4"
        @submit.prevent="submitGlossary"
      >
        <DialogHeader>
          <DialogTitle>{{ $t('components.aiSettings.glossaries.create') }}</DialogTitle>
        </DialogHeader>
        <InputField
          id="new-glossary-name"
          v-model="glossaryName"
          name="name"
          :label="$t('components.aiSettings.columns.name')"
          required
          auto-focus
        />
        <DialogFooter>
          <Button
            type="button"
            variant="ghost"
            @click="glossaryDialogOpen = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="primary"
            :disabled="!glossaryName.trim()"
            :loading="isPending"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
