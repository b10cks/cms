<script setup lang="ts">
import { CheckboxField } from '~/components/ui/form'

defineProps<{ value: LinkSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()
</script>

<template>
  <div class="flex flex-col gap-6">
    <CheckboxField
      :model-value="value.asset_link_type"
      name="required"
      :label="$t('labels.blocks.fields.asset_link_type')"
      @update:model-value="emit('update:item-value', 'asset_link_type', $event)"
    />
    <CheckboxField
      :model-value="value.email_link_type"
      name="required"
      :label="$t('labels.blocks.fields.email_link_type')"
      @update:model-value="emit('update:item-value', 'email_link_type', $event)"
    />
    <CheckboxField
      :model-value="value.allow_target_blank"
      name="required"
      :label="$t('labels.blocks.fields.allow_target_blank')"
      @update:model-value="emit('update:item-value', 'allow_target_blank', $event)"
    />
  </div>
</template>
