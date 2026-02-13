<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'

interface HtmlClass {
  name: string
  className: string
  css?: string
}

const props = defineProps<{
  name: string
  value: RichTextSchema
}>()

const emit = defineEmits<{
  'update:item-value': [key: string, value: unknown]
}>()

const htmlClasses = ref<HtmlClass[]>(props.value.html_classes || [])
const newClass = ref<HtmlClass>({ name: '', className: '', css: '' })
const showAddForm = ref(false)

const updateItemValue = (key: string, value: unknown) => {
  emit('update:item-value', key, value)
}

const addHtmlClass = () => {
  if (!newClass.value.name || !newClass.value.className) return

  htmlClasses.value.push({ ...newClass.value })
  updateItemValue('html_classes', htmlClasses.value)
  newClass.value = { name: '', className: '', css: '' }
  showAddForm.value = false
}

const removeHtmlClass = (index: number) => {
  htmlClasses.value.splice(index, 1)
  updateItemValue('html_classes', htmlClasses.value)
}

const updateHtmlClass = (index: number, key: keyof HtmlClass, value: string) => {
  htmlClasses.value[index] = {
    ...htmlClasses.value[index],
    [key]: value,
  }
  updateItemValue('html_classes', htmlClasses.value)
}

watch(
  () => props.value.html_classes,
  (newClasses) => {
    htmlClasses.value = newClasses || []
  },
  { deep: true }
)
</script>

<template>
  <div class="space-y-4">
    <div class="space-y-2">
      <h4 class="text-sm font-semibold">HTML Classes</h4>
      <div class="space-y-2">
        <div
          v-for="(htmlClass, index) in htmlClasses"
          :key="index"
          class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
        >
          <div class="flex items-end gap-2">
            <InputField
              :model-value="htmlClass.name"
              name="class-name"
              label="Display Name"
              placeholder="e.g., Highlight"
              @update:model-value="(v) => updateHtmlClass(index, 'name', v as string)"
            />
            <InputField
              :model-value="htmlClass.className"
              name="class-value"
              label="CSS Class"
              placeholder="e.g., bg-yellow-100"
              @update:model-value="(v) => updateHtmlClass(index, 'className', v as string)"
            />
            <Button
              type="button"
              size="icon"
              variant="ghost"
              class="hover:text-destructive"
              @click="removeHtmlClass(index)"
            >
              <Icon
                name="lucide:trash-2"
                size="0.9rem"
              />
            </Button>
          </div>
          <InputField
            :model-value="htmlClass.css"
            name="css-preview"
            label="CSS Preview (optional)"
            placeholder="e.g., background-color: yellow;"
            @update:model-value="(v) => updateHtmlClass(index, 'css', v as string)"
          />
        </div>
      </div>

      <div
        v-if="showAddForm"
        class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
      >
        <InputField
          v-model="newClass.name"
          name="new-class-name"
          label="Display Name"
          placeholder="e.g., Highlight"
        />
        <InputField
          v-model="newClass.className"
          name="new-class-value"
          label="CSS Class"
          placeholder="e.g., bg-yellow-100"
        />
        <InputField
          v-model="newClass.css"
          name="new-css-preview"
          label="CSS Preview (optional)"
          placeholder="e.g., background-color: yellow;"
        />
        <div class="flex gap-2">
          <Button
            type="button"
            size="sm"
            @click="addHtmlClass"
          >
            Add Class
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            @click="showAddForm = false"
          >
            Cancel
          </Button>
        </div>
      </div>

      <Button
        v-if="!showAddForm"
        type="button"
        size="sm"
        variant="outline"
        @click="showAddForm = true"
      >
        <Icon
          name="lucide:plus"
          size="0.9rem"
        />
        Add HTML Class
      </Button>
    </div>
  </div>
</template>
