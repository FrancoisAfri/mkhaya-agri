<template>
  <VContainer fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_my_learning.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('lms_my_learning.page_description') }}</p>
      </div>
      <VBtn :href="route('lms.catalog')" variant="tonal" color="primary" size="small">
        {{ t('lms_my_learning.browse_catalog') }}
      </VBtn>
    </div>

    <!-- ── Learning Progress KPIs ─────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol v-for="kpi in progressKpis" :key="kpi.label" cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon :color="kpi.color" size="22" class="mb-1">{{ kpi.icon }}</VIcon>
            <div class="text-h6 font-weight-bold" :class="`text-${kpi.color}`">{{ kpi.value }}</div>
            <div class="text-caption text-medium-emphasis">{{ kpi.label }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- ── My Courses ──────────────────────────────────────────────── -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18">mdi-book-open-variant</VIcon> {{ t('lms_my_learning.my_courses') }}</span>
            <VChip size="x-small" color="primary">{{ myCourses.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <div style="max-height:320px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in myCourses.slice(0,10)" :key="c.id" class="py-2">
                <template #prepend>
                  <VChip :color="statusColor(c.status)" size="x-small" label class="mr-2">
                    {{ t(`lms_my_learning.statuses.${c.status}`) }}
                  </VChip>
                </template>
                <VListItemTitle class="text-body-2 font-weight-medium">{{ c.title }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VProgressLinear :model-value="c.progress_pct" :color="progressColor(c.progress_pct)"
                      rounded height="5" style="max-width:120px" />
                    <span class="text-caption">{{ c.progress_pct }}%</span>
                    <VChip v-if="c.is_mandatory" color="error" size="x-small" variant="tonal">
                      {{ t('lms_my_learning.mandatory') }}
                    </VChip>
                    <VChip v-if="c.has_cert" color="success" size="x-small" variant="tonal">
                      {{ t('lms_my_learning.certificate.certified') }}
                    </VChip>
                  </div>
                </VListItemSubtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ c.due_date ?? '' }}</span>
                </template>
              </VListItem>
              <VListItem v-if="!myCourses.length" class="text-center text-medium-emphasis pa-4" v-html="t('lms_my_learning.no_courses', { url: route('lms.catalog') })" />
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Mandatory Training ────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="error">mdi-alert-circle</VIcon> {{ t('lms_my_learning.mandatory_training') }}</span>
            <VChip size="x-small" :color="mandatoryPending > 0 ? 'error' : 'success'">
              {{ t('lms_my_learning.pending', { count: mandatoryPending }) }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <div style="max-height:320px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="m in mandatoryTraining" :key="m.course_id" class="py-2">
                <template #prepend>
                  <VIcon :color="statusColor(m.status)" size="18">
                    {{ m.status === 'completed' ? 'mdi-check-circle' : 'mdi-clock-alert' }}
                  </VIcon>
                </template>
                <VListItemTitle class="text-body-2">{{ m.title }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VProgressLinear :model-value="m.progress_pct" :color="progressColor(m.progress_pct)"
                      rounded height="4" style="max-width:80px" />
                    <span class="text-caption">{{ m.progress_pct }}%</span>
                    <span v-if="m.due_date" class="text-caption text-error">
                      {{ t('lms_my_learning.due_date', { date: m.due_date }) }}
                    </span>
                  </div>
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!mandatoryTraining.length" class="text-center text-medium-emphasis pa-4">
                {{ t('lms_my_learning.no_mandatory') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Overdue Training ──────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="error">mdi-calendar-remove</VIcon> {{ t('lms_my_learning.overdue_training') }}</span>
            <VChip size="x-small" :color="overdueTraining.length > 0 ? 'error' : 'success'">
              {{ overdueTraining.length }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <div style="max-height:260px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="o in overdueTraining" :key="`${o.source}-${o.course_id}`" class="py-2">
                <template #prepend>
                  <VIcon color="error" size="16">mdi-clock-alert-outline</VIcon>
                </template>
                <VListItemTitle class="text-body-2">{{ o.title }}</VListItemTitle>
                <VListItemSubtitle>
                  <span class="text-error text-caption">{{ t('lms_my_learning.days_overdue', { days: o.days_overdue }) }}</span>
                  <span class="text-caption text-medium-emphasis ml-2">
                    ({{ t('lms_my_learning.due_date', { date: o.due_date }) }})
                  </span>
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!overdueTraining.length" class="text-center text-medium-emphasis pa-4">
                <VIcon color="success">mdi-check-circle</VIcon> {{ t('lms_my_learning.all_up_to_date') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── My Understanding Scores ───────────────────────────────────── -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="info">mdi-brain</VIcon> {{ t('lms_my_learning.my_understanding') }}
          </VCardTitle>
          <VDivider />
          <div style="max-height:260px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="s in myUnderstanding" :key="s.course_id" class="py-2">
                <template #prepend>
                  <VChip :color="s.grade_color" size="small" label class="mr-2 font-weight-bold">{{ s.grade }}</VChip>
                </template>
                <VListItemTitle class="text-body-2">{{ s.course_title }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VProgressLinear :model-value="s.total_score" color="info" rounded height="5"
                      style="max-width:100px" />
                    <span class="text-caption">{{ fmt(s.total_score) }}%</span>
                    <VChip v-if="s.refresher_recommended" color="warning" size="x-small" variant="tonal">
                      {{ t('lms_my_learning.refresher') }}
                    </VChip>
                    <VChip v-for="f in s.flags" :key="f" color="error" size="x-small" variant="tonal">{{ f }}</VChip>
                  </div>
                </VListItemSubtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ s.calculated_at }}</span>
                </template>
              </VListItem>
              <VListItem v-if="!myUnderstanding.length" class="text-center text-medium-emphasis pa-4">
                {{ t('lms_my_learning.no_understanding') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── My Tests ────────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="purple">mdi-file-check</VIcon> {{ t('lms_my_learning.my_tests') }}</span>
            <div class="d-flex gap-2">
              <VChip color="success" size="x-small">{{ t('lms_my_learning.passed') }} {{ testsPassed }}</VChip>
              <VChip color="error" size="x-small">{{ t('lms_my_learning.failed') }} {{ testsFailed }}</VChip>
            </div>
          </VCardTitle>
          <VDivider />
          <div style="max-height:280px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="t in myTests" :key="t.id" class="py-2">
                <template #prepend>
                  <VIcon :color="t.passed ? 'success' : 'error'" size="18">
                    {{ t.passed ? 'mdi-check-circle' : 'mdi-close-circle' }}
                  </VIcon>
                </template>
                <VListItemTitle class="text-body-2">{{ t.course_title }}</VListItemTitle>
                <VListItemSubtitle>
                  {{ t('lms_my_learning.score_label', { score: t.score }) }}
                  <span class="text-caption text-medium-emphasis ml-2">{{ t.attempted_at?.slice(0,10) }}</span>
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!myTests.length" class="text-center text-medium-emphasis pa-4">
                {{ t('lms_my_learning.no_tests') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── My Certificates ──────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="amber-darken-2">mdi-certificate</VIcon> {{ t('lms_my_learning.my_certificates') }}</span>
            <VChip size="x-small" color="amber-darken-2">{{ myCertificates.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <div style="max-height:280px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in myCertificates" :key="c.id" class="py-2">
                <template #prepend>
                  <VIcon :color="c.is_expired ? 'error' : 'amber-darken-2'" size="18">
                    {{ c.is_expired ? 'mdi-certificate-outline' : 'mdi-certificate' }}
                  </VIcon>
                </template>
                <VListItemTitle class="text-body-2">{{ c.course_title }}</VListItemTitle>
                <VListItemSubtitle>
                  <span class="text-caption">{{ c.certificate_number }}</span>
                  <span class="text-caption text-medium-emphasis ml-2">{{ t('lms_my_learning.issued', { date: c.issued_at }) }}</span>
                  <VChip v-if="c.is_expired" color="error" size="x-small" class="ml-1">{{ t('lms_my_learning.expired') }}</VChip>
                  <VChip v-else-if="c.days_until_expiry !== null && c.days_until_expiry <= 30"
                    color="warning" size="x-small" class="ml-1">
                    {{ t('lms_my_learning.expires_in', { days: c.days_until_expiry }) }}
                  </VChip>
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!myCertificates.length" class="text-center text-medium-emphasis pa-4">
                {{ t('lms_my_learning.no_certificates') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Recommended Courses ───────────────────────────────────────── -->
      <VCol cols="12">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="deep-purple">mdi-robot</VIcon> {{ t('lms_my_learning.recommended') }}
          </VCardTitle>
          <VDivider />
          <VCardText v-if="!recommendedCourses.length" class="text-center text-medium-emphasis pa-4">
            {{ t('lms_my_learning.no_recommendations') }}
          </VCardText>
          <VRow v-else class="pa-3">
            <VCol v-for="r in recommendedCourses" :key="r.id" cols="12" sm="6" md="4">
              <VCard variant="tonal" color="deep-purple" rounded="lg">
                <VCardText class="pa-3">
                  <div class="text-body-2 font-weight-bold mb-1">{{ r.title }}</div>
                  <div class="text-caption text-medium-emphasis mb-2">{{ r.category }}</div>
                  <div v-if="r.reason" class="text-caption mb-2 font-italic">{{ r.reason }}</div>
                  <div class="d-flex align-center justify-space-between">
                    <VChip v-if="r.priority" :color="priorityColor(r.priority)" size="x-small">
                      {{ t(`lms_my_learning.priority.${r.priority}`) }}
                    </VChip>
                    <span v-if="r.duration_mins" class="text-caption">{{ r.duration_mins }} min</span>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  myCourses:         any[]
  mandatoryTraining: any[]
  overdueTraining:   any[]
  myTests:           any[]
  myUnderstanding:   any[]
  myCertificates:    any[]
  learningProgress:  Record<string, any>
  recommendedCourses: any[]
}>()

const lp = computed(() => props.learningProgress)

const progressKpis = computed(() => [
  { label: t('lms_my_learning.kpis.enrolled'),      value: lp.value.total_enrolled,    color: 'primary',        icon: 'mdi-book-open-variant' },
  { label: t('lms_my_learning.kpis.completed'),     value: lp.value.total_completed,   color: 'success',        icon: 'mdi-check-circle' },
  { label: t('lms_my_learning.kpis.in_progress'),   value: lp.value.total_in_progress, color: 'info',           icon: 'mdi-progress-clock' },
  { label: t('lms_my_learning.kpis.completion_rate'), value: `${lp.value.completion_rate}%`, color: 'success',    icon: 'mdi-chart-donut' },
  { label: t('lms_my_learning.kpis.certificates'),  value: lp.value.certificates_earned, color: 'amber-darken-2', icon: 'mdi-certificate' },
  { label: t('lms_my_learning.kpis.mandatory_done'),value: `${lp.value.mandatory_done}/${lp.value.mandatory_total}`, color: lp.value.mandatory_done < lp.value.mandatory_total ? 'error' : 'success', icon: 'mdi-alert-circle' },
])

const mandatoryPending = computed(() =>
  props.mandatoryTraining.filter(m => m.status !== 'completed').length
)

const testsPassed = computed(() => props.myTests.filter(t => t.passed).length)
const testsFailed = computed(() => props.myTests.filter(t => !t.passed).length)

function statusColor(status: string): string {
  const map: Record<string, string> = {
    completed: 'success', in_progress: 'info', enrolled: 'primary',
    not_started: 'default', overdue: 'error'
  }
  return map[status] ?? 'default'
}

function progressColor(pct: number): string {
  if (pct >= 80) return 'success'
  if (pct >= 40) return 'info'
  return 'warning'
}

function priorityColor(p: string): string {
  const map: Record<string, string> = {
    critical: 'error', high: 'warning', medium: 'info', low: 'default'
  }
  return map[p] ?? 'default'
}

function fmt(v: number | null | undefined): string {
  return v != null ? Number(v).toFixed(1) : '—'
}
</script>
