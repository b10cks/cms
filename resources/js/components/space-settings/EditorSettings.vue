<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import { Label } from '~/components/ui/form'
import SettingsTable, { type ColumnDefinition } from '~/components/ui/settings-table.vue'
import { Switch } from '~/components/ui/switch'

const { $t } = useI18n()
const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()

const props = defineProps<{ space: SpaceResource }>()

const environments = ref(
  deepClone(
    Array.isArray(props.space.settings?.environments) ? props.space.settings!.environments : []
  )
)
const visualEditorEnabled = ref(props.space.settings.visual_editor)

const columns: ColumnDefinition[] = [
  {
    key: 'name',
    label: $t('labels.settings.editor.name'),
    type: 'text',
    placeholder: $t('labels.settings.editor.namePlaceholder'),
    required: true,
  },
  {
    key: 'url',
    label: $t('labels.settings.editor.url'),
    type: 'text',
    placeholder: $t('labels.settings.editor.urlPlaceholder'),
    required: true,
  },
]

const newItemTemplate = {
  name: '',
  url: '',
}

const removeEnvironment = (index: number) => {
  environments.value.splice(index, 1)
}

const openInTab = (url: string) => {
  window.open(url, '_blank')
}

const saveSettings = async () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        environments: environments.value,
        visual_editor: visualEditorEnabled.value,
      },
    },
  })
}
</script>

<template>
  <Card variant="outline">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.editor.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.editor.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-sm font-medium">{{ $t('labels.settings.editor.environments') }}</h4>
            <p class="text-xs text-muted">
              {{ $t('labels.settings.editor.environmentsDescription') }}
            </p>
          </div>
        </div>
        <SettingsTable
          v-model:items="environments"
          :columns="columns"
          :new-item-template="newItemTemplate"
          :allow-sort="true"
          @add="(item) => environments.push(item)"
          @remove="removeEnvironment"
          @update:items="(items) => (environments = items)"
        >
          <template #actions="{ item }">
            <Button
              variant="ghost"
              size="icon"
              @click="openInTab(item.url)"
            >
              <Icon name="lucide:external-link" />
              <span class="sr-only">{{ $t('actions.open') }}</span>
            </Button>
          </template>
        </SettingsTable>
      </div>

      <div class="space-y-4">
        <div>
          <h4 class="text-sm font-medium">{{ $t('labels.settings.editor.visualEditor') }}</h4>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.editor.visualEditorDescription') }}
          </p>
        </div>

        <div class="flex items-center space-x-2">
          <Switch
            id="visual-editor"
            v-model="visualEditorEnabled"
            aria-label="Enable Visual Editor"
          />
          <Label
            for="visual-editor"
            class="text-sm font-medium"
            :label="$t('labels.settings.editor.enableVisualEditor')"
          />
        </div>
        <p class="text-xs text-muted">
          {{ $t('labels.settings.editor.visualEditorInfo') }}
        </p>
      </div>
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        @click="saveSettings"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
