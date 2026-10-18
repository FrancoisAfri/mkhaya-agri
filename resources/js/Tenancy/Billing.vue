<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const confirmRef = ref()

interface Inv {
  id: number; number: string; company: string | null; plan: string | null
  amount: number; period: string; due_date: string; status: string; paid_at: string | null
}
const props = defineProps<{
  metrics: { mrr: number; arr: number; active: number; trial: number; suspended: number; outstanding: number }
  invoices: Inv[]
}>()

function fmtMoney(n: any) { return 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
const statusColor: Record<string, string> = { open: 'info', paid: 'success', overdue: 'error', void: 'secondary' }

function generate() {
  confirmRef.value.open(
    { title: 'Generate this month\'s invoices?', message: 'Creates subscription invoices for all active companies for the current period.', confirmText: 'Generate', confirmColor: 'primary', icon: 'tabler-file-plus' },
    () => router.post(route('tenancy.billing.generate'), {}, { preserveScroll: true }),
  )
}
function processOverdue() {
  confirmRef.value.open(
    { title: 'Process overdue invoices?', message: 'Flags unpaid invoices as overdue and auto-suspends companies past the grace period.', confirmText: 'Process overdue', confirmColor: 'warning', icon: 'tabler-clock-exclamation' },
    () => router.post(route('tenancy.billing.overdue'), {}, { preserveScroll: true }),
  )
}
function markPaid(i: Inv) {
  confirmRef.value.open(
    { title: 'Mark invoice as paid?', message: `Mark ${i.number} (${fmtMoney(i.amount)}) for ${i.company} as paid? This reactivates the company if it was suspended.`, confirmText: 'Mark paid', confirmColor: 'success', icon: 'tabler-check' },
    () => router.post(route('tenancy.billing.paid', i.id), {}, { preserveScroll: true }),
  )
}

const kpis = [
  { label: 'MRR', key: 'mrr', money: true, color: 'primary' },
  { label: 'ARR', key: 'arr', money: true, color: 'success' },
  { label: 'Active', key: 'active', money: false, color: 'info' },
  { label: 'Trial', key: 'trial', money: false, color: 'warning' },
  { label: 'Suspended', key: 'suspended', money: false, color: 'error' },
  { label: 'Outstanding', key: 'outstanding', money: true, color: 'error' },
] as const
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Platform Billing</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Subscription revenue and company invoices.</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
        <Link :href="route('tenancy.companies.index')"><VBtn variant="tonal" prepend-icon="tabler-building">Companies</VBtn></Link>
        <VBtn variant="tonal" color="warning" prepend-icon="tabler-clock-exclamation" @click="processOverdue">Process overdue</VBtn>
        <VBtn color="primary" prepend-icon="tabler-file-plus" @click="generate">Generate this month</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol v-for="k in kpis" :key="k.label" cols="6" md="2">
        <VCard elevation="0" border rounded="lg">
          <VCardText class="text-center py-4">
            <div class="text-caption text-medium-emphasis mb-1">{{ k.label }}</div>
            <div class="text-subtitle-1 font-weight-bold" :class="`text-${k.color}`">
              {{ k.money ? fmtMoney((props.metrics as any)[k.key]) : (props.metrics as any)[k.key] }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg">
      <VTable hover>
        <thead>
          <tr><th>Invoice</th><th>Company</th><th>Plan</th><th>Period</th><th class="text-end">Amount</th><th>Due</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="i in props.invoices" :key="i.id">
            <td class="font-weight-medium">{{ i.number }}</td>
            <td>{{ i.company }}</td>
            <td>{{ i.plan }}</td>
            <td>{{ i.period }}</td>
            <td class="text-end">{{ fmtMoney(i.amount) }}</td>
            <td>{{ i.due_date }}</td>
            <td><VChip size="small" :color="statusColor[i.status] ?? 'secondary'" variant="tonal" label>{{ i.status }}</VChip></td>
            <td class="text-end">
              <VBtn v-if="i.status !== 'paid' && i.status !== 'void'" size="small" color="success" variant="tonal" @click="markPaid(i)">Mark paid</VBtn>
              <span v-else-if="i.paid_at" class="text-caption text-medium-emphasis">paid {{ i.paid_at }}</span>
            </td>
          </tr>
          <tr v-if="!props.invoices.length"><td colspan="8" class="text-center py-12 text-medium-emphasis">No subscription invoices yet — click “Generate this month”.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
