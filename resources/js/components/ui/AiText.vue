<script setup lang="ts">
import type { AiReasoningEffort, MentionItem } from '~/api/resources/ai'
import AiConfigPicker from '~/components/ai/AiConfigPicker.vue'
import AiReasoningPicker from '~/components/ai/AiReasoningPicker.vue'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import type { TipTapCommandItem } from '~/components/ui/input-group/InputGroupTipTap.vue'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupTipTap,
} from '~/components/ui/input-group'
import { useAiMentions } from '~/composables/useAiMentions'
import { useAiReasoning } from '~/lib/aiReasoning'

export interface AiPromptPayload {
  prompt: string
  configId: string | null
  /** Null for models that don't reason */
  reasoningEffort: AiReasoningEffort | null
  mentions: MentionItem[]
}

/**
 * A prompt box with `@` mentions, the config picker and, for models that
 * reason, the reasoning bars. It only collects the prompt; the parent runs it
 * and passes `loading` while it does.
 */
const modelValue = defineModel<string | null>()
const editorValue = computed({
  get: () => modelValue.value ?? '',
  set: (value: string) => {
    modelValue.value = value
  },
})

const emit = defineEmits<{
  send: [payload: AiPromptPayload]
  cancel: []
  command: [item: TipTapCommandItem]
}>()

const props = withDefaults(
  defineProps<{
    placeholder?: string
    loading?: boolean
    spaceId: string
    extraMentionItems?: AiMentionItem[]
    /** `/` commands at the start of the input */
    commands?: TipTapCommandItem[]
    /** Send without text, when a picked skill is the task */
    allowEmpty?: boolean
  }>(),
  {
    placeholder: '',
    loading: false,
    extraMentionItems: () => [],
    commands: () => [],
    allowEmpty: false,
  }
)

const { t } = useI18n()
const { useMentionItemsQuery } = useAiMentions(toRef(props, 'spaceId'))
const { items: mentionItems } = useMentionItemsQuery()

const mergedMentionItems = computed(() => {
  const seen = new Set<string>()
  return [...mentionItems.value, ...props.extraMentionItems].filter((item) => {
    const key = `${item.type}:${item.id}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
})

const tiptapRef = ref<InstanceType<typeof InputGroupTipTap> | null>(null)
const configId = ref<string | null>(null)
const { modelInfo, level: reasoning, effort } = useAiReasoning(() => props.spaceId, configId)

const canSend = computed(
  () => ((modelValue.value?.trim()?.length ?? 0) > 0 || props.allowEmpty) && !props.loading && !!configId.value
)

const clear = () => {
  modelValue.value = null
  tiptapRef.value?.clear()
}

const handleSend = () => {
  if (!canSend.value) return

  const prompt = (tiptapRef.value?.getTextWithMentions() ?? modelValue.value ?? '').trim()
  if (!prompt && !props.allowEmpty) return

  const mentions = (tiptapRef.value?.getMentions() ?? []).map((mention) => ({
    type: mention.type,
    id: mention.id,
    content: null,
    label: mention.label,
  }))

  emit('send', { prompt, configId: configId.value, reasoningEffort: effort.value, mentions })
  clear()
}

defineExpose({
  clear,
  focus: () => tiptapRef.value?.focus(),
})
</script>

<template>
  <InputGroup
    class="relative rounded-xl bg-popover transition-colors"
    :class="loading ? 'ring-1 ring-ai/30' : 'focus-within:ring-1 focus-within:ring-ai/30'"
  >
    <InputGroupAddon
      v-if="$slots.context"
      align="block-start"
      class="flex-wrap gap-1.5"
    >
      <slot name="context" />
    </InputGroupAddon>
    <InputGroupTipTap
      ref="tiptapRef"
      v-model="editorValue"
      :placeholder="placeholder || t('components.aiText.placeholder')"
      :disabled="loading"
      :mention-items="mergedMentionItems"
      :commands="commands"
      @submit="handleSend"
      @command="(item) => emit('command', item)"
    />

    <InputGroupAddon
      align="block-end"
      class="gap-1"
    >
      <AiConfigPicker
        v-model="configId"
        :space-id="spaceId"
        :disabled="loading"
      />
      <AiReasoningPicker
        v-if="modelInfo?.supports_reasoning"
        v-model="reasoning"
        :required="modelInfo.reasoning_required"
        :disabled="loading"
      />
      <span class="flex-1" />

      <InputGroupButton
        v-if="!loading"
        type="button"
        variant="ai"
        size="round"
        :disabled="!canSend"
        @click="handleSend"
      >
        <Icon name="lucide:arrow-up" />
        <span class="sr-only">{{ $t('actions.send') }}</span>
      </InputGroupButton>

      <InputGroupButton
        v-else
        type="button"
        variant="ghost"
        size="round"
        class="animate-pulse"
        @click="emit('cancel')"
      >
        <Icon name="lucide:circle-stop" />
        <span class="sr-only">{{ $t('actions.cancel') }}</span>
      </InputGroupButton>
    </InputGroupAddon>
  </InputGroup>
</template>
