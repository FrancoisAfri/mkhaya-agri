<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Prediction = {
  id: number; type: string; type_label: string; scope: string
  department: string | null; employee: string | null
  confidence: number; risk_level: string; risk_color: string
  predicted_for: string; evidence: Record<string, any> | null
  ai_explanation: string | null; status: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  predictions: Paginated<Prediction>
  filters:     { type: string; status: string }
  types:       Record<string, string>
}>()

const subNav       = ref('predictions')
const filterType   = ref(props.filters.type)
const filterStatus = ref(props.filters.status)
const dismissId    = ref<number | null>(null)

const filterTypeOptions = computed(() => [
  { title: t('attendance_workforce.filter_all_types'), value: 'all' },
  ...Object.entries(props.types).map(([v, t]) => ({ title: t, value: v })),
])

const filterStatusOptions = computed(() => [
  { title: t('attendance_workforce.status_all'), value: 'all' },
  { title: t('attendance_workforce.status_active'), value: 'active' },
  { title: t('attendance_workforce.status_confirmed'), value: 'confirmed' },
  { title: t('attendance_workforce.status_dismissed'), value: 'dismissed' },
])

function applyFilter() {
  router.get('/attendance/workforce/predictions', {
    type: filterType.value || undefined,
    status: filterStatus.value,
  }, { preserveState: true })
}

function dismiss(id: number) { dismissId.value = id; useForm({}).post(`/attendance/workforce/predictions/${id}/dismiss`, { onFinish: () => { dismissId.value = null } }) }

const typeIcons: Record<string, string> = {
  absenteeism_spike: 'fas fa-user-slash', turnover_risk: 'fas fa-door-open',
  understaffing: 'fas fa-users-slash', fatigue_outbreak: 'fas fa-bed',
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="workforce" href="/attendance/workforce"><VIcon start size="16" icon="tabler-account-group" />{{ t('attendance_workforce.tab_overview') }}</VTab>
            <VTab value="burnout" href="/attendance/workforce/burnout-profiles"><VIcon start size="16" icon="tabler-fire" />{{ t('attendance_workforce.tab_burnout') }}</VTab>
            <VTab value="predictions" href="/attendance/workforce/predictions"><VIcon start size="16" icon="tabler-crystal-ball" />{{ t('attendance_workforce.tab_predictions') }}</VTab>
            <VTab value="wellness" href="/attendance/wellness"><VIcon start size="16" icon="tabler-heart-pulse" />{{ t('attendance_workforce.tab_wellness') }}</VTab>
            <VTab value="dept-wellness" href="/attendance/wellness/departments"><VIcon start size="16" icon="tabler-domain" />{{ t('attendance_workforce.tab_dept_wellness') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_workforce.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_workforce.predictions_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_workforce.predictions_subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="primary" href="/attendance/workforce"><i class="fas fa-heartbeat me-2"></i>{{ t('attendance_workforce.wellness_scores_btn') }}</VBtn>
              <VBtn variant="text" color="info" href="/attendance/workforce/burnout-profiles"><i class="fas fa-fire me-2"></i>{{ t('attendance_workforce.burnout_profiles_btn') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VSelect v-model="filterType" :items="filterTypeOptions" :label="t('attendance_workforce.filter_type')" density="compact" hide-details style="max-width:220px" />
            <VSelect v-model="filterStatus" :items="filterStatusOptions" :label="t('attendance_workforce.filter_status')" density="compact" hide-details style="max-width:160px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_workforce.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!predictions.data.length" cols="12">
        <VAlert type="info" variant="tonal">{{ t('attendance_workforce.no_predictions') }}</VAlert>
      </VCol>

      <VCol cols="12" md="6" xl="4" v-for="p in predictions.data" :key="p.id">
        <VCard height="100%">
          <VCardTitle class="py-3 px-5 d-flex align-center gap-2">
            <VAvatar :color="p.risk_color" variant="tonal" size="32"><i :class="typeIcons[p.type] ?? 'fas fa-exclamation-triangle'" style="font-size:12px"></i></VAvatar>
            <span class="text-body-2 font-weight-medium">{{ p.type_label }}</span>
            <VSpacer />
            <VChip :color="p.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ p.risk_level }}</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="d-flex justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.scope') }}</span><span class="text-caption text-capitalize">{{ p.scope }}</span></div>
            <div class="d-flex justify-space-between mb-2" v-if="p.department"><span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.department') }}</span><span class="text-caption font-weight-medium">{{ p.department }}</span></div>
            <div class="d-flex justify-space-between mb-2" v-if="p.employee"><span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.employee') }}</span><span class="text-caption font-weight-medium">{{ p.employee }}</span></div>
            <div class="d-flex justify-space-between mb-3"><span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.confidence') }}</span><VChip :color="p.confidence >= 70 ? 'error' : 'warning'" size="x-small" variant="tonal">{{ p.confidence }}%</VChip></div>
            <div v-if="p.evidence" class="mb-3 pa-2 rounded" style="background:rgba(0,0,0,0.04)">
              <div class="text-caption font-weight-medium mb-1">{{ t('attendance_workforce.evidence') }}</div>
              <div v-for="(val, key) in p.evidence" :key="key" class="d-flex justify-space-between text-caption mb-1"><span class="text-medium-emphasis text-capitalize">{{ String(key).replace(/_/g,' ') }}</span><span class="font-weight-medium">{{ val }}</span></div>
            </div>
            <VAlert v-if="p.ai_explanation" type="info" variant="tonal" density="compact" style="font-size:0.8rem"><i class="fas fa-robot me-1"></i>{{ p.ai_explanation }}</VAlert>
            <div class="d-flex align-center justify-space-between mt-2">
              <span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.predicted_for') }} {{ p.predicted_for }}</span>
              <VChip :color="p.status === 'active' ? 'warning' : p.status === 'confirmed' ? 'success' : 'secondary'" size="x-small" variant="tonal">{{ p.status }}</VChip>
            </div>
          </VCardText>
          <VCardActions class="px-5 pb-3" v-if="p.status === 'active'">
            <VBtn size="small" variant="text" color="secondary" :loading="dismissId === p.id" @click="dismiss(p.id)"><i class="fas fa-times me-1"></i>{{ t('attendance_workforce.dismiss') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <VCol cols="12" v-if="predictions.last_page > 1">
        <div class="d-flex justify-center">
          <VPagination :model-value="predictions.current_page" :length="predictions.last_page" @update:model-value="page => router.get('/attendance/workforce/predictions', { page }, { preserveState: true })" />
        </div>
      </VCol>
    </VRow>
  </VContainer>
</template>
