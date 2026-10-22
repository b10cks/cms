<script setup lang="ts">
import { useClipboard } from '@vueuse/core'
import { ref } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField } from '~/components/ui/form'
import { SettingsSection } from '~/components/ui/settings-section'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

const props = defineProps<{ space: SpaceResource }>()

const { $t } = useI18n()
const { formatNumber, formatDateTime } = useFormat()
const { useTokensQuery, useCreateTokenMutation, useDeleteTokenMutation } = useTokens(props.space.id)
const { data: tokens, isLoading: isLoadingTokens } = useTokensQuery()

const { mutate: createToken, isPending: isGenerating } = useCreateTokenMutation()
const { mutate: deleteToken } = useDeleteTokenMutation()

const newTokenName = ref('')
const newTokenPreview = ref<boolean | 'indeterminate'>(false)
const currentToken = ref('')

const { copy } = useClipboard({ source: currentToken })

const generateToken = async () => {
  if (newTokenName.value) {
    await createToken({
      name: newTokenName.value,
      abilities: newTokenPreview.value === true ? ['*:read', '*:preview'] : ['*:read'],
    })
    newTokenName.value = ''
    newTokenPreview.value = false
  }
}

const removeToken = (id: string) => {
  deleteToken(id)
}

const copyToken = (token: string) => {
  currentToken.value = token
  copy(token)
  toast.success($t('labels.settings.accessTokens.tokenCopied'))
}
</script>

<template>
  <SettingsSection
    wide
    :title="$t('labels.settings.accessTokens.title')"
    :description="$t('labels.settings.accessTokens.description')"
  >
    <div class="flex items-end gap-4">
      <div class="flex-1 space-y-2">
        <InputField
          v-model="newTokenName"
          name="token-name"
          :label="$t('labels.settings.accessTokens.tokenName')"
          :placeholder="$t('labels.settings.accessTokens.tokenNamePlaceholder')"
        />
      </div>
      <Button
        :loading="isGenerating"
        :disabled="!newTokenName"
        @click="generateToken"
      >
        {{
          isGenerating
            ? $t('labels.settings.accessTokens.generating')
            : $t('labels.settings.accessTokens.generateToken')
        }}
      </Button>
    </div>

    <CheckboxField
      v-model="newTokenPreview"
      name="token-preview"
      :label="$t('labels.settings.accessTokens.allowPreview')"
      :description="$t('labels.settings.accessTokens.allowPreviewDescription')"
    />

    <div class="space-y-2">
      <h4 class="text-sm font-semibold text-primary">{{ $t('labels.settings.accessTokens.existingTokens') }}</h4>
      <div class="overflow-hidden rounded-lg border border-border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{{ $t('labels.settings.accessTokens.name') }}</TableHead>
              <TableHead>{{ $t('labels.settings.accessTokens.token') }}</TableHead>
              <TableHead class="text-right">{{
                $t('labels.settings.accessTokens.executionCount')
              }}</TableHead>
              <TableHead>{{ $t('labels.settings.accessTokens.lastUsedAt') }}</TableHead>
              <TableHead>{{ $t('labels.settings.accessTokens.createdAt') }}</TableHead>
              <TableHead class="w-24" />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableLoadingRow
              v-if="isLoadingTokens"
              :colspan="6"
              :rows="3"
            />
            <TableRow
              v-for="token in tokens"
              :key="token.id"
            >
              <TableCell>{{ token.name }}</TableCell>
              <TableCell class="font-mono text-sm">
                {{ token.token.substring(0, 8) }}...{{
                  token.token.substring(token.token.length - 4)
                }}
              </TableCell>
              <TableCell class="text-right">{{ formatNumber(token.execution_count) }}</TableCell>
              <TableCell>{{
                token.last_used_at ? formatDateTime(token.last_used_at) : '–'
              }}</TableCell>
              <TableCell>{{ formatDateTime(token.created_at) }}</TableCell>
              <TableCell>
                <div class="flex space-x-1">
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="copyToken(token.token)"
                  >
                    <Icon name="lucide:copy" />
                    <span class="sr-only">{{ $t('actions.copy') }}</span>
                  </Button>
                  <Button
                    variant="destructive"
                    size="icon"
                    @click="removeToken(token.id)"
                  >
                    <Icon name="lucide:trash-2" />
                    <span class="sr-only">{{ $t('actions.delete') }}</span>
                  </Button>
                </div>
              </TableCell>
            </TableRow>
            <TableEmptyRow
              v-if="!isLoadingTokens && tokens?.length === 0"
              :colspan="6"
              :label="$t('labels.settings.accessTokens.noTokens')"
            />
          </TableBody>
        </Table>
      </div>
    </div>
  </SettingsSection>
</template>
