<script setup lang="ts">
import InvoicesIcon from '~/assets/images/invoices.svg?component'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
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

const props = defineProps<{
  invoices: InvoiceResource[]
  loading?: boolean
}>()

function statusVariant(status: string | null) {
  switch (status) {
    case 'paid':
      return 'success'
    case 'refunded':
    case 'void':
      return 'secondary'
    case 'pending':
      return 'warning'
    default:
      return 'surface'
  }
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}
</script>

<template>
  <div class="overflow-hidden rounded-md border border-input">
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('labels.invoices.columns.date') }}</TableHead>
          <TableHead>{{ $t('labels.invoices.columns.amount') }}</TableHead>
          <TableHead>{{ $t('labels.invoices.columns.status') }}</TableHead>
          <TableHead class="w-32" />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableLoadingRow
          v-if="props.loading"
          :colspan="4"
          :rows="3"
        />

        <template v-else-if="props.invoices.length">
          <TableRow
            v-for="invoice in props.invoices"
            :key="invoice.id"
          >
            <TableCell class="text-muted">{{ formatDate(invoice.created_at) }}</TableCell>
            <TableCell class="font-medium">{{ invoice.total_formatted }}</TableCell>
            <TableCell>
              <Badge :variant="statusVariant(invoice.status)">
                {{ invoice.status_formatted ?? invoice.status }}
              </Badge>
            </TableCell>
            <TableCell class="text-right">
              <a
                v-if="invoice.invoice_url"
                :href="invoice.invoice_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 font-medium text-primary no-underline hover:underline"
              >
                <Icon
                  name="lucide:external-link"
                  size="0.875rem"
                />
                {{ $t('actions.invoices.view') }}
              </a>
            </TableCell>
          </TableRow>
        </template>

        <TableEmptyRow
          v-else
          :colspan="4"
          :icon="InvoicesIcon"
          :label="$t('labels.invoices.empty')"
        />
      </TableBody>
    </Table>
  </div>
</template>
