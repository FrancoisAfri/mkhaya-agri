<script lang="ts" setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Entry { date: string; ref: string; type: string; charge: number; credit: number; balance: number }
const props = defineProps<{ statement: { party: { id: number; name: string; code: string }; from: string; to: string; opening: number; rows: Entry[]; closing: number } }>()

const from = ref(props.statement.from)
const to = ref(props.statement.to)
function reload() { router.get(route('finance.accounting.parties.statement', props.statement.party.id), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true }) }
const pdfUrl = () => route('finance.accounting.parties.statement.pdf', props.statement.party.id) + `?from=${from.value}&to=${to.value}`
const confirm = ref()
function emailStatement() {
  confirm.value.open(
    { title: t('acc.email_statement'), message: t('acc.email_statement_confirm', { name: props.statement.party.name }), confirmText: t('acc.send'), confirmColor: 'primary', icon: 'tabler-mail' },
    () => useForm({ from: from.value, to: to.value }).post(route('finance.accounting.parties.statement.email', props.statement.party.id), { preserveScroll: true }),
  )
}
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_statement') }}</div>
        <h2 class="text-h5 mb-0">{{ statement.party.name }}</h2>
        <div class="text-caption text-medium-emphasis">{{ statement.party.code }}</div>
      </div>
      <div class="d-flex gap-3 align-center">
        <VTextField v-model="from" type="date" :label="t('acc.from')" density="compact" hide-details style="max-width: 170px" @update:model-value="reload" />
        <VTextField v-model="to" type="date" :label="t('acc.to')" density="compact" hide-details style="max-width: 170px" @update:model-value="reload" />
        <VBtn variant="tonal" prepend-icon="tabler-file-type-pdf" :href="pdfUrl()" target="_blank">PDF</VBtn>
        <VBtn variant="tonal" color="primary" prepend-icon="tabler-mail" @click="emailStatement">{{ t('acc.email') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.date') }}</th><th>{{ t('acc.reference') }}</th><th>{{ t('acc.type') }}</th><th class="text-right">{{ t('acc.charge') }}</th><th class="text-right">{{ t('acc.payment_col') }}</th><th class="text-right">{{ t('acc.balance') }}</th></tr></thead>
        <tbody>
          <tr class="font-weight-medium bg-surface-light"><td colspan="5">{{ t('acc.opening_balance') }}</td><td class="text-right">{{ money(statement.opening) }}</td></tr>
          <tr v-for="(r, i) in statement.rows" :key="i">
            <td class="text-caption">{{ r.date }}</td>
            <td>{{ r.ref }}</td>
            <td><VChip size="x-small" variant="tonal">{{ r.type }}</VChip></td>
            <td class="text-right">{{ r.charge ? money(r.charge) : '' }}</td>
            <td class="text-right">{{ r.credit ? money(r.credit) : '' }}</td>
            <td class="text-right font-weight-medium">{{ money(r.balance) }}</td>
          </tr>
          <tr v-if="!statement.rows.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('acc.no_activity_period') }}</td></tr>
        </tbody>
        <tfoot>
          <tr class="font-weight-bold"><td colspan="5" class="text-right">{{ t('acc.closing_balance') }}</td><td class="text-right">{{ money(statement.closing) }}</td></tr>
        </tfoot>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
