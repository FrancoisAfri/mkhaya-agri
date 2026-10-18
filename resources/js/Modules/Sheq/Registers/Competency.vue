<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

const props = defineProps<{competencies: Record<string, any>[]; records: Record<string, any>[]; people?: { id: number; name: string }[]; sites?: { id: number; name: string }[]; canManage?: boolean; noCompany?: boolean}>()

const siteItems = (props.sites ?? []).map(s => ({ title: s.name, value: s.id }))
const peopleItems = (props.people ?? []).map(p => ({ title: p.name, value: p.id }))
// Codes come from this company's own catalogue, so a certificate can only be recorded against a
// competency that exists — a typed code was a refusal, not a record.
const competencyItems = props.competencies.map(c => ({ title: `${c.code} — ${c.title}`, value: c.code }))

const statusColour = (status: string) => ({
  DRAFT: 'grey', OPEN: 'warning', APPROVED: 'success', ACTIVE: 'success', ISSUED: 'info',
  IN_PROGRESS: 'info', COMPLETED: 'success', CLOSED: 'grey', SUPERSEDED: 'grey',
  SUSPENDED: 'error', REJECTED: 'error', PUBLISHED: 'success', WITHDRAWN: 'error',
  VALID: 'success', EXPIRED: 'error', BLOCKED: 'error', COMPLIANT: 'success',
  EXPIRING: 'warning', PENDING: 'warning', TRIAGED: 'info',
}[status] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.competency.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.competency.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.competency.add')" action="/sheq/competency"
        :button-label="t('sheq.registers.competency.add')" :note="t('sheq.registers.competency.add_note')"
        :fields="[
        { key: 'code', label: t('sheq.registers.col.code'), required: true },
        { key: 'title', label: t('sheq.registers.col.title'), required: true },
        { key: 'competency_type', label: t('sheq.registers.col.type'), type: 'select', required: true,
          items: [
            { title: 'Training', value: 'TRAINING' },
            { title: 'Licence', value: 'LICENCE' },
            { title: 'Appointment', value: 'APPOINTMENT' },
            { title: 'Medical', value: 'MEDICAL' },
            { title: 'Other', value: 'OTHER' },
          ] },
        { key: 'validity_months', label: t('sheq.registers.col.valid_months'), type: 'number' },
        { key: 'regulatory_reference', label: t('sheq.registers.col.regulation'), cols: 12 },
        { key: 'is_mandatory', label: t('sheq.registers.col.mandatory'), type: 'switch' },
        { key: 'blocks_work_when_expired', label: t('sheq.registers.col.blocks_work'), type: 'switch' },
      ]"
      />
      <!--
        A ticket earned somewhere else — St John first aid, a rigging certificate, a forklift
        licence. Without it the only competency a person could hold was one the LMS issued, so
        somebody genuinely trained read as not competent and the permit gates turned them away.
      -->
      <!-- The grid answers "where are we short", which a flat list of records cannot. -->
      <VBtn variant="tonal" prepend-icon="mdi-grid" href="/sheq/competency/matrix">
        {{ t('sheq.matrix.title') }}
      </VBtn>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.competency.record')" action="/sheq/competency/records"
        :button-label="t('sheq.registers.competency.record')"
        :note="t('sheq.registers.competency.record_note')"
        :fields="[
        { key: 'hr_person_id', label: t('sheq.registers.col.person'), type: 'select', required: true, items: peopleItems },
        { key: 'competency_code', label: t('sheq.registers.col.competency'), type: 'select', required: true, items: competencyItems },
        { key: 'achieved_at', label: t('sheq.registers.col.achieved'), type: 'date' },
        { key: 'expires_at', label: t('sheq.registers.col.expires'), type: 'date', hint: t('sheq.registers.competency.expiry_hint') },
        { key: 'certificate_number', label: t('sheq.registers.col.certificate_no') },
        { key: 'issuing_body', label: t('sheq.registers.col.issuing_body') },
        { key: 'score', label: t('sheq.registers.col.score'), type: 'number' },
        { key: 'evidence', label: t('sheq.registers.col.certificate'), type: 'file', cols: 12, hint: t('sheq.registers.competency.evidence_hint') },
      ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.competency.records')" :rows="records"
      :subtitle="t('sheq.registers.competency.records_hint')"
      :search-keys="['person', 'code', 'title']"
      :empty-hint="t('sheq.registers.competency.empty_records')"
      :columns="[
        { key: 'person', label: t('sheq.registers.col.person') },
        { key: 'title', label: t('sheq.registers.col.competency') },
        { key: 'appointment_scope', label: t('sheq.registers.col.appointment'), kind: 'muted' },
        { key: 'status', label: t('sheq.registers.col.status'), kind: 'chip', chipColour: (r) => statusColour(r.status) },
        { key: 'expires_at', label: t('sheq.registers.col.expires'), kind: 'flag', flagField: 'expired' },
      ]"
    />

    <RegisterTable
      :title="t('sheq.registers.competency.catalogue')" :rows="competencies"
      :search-keys="['code', 'title']"
      :empty-hint="t('sheq.registers.competency.empty_catalogue')"
      :columns="[
        { key: 'code', label: t('sheq.registers.col.code'), width: '120px' },
        { key: 'title', label: t('sheq.registers.col.title') },
        { key: 'competency_type', label: t('sheq.registers.col.type'), kind: 'muted' },
        { key: 'validity_months', label: t('sheq.registers.col.valid_months'), kind: 'muted' },
      ]"
    />
  </div>
</template>
