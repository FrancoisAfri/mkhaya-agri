<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Exception = { code: string; message: string; blocking: boolean; resolved_at?: string; resolution?: string }
type Row = {
  uuid: string; period: string | null; period_status: string | null; document_date: string; document_type: string; document_number: string | null
  direction: string; party: string | null; party_vat_number: string | null; tax_code: string | null; tax_code_source: string | null
  treatment: string | null; return_field: string | null; taxable: string; tax: string; calculated: string | null; difference: string
  currency: string; fx_rate: string; fx_source: string | null; status: string; version: number; is_current: boolean
  exceptions: Exception[]; has_exception: boolean; source: { label: string; url: string } | null; source_key: string
  calculation: Record<string, unknown> | null
}

const props = defineProps<{
  transactions: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number }
  filters: { period?: string; direction?: string; code?: string; exceptions?: string; search?: string; history?: string }
  periods: Array<{ uuid: string; label: string }>
  codes: string[]
  can: { prepare: boolean }
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
}>()

const f = reactive({
  period: props.filters.period ?? null, direction: props.filters.direction ?? null, code: props.filters.code ?? null,
  exceptions: props.filters.exceptions === '1', search: props.filters.search ?? '', history: props.filters.history === '1',
})
function params() {
  return Object.fromEntries(Object.entries({ ...f, business: props.business, exceptions: f.exceptions ? '1' : null, history: f.history ? '1' : null })
    .filter(([, v]) => v !== null && v !== '' && v !== false))
}
function apply() {
  router.get(route('tax.transactions.index'), params(), { preserveState: true, preserveScroll: true })
}
function exportCsv() {
  window.location.href = route('tax.transactions.export', params())
}

const open = ref<string | null>(null)

