<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { frequencyLabel, label, months, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'
import EvidencePanel from './components/EvidencePanel.vue'

const route = ziggyRoute

type Registration = {
  uuid: string; entity_id: number | null; entity: string | null; tax_type_id: number; tax_type: string; tax_type_name: string
  reference: string | null; effective_from: string; effective_to: string | null; filing_frequency: string; year_end_month: number | null
  authority: string; status: string; preparer_id: number | null; reviewer_id: number | null; escalation_id: number | null
  preparer: string | null; reviewer: string | null; applicability_confirmed: boolean; notes: string | null; version: number
  missing: string[]
  history: Array<{ version: number; reason: string; effective_date: string | null; changes: Record<string, [unknown, unknown]> | null; by: string | null; at: string | null }>
  documents: Array<{ id: number; title: string; added: string | null; url: string }>
}

const props = defineProps<{
  registrations: Registration[]
  taxTypes: Array<{ id: number; code: string; name: string; frequencies: string[]; default_frequency: string | null }>
  people: Array<{ id: number; name: string }>
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
  can: { manage: boolean }
}>()

const search = ref('')
const shown = computed(() => props.registrations.filter(r => !search.value
  || `${r.entity} ${r.tax_type} ${r.reference ?? ''}`.toLowerCase().includes(search.value.toLowerCase())))

// ── Create / edit ───────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Registration | null>(null)
const form = useForm({
  entity_id: null as number | null, tax_type_id: null as number | null, reference: '', effective_from: '', effective_to: '',
  filing_frequency: '', year_end_month: null as number | null, authority: 'SARS',
  preparer_id: null as number | null, reviewer_id: null as number | null, escalation_id: null as number | null, notes: '',
  reason: '', effective_date: new Date().toISOString().slice(0, 10),
})
const selectedType = computed(() => props.taxTypes.find(t => t.id === (editing.value?.tax_type_id ?? form.tax_type_id)))
const frequencyOptions = computed(() => (selectedType.value?.frequencies ?? []).map(f => ({ title: frequencyLabel[f] ?? label(f), value: f })))
const needsYearEnd = computed(() => ['four_monthly', 'six_monthly', 'annual'].includes(form.filing_frequency))

function openCreate() {
  editing.value = null
  form.reset()
  // One business: it is the answer, so it is filled in and the choice is not shown.
  form.entity_id = props.businesses.length === 1 ? props.businesses[0].id : props.business
  form.clearErrors()
  dialog.value = true
}

function openEdit(r: Registration) {
  editing.value = r
  Object.assign(form, {
    entity_id: r.entity_id, tax_type_id: r.tax_type_id, reference: r.reference ?? '', effective_from: r.effective_from,
    effective_to: r.effective_to ?? '', filing_frequency: r.filing_frequency, year_end_month: r.year_end_month, authority: r.authority,
    preparer_id: r.preparer_id, reviewer_id: r.reviewer_id, escalation_id: r.escalation_id, notes: r.notes ?? '',
    reason: '', effective_date: new Date().toISOString().slice(0, 10),
  })
  form.clearErrors()
  dialog.value = true
}

function onTypeChange() {
  form.filing_frequency = selectedType.value?.default_frequency ?? ''
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) {
    form.transform(({ entity_id, tax_type_id, ...rest }) => ({ ...rest, effective_to: rest.effective_to || null }))
      .patch(route('tax.registrations.update', editing.value.uuid), done)
  } else {
    form.transform(({ reason, effective_date, ...rest }) => ({ ...rest, effective_to: rest.effective_to || null }))
      .post(route('tax.registrations.store'), done)
  }
}

// ── Confirm applicability (TAX-REG-003) ─────────────────────────────────────
const confirmDialog = ref(false)
const confirmTarget = ref<Registration | null>(null)
const confirmForm = useForm({ reason: '' })
function openConfirm(r: Registration) {
  confirmTarget.value = r
  confirmForm.reset()
  confirmDialog.value = true
}
function confirmApplies() {
  if (!confirmTarget.value) return
  confirmForm.post(route('tax.registrations.confirm', confirmTarget.value.uuid), { preserveScroll: true, onSuccess: () => { confirmDialog.value = false } })
}

