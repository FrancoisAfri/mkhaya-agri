<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface LedgerRow { journal_id: number; journal_no: string; date: string; description: string | null; source: string; status: string; narration: string | null; debit: number; credit: number; balance: number }
interface Ledger { account: { id: number; code: string; name: string; type: string; normal_balance: string }; from: string; to: string; opening: number; rows: LedgerRow[]; debit_total: number; credit_total: number; closing: number }
const props = defineProps<{ ledger: Ledger | null; accounts: { id: number; label: string }[] }>()

const accountId = ref(props.ledger?.account.id ?? props.accounts[0]?.id ?? null)
const from = ref(props.ledger?.from ?? new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10))
const to = ref(props.ledger?.to ?? new Date().toISOString().slice(0, 10))
function reload() { router.get(route('finance.accounting.ledger'), { account_id: accountId.value, from: from.value, to: to.value }, { preserveState: true, preserveScroll: true }) }
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { posted: 'success', reversed: 'error', draft: 'warning' }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
      <h2 class="text-h5 mb-0">{{ t('acc.account_ledger') }}</h2>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VSelect v-model="accountId" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.account')" density="compact" hide-details style="max-width: 340px" @update:model-value="reload" />
        <VTextField v-model="from" type="date" :label="t('acc.from')" density="compact" hide-details style="max-width: 180px" @update:model-value="reload" />
        <VTextField v-model="to" type="date" :label="t('acc.to')" density="compact" hide-details style="max-width: 180px" @update:model-value="reload" />
      </VCardText>
    </VCard>

    <VCard v-if="ledger" border>
      <VCardText class="d-flex justify-space-between align-center flex-wrap gap-2">
        <div>
          <div class="text-h6">{{ ledger.account.code }} · {{ ledger.account.name }}</div>
          <VChip size="x-small" variant="tonal" class="text-capitalize">{{ ledger.account.type }} · {{ ledger.account.normal_balance }}-{{ t('acc.normal_word') }}</VChip>
        </div>
        <div class="text-right">
          <div class="text-caption text-medium-emphasis">{{ t('acc.closing_balance') }}</div>
          <div class="text-h6" :class="ledger.closing >= 0 ? 'text-success' : 'text-error'">{{ money(ledger.closing) }}</div>
        </div>
      </VCardText>
      <VTable density="compact">
        <thead><tr><th>{{ t('acc.date') }}</th><th>{{ t('acc.journal_no') }}</th><th>{{ t('acc.description') }}</th><th>{{ t('acc.source') }}</th><th class="text-right">{{ t('acc.debit') }}</th><th class="text-right">{{ t('acc.credit') }}</th><th class="text-right">{{ t('acc.balance') }}</th></tr></thead>
        <tbody>
          <tr class="font-weight-medium bg-surface-light"><td colspan="6">{{ t('acc.opening_balance') }}</td><td class="text-right">{{ money(ledger.opening) }}</td></tr>
          <tr v-for="(r, i) in ledger.rows" :key="i">
            <td class="text-caption">{{ r.date }}</td>
            <td>{{ r.journal_no }} <VChip v-if="r.status !== 'posted'" size="x-small" :color="statusColor[r.status]" variant="tonal" class="ms-1 text-capitalize">{{ r.status }}</VChip></td>
            <td>{{ r.description || r.narration || '—' }}</td>
            <td><VChip size="x-small" variant="tonal" class="text-capitalize">{{ r.source }}</VChip></td>
            <td class="text-right">{{ r.debit ? money(r.debit) : '' }}</td>
            <td class="text-right">{{ r.credit ? money(r.credit) : '' }}</td>
            <td class="text-right font-weight-medium">{{ money(r.balance) }}</td>
          </tr>
          <tr v-if="!ledger.rows.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('acc.no_movement_period') }}</td></tr>
        </tbody>
        <tfoot>
          <tr class="font-weight-bold"><td colspan="4" class="text-right">{{ t('acc.period_movement') }}</td><td class="text-right">{{ money(ledger.debit_total) }}</td><td class="text-right">{{ money(ledger.credit_total) }}</td><td class="text-right">{{ money(ledger.closing) }}</td></tr>
        </tfoot>
      </VTable>
    </VCard>
    <VCard v-else border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.select_account_ledger') }}</VCardText></VCard>
  </div>
</template>
