<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** Vision, reasoning and tool use of a model, as small icons; unsupported ones are left out. */
const props = defineProps<{
  capabilities: { supports_vision: boolean; supports_reasoning: boolean; supports_tools: boolean }
}>()

const { t } = useI18n()

const items = computed(() =>
  [
    { key: 'vision', icon: 'lucide:eye', on: props.capabilities.supports_vision },
    { key: 'reasoning', icon: 'lucide:brain', on: props.capabilities.supports_reasoning },
    { key: 'tools', icon: 'lucide:wrench', on: props.capabilities.supports_tools },
  ]
    .filter((item) => item.on)
    .map((item) => ({ ...item, label: t(`components.aiModelSelector.capabilities.${item.key}`) }))
)
</script>

<template>
  <span class="inline-flex shrink-0 items-center gap-1">
    <span
      v-for="item in items"
      :key="item.key"
      class="inline-flex size-5 items-center justify-center rounded bg-secondary text-muted"
      :title="item.label"
    >
      <Icon
        :name="item.icon"
        size="0.8125rem"
      />
      <span class="sr-only">{{ item.label }}</span>
    </span>
  </span>
</template>
