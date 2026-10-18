<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Row = {
  uuid: string; reference: string; form: string; tax_type: string; period: string | null; entity: string | null; version: number
  purpose: string; status: string; amount_payable: string; submission_status: string; submission_reference: string | null
  payment_status: string; due_date: string | null
}

const props = defineProps<{
  returns: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { status: string | null }
  statuses: string[]
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

function status(s: string | null) {
  router.get(route('tax.returns.index'), { ...(s ? { status: s } : {}), ...(props.business ? { business: props.business } : {}) }, { preserveState: true })
}
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Tax returns</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Every version is kept. Prepare a return from its period; it is reviewed and approved by different people, and recorded as submitted only with SARS's reference.
    </p>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-2 pb-0 align-center">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VChip v-for="s in [null, ...statuses]" :key="String(s)" :color="filters.status === s ? 'primary' : undefined"
               :variant="filters.status === s ? 'tonal' : 'outlined'" @click="status(s)">{{ s ? label(s) : 'All' }}</VChip>
      </VCardText>
      <VTable density="comfortable">
        <thead>
          <tr><th>Return</th><th>Period</th><th class="text-right">Payable / (refund)</th><th>Status</th><th>Submission</th><th>Payment</th><th>Due</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in returns.data" :key="r.uuid" class="cursor-pointer" @click="router.get(route('tax.returns.show', r.uuid))">
            <td class="font-weight-medium">{{ r.reference }}
              <div class="text-caption text-disabled">{{ r.form }} · v{{ r.version }}<span v-if="r.purpose === 'amendment'"> · amendment</span> · {{ r.entity }}</div>
            </td>
            <td>{{ r.period }}</td>
            <td class="text-right">{{ money(r.amount_payable) }}</td>
            <td><VChip size="small" :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip></td>
            <td>
              <VChip size="x-small" :color="r.submission_status === 'accepted' ? 'success' : r.submission_status === 'unknown' || r.submission_status === 'rejected' ? 'error' : undefined" label>{{ label(r.submission_status) }}</VChip>
              <div v-if="r.submission_reference" class="text-caption text-disabled">{{ r.submission_reference }}</div>
            </td>
            <td class="text-caption">{{ label(r.payment_status) }}</td>
            <td class="text-caption">{{ r.due_date ?? '—' }}</td>
          </tr>
          <tr v-if="!returns.data.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No returns yet. Open a period and prepare its return.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="returns.links.length > 3" class="d-flex justify-center gap-1">
        <VBtn v-for="(l, i) in returns.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
