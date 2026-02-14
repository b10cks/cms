<script setup lang="ts">
import { CheckboxField, InputField } from '~/components/ui/form';
import { ContentResource } from '~/types/contents';

const content = defineModel<ContentResource>()
</script>

<template>
  <div class="flex flex-col gap-6">
    <InputField
      v-if="content"
      v-model="content.name"
      :label="$t('labels.contents.fields.name')"
      name="name"
    />
    <InputField
      v-if="content"
      v-model="content.slug"
      :label="$t('labels.contents.fields.slug')"
      name="slug"
    />
    <CheckboxField
      v-if="content"
      v-model="content.settings.disablePreview"
      :label="$t('labels.contents.settings.disablePreview')"
      name="disablePreview"
      :description="$t('labels.contents.settings.disablePreviewDescription')"
    />
  </div>
</template>
