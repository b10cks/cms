<script setup lang="ts">
import type { AiResolvedSkill, MentionItem } from '~/api/resources/ai'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import AiText from '~/components/ui/AiText.vue'
import { Button } from '~/components/ui/button'
import type { AiThreadChip } from '~/lib/aiThreads'
import { sameChip } from '~/lib/aiThreads'
import { aiSkillLabel } from '~/lib/aiSkills'

export interface AiThreadMessage {
  prompt: string
  skill: string | null
  configId: string | null
  mentions: MentionItem[]
}

/**
 * The thread composer on AiText: attached chips above the input (removable),
 * the screen as a ghost chip when it isn't attached, `@` mentions of entries,
 * assets and knowledge documents, `/` and empty-state chips to pick a skill,
 * and the model picker.
 */
const props = defineProps<{
  spaceId: string
  skills: readonly AiResolvedSkill[]
  /** Chips the screen offers that the thread doesn't have yet */
  ghostChips: readonly AiThreadChip[]
  /** Offer the skills as chips, for a thread without turns */
  showSkillChips: boolean
  running: boolean
}>()

const chips = defineModel<AiThreadChip[]>('chips', { required: true })
const skill = defineModel<string | null>('skill', { required: true })
const prompt = defineModel<string | null>('prompt', { required: true })

const emit = defineEmits<{
  send: [message: AiThreadMessage]
  cancel: []
}>()

const { t } = useI18n()
const text = ref<InstanceType<typeof AiText> | null>(null)

const { useDocumentsQuery } = useAiKnowledge(() => ({ kind: 'space' as const, id: props.spaceId }))
const { data: documents } = useDocumentsQuery()
const { useAssetsQuery } = useAssets(computed(() => props.spaceId))
const { data: assets } = useAssetsQuery({ per_page: 50, sort: '-updated_at' })

const mentionItems = computed<AiMentionItem[]>(() => [
  ...(assets.value?.data ?? []).map((asset) => ({
    id: asset.id,
    label: asset.filename,
    type: 'asset' as const,
    icon: 'lucide:image',
  })),
  ...(documents.value ?? []).map((document) => ({
    id: document.id,
    label: document.title,
    type: 'document' as const,
    icon: 'lucide:book-open',
  })),
])

const commands = computed(() =>
  props.skills.map((option) => ({ id: option.name, label: aiSkillLabel(option.name, t), description: option.description }))
)

const pickSkill = (name: string) => {
  skill.value = name
  text.value?.focus()
}

const attach = (chip: AiThreadChip) => {
  if (!chips.value.some((attached) => sameChip(attached, chip))) chips.value = [...chips.value, chip]
}

const detach = (chip: AiThreadChip) => {
  chips.value = chips.value.filter((attached) => !sameChip(attached, chip))
}

const CHIP_ICONS = {
  content: 'lucide:file-text',
  data_source: 'lucide:database',
  asset: 'lucide:image',
  document: 'lucide:book-open',
} as const satisfies Record<AiThreadChip['type'], string>

const onSend = (value: string, _files: never[], configId: string | null, mentions: MentionItem[]) => {
  emit('send', { prompt: value, skill: skill.value, configId, mentions })
  skill.value = null
}

defineExpose({ focus: () => text.value?.focus() })
</script>

<template>
  <div class="grid gap-2">
    <div
      v-if="showSkillChips && skills.length > 0 && !skill"
      class="flex flex-wrap gap-1.5"
      :aria-label="$t('components.aiThreads.suggestedSkills')"
      role="group"
    >
      <Button
        v-for="option in skills"
        :key="option.name"
        size="sm"
        variant="outline"
        :title="option.description"
        @click="pickSkill(option.name)"
      >
        <Icon
          name="lucide:sparkles"
          class="text-ai"
        />
        {{ aiSkillLabel(option.name, t) }}
      </Button>
    </div>

    <AiText
      ref="text"
      v-model="prompt"
      :space-id="spaceId"
      :placeholder="skill ? $t('components.aiThreads.extraInstructions') : $t('components.aiThreads.placeholder')"
      :loading="running"
      :extra-mention-items="mentionItems"
      :commands="commands"
      :allow-empty="!!skill"
      direct-emit
      @send="onSend"
      @cancel="emit('cancel')"
      @command="(item) => pickSkill(item.id)"
    >
      <template
        v-if="chips.length > 0 || ghostChips.length > 0 || skill"
        #context
      >
        <span
          v-if="skill"
          class="inline-flex h-7 items-center gap-1 rounded-md bg-ai-background/20 pr-1 pl-2 text-sm text-ai"
        >
          <Icon name="lucide:sparkles" />
          {{ aiSkillLabel(skill, t) }}
          <button
            type="button"
            class="flex size-5 items-center justify-center rounded-sm hover:bg-ai-background/40"
            :aria-label="$t('components.aiThreads.removeSkill')"
            @click="skill = null"
          >
            <Icon name="lucide:x" />
          </button>
        </span>
        <span
          v-for="chip in chips"
          :key="`${chip.type}:${chip.id}`"
          class="inline-flex h-7 max-w-full items-center gap-1 rounded-md bg-chip pr-1 pl-2 text-sm text-chip-foreground"
          data-chip
        >
          <Icon :name="CHIP_ICONS[chip.type]" />
          <span class="truncate">{{ chip.label }}</span>
          <button
            type="button"
            class="flex size-5 shrink-0 items-center justify-center rounded-sm hover:bg-input"
            :aria-label="$t('components.aiThreads.removeChip', { name: chip.label })"
            @click="detach(chip)"
          >
            <Icon name="lucide:x" />
          </button>
        </span>
        <button
          v-for="chip in ghostChips"
          :key="`ghost:${chip.type}:${chip.id}`"
          type="button"
          class="inline-flex h-7 max-w-full items-center gap-1 rounded-md border border-dashed border-border-strong px-2 text-sm text-muted hover:text-foreground"
          :aria-label="$t('components.aiThreads.attachChip', { name: chip.label })"
          data-ghost-chip
          @click="attach(chip)"
        >
          <Icon name="lucide:plus" />
          <span class="truncate">{{ chip.label }}</span>
        </button>
      </template>
    </AiText>
  </div>
</template>
