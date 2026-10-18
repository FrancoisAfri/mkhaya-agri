<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.wellness.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.wellness.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-radar" :loading="detecting" @click="detectSignals">
          {{ t('pa.wellness.detect_signals') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-refresh" :loading="generating" @click="generateAll">
          {{ t('pa.wellness.generate_all') }}
        </VBtn>
      </div>
    </div>

    <!-- Cycle filter -->
    <VSelect
      v-model="selectedCycle"
      :items="cycles"
      item-title="name"
      item-value="id"
      label="Cycle"
      density="compact"
      variant="outlined"
      clearable
      hide-details
      style="max-width: 280px"
      class="mb-5"
      @update:model-value="applyFilter"
    />

    <!-- Stats strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-primary">{{ stats.avg_score }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.wellness.avg_score') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-error">{{ stats.critical_count }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.wellness.critical_signals') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-warning">{{ stats.high_risk }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.wellness.high_burnout_risk') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-orange">{{ stats.total_signals }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.wellness.active_signals') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="scores">
        <VIcon start>tabler-heartbeat</VIcon>{{ t('pa.wellness.tab_scores') }} ({{ wellnessScores.length }})
      </VTab>
      <VTab value="signals">
        <VIcon start>tabler-alert-triangle</VIcon>
        {{ t('pa.wellness.tab_signals') }}
        <VBadge v-if="signals.length" :content="signals.length" color="error" inline class="ml-1" />
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">
      <!-- Scores tab -->
      <VTabsWindowItem value="scores">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-user-plus" @click="scoreOpen = true">
            {{ t('pa.wellness.score_employee') }}
          </VBtn>
        </div>

        <VRow v-if="wellnessScores.length" dense>
          <VCol v-for="ws in wellnessScores" :key="ws.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar size="42" :color="gradeColor(ws.wellness_grade)" variant="flat">
                    <span class="text-body-1 font-weight-bold text-white">{{ ws.wellness_grade }}</span>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-medium">{{ ws.employee_name }}</VCardTitle>
                <VCardSubtitle>Score: {{ ws.wellness_score }}/100</VCardSubtitle>
                <template #append>
                  <div class="d-flex flex-column align-end ga-1">
                    <VChip size="x-small" :color="burnoutColor(ws.burnout_risk)" variant="tonal">
                      {{ ws.burnout_risk }} {{ t('pa.wellness.burnout') }}
                    </VChip>
                    <VChip size="x-small" :color="disengColor(ws.disengagement_level)" variant="tonal">
                      {{ ws.disengagement_level }} {{ t('pa.wellness.diseng') }}
                    </VChip>
                  </div>
                </template>
              </VCardItem>

              <VCardText class="pt-0 pb-2">
                <VProgressLinear
                  :model-value="ws.wellness_score"
                  :color="gradeColor(ws.wellness_grade)"
                  height="6"
                  rounded
                  class="mb-2"
                />
                <!-- Component breakdown -->
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span v-if="ws.kpi_score !== null">KPI {{ ws.kpi_score }}%</span>
                  <span v-if="ws.feedback_score !== null">FB {{ ws.feedback_score }}%</span>
                  <span v-if="ws.checkin_score !== null">CI {{ ws.checkin_score }}%</span>
                  <span v-if="ws.engagement_score !== null">Eng {{ ws.engagement_score }}%</span>
                </div>
                <!-- Signal chips -->
                <div v-if="ws.signals.length" class="d-flex flex-wrap ga-1 mt-2">
                  <VChip
                    v-for="sig in ws.signals"
                    :key="sig"
                    size="x-small"
                    color="error"
                    variant="tonal"
                  >
                    {{ sig.replace('_', ' ') }}
                  </VChip>
                </div>
              </VCardText>

              <VCardActions class="px-4 pb-3 pt-0">
                <span class="text-caption text-medium-emphasis">{{ ws.created_at }}</span>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">
          {{ t('pa.wellness.empty_scores') }}
        </VAlert>
      </VTabsWindowItem>

      <!-- Signals tab -->
      <VTabsWindowItem value="signals">
        <VList v-if="signals.length" class="pa-0">
          <VListItem
            v-for="sig in signals"
            :key="sig.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="severityColor(sig.severity)" variant="tonal" size="36">
                <VIcon size="16">{{ signalIcon(sig.signal_type) }}</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ sig.title }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ sig.employee_name }} · {{ sig.created_at }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="severityColor(sig.severity)" variant="flat">
                  {{ sig.severity }}
                </VChip>
                <VBtn
                  size="x-small"
                  variant="tonal"
                  color="success"
                  @click="acknowledge(sig.id)"
                >
                  {{ t('pa.wellness.acknowledge') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="success" variant="tonal">
          {{ t('pa.wellness.no_signals') }}
        </VAlert>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- Score employee dialog -->
    <VDialog v-model="scoreOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.wellness.generate_score_title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="scoreForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('pa.common.employee')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-model="scoreForm.cycle_id"
            :items="cycles"
            item-title="name"
            item-value="id"
            :label="t('pa.common.cycle_optional')"
            density="compact"
            variant="outlined"
            clearable
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="scoreOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingScore" @click="submitScore">{{ t('pa.wellness.generate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface WellnessScore {
  id: number; employee_id: number; employee_name: string; wellness_score: number
  wellness_grade: string; burnout_risk: string; disengagement_level: string
  kpi_score: number | null; feedback_score: number | null; checkin_score: number | null
  engagement_score: number | null; signals: string[]; created_at: string
}

interface Signal {
  id: number; employee_id: number; employee_name: string; signal_type: string
  severity: string; title: string; description: string; data: any; created_at: string
}

interface Cycle    { id: number; name: string }
interface Employee { id: number; name: string }

const props = defineProps<{
  wellnessScores: WellnessScore[]
  signals:        Signal[]
  cycles:         Cycle[]
  employees:      Employee[]
  selectedCycle:  number | null
  stats:          { avg_score: number; critical_count: number; high_risk: number; total_signals: number }
}>()

const tab           = ref('scores')
const selectedCycle = ref<number | null>(props.selectedCycle)
const detecting     = ref(false)
const generating    = ref(false)

function applyFilter() {
  router.get(route('performance.wellness.index'), {
    cycle_id: selectedCycle.value ?? undefined,
  }, { preserveState: true, replace: true })
}

function detectSignals() {
  if (!selectedCycle.value) return
  detecting.value = true
  router.post(route('performance.wellness.signals'), { cycle_id: selectedCycle.value }, {
    onFinish: () => { detecting.value = false },
  })
}

function generateAll() {
  generating.value = true
  router.post(route('performance.wellness.score.all'), { cycle_id: selectedCycle.value }, {
    onFinish: () => { generating.value = false },
  })
}

function acknowledge(id: number) {
  router.patch(route('performance.wellness.acknowledge', id))
}

// Score single employee
const scoreOpen   = ref(false)
const savingScore = ref(false)
const scoreForm   = reactive({ employee_id: null as number | null, cycle_id: null as number | null })

function submitScore() {
  savingScore.value = true
  router.post(route('performance.wellness.score'), scoreForm as any, {
    onSuccess: () => { scoreOpen.value = false },
    onFinish:  () => { savingScore.value = false },
  })
}

// Helpers
function gradeColor(grade: string) {
  return { A: 'green', B: 'blue', C: 'orange', D: 'deep-orange', F: 'error' }[grade] ?? 'grey'
}

function burnoutColor(level: string) {
  return { low: 'green', medium: 'orange', high: 'error', critical: 'deep-purple' }[level] ?? 'grey'
}

function disengColor(level: string) {
  return { low: 'green', medium: 'orange', high: 'error' }[level] ?? 'grey'
}

function severityColor(severity: string) {
  return { info: 'blue', warning: 'orange', critical: 'error' }[severity] ?? 'grey'
}

function signalIcon(type: string) {
  return {
    burnout:               'tabler-flame',
    disengagement:         'tabler-user-off',
    productivity_decline:  'tabler-trending-down',
    workload_imbalance:    'tabler-scale',
    attendance_correlation:'tabler-calendar-exclamation',
  }[type] ?? 'tabler-alert-triangle'
}
</script>
