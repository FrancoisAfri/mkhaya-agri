<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

const props = defineProps<{rows: Record<string, any>[]; sites?: { id: number; name: string }[]; canManage?: boolean; noCompany?: boolean}>()

const siteItems = (props.sites ?? []).map(s => ({ title: s.name, value: s.id }))

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
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.risk.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.risk.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.risk.add')" action="/sheq/risk-assessments"
        :button-label="t('sheq.registers.risk.add')" :note="t('sheq.registers.risk.add_note')"
        :fields="[
        { key: 'title', label: t('sheq.registers.col.title'), required: true, cols: 12 },
        { key: 'ra_type', label: t('sheq.registers.col.type'), type: 'select', required: true,
          items: [
            { title: 'Baseline', value: 'BASELINE' },
            { title: 'Issue based', value: 'ISSUE_BASED' },
            { title: 'Continuous', value: 'CONTINUOUS' },
            { title: 'Task', value: 'TASK' },
          ] },
        { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select', items: siteItems },
        { key: 'effective_from', label: t('sheq.registers.col.effective_from'), type: 'date' },
        { key: 'scope', label: t('sheq.registers.col.scope'), type: 'textarea', cols: 12 },
      ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.risk.list')" :rows="rows"
      :search-keys="['reference', 'title', 'site_name']"
      :empty-hint="t('sheq.registers.risk.empty')"
      :row-href="(row) => `/sheq/risk-assessments/${row.uuid}`"
      :columns="[
        { key: 'reference', label: t('sheq.registers.col.reference'), width: '140px' },
        { key: 'title', label: t('sheq.registers.col.title') },
        { key: 'ra_type', label: t('sheq.registers.col.type'), kind: 'muted' },
        { key: 'site_name', label: t('sheq.registers.col.site'), kind: 'muted' },
        { key: 'status', label: t('sheq.registers.col.status'), kind: 'chip', chipColour: (r) => statusColour(r.status) },
        { key: 'review_due', label: t('sheq.registers.col.review_due'), kind: 'flag', flagField: 'review_overdue' },
      ]"
    />
  </div>
</template>
