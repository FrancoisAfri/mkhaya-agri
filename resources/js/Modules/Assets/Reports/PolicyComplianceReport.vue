<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Violation = {
  id: number
  uuid: string
  rule_name: string
  severity: string
  status: { value: string; label: string; color: string }
  asset_name: string | null
  user_name: string | null
  created_at: string
}

const props = defineProps<{
  violations: Violation[]
  totals: { open: number; resolved: number; exceptions_granted: number }
  bySeverity: { severity: string; count: number }[]
}>()
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">{{ t('assets_reports_policy_compliance.page_title') }}</h1>

    <VRow class="mb-4">
      <VCol cols="12" md="4"><VCard><VCardItem><div class="text-overline">{{ t('assets_reports_policy_compliance.stats.open_violations') }}</div><div class="text-h4">{{ props.totals.open }}</div></VCardItem></VCard></VCol>
      <VCol cols="12" md="4"><VCard><VCardItem><div class="text-overline">{{ t('assets_reports_policy_compliance.stats.resolved') }}</div><div class="text-h4">{{ props.totals.resolved }}</div></VCardItem></VCard></VCol>
      <VCol cols="12" md="4"><VCard><VCardItem><div class="text-overline">{{ t('assets_reports_policy_compliance.stats.exceptions_granted') }}</div><div class="text-h4">{{ props.totals.exceptions_granted }}</div></VCardItem></VCard></VCol>
    </VRow>

    <VCard class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('assets_reports_policy_compliance.by_severity.title') }}</VCardTitle>
        <div class="d-flex gap-3 flex-wrap">
          <VChip v-for="s in props.bySeverity" :key="s.severity"
            :color="s.severity === 'critical' ? 'error' : s.severity === 'high' ? 'orange' : s.severity === 'medium' ? 'warning' : 'default'">
            {{ s.severity }}: {{ s.count }}
          </VChip>
        </div>
      </VCardItem>
    </VCard>

    <VCard>
      <VCardItem>
        <VTable density="compact">
          <thead><tr><th>{{ t('assets_reports_policy_compliance.table.rule') }}</th><th>{{ t('assets_reports_policy_compliance.table.severity') }}</th><th>{{ t('assets_reports_policy_compliance.table.asset') }}</th><th>{{ t('assets_reports_policy_compliance.table.user') }}</th><th>{{ t('assets_reports_policy_compliance.table.status') }}</th><th>{{ t('assets_reports_policy_compliance.table.when') }}</th></tr></thead>
          <tbody>
            <tr v-for="v in props.violations" :key="v.id">
              <td>{{ v.rule_name }}</td>
              <td>{{ v.severity }}</td>
              <td>{{ v.asset_name ?? '—' }}</td>
              <td>{{ v.user_name ?? '—' }}</td>
              <td><VChip :color="v.status.color" size="small">{{ v.status.label }}</VChip></td>
              <td>{{ new Date(v.created_at).toLocaleDateString() }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
