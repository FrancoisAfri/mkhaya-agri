<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type PolicyRule = {
  id: number; code: string; name: string
  category: string; category_label: string
  threshold: number; threshold_unit: string; period_days: number
  severity: string; severity_color: string
  action: string; action_label: string
  auto_escalate: boolean; active: boolean
}
type Violation = {
  id: number; employee: string; policy_rule: string
  category_label: string; severity: string; severity_color: string
  action: string; detected_value: string; threshold_value: string
  status: string; status_color: string; blocked: boolean
  period_start: string; period_end: string
  ai_enforcement_decision: string | null
}

const props = defineProps<{
  recentViolations: Violation[]
  rules: PolicyRule[]
  summary: { open: number; escalated: number; resolved: number; blocked: number }
}>()

const subNav      = ref('engine')
const scanDialog  = ref(false)
const expandedId  = ref<number | null>(null)

const scanForm = useForm({
  period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
  period_end:   new Date().toISOString().split('T')[0],
})

function runScan() { scanForm.post('/attendance/policy/scan', { onSuccess: () => { scanDialog.value = false } }) }
function toggleRule(id: number) { router.post(`/attendance/policy/rules/${id}/toggle`, {}, { preserveState: false }) }

const severityIcon: Record<string, string> = {
  low: 'fa-info-circle', medium: 'fa-exclamation-triangle',
  high: 'fa-exclamation-circle', critical: 'fa-skull-crossbones',
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="engine" href="/attendance/policy"><VIcon start size="16" icon="tabler-cog-outline" />{{ t('attendance_policy_pages.tab_policy_engine') }}</VTab>
            <VTab value="violations" href="/attendance/policy/violations"><VIcon start size="16" icon="tabler-alert" />{{ t('attendance_policy_pages.tab_violations') }}</VTab>
            <VTab value="compliance" href="/attendance/policy/compliance"><VIcon start size="16" icon="tabler-shield-check" />{{ t('attendance_policy_pages.tab_compliance') }}</VTab>
            <VTab value="exceptions" href="/attendance/policy/exceptions"><VIcon start size="16" icon="tabler-shield-off-outline" />{{ t('attendance_policy_pages.tab_exceptions') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_policy_pages.engine_header') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_policy_pages.engine_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_policy_pages.engine_subtitle') }}</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <VBtn variant="text" color="info" href="/attendance/policy/violations"><i class="fas fa-exclamation-triangle me-2"></i>{{ t('attendance_policy_pages.all_violations_btn') }}</VBtn>
              <VBtn variant="text" color="success" href="/attendance/policy/compliance"><i class="fas fa-chart-bar me-2"></i>{{ t('attendance_policy_pages.compliance_scores_btn') }}</VBtn>
              <VBtn color="primary" @click="scanDialog = true"><i class="fas fa-search-plus me-2"></i>{{ t('attendance_policy_pages.run_policy_scan') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" md="3"><VCard color="error" variant="tonal" class="text-center"><VCardText class="py-4"><div class="text-h4 font-weight-bold">{{ summary.open }}</div><div class="text-caption">{{ t('attendance_policy_pages.open_violations') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard color="deep-purple" variant="tonal" class="text-center"><VCardText class="py-4"><div class="text-h4 font-weight-bold">{{ summary.escalated }}</div><div class="text-caption">{{ t('attendance_policy_pages.escalated') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard color="success" variant="tonal" class="text-center"><VCardText class="py-4"><div class="text-h4 font-weight-bold">{{ summary.resolved }}</div><div class="text-caption">{{ t('attendance_policy_pages.resolved') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard color="warning" variant="tonal" class="text-center"><VCardText class="py-4"><div class="text-h4 font-weight-bold">{{ summary.blocked }}</div><div class="text-caption">{{ t('attendance_policy_pages.blocked_actions') }}</div></VCardText></VCard></VCol>

      <VCol cols="12" md="7">
        <VCard>
          <VCardTitle class="py-3 px-5 d-flex align-center justify-space-between"><span>{{ t('attendance_policy_pages.recent_open_violations') }}</span><VBtn size="small" variant="text" color="primary" href="/attendance/policy/violations">{{ t('attendance_policy_pages.view_all') }}</VBtn></VCardTitle>
          <VDivider />
          <VTable hover density="comfortable">
            <thead><tr><th>{{ t('attendance_policy_pages.table_employee') }}</th><th>{{ t('attendance_policy_pages.table_policy_rule') }}</th><th>{{ t('attendance_policy_pages.table_severity') }}</th><th>{{ t('attendance_policy_pages.table_status') }}</th><th>{{ t('attendance_policy_pages.table_detected') }}</th></tr></thead>
            <tbody>
              <tr v-if="!recentViolations.length"><td colspan="5" class="text-center py-8 text-medium-emphasis">{{ t('attendance_policy_pages.no_open_violations') }}</td></tr>
              <template v-for="v in recentViolations" :key="v.id">
                <tr @click="expandedId = expandedId === v.id ? null : v.id" style="cursor:pointer">
                  <td class="font-weight-medium">{{ v.employee }}</td>
                  <td class="text-caption">{{ v.policy_rule }}</td>
                  <td><VChip :color="v.severity_color" size="x-small" variant="tonal" class="text-capitalize"><i :class="'fas ' + severityIcon[v.severity]" style="font-size:9px;margin-right:3px"></i>{{ v.severity }}</VChip></td>
                  <td><VChip :color="v.status_color" size="x-small" variant="tonal" class="text-capitalize">{{ v.status }}</VChip><VChip v-if="v.blocked" color="error" size="x-small" variant="outlined" class="ms-1">{{ t('attendance_policy_pages.blocked_chip') }}</VChip></td>
                  <td class="text-caption text-medium-emphasis">{{ v.detected_value }}</td>
                </tr>
                <tr v-if="expandedId === v.id">
                  <td colspan="5" class="py-2 px-6" style="background:rgba(0,0,0,0.02)">
                    <VAlert v-if="v.ai_enforcement_decision" type="warning" variant="tonal" density="compact" class="mb-0" style="font-size:0.82rem"><i class="fas fa-robot me-2"></i><strong>{{ t('attendance_policy_pages.ai_decision') }}</strong> {{ v.ai_enforcement_decision }}</VAlert>
                    <div v-else class="text-caption text-medium-emphasis font-italic">{{ t('attendance_policy_pages.threshold_period', { threshold: v.threshold_value, start: v.period_start, end: v.period_end }) }}</div>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard>
          <VCardTitle class="py-3 px-5">{{ t('attendance_policy_pages.active_policy_rules') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-if="!rules.length" class="text-medium-emphasis text-caption py-4 text-center">{{ t('attendance_policy_pages.no_rules_configured') }}</VListItem>
            <VListItem v-for="r in rules" :key="r.id" class="py-2">
              <template #prepend><VIcon :color="r.severity_color" size="18"><i :class="'fas ' + severityIcon[r.severity]"></i></VIcon></template>
              <VListItemTitle class="text-caption font-weight-medium">{{ r.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ r.category_label }} · ≥{{ r.threshold }} {{ r.threshold_unit }} in {{ r.period_days }}d <VChip size="x-small" :color="r.active ? 'success' : 'secondary'" variant="tonal" class="ms-1">{{ r.action_label }}</VChip></VListItemSubtitle>
              <template #append><VSwitch :model-value="r.active" density="compact" hide-details color="primary" @change="toggleRule(r.id)" /></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="scanDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_policy_pages.scan_dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4" style="font-size:0.82rem">{{ t('attendance_policy_pages.scan_dialog_info') }}</VAlert>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="scanForm.period_start" :label="t('attendance_policy_pages.field_period_start')" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="scanForm.period_end" :label="t('attendance_policy_pages.field_period_end')" type="date" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="scanDialog = false">{{ t('attendance_policy_pages.cancel') }}</VBtn><VBtn color="primary" :loading="scanForm.processing" @click="runScan"><i class="fas fa-search-plus me-2"></i>{{ t('attendance_policy_pages.run_scan') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
