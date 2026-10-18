<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

const props = defineProps<{
  rows: Record<string, any>[]
  sites?: { id: number; name: string }[]
  /** For attributing a permit to the firm doing the work (CON-20). */
  contractors?: { id: number; name: string }[]
  canManage?: boolean
  noCompany?: boolean
}>()

const siteItems = (props.sites ?? []).map(s => ({ title: s.name, value: s.id }))

const contractorItems = (props.contractors ?? []).map(c => ({ title: c.name, value: c.id }))

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
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.permits.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.permits.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.permits.add')" action="/sheq/permits"
        :button-label="t('sheq.registers.permits.add')" :note="t('sheq.registers.permits.add_note')"
        :fields="[
        { key: 'permit_type', label: t('sheq.registers.col.type'), type: 'select', required: true,
          items: [
            { title: 'Hot work', value: 'HOT_WORK' },
            { title: 'Confined space', value: 'CONFINED_SPACE' },
            { title: 'Working at height', value: 'WORKING_AT_HEIGHT' },
            { title: 'Electrical', value: 'ELECTRICAL' },
            { title: 'Excavation', value: 'EXCAVATION' },
            { title: 'Lifting', value: 'LIFTING' },
          ] },
        { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select', items: siteItems },
        /*
         * Whose work this is. Optional, because most permits on most sites are for the host's
         * own employees — but without it a suspended permit cannot be attributed to anybody, and
         * permit violations stay uncountable on the contractor score.
         */
        { key: 'contractor_id', label: 'Contractor doing the work', type: 'select',
          items: contractorItems,
          hint: 'Leave empty for your own employees. Needed for this permit to count towards a contractor\'s safety record.' },
        { key: 'work_description', label: t('sheq.registers.col.work'), type: 'textarea', required: true, cols: 12 },
        { key: 'location_text', label: t('sheq.registers.col.where'), cols: 12 },
        { key: 'valid_from', label: t('sheq.registers.col.valid_from'), type: 'date', required: true },
        { key: 'valid_to', label: t('sheq.registers.col.valid_to'), type: 'date', required: true },
      ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.permits.list')" :rows="rows"
      :search-keys="['reference', 'permit_type', 'work_description', 'site_name']"
      :empty-hint="t('sheq.registers.permits.empty')"
      :row-href="(row) => `/sheq/permits/${row.uuid}`"
      :columns="[
        { key: 'reference', label: t('sheq.registers.col.reference'), width: '140px' },
        { key: 'permit_type', label: t('sheq.registers.col.type') },
        { key: 'work_description', label: t('sheq.registers.col.work') },
        { key: 'site_name', label: t('sheq.registers.col.site'), kind: 'muted' },
        { key: 'contractor_name', label: 'Contractor', kind: 'muted' },
        { key: 'status', label: t('sheq.registers.col.status'), kind: 'chip', chipColour: (r) => statusColour(r.status) },
        { key: 'valid_to', label: t('sheq.registers.col.valid_to'), kind: 'flag', flagField: 'expired' },
      ]"
    />
  </div>
</template>