const expanded = ref<string[]>([])
const headers = [
  { title: 'Business', key: 'entity' }, { title: 'Tax', key: 'tax_type' }, { title: 'Reference', key: 'reference' },
  { title: 'Frequency', key: 'filing_frequency' }, { title: 'Effective', key: 'effective_from' },
  { title: 'Preparer / reviewer', key: 'preparer', sortable: false }, { title: 'Status', key: 'status' },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Tax registrations</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Which taxes each business is registered for. Nothing is generated for a registration until someone confirms it applies.
        </p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="openCreate">Add registration</VBtn>
    </div>

    <VCard border>
      <VCardText class="pb-0">
        <div class="d-flex flex-wrap gap-3">
          <BusinessFilter :businesses="businesses" :business="business" />
          <VTextField v-model="search" prepend-inner-icon="tabler-search" placeholder="Search business, tax or reference" density="compact" hide-details style="max-inline-size: 360px" />
        </div>
        <p class="text-caption text-medium-emphasis mt-2 mb-0">
          {{ accountingEnabled ? 'Businesses come from Accounting → Configure → Businesses.' : 'Tax is kept for the company. Turn on Accounting to keep it per business.' }}
        </p>
      </VCardText>
      <VDataTable v-model:expanded="expanded" :headers="headers" :items="shown" item-value="uuid" show-expand :items-per-page="25" density="comfortable">
        <template #item.tax_type="{ item }">
          <span class="font-weight-medium">{{ item.tax_type }}</span>
          <div class="text-caption text-disabled">{{ item.tax_type_name }}</div>
        </template>
        <template #item.reference="{ item }">{{ item.reference ?? '—' }}</template>
        <template #item.filing_frequency="{ item }">{{ frequencyLabel[item.filing_frequency] ?? label(item.filing_frequency) }}</template>
        <template #item.effective_from="{ item }">{{ item.effective_from }}<span v-if="item.effective_to"> → {{ item.effective_to }}</span></template>
        <template #item.preparer="{ item }">
          <div class="text-body-2">{{ item.preparer ?? '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.reviewer ?? 'no reviewer' }}</div>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" :color="statusColor[item.status]" label>{{ label(item.status) }}</VChip>
          <VTooltip v-if="item.missing.length" location="top">
            <template #activator="{ props: tip }">
              <VIcon v-bind="tip" icon="tabler-alert-circle" color="warning" size="18" class="ms-1" />
            </template>
            Needs {{ item.missing.join('; ') }}
          </VTooltip>
        </template>
        <template #item.actions="{ item }">
          <VBtn v-if="can.manage && !item.applicability_confirmed" size="small" color="success" variant="tonal" class="me-1" @click="openConfirm(item)">Confirm it applies</VBtn>
          <VBtn v-if="can.manage && item.applicability_confirmed" size="small" variant="text" class="me-1"
                @click="router.post(route('tax.registrations.generate', item.uuid), {}, { preserveScroll: true })">Generate periods</VBtn>
          <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-edit" @click="openEdit(item)" />
        </template>
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="py-3">
              <VRow>
                <VCol cols="12" md="7">
                  <div class="text-overline">Change history</div>
                  <VTable density="compact">
                    <tbody>
                      <tr v-for="h in item.history" :key="h.version">
                        <td class="text-caption">v{{ h.version }}</td>
                        <td class="text-caption">{{ h.at }}<br>{{ h.by ?? 'System' }}</td>
                        <td class="text-caption">
                          {{ h.reason }} <span class="text-disabled">(effective {{ h.effective_date }})</span>
                          <div v-for="(pair, field) in h.changes ?? {}" :key="field" class="text-disabled">{{ label(String(field)) }}: {{ pair[0] ?? '—' }} → {{ pair[1] ?? '—' }}</div>
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCol>
                <VCol cols="12" md="5">
                  <EvidencePanel type="registrations" :uuid="item.uuid" :items="item.documents" :can-add="can.manage" hint="Attach the SARS registration certificate or letter." />
                  <div v-if="item.notes" class="text-overline mt-2">Notes</div>
                  <p v-if="item.notes" class="text-body-2">{{ item.notes }}</p>
                </VCol>
              </VRow>
            </td>
          </tr>
        </template>
        <template #no-data>
          <div class="pa-6 text-medium-emphasis">No registrations yet. Add one for each tax a business is registered for.</div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="760">
      <VCard :title="editing ? `Edit ${editing.tax_type} registration` : 'Add tax registration'">
        <VCardText>
          <VRow dense>
            <VCol v-if="businesses.length > 1 || editing" cols="12" md="6">
              <VSelect v-model="form.entity_id" :items="businesses" item-title="name" item-value="id" label="Business" :disabled="!!editing" :error-messages="form.errors.entity_id" />
            </VCol>
            <VCol cols="12" :md="businesses.length > 1 || editing ? 6 : 12">
              <VSelect v-model="form.tax_type_id" :items="taxTypes" :item-title="(t: any) => `${t.code} — ${t.name}`" item-value="id" label="Tax"
                       :disabled="!!editing" :error-messages="form.errors.tax_type_id" @update:model-value="onTypeChange" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.reference" label="Registration number / reference" :error-messages="form.errors.reference"
                          :hint="selectedType?.code === 'VAT' ? '10 digits starting with 4' : ''" persistent-hint />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.filing_frequency" :items="frequencyOptions" label="Filing frequency" :error-messages="form.errors.filing_frequency" />
            </VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.effective_from" type="date" label="Registered from" :error-messages="form.errors.effective_from" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.effective_to" type="date" label="Deregistered on (optional)" :error-messages="form.errors.effective_to" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="form.year_end_month" :items="months" label="Financial year-end" clearable :error-messages="form.errors.year_end_month" :hint="needsYearEnd ? 'Required for this frequency' : ''" persistent-hint /></VCol>
            <VCol cols="12" md="4"><VAutocomplete v-model="form.preparer_id" :items="people" item-title="name" item-value="id" label="Preparer" clearable /></VCol>
            <VCol cols="12" md="4"><VAutocomplete v-model="form.reviewer_id" :items="people" item-title="name" item-value="id" label="Reviewer" clearable /></VCol>
            <VCol cols="12" md="4"><VAutocomplete v-model="form.escalation_id" :items="people" item-title="name" item-value="id" label="Escalation contact" clearable /></VCol>
            <VCol cols="12"><VTextarea v-model="form.notes" label="Notes" rows="2" auto-grow /></VCol>
            <template v-if="editing">
              <VCol cols="12" md="8"><VTextField v-model="form.reason" label="Reason for the change" :error-messages="form.errors.reason" /></VCol>
              <VCol cols="12" md="4"><VTextField v-model="form.effective_date" type="date" label="Change takes effect" :error-messages="form.errors.effective_date" /></VCol>
            </template>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="confirmDialog" max-width="520">
      <VCard v-if="confirmTarget" :title="`Confirm ${confirmTarget.tax_type} applies to ${confirmTarget.entity}`">
        <VCardText>
          <p class="text-body-2">
            Once confirmed, the registration becomes active and its tax periods and deadlines are created.
            Confirm only when the business is actually registered for this tax.
          </p>
          <VTextarea v-model="confirmForm.reason" label="Basis for confirming (e.g. SARS registration letter dated …)" rows="2" auto-grow :error-messages="confirmForm.errors.reason" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDialog = false">Cancel</VBtn>
          <VBtn color="success" :loading="confirmForm.processing" :disabled="confirmForm.reason.trim().length < 5" @click="confirmApplies">Confirm</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
