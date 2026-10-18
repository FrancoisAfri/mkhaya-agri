<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

defineProps<{
  kpis: { registrations: number; unconfirmed: number; overdue: number; due_14: number; exceptions: number; awaiting_sign_off: number
    returns_in_review: number; returns_to_submit: number; unconfirmed_submissions: number; overdue_liabilities: number }
  owed: Array<{ tax_type: string; owed: string; paid: string; outstanding: string }>
  pipeline: Record<string, number>
  liabilities: Array<{ uuid: string; label: string; entity: string | null; tax_type: string; output: string; input: string; net: string; status: string; final: boolean; due: string | null }>
  deadlines: Array<{ uuid: string; title: string; due_date: string; days_left: number; status: string }>
  recent: Array<{ action: string; actor: string | null; reason: string | null; at: string | null }>
  filters: { tax_type: string | null; business: number | null }
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
  taxTypes: string[]
}>()

function filter(taxType: string | null) {
  const url = new URL(window.location.href)
  if (taxType) url.searchParams.set('tax_type', taxType)
  else url.searchParams.delete('tax_type')
  router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true })
}

const tiles = [
  { key: 'overdue', label: 'Overdue deadlines', color: 'error', to: () => route('tax.calendar.index', { status: 'overdue' }) },
  { key: 'due_14', label: 'Due in 14 days', color: 'warning', to: () => route('tax.calendar.index', { status: 'outstanding' }) },
  { key: 'exceptions', label: 'Register exceptions', color: 'warning', to: () => route('tax.transactions.index', { exceptions: 1 }) },
  { key: 'awaiting_sign_off', label: 'Reconciliations to sign off', color: 'info', to: () => route('tax.reconciliations.index') },
  { key: 'returns_in_review', label: 'Returns being prepared or reviewed', color: 'info', to: () => route('tax.returns.index', { status: 'under_review' }) },
  { key: 'returns_to_submit', label: 'Approved, not yet submitted', color: 'warning', to: () => route('tax.returns.index', { status: 'approved' }) },
  { key: 'unconfirmed_submissions', label: 'Submissions not confirmed', color: 'error', to: () => route('tax.returns.index') },
  { key: 'overdue_liabilities', label: 'Overdue liabilities', color: 'error', to: () => route('tax.liabilities.index', { status: 'outstanding' }) },
  { key: 'registrations', label: 'Active registrations', color: 'success', to: () => route('tax.registrations.index') },
  { key: 'unconfirmed', label: 'Registrations to confirm', color: 'warning', to: () => route('tax.registrations.index') },
] as const
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Tax Management</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Liabilities, deadlines and what still needs attention, from the records you can see.</p>
      </div>
      <div class="d-flex flex-wrap gap-3 align-center">
      <BusinessFilter :businesses="businesses" :business="business" />
      <VBtnToggle :model-value="filters.tax_type" density="compact" variant="outlined" divided @update:model-value="filter">
        <VBtn :value="null">All taxes</VBtn>
        <VBtn v-for="c in taxTypes" :key="c" :value="c">{{ c }}</VBtn>
      </VBtnToggle>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol v-for="tile in tiles" :key="tile.key" cols="6" md="2">
        <VCard border class="h-100" @click="router.get(tile.to())">
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ tile.label }}</div>
            <div class="text-h5" :class="kpis[tile.key] ? `text-${tile.color}` : ''">{{ kpis[tile.key] }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">VAT by period</VCardTitle>
            <VCardSubtitle>From the VAT register. Periods not yet approved are drafts, not final liabilities.</VCardSubtitle>
          </VCardItem>
          <VTable v-if="liabilities.length" density="compact">
            <thead>
              <tr><th>Period</th><th class="text-right">Output</th><th class="text-right">Input</th><th class="text-right">Net</th><th>Status</th><th>Due</th></tr>
            </thead>
            <tbody>
              <tr v-for="l in liabilities" :key="l.uuid" class="cursor-pointer" @click="router.get(route('tax.periods.show', l.uuid))">
                <td>{{ l.label }}<div class="text-caption text-disabled">{{ l.entity }}</div></td>
                <td class="text-right">{{ money(l.output) }}</td>
                <td class="text-right">{{ money(l.input) }}</td>
                <td class="text-right font-weight-medium">
                  {{ money(l.net) }}
                  <div class="text-caption" :class="l.final ? 'text-success' : 'text-warning'">{{ l.final ? 'approved' : 'draft' }}</div>
                </td>
                <td><VChip size="x-small" :color="statusColor[l.status]" label>{{ label(l.status) }}</VChip></td>
                <td class="text-caption">{{ l.due ?? '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-medium-emphasis">
            No VAT periods yet. Record a VAT registration and confirm it applies, and its periods appear here.
          </VCardText>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Periods by status</VCardTitle></VCardItem>
          <VCardText class="d-flex flex-wrap gap-2">
            <VChip v-for="(n, s) in pipeline" :key="s" :color="n ? statusColor[s] : undefined" :variant="n ? 'tonal' : 'outlined'"
                   @click="router.get(route('tax.periods.index', { status: s }))">
              {{ label(String(s)) }} · {{ n }}
            </VChip>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">Owed to SARS</VCardTitle>
            <VCardSubtitle>From approved returns and recorded assessments</VCardSubtitle>
            <template #append><VBtn size="small" variant="text" @click="router.get(route('tax.liabilities.index'))">Liabilities</VBtn></template>
          </VCardItem>
          <VTable v-if="owed.length" density="compact">
            <thead><tr><th>Tax</th><th class="text-right">Owed</th><th class="text-right">Paid</th><th class="text-right">Outstanding</th></tr></thead>
            <tbody><tr v-for="o in owed" :key="o.tax_type"><td>{{ o.tax_type }}</td><td class="text-right">{{ money(o.owed) }}</td><td class="text-right">{{ money(o.paid) }}</td><td class="text-right font-weight-medium">{{ money(o.outstanding) }}</td></tr></tbody>
          </VTable>
          <VCardText v-else class="text-medium-emphasis">Nothing recorded yet.</VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">Upcoming and overdue</VCardTitle>
            <template #append><VBtn size="small" variant="text" @click="router.get(route('tax.calendar.index'))">Calendar</VBtn></template>
          </VCardItem>
          <VList v-if="deadlines.length" density="compact">
            <VListItem v-for="d in deadlines" :key="d.uuid">
              <VListItemTitle class="text-body-2">{{ d.title }}</VListItemTitle>
              <VListItemSubtitle>{{ d.due_date }}</VListItemSubtitle>
              <template #append>
                <VChip size="x-small" label :color="d.days_left < 0 ? 'error' : d.days_left <= 3 ? 'warning' : undefined">
                  {{ d.days_left < 0 ? `${-d.days_left} day(s) late` : d.days_left === 0 ? 'today' : `${d.days_left} day(s)` }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">Nothing outstanding.</VCardText>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Recent rule changes and decisions</VCardTitle></VCardItem>
          <VList v-if="recent.length" density="compact">
            <VListItem v-for="(r, i) in recent" :key="i">
              <VListItemTitle class="text-body-2">{{ label(r.action.replace('.', ' ')) }}</VListItemTitle>
              <VListItemSubtitle>{{ r.actor }} · {{ r.at }}<span v-if="r.reason"> · {{ r.reason }}</span></VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">No changes yet.</VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
