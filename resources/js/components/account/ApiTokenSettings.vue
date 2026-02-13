<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useClipboard } from '@vueuse/core'
import dayjs from 'dayjs'
import { toast } from 'vue-sonner'

import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField, SelectField } from '~/components/ui/form'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import { useAlertDialog } from '~/composables/useAlertDialog'

const { t } = useI18n()
const { formatDateTime } = useFormat()
const { alert } = useAlertDialog()
const { useTokensQuery, useCreateTokenMutation, useDeleteTokenMutation } = usePersonalAccessTokens()

const { data: tokensResponse, isLoading } = useTokensQuery()
const { mutateAsync: createToken, isPending: isCreating } = useCreateTokenMutation()
const { mutate: deleteToken, isPending: isDeleting } = useDeleteTokenMutation()

const tokenName = ref('')
const expiresIn = ref('31')
const showTokenDialog = ref(false)
const newTokenValue = ref('')
const { copy } = useClipboard({ source: newTokenValue })

const tokens = computed(() => tokensResponse.value?.data ?? [])

const expiryOptions = computed(() => [
  { value: '7', label: t('labels.account.apiTokens.expiresIn', { days: 7 }) },
  { value: '31', label: t('labels.account.apiTokens.expiresIn', { days: 31 }) },
  { value: '180', label: t('labels.account.apiTokens.expiresIn', { days: 180 }) },
  { value: '365', label: t('labels.account.apiTokens.expiresIn', { days: 365 }) },
])

const isExpired = (token: PersonalAccessToken) => {
  if (!token.expires_at) return false
  return dayjs(token.expires_at).isBefore(dayjs())
}

const handleCreateToken = async () => {
  if (!tokenName.value || isCreating.value) return

  const expiresAt = dayjs().add(Number(expiresIn.value), 'day').toISOString()

  try {
    const response = await createToken({
      name: tokenName.value,
      expires_at: expiresAt,
    })

    newTokenValue.value = response.plain_text_token
    showTokenDialog.value = true
    tokenName.value = ''
  } catch (_) {
    // handled in mutation
  }
}

const handleCopyToken = async () => {
  await copy()
  toast.success(t('labels.account.apiTokens.copied') as string)
}

const handleDeleteToken = async (id: number, tokenName: string) => {
  if (isDeleting.value) return

  const confirmed = await alert.confirm(t('messages.deleteTokenConfirm', { name: tokenName }), {
    title: t('labels.account.apiTokens.deleteConfirmTitle'),
    confirmLabel: t('actions.delete'),
    variant: 'destructive',
  })

  if (confirmed) {
    deleteToken(id)
  }
}
</script>

<template>
  <Card variant="outline">
    <CardHeader>
      <CardTitle>{{ $t('labels.account.apiTokens.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.account.apiTokens.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="grid gap-6">
      <div class="grid gap-4 lg:grid-cols-[1.25fr_0.75fr_auto]">
        <InputField
          v-model="tokenName"
          name="api-token-name"
          :label="$t('labels.account.apiTokens.nameLabel')"
          :placeholder="$t('labels.account.apiTokens.namePlaceholder')"
        />

        <SelectField
          v-model="expiresIn"
          :label="$t('labels.account.apiTokens.expiresLabel')"
          :placeholder="$t('labels.account.apiTokens.expiresPlaceholder')"
          :options="expiryOptions"
        />

        <div class="flex items-end">
          <Button
            class="w-full"
            :disabled="!tokenName || isCreating"
            @click="handleCreateToken"
          >
            <Icon
              v-if="isCreating"
              name="lucide:loader"
              class="animate-spin"
            />
            {{
              isCreating
                ? $t('labels.account.apiTokens.generating')
                : $t('labels.account.apiTokens.generate')
            }}
          </Button>
        </div>
      </div>

      <div class="space-y-2">
        <h4 class="text-sm font-medium">
          {{ $t('labels.account.apiTokens.existingTokens') }}
        </h4>
        <div class="overflow-hidden rounded-md border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{{ $t('labels.account.apiTokens.table.name') }}</TableHead>
                <TableHead>{{ $t('labels.account.apiTokens.table.createdAt') }}</TableHead>
                <TableHead>{{ $t('labels.account.apiTokens.table.expiresAt') }}</TableHead>
                <TableHead>{{ $t('labels.account.apiTokens.table.lastUsedAt') }}</TableHead>
                <TableHead>{{ $t('labels.account.apiTokens.table.status') }}</TableHead>
                <TableHead class="w-24" />
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="token in tokens"
                :key="token.id"
                :class="{
                  'opacity-70': isExpired(token),
                }"
              >
                <TableCell class="font-medium">{{ token.name }}</TableCell>
                <TableCell>{{ formatDateTime(token.created_at) }}</TableCell>
                <TableCell>
                  {{ token.expires_at ? formatDateTime(token.expires_at) : $t('labels.never') }}
                </TableCell>
                <TableCell>
                  {{ token.last_used_at ? formatDateTime(token.last_used_at) : $t('labels.never') }}
                </TableCell>
                <TableCell>
                  <Badge
                    v-if="isExpired(token)"
                    variant="warning"
                    size="sm"
                  >
                    {{ $t('labels.account.apiTokens.expired') }}
                  </Badge>
                  <Badge
                    v-else
                    variant="success"
                    size="sm"
                  >
                    {{ $t('labels.account.apiTokens.active') }}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div class="flex justify-end">
                    <Button
                      variant="destructive"
                      size="icon"
                      @click="handleDeleteToken(token.id, token.name)"
                    >
                      <Icon name="lucide:trash-2" />
                      <span class="sr-only">{{ $t('actions.delete') }}</span>
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
              <TableEmptyRow
                v-if="!isLoading && tokens.length === 0"
                :colspan="6"
                :label="$t('labels.account.apiTokens.empty')"
              />
            </TableBody>
          </Table>
        </div>
      </div>
    </CardContent>
  </Card>

  <Dialog v-model:open="showTokenDialog">
    <DialogContent class="max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.account.apiTokens.tokenGenerated')"
        :description="$t('labels.account.apiTokens.tokenWarning')"
      />
      <div class="space-y-4">
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
          <div class="text-xs font-semibold tracking-wide text-muted uppercase">
            {{ $t('labels.account.apiTokens.newTokenLabel') }}
          </div>
          <div class="mt-2 font-mono text-sm break-all text-foreground">
            {{ newTokenValue }}
          </div>
        </div>
      </div>
      <DialogFooter class="mt-6">
        <Button
          variant="outline"
          @click="handleCopyToken"
        >
          <Icon name="lucide:copy" />
          {{ $t('actions.copy') }}
        </Button>
        <Button @click="showTokenDialog = false">
          {{ $t('actions.close') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
