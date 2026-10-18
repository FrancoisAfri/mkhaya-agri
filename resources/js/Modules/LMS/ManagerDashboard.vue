<template>
  <VContainer fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_team_dashboard.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('lms_team_dashboard.page_description') }}</p>
      </div>
    </div>

    <!-- ── Team Summary KPIs ───────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol v-for="kpi in summaryKpis" :key="kpi.label" cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon :color="kpi.color" size="22" class="mb-1">{{ kpi.icon }}</VIcon>
            <div class="text-h6 font-weight-bold" :class="`text-${kpi.color}`">{{ kpi.value }}</div>
            <div class="text-caption text-medium-emphasis">{{ kpi.label }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-if="!teamSummary.team_size" type="info" variant="tonal" class="mb-4">
      {{ t('lms_team_dashboard.no_team') }}
    </VAlert>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="completion">{{ t('lms_team_dashboard.tabs.completion') }}</VTab>
      <VTab value="understanding">{{ t('lms_team_dashboard.tabs.understanding') }}</VTab>
      <VTab value="tests">{{ t('lms_team_dashboard.tabs.tests') }}</VTab>
      <VTab value="skills">{{ t('lms_team_dashboard.tabs.skills') }}</VTab>
      <VTab value="overdue">{{ t('lms_team_dashboard.tabs.overdue') }}</VTab>
      <VTab value="certs">{{ t('lms_team_dashboard.tabs.certs') }}</VTab>
      <VTab value="devplans">{{ t('lms_team_dashboard.tabs.devplans') }}</VTab>
      <VTab value="learnerships">{{ t('lms_team_dashboard.tabs.learnerships') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- ── Tab 1: Team Training Completion ────────────────────────────── -->
      <VWindowItem value="completion">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4">{{ t('lms_team_dashboard.completion.title') }}</VCardTitle>
          <VDivider />
          <VDataTable :headers="completionHeaders" :items="teamCompletion" :items-per-page="25" class="text-body-2">
            <template #item.completion_rate="{ item }">
              <div class="d-flex align-center gap-2" style="min-width:140px">
                <VProgressLinear :model-value="item.completion_rate" :color="rateColor(item.completion_rate)"
                  rounded height="8" style="min-width:90px" />
                <span class="text-caption font-weight-medium">{{ item.completion_rate }}%</span>
              </div>
            </template>
            <template #item.mandatory_done="{ item }">
              <VChip :color="item.mandatory_done < item.mandatory_total ? 'warning' : 'success'" size="x-small" label>
                {{ item.mandatory_done }}/{{ item.mandatory_total }}
              </VChip>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 2: Team Understanding Scores ───────────────────────────── -->
      <VWindowItem value="understanding">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4">{{ t('lms_team_dashboard.understanding.title') }}</VCardTitle>
          <VDivider />
          <VDataTable :headers="understandingHeaders" :items="teamUnderstanding" :items-per-page="25" class="text-body-2">
            <template #item.avg_score="{ item }">
              <div class="d-flex align-center gap-2">
                <VProgressLinear v-if="item.avg_score != null" :model-value="item.avg_score"
                  :color="rateColor(item.avg_score)" rounded height="8" style="min-width:80px" />
                <span :class="item.avg_score != null ? 'font-weight-medium' : 'text-medium-emphasis'">
                  {{ item.avg_score != null ? fmt(item.avg_score) + '%' : '—' }}
                </span>
              </div>
            </template>
            <template #item.top_grade="{ item }">
              <VChip v-if="item.top_grade" :color="gradeColor(item.top_grade)" size="small" label>
                {{ item.top_grade }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.flag_count="{ item }">
              <VChip v-if="item.flag_count > 0" color="warning" size="x-small">
                {{ item.flag_count }} {{ t('lms_team_dashboard.understanding.flags').toLowerCase() }}
              </VChip>
              <VIcon v-else color="success" size="16">mdi-check</VIcon>
            </template>
            <template #item.refresher_needed="{ item }">
              <VChip v-if="item.refresher_needed > 0" color="orange" size="x-small">{{ item.refresher_needed }}</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.expand="{ item }">
              <VBtn size="x-small" variant="text" @click="expandedUser = expandedUser === item.user_id ? null : item.user_id">
                <VIcon size="14">{{ expandedUser === item.user_id ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</VIcon>
              </VBtn>
            </template>
          </VDataTable>
          <!-- Expanded course scores -->
          <div v-if="expandedUser" class="pa-4 border-t">
            <div class="text-subtitle-2 mb-2">
              {{ t('lms_team_dashboard.understanding.course_scores', { name: teamUnderstanding.find(u => u.user_id === expandedUser)?.name }) }}
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('lms_team_dashboard.understanding.course') }}</th>
                  <th>{{ t('lms_team_dashboard.understanding.score') }}</th>
                  <th>{{ t('lms_team_dashboard.understanding.grade') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in teamUnderstanding.find(u => u.user_id === expandedUser)?.scores" :key="s.course">
                  <td>{{ s.course }}</td>
                  <td>{{ fmt(s.total_score) }}%</td>
                  <td><VChip :color="gradeColor(s.grade)" size="x-small">{{ s.grade }}</VChip></td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 3: Tests & Assessments ────────────────────────────────── -->
      <VWindowItem value="tests">
        <VRow>
          <VCol cols="12" md="6">
            <VCard border rounded="lg" class="mb-4">
              <VCardTitle class="pa-4">{{ t('lms_team_dashboard.tests.failed_tests') }}</VCardTitle>
              <VDivider />
              <VList density="compact" style="max-height:400px;overflow-y:auto">
                <VListItem v-for="f in teamFailedTests" :key="f.user_id" class="py-2">
                  <VListItemTitle class="text-body-2 font-weight-medium">
                    {{ f.name }} <VChip color="error" size="x-small" class="ml-1">{{ f.fail_count }} {{ t('lms_team_dashboard.tests.failed_tests').toLowerCase() }}</VChip>
                  </VListItemTitle>
                  <VListItemSubtitle>
                    <div v-for="a in f.attempts.slice(0,3)" :key="a.course_id" class="text-caption">
                      {{ a.course_title }} — {{ a.score }}% ({{ a.attempted_at }})
                    </div>
                  </VListItemSubtitle>
                </VListItem>
                <VListItem v-if="!teamFailedTests.length" class="text-center text-medium-emphasis pa-4">
                  <VIcon color="success">mdi-check-circle</VIcon> {{ t('lms_team_dashboard.tests.no_failed') }}
                </VListItem>
              </VList>
            </VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard border rounded="lg">
              <VCardTitle class="pa-4">{{ t('lms_team_dashboard.tests.recent_assessments') }}</VCardTitle>
              <VDivider />
              <VDataTable :headers="assessmentHeaders" :items="assessmentResults.slice(0,20)" :items-per-page="20"
                class="text-body-2">
                <template #item.passed="{ item }">
                  <VIcon :color="item.passed ? 'success' : 'error'" size="16">
                    {{ item.passed ? 'mdi-check-circle' : 'mdi-close-circle' }}
                  </VIcon>
                </template>
                <template #item.score="{ item }">
                  <span :class="item.score >= 60 ? 'text-success' : 'text-error'">{{ item.score }}%</span>
                </template>
              </VDataTable>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ── Tab 4: Skills Gaps ────────────────────────────────────────── -->
      <VWindowItem value="skills">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center justify-space-between">
            <span>{{ t('lms_team_dashboard.skills.title') }}</span>
            <VChip :color="teamSkillGaps.length > 0 ? 'warning' : 'success'" size="small">
              {{ t('lms_team_dashboard.skills.members_with_gaps', { count: teamSkillGaps.length }) }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <template v-for="member in teamSkillGaps" :key="member.user_id">
              <VListItem class="py-3">
                <template #prepend>
                  <VChip color="warning" size="small" class="mr-2">
                    {{ t('lms_team_dashboard.skills.gap_count', { count: member.gap_count }) }}
                  </VChip>
                </template>
                <VListItemTitle class="font-weight-medium">{{ member.name }}</VListItemTitle>
                <VListItemSubtitle>
                  <VChip v-for="g in member.gaps.slice(0,4)" :key="g.skill" size="x-small" class="mr-1 mt-1" color="orange">
                    {{ t('lms_team_dashboard.skills.gap_format', { skill: g.skill, current: g.current_level, required: g.required_level }) }}
                  </VChip>
                </VListItemSubtitle>
              </VListItem>
              <VDivider />
            </template>
            <VListItem v-if="!teamSkillGaps.length" class="text-center text-medium-emphasis pa-4">
              <VIcon color="success">mdi-check-circle</VIcon> {{ t('lms_team_dashboard.skills.no_gaps') }}
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 5: Overdue Training ───────────────────────────────────── -->
      <VWindowItem value="overdue">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4">{{ t('lms_team_dashboard.overdue.title') }}</VCardTitle>
          <VDivider />
          <VDataTable :headers="overdueHeaders" :items="overdueTraining" :items-per-page="25" class="text-body-2">
            <template #item.days_overdue="{ item }">
              <VChip :color="item.days_overdue > 14 ? 'error' : 'warning'" size="x-small">
                {{ item.days_overdue }}d
              </VChip>
            </template>
            <template #item.is_mandatory="{ item }">
              <VChip v-if="item.is_mandatory" color="error" size="x-small" label>
                {{ t('lms_team_dashboard.overdue.mandatory') }}
              </VChip>
            </template>
            <template #item.source="{ item }">
              <VChip :color="item.source === 'compliance' ? 'deep-orange' : 'orange'" size="x-small" label>
                {{ item.source }}
              </VChip>
            </template>
          </VDataTable>
          <div v-if="!overdueTraining.length" class="text-center text-medium-emphasis pa-6">
            <VIcon color="success" size="32">mdi-check-circle</VIcon>
            <div class="mt-2">{{ t('lms_team_dashboard.overdue.no_overdue') }}</div>
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 6: Certification Expiry ───────────────────────────────── -->
      <VWindowItem value="certs">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center justify-space-between">
            <span>{{ t('lms_team_dashboard.certs.title') }}</span>
            <VChip :color="certificationExpiry.length > 0 ? 'warning' : 'success'" size="small">
              {{ t('lms_team_dashboard.certs.certificates', { count: certificationExpiry.length }) }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <VDataTable :headers="certHeaders" :items="certificationExpiry" :items-per-page="25" class="text-body-2">
            <template #item.days_until_expiry="{ item }">
              <VChip :color="item.is_expired ? 'error' : item.days_until_expiry <= 14 ? 'deep-orange' : item.days_until_expiry <= 30 ? 'warning' : 'info'"
                size="x-small">
                {{ item.is_expired ? t('lms_team_dashboard.certs.expired') : t('lms_team_dashboard.certs.days_left', { days: item.days_until_expiry }) }}
              </VChip>
            </template>
          </VDataTable>
          <div v-if="!certificationExpiry.length" class="text-center text-medium-emphasis pa-6">
            <VIcon color="success" size="32">mdi-certificate</VIcon>
            <div class="mt-2">{{ t('lms_team_dashboard.certs.no_certs') }}</div>
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 7: Development Plans ─────────────────────────────────── -->
      <VWindowItem value="devplans">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4">{{ t('lms_team_dashboard.devplans.title') }}</VCardTitle>
          <VDivider />
          <VDataTable :headers="devPlanHeaders" :items="developmentPlans" :items-per-page="25" class="text-body-2">
            <template #item.overall_progress="{ item }">
              <div class="d-flex align-center gap-2" style="min-width:130px">
                <VProgressLinear :model-value="item.overall_progress" :color="progressColor(item.overall_progress)"
                  rounded height="8" style="min-width:80px" />
                <span class="text-caption">{{ item.overall_progress }}%</span>
              </div>
            </template>
            <template #item.status="{ item }">
              <VChip :color="item.status_color" size="x-small" label>{{ item.status }}</VChip>
            </template>
          </VDataTable>
          <div v-if="!developmentPlans.length" class="text-center text-medium-emphasis pa-6">
            {{ t('lms_team_dashboard.devplans.no_plans') }}
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Tab 8: Team Learnerships ───────────────────────────────────── -->
      <VWindowItem value="learnerships">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center gap-2">
            <VIcon size="18" color="deep-purple">mdi-school</VIcon>
            {{ t('lms_team_dashboard.learnerships.title') }}
          </VCardTitle>
          <VDivider />
          <VTable class="text-body-2">
            <thead>
              <tr>
                <th>{{ t('lms_team_dashboard.learnerships.employee') }}</th>
                <th>{{ t('lms_team_dashboard.learnerships.learnership') }}</th>
                <th>{{ t('lms_team_dashboard.learnerships.status') }}</th>
                <th>{{ t('lms_team_dashboard.learnerships.enrolled_date') }}</th>
                <th>{{ t('lms_team_dashboard.learnerships.expected_end') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in teamLearnerships" :key="e.id">
                <td>{{ e.employee_name }}</td>
                <td>{{ e.learnership_name }}</td>
                <td>
                  <VChip :color="enrolmentColor(e.status)" size="x-small" label>
                    {{ t(`lms_team_dashboard.statuses.${e.status}`) }}
                  </VChip>
                </td>
                <td>{{ e.enrolled_at ?? '—' }}</td>
                <td>{{ e.expected_end_date ?? '—' }}</td>
              </tr>
              <tr v-if="!teamLearnerships.length">
                <td colspan="5" class="text-center text-medium-emphasis pa-6">
                  {{ t('lms_team_dashboard.learnerships.no_enrolments') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>
  </VContainer>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type TeamLearnership = {
  id: number
  learnership_name: string
  employee_name: string
  status: string
  enrolled_at: string | null
  expected_end_date: string | null
}

const props = defineProps<{
  teamCompletion:     any[]
  teamUnderstanding:  any[]
  teamFailedTests:    any[]
  teamSkillGaps:      any[]
  overdueTraining:    any[]
  certificationExpiry: any[]
  assessmentResults:  any[]
  developmentPlans:   any[]
  teamSummary:        Record<string, any>
  teamLearnerships:   TeamLearnership[]
}>()

const tab          = ref('completion')
const expandedUser = ref<number | null>(null)

// ── Summary KPIs ──────────────────────────────────────────────────────
const summaryKpis = computed(() => {
  const s = props.teamSummary
  return [
    { label: t('lms_team_dashboard.kpis.team_size'), value: s.team_size, color: 'primary', icon: 'mdi-account-group' },
    { label: t('lms_team_dashboard.kpis.avg_completion'), value: `${s.avg_completion_rate}%`, color: rateColor(s.avg_completion_rate), icon: 'mdi-chart-donut' },
    { label: t('lms_team_dashboard.kpis.overdue_items'), value: s.total_overdue, color: s.total_overdue > 0 ? 'error' : 'success', icon: 'mdi-clock-alert' },
    { label: t('lms_team_dashboard.kpis.skill_gaps'), value: s.total_skill_gaps, color: s.total_skill_gaps > 0 ? 'warning' : 'success', icon: 'mdi-lightning-bolt' },
    { label: t('lms_team_dashboard.kpis.certs_expiring'), value: s.certs_expiring_30, color: s.certs_expiring_30 > 0 ? 'warning' : 'success', icon: 'mdi-certificate' },
    { label: t('lms_team_dashboard.kpis.failed_tests'), value: s.failed_tests_total, color: s.failed_tests_total > 0 ? 'error' : 'success', icon: 'mdi-close-circle' },
  ]
})

// ── Table headers ─────────────────────────────────────────────────────
const completionHeaders = [
  { title: t('lms_team_dashboard.completion.name'), key: 'name' },
  { title: t('lms_team_dashboard.completion.total'), key: 'total', align: 'end' as const },
  { title: t('lms_team_dashboard.completion.completed'), key: 'completed', align: 'end' as const },
  { title: t('lms_team_dashboard.completion.in_progress'), key: 'in_progress', align: 'end' as const },
  { title: t('lms_team_dashboard.completion.not_started'), key: 'not_started', align: 'end' as const },
  { title: t('lms_team_dashboard.completion.completion'), key: 'completion_rate' },
  { title: t('lms_team_dashboard.completion.mandatory'), key: 'mandatory_done' },
]

const understandingHeaders = [
  { title: t('lms_team_dashboard.understanding.name'), key: 'name' },
  { title: t('lms_team_dashboard.understanding.avg_score'), key: 'avg_score' },
  { title: t('lms_team_dashboard.understanding.top_grade'), key: 'top_grade' },
  { title: t('lms_team_dashboard.understanding.courses'), key: 'course_count', align: 'end' as const },
  { title: t('lms_team_dashboard.understanding.refresher'), key: 'refresher_needed', align: 'end' as const },
  { title: t('lms_team_dashboard.understanding.flags'), key: 'flag_count' },
  { title: '', key: 'expand', sortable: false },
]

const assessmentHeaders = [
  { title: t('lms_team_dashboard.tests.name'), key: 'name' },
  { title: t('lms_team_dashboard.tests.course'), key: 'course_title' },
  { title: t('lms_team_dashboard.tests.score'), key: 'score', align: 'end' as const },
  { title: t('lms_team_dashboard.tests.result'), key: 'passed' },
  { title: t('lms_team_dashboard.tests.date'), key: 'attempted_at' },
]

const overdueHeaders = [
  { title: t('lms_team_dashboard.overdue.name'), key: 'name' },
  { title: t('lms_team_dashboard.overdue.course'), key: 'course_title' },
  { title: t('lms_team_dashboard.overdue.due_date'), key: 'due_date' },
  { title: t('lms_team_dashboard.overdue.days_overdue'), key: 'days_overdue' },
  { title: t('lms_team_dashboard.overdue.type'), key: 'is_mandatory' },
  { title: t('lms_team_dashboard.overdue.source'), key: 'source' },
]

const certHeaders = [
  { title: t('lms_team_dashboard.certs.name'), key: 'name' },
  { title: t('lms_team_dashboard.certs.course'), key: 'course_title' },
  { title: t('lms_team_dashboard.certs.cert_number'), key: 'certificate_number' },
  { title: t('lms_team_dashboard.certs.expires'), key: 'expires_at' },
  { title: t('lms_team_dashboard.certs.status'), key: 'days_until_expiry' },
]

const devPlanHeaders = [
  { title: t('lms_team_dashboard.devplans.name'), key: 'name' },
  { title: t('lms_team_dashboard.devplans.plan'), key: 'title' },
  { title: t('lms_team_dashboard.devplans.status'), key: 'status' },
  { title: t('lms_team_dashboard.devplans.progress'), key: 'overall_progress' },
  { title: t('lms_team_dashboard.devplans.activities'), key: 'activities_count', align: 'end' as const },
  { title: t('lms_team_dashboard.devplans.target_date'), key: 'target_date' },
]

// ── Helpers ───────────────────────────────────────────────────────────
function rateColor(rate: number): string {
  if (rate >= 80) return 'success'
  if (rate >= 60) return 'info'
  if (rate >= 40) return 'warning'
  return 'error'
}

function progressColor(pct: number): string {
  if (pct >= 80) return 'success'
  if (pct >= 50) return 'info'
  if (pct >= 25) return 'warning'
  return 'error'
}

function gradeColor(grade: string): string {
  return { A: 'success', B: 'light-green', C: 'orange', D: 'deep-orange', F: 'error' }[grade] ?? 'default'
}

function fmt(v: number | null | undefined): string {
  return v != null ? Number(v).toFixed(1) : '—'
}

function enrolmentColor(status: string): string {
  return {
    active:     'success',
    registered: 'info',
    completed:  'deep-purple',
    withdrawn:  'error',
    suspended:  'warning',
  }[status] ?? 'default'
}
</script>
