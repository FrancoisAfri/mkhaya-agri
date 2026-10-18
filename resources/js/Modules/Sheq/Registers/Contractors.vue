<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

type Supplier = {
  id: number
  name: string
  trading_name: string | null
  registration_number: string | null
  contact_email: string | null
  contact_phone: string | null
}

const props = defineProps<{rows: Record<string, any>[]; expiring: Record<string, any>[]; sites?: { id: number; name: string }[]; suppliers?: Supplier[]; canManage?: boolean; noCompany?: boolean}>()

const siteItems = (props.sites ?? []).map(s => ({ title: s.name, value: s.id }))

/*
 * Suppliers already onboarded commercially, offered before the name field is asked for.
 *
 * Picking one fills the name, registration number and contact details from the supplier record
 * and joins the two — which is what `supplier_id` was always for. Typing the company in again
 * instead is how it ends up in the system twice, with a safety file on one row and a commercial
 * record on another and nothing tying them together.
 *
 * The picker is omitted entirely when there are no linkable suppliers, rather than offered empty.
 */
const supplierItems = (props.suppliers ?? []).map(s => ({
  title: s.name,
  subtitle: [s.trading_name, s.registration_number].filter(Boolean).join(' · ') || undefined,
  value: s.id,
  fill: {
    name: s.name,
    registration_number: s.registration_number,
    contact_email: s.contact_email,
    contact_phone: s.contact_phone,
  },
}))

/*
  The six calculated statuses, each with a colour and — more importantly — a translated label.

  The chip used to render the raw column value, so a contractor read "NON_COMPLIANT" in capitals
  with an underscore, in all twelve languages. Two of the six had no colour either and came out
  grey, which for "not compliant" reads as "nothing to see".

  The labels are the ones the safety file screen already uses. One vocabulary for one set of
  statuses: two would drift, and a contractor would be "Not compliant" on one screen and something
  else on the next.
*/
const statusColour = (status: string) => ({
  COMPLIANT: 'success', EXPIRING: 'warning', NON_COMPLIANT: 'warning',
  BLOCKED: 'error', SUSPENDED: 'error', PENDING_APPROVAL: 'info',
}[status] ?? 'grey')

const rows = computed(() => props.rows.map(row => ({
  ...row,
  access_status_label: row.access_status
    ? t('sheq.safety_file.status.' + row.access_status)
    : '—',
})))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.contractors.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.contractors.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.contractors.add')" action="/sheq/contractors"
        :button-label="t('sheq.registers.contractors.add')" :note="t('sheq.registers.contractors.add_note')"
        :fields="[
        ...(supplierItems.length ? [{
          key: 'supplier_id', label: t('sheq.registers.contractors.from_supplier'),
          type: 'lookup' as const, lookupItems: supplierItems, cols: 12,
          hint: t('sheq.registers.contractors.from_supplier_hint'),
          fillNote: t('sheq.registers.contractors.from_supplier_filled'),
        }] : []),
        { key: 'name', label: t('sheq.registers.col.contractor'), required: true },
        { key: 'trade', label: t('sheq.registers.col.trade'), type: 'select', items: [
          { title: 'Scaffolding', value: 'SCAFFOLDING' },
          { title: 'Electrical', value: 'ELECTRICAL' },
          { title: 'Mechanical', value: 'MECHANICAL' },
          { title: 'Civil / structural', value: 'CIVIL' },
          { title: 'Welding & hot work', value: 'WELDING' },
          { title: 'Painting & coatings', value: 'PAINTING' },
          { title: 'Plumbing', value: 'PLUMBING' },
          { title: 'HVAC & refrigeration', value: 'HVAC' },
          { title: 'Roofing & working at height', value: 'ROOFING' },
          { title: 'Demolition', value: 'DEMOLITION' },
          { title: 'Excavation & earthworks', value: 'EXCAVATION' },
          { title: 'Lifting & rigging', value: 'RIGGING' },
          { title: 'Cleaning & hygiene', value: 'CLEANING' },
          { title: 'Catering', value: 'CATERING' },
          { title: 'Security', value: 'SECURITY' },
          { title: 'Transport & logistics', value: 'TRANSPORT' },
          { title: 'Waste management', value: 'WASTE' },
          { title: 'Laboratory & testing', value: 'LABORATORY' },
          { title: 'Instrumentation', value: 'INSTRUMENTATION' },
          { title: 'Other', value: 'OTHER' },
        ] },
        { key: 'registration_number', label: t('sheq.registers.col.reg_no') },
        { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select', items: siteItems },
        { key: 'contact_name', label: t('sheq.registers.col.contact') },
        { key: 'contact_email', label: t('sheq.registers.col.email') },
        { key: 'contact_phone', label: t('sheq.registers.col.phone') },
      ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.contractors.list')" :rows="rows"
      :subtitle="t('sheq.registers.contractors.hint')"
      :search-keys="['name', 'trade', 'site_name']"
      :empty-hint="t('sheq.registers.contractors.empty')"
      :row-href="(row) => `/sheq/contractors/${row.uuid}`"
      :columns="[
        { key: 'name', label: t('sheq.registers.col.contractor') },
        { key: 'trade', label: t('sheq.registers.col.trade'), kind: 'muted' },
        { key: 'site_name', label: t('sheq.registers.col.site'), kind: 'muted' },
        { key: 'access_status_label', label: t('sheq.registers.col.access'), kind: 'chip', chipColour: (r) => statusColour(r.access_status) },
        { key: 'override_until', label: t('sheq.registers.col.override_until'), kind: 'muted' },
      ]"
    />

    <RegisterTable
      :title="t('sheq.registers.contractors.expiring')" :rows="expiring"
      :search-keys="['contractor', 'document_type']"
      :empty-hint="t('sheq.registers.contractors.empty_expiring')"
      :columns="[
        { key: 'contractor', label: t('sheq.registers.col.contractor') },
        { key: 'document_type', label: t('sheq.registers.col.document') },
        { key: 'expires_on', label: t('sheq.registers.col.expires') },
      ]"
    />
  </div>
</template>
