<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useClipboard } from '@vueuse/core'
import { ref } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import { InputField } from '~/components/ui/form'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'

const props = defineProps<{ space: SpaceResource }>()

const { $t } = useI18n()
const { formatNumber, formatDateTime } = useFormat()
const { useTokensQuery, useCreateTokenMutation, useDeleteTokenMutation } = useTokens(props.space.id)
const { data: tokens } = useTokensQuery()

const { mutate: createToken, isPending: isGenerating } = useCreateTokenMutation()
const { mutate: deleteToken } = useDeleteTokenMutation()

const newTokenName = ref('')
const currentToken = ref('')

const { copy } = useClipboard({ source: currentToken })

const generateToken = async () => {
  if (newTokenName.value) {
    await createToken({
      name: newTokenName.value,
    })
    newTokenName.value = ''
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
  <Card variant="outline">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.accessTokens.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.accessTokens.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="grid gap-6">
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
          :disabled="!newTokenName || isGenerating"
          @click="generateToken"
        >
          <Icon
            v-if="isGenerating"
            name="lucide:loader"
            class="animate-spin"
          />
          {{
            isGenerating
              ? $t('labels.settings.accessTokens.generating')
              : $t('labels.settings.accessTokens.generateToken')
          }}
        </Button>
      </div>

      <div class="space-y-2">
        <h4 class="text-sm font-medium">{{ $t('labels.settings.accessTokens.existingTokens') }}</h4>
        <div class="overflow-hidden rounded-md">
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
              <TableRow
                v-for="token in tokens"
                :key="token.id"
              >
                <TableCell>{{ token.name }}</TableCell>
                <TableCell class="font-mono text-xs">
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
              <TableRow v-if="tokens?.length === 0">
                <TableCell
                  colspan="3"
                  class="text-center text-gray-500"
                >
                  {{ $t('labels.settings.accessTokens.noTokens') }}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