// ── Resolve an exception (classify, or accept with a reason) ────────────────
const dialog = ref(false)
const target = ref<Row | null>(null)
const form = useForm({ action: 'classify' as 'classify' | 'accept', reason: '', tax_code: '' })
function openResolve(r: Row) {
  target.value = r
  form.reset()
  form.action = r.exceptions.some(e => ['missing_code', 'no_rule', 'inferred_code'].includes(e.code) && !e.resolved_at) ? 'classify' : 'accept'
  form.tax_code = r.tax_code ?? ''
  dialog.value = true
}
function resolve() {
  if (!target.value) return
  form.post(route('tax.transactions.resolve', target.value.uuid), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">VAT register</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Every VAT line, traced to the invoice, credit note or expense it came from. Amounts in rand.</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" @click="exportCsv">Export CSV</VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0 align-center">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VSelect v-model="f.period" :items="periods" item-title="label" item-value="uuid" label="Period" clearable density="compact" style="min-inline-size: 240px" @update:model-value="apply" />
        <VSelect v-model="f.direction" :items="[{ title: 'Output (sales)', value: 'output' }, { title: 'Input (purchases)', value: 'input' }]" label="Direction" clearable density="compact" style="max-inline-size: 190px" @update:model-value="apply" />
        <VSelect v-model="f.code" :items="codes" label="Tax code" clearable density="compact" style="max-inline-size: 150px" @update:model-value="apply" />
        <VTextField v-model="f.search" prepend-inner-icon="tabler-search" placeholder="Document or party" density="compact" hide-details style="max-inline-size: 240px" @keyup.enter="apply" />
        <VSwitch v-model="f.exceptions" label="Needs attention" color="warning" density="compact" hide-details inset @update:model-value="apply" />
        <VSwitch v-model="f.history" label="Show earlier versions" density="compact" hide-details inset @update:model-value="apply" />
      </VCardText>

      <VTable density="compact" class="mt-2">
        <thead>
          <tr>
            <th /><th>Date</th><th>Document</th><th>Party</th><th>Code</th><th class="text-right">Taxable</th><th class="text-right">VAT</th><th class="text-right">Rule says</th><th>Status</th><th />
          </tr>
        </thead>
        <tbody>
          <template v-for="r in transactions.data" :key="r.uuid">
            <tr :class="{ 'text-disabled': !r.is_current }">
              <td><VBtn size="x-small" variant="text" :icon="open === r.uuid ? 'tabler-chevron-down' : 'tabler-chevron-right'" @click="open = open === r.uuid ? null : r.uuid" /></td>
              <td>{{ r.document_date }}<div class="text-caption text-disabled">{{ r.period }}</div></td>
              <td>
                <a v-if="r.source" :href="r.source.url" class="text-primary">{{ r.document_number }}</a><span v-else>{{ r.document_number }}</span>
                <div class="text-caption text-disabled">{{ label(r.document_type) }} · {{ r.direction }}<span v-if="r.version > 1"> · v{{ r.version }}</span></div>
              </td>
              <td>{{ r.party ?? '—' }}<div class="text-caption text-disabled">{{ r.party_vat_number ?? 'no VAT no.' }}</div></td>
              <td>
                {{ r.tax_code ?? '—' }}
                <div v-if="r.tax_code_source && r.tax_code_source !== 'document'" class="text-caption text-warning">{{ r.tax_code_source }}</div>
              </td>
              <td class="text-right">{{ money(r.taxable) }}</td>
              <td class="text-right">{{ money(r.tax) }}</td>
              <td class="text-right" :class="Number(r.difference) !== 0 ? 'text-error' : 'text-medium-emphasis'">{{ r.calculated === null ? 'not calculated' : money(r.calculated) }}</td>
              <td>
                <VChip v-if="r.has_exception" size="x-small" color="error" label>needs attention</VChip>
                <VChip v-else-if="r.exceptions.length" size="x-small" color="warning" label>note</VChip>
                <VChip v-else size="x-small" :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip>
              </td>
              <td class="text-right">
                <VBtn v-if="can.prepare && r.is_current && r.exceptions.some(e => !e.resolved_at)" size="x-small" variant="tonal" @click="openResolve(r)">Resolve</VBtn>
              </td>
            </tr>
            <tr v-if="open === r.uuid">
              <td colspan="10" class="bg-surface-light py-3">
                <VRow>
                  <VCol cols="12" md="6">
                    <div class="text-overline">Exceptions</div>
                    <div v-if="!r.exceptions.length" class="text-caption text-medium-emphasis">None.</div>
                    <div v-for="(e, i) in r.exceptions" :key="i" class="text-body-2 mb-1">
                      <VIcon :icon="e.resolved_at ? 'tabler-check' : e.blocking ? 'tabler-alert-circle' : 'tabler-info-circle'" :color="e.resolved_at ? 'success' : e.blocking ? 'error' : 'warning'" size="16" />
                      {{ e.message }}
                      <div v-if="e.resolution" class="text-caption text-medium-emphasis ms-5">Accepted: {{ e.resolution }}</div>
                    </div>
                  </VCol>
                  <VCol cols="12" md="6">
                    <div class="text-overline">Calculation</div>
                    <div class="text-caption">
                      Treatment {{ label(r.treatment) }} · VAT201 field {{ r.return_field ?? 'default' }} · rule v{{ (r.calculation as any)?.rule_version ?? '—' }} at {{ (r.calculation as any)?.rate ?? '—' }}%
                      · rounding {{ (r.calculation as any)?.rounding ?? '—' }}<br>
                      <span v-if="r.currency !== 'ZAR'">{{ r.currency }} at {{ r.fx_rate }} ({{ r.fx_source ?? 'no source' }})<br></span>
                      Source key {{ r.source_key }}
                    </div>
                  </VCol>
                </VRow>
              </td>
            </tr>
          </template>
          <tr v-if="!transactions.data.length">
            <td colspan="10" class="text-center text-medium-emphasis py-6">No lines match. A period's lines appear once its register is built from its period page.</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="transactions.links.length > 3" class="d-flex justify-center gap-1">
        <VBtn v-for="(l, i) in transactions.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard v-if="target" :title="`Resolve ${target.document_number}`">
        <VCardText>
          <VBtnToggle v-model="form.action" mandatory density="compact" variant="outlined" divided class="mb-3">
            <VBtn value="classify">Classify the code</VBtn>
            <VBtn value="accept">Accept with a reason</VBtn>
          </VBtnToggle>
          <p class="text-caption text-medium-emphasis">
            {{ form.action === 'classify' ? 'Sets the tax code on this register line only. The invoice itself is not changed.' : 'Records that the exception was reviewed and is acceptable, and why. It stays visible on the line.' }}
          </p>
          <VCombobox v-if="form.action === 'classify'" v-model="form.tax_code" :items="codes" label="Tax code" :error-messages="form.errors.tax_code" class="mb-2" />
          <VTextarea v-model="form.reason" label="Reason" rows="2" auto-grow :error-messages="form.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="form.reason.trim().length < 5" @click="resolve">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
