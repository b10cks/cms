<script setup lang="ts">
import Markdown from '~/components/Markdown.vue'
import { Label } from '~/components/ui/form'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { Textarea } from '~/components/ui/textarea'

/** Markdown source with a rendered preview tab. */
const model = defineModel<string>({ required: true })

defineProps<{
  id: string
  label: string
  disabled?: boolean
}>()

const mode = ref<'write' | 'preview'>('write')
</script>

<template>
  <Tabs
    v-model="mode"
    class="grid gap-2"
  >
    <div class="flex items-center justify-between gap-4">
      <Label
        :for="id"
        :label="label"
      />
      <TabsList class="h-8">
        <TabsTrigger
          value="write"
          class="px-2 py-1"
        >
          {{ $t('components.aiSettings.markdown.write') }}
        </TabsTrigger>
        <TabsTrigger
          value="preview"
          class="px-2 py-1"
        >
          {{ $t('components.aiSettings.markdown.preview') }}
        </TabsTrigger>
      </TabsList>
    </div>
    <TabsContent
      value="write"
      class="mt-0"
    >
      <Textarea
        :id="id"
        v-model="model"
        :disabled="disabled"
        class="min-h-96 font-mono text-sm"
        auto-size
      />
    </TabsContent>
    <TabsContent
      value="preview"
      class="mt-0 min-h-96 rounded-md border border-border px-4 py-3"
    >
      <Markdown
        v-if="model.trim()"
        :content="model"
      />
      <p
        v-else
        class="text-sm text-muted"
      >
        {{ $t('components.aiSettings.markdown.empty') }}
      </p>
    </TabsContent>
  </Tabs>
</template>
