<script setup lang="ts">
import { ref } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  stats: { total_plans: number; completed_plans: number; active_plans: number; total_interviews: number; total_clearances: number; pending_clearances: number }
}>()

const dateFrom = ref(''); const dateTo = ref(''); const status = ref(''); const loading = ref<string | null>(null)

const reports = computed(() => [
  { key: 'plans_summary', title: t('offboarding.reports_plan_summary'), description: t('offboarding.reports_plan_summary_desc'), icon: 'tabler-clipboard-list', color: 'primary', supportsStatus: true },
  { key: 'exit_interviews', title: t('offboarding.reports_exit_interviews'), description: t('offboarding.reports_exit_interviews_desc'), icon: 'tabler-message-circle', color: 'success', supportsStatus: false },
  { key: 'clearance_status', title: t('offboarding.reports_clearance'), description: t('offboarding.reports_clearance_desc'), icon: 'tabler-shield-check', color: 'warning', supportsStatus: true },
])

const statusOptions = computed(() => [
  { title: t('offboarding.reports_all_statuses'), value: '' },
  { title: t('offboarding.active'), value: 'active' },
  { title: t('offboarding.completed'), value: 'completed' },
  { title: t('offboarding.pending'), value: 'pending' },
  { title: t('offboarding.status_cancelled'), value: 'cancelled' },
])

function download(key: string) { loading.value = key; const params: Record<string, string> = {}; if (dateFrom.value) params.date_from = dateFrom.value; if (dateTo.value) params.date_to = dateTo.value; if (status.value) params.status = status.value; const qs = new URLSearchParams(params).toString(); const url = route('offboarding.reports.export', { key }) + (qs ? `?${qs}` : ''); window.location.href = url; setTimeout(() => { loading.value = null }, 1500) }
</script>

<template>
  <VCard>
    <VCardItem>
      <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-file-analytics" size="22" />{{ t('offboarding.reports_title') }}</VCardTitle>
      <template #append>
        <VBtn variant="tonal" color="secondary" size="small" :href="route('offboarding.analytics')"><VIcon start icon="tabler-chart-bar" size="16" />{{ t('offboarding.reports_analytics_btn') }}</VBtn>
        <VBtn variant="tonal" color="secondary" size="small" class="ms-2" :href="route('offboarding.index')"><VIcon start icon="tabler-arrow-left" size="16" />{{ t('offboarding.reports_plans_btn') }}</VBtn>
      </template>
    </VCardItem>
    <VDivider />

    <VCardText>
      <VRow>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="primary" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.total_plans }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_total') }}</div></VCardText></VCard></VCol>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="success" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.completed_plans }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_completed') }}</div></VCardText></VCard></VCol>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="warning" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.active_plans }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_active') }}</div></VCardText></VCard></VCol>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="info" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.total_interviews }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_interviews') }}</div></VCardText></VCard></VCol>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="secondary" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.total_clearances }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_clearances') }}</div></VCardText></VCard></VCol>
        <VCol cols="6" sm="4" md="2"><VCard variant="tonal" color="error" rounded="lg"><VCardText class="pa-3 text-center"><div class="text-h5 font-weight-bold">{{ props.stats.pending_clearances }}</div><div class="text-caption">{{ t('offboarding.reports_kpi_pending_clearances') }}</div></VCardText></VCard></VCol>
      </VRow>
    </VCardText>
    <VDivider />

    <VCardText>
      <VRow align="center">
        <VCol cols="12" sm="4"><VTextField v-model="dateFrom" :label="t('offboarding.reports_date_from')" type="date" density="compact" variant="outlined" clearable hide-details /></VCol>
        <VCol cols="12" sm="4"><VTextField v-model="dateTo" :label="t('offboarding.reports_date_to')" type="date" density="compact" variant="outlined" clearable hide-details /></VCol>
        <VCol cols="12" sm="4"><VSelect v-model="status" :label="t('offboarding.reports_status_filter')" density="compact" variant="outlined" clearable hide-details :items="statusOptions" item-title="title" item-value="value" /></VCol>
      </VRow>
      <div class="text-caption text-medium-emphasis mt-1">{{ t('offboarding.reports_filter_hint') }}</div>
    </VCardText>
    <VDivider />

    <VCardText>
      <VRow>
        <VCol v-for="report in reports" :key="report.key" cols="12" md="6">
          <VCard variant="outlined" rounded="lg" height="100%">
            <VCardItem>
              <template #prepend><VAvatar :color="report.color" variant="tonal" size="42" rounded="lg"><VIcon :icon="report.icon" size="22" /></VAvatar></template>
              <VCardTitle class="text-body-1 font-weight-semibold">{{ report.title }}</VCardTitle>
            </VCardItem>
            <VCardText class="pt-0">
              <p class="text-body-2 text-medium-emphasis mb-4">{{ report.description }}</p>
              <div class="d-flex align-center gap-2 text-caption text-medium-emphasis mb-4">
                <VIcon icon="tabler-file-type-csv" size="14" />{{ t('offboarding.reports_csv_format') }} · <VIcon icon="tabler-clock" size="14" />{{ t('offboarding.reports_live_data') }}
                <span v-if="report.supportsStatus">· <VIcon icon="tabler-filter" size="14" />{{ t('offboarding.reports_supports_filter') }}</span>
              </div>
              <VBtn :color="report.color" variant="tonal" :loading="loading === report.key" prepend-icon="tabler-download" @click="download(report.key)">{{ t('offboarding.reports_download_csv') }}</VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VCardText>
  </VCard>
</template>
