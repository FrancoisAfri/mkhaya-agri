<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.talent.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ t('pa.talent.subtitle') }}
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-school" @click="recOpen = true">
          {{ t('pa.talent.generate_recommendations') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-refresh" :loading="building" @click="buildAll">
          {{ t('pa.talent.build_all') }}
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
          <p class="text-h5 font-weight-bold text-purple">{{ stats.top_talent }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.talent.top_talent') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-blue">{{ stats.high_performers }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.talent.high_performers') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-green">{{ stats.promotion_ready }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.talent.promotion_ready') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-orange">{{ stats.leadership_pipeline }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.talent.leadership_pipeline') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="profiles">
        <VIcon start>tabler-user-star</VIcon>
        {{ t('pa.talent.tab_profiles') }} ({{ profiles.length }})
      </VTab>
      <VTab value="promotion">
        <VIcon start>tabler-trending-up</VIcon>
        {{ t('pa.talent.tab_promotion') }}
        <VBadge v-if="promotionReady.length" :content="promotionReady.length" color="green" inline class="ml-1" />
      </VTab>
      <VTab value="pipeline">
        <VIcon start>tabler-star</VIcon>
        {{ t('pa.talent.tab_pipeline') }}
        <VBadge v-if="leadershipPipeline.length" :content="leadershipPipeline.length" color="orange" inline class="ml-1" />
      </VTab>
      <VTab value="recommendations">
        <VIcon start>tabler-bulb</VIcon>
        {{ t('pa.talent.tab_recommendations') }}
        <VBadge v-if="recommendations.length" :content="recommendations.length" color="primary" inline class="ml-1" />
      </VTab>
      <VTab value="gaps">
        <VIcon start>tabler-chart-bar</VIcon>
        {{ t('pa.talent.tab_gaps') }}
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Talent Profiles ───────────────────────────────────────────────── -->
      <VTabsWindowItem value="profiles">
        <VRow v-if="profiles.length" dense>
          <VCol v-for="p in profiles" :key="p.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="tierColor(p.talent_tier)" variant="flat" size="44">
                    <VIcon size="22" color="white">{{ tierIcon(p.talent_tier) }}</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-medium">{{ p.employee_name }}</VCardTitle>
                <VCardSubtitle>{{ tierLabel(p.talent_tier) }}</VCardSubtitle>
                <template #append>
                  <div class="d-flex flex-column align-end ga-1">
                    <VChip size="x-small" :color="readinessColor(p.promotion_readiness_level)" variant="tonal">
                      {{ readinessLabel(p.promotion_readiness_level) }}
                    </VChip>
                    <VChip v-if="p.is_leadership_pipeline" size="x-small" color="orange" variant="tonal">
                      <VIcon start size="10">tabler-star</VIcon>Pipeline
                    </VChip>
                  </div>
                </template>
              </VCardItem>

              <VCardText class="pt-0 pb-2">
                <div class="d-flex justify-space-between text-caption mb-1">
                  <span>{{ t('pa.talent.composite_score') }}</span>
                  <span class="font-weight-medium">{{ p.composite_score }}%</span>
                </div>
                <VProgressLinear
                  :model-value="p.composite_score"
                  :color="tierColor(p.talent_tier)"
                  height="6"
                  rounded
                  class="mb-2"
                />
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span>Promo {{ p.promotion_readiness_score }}%</span>
                  <span>Leadership {{ p.leadership_score }}%</span>
                  <span>{{ p.skill_breadth }} skills · Depth {{ p.skill_depth_avg }}/5</span>
                </div>
              </VCardText>

              <VCardActions class="px-4 pb-3 pt-0">
                <span class="text-caption text-medium-emphasis">
                  {{ p.assessed_at ? t('pa.talent.assessed') + ' ' + p.assessed_at : t('pa.talent.not_assessed') }}
                </span>
                <VSpacer />
                <VBtn size="x-small" variant="tonal" @click="openGapsDialog(p)">{{ t('pa.talent.skill_gaps') }}</VBtn>
                <VBtn size="x-small" variant="tonal" color="secondary" class="ml-1" @click="openSuccRec(p)">
                  {{ t('pa.talent.succession') }}
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">
          {{ t('pa.talent.empty_profiles') }}
        </VAlert>
      </VTabsWindowItem>

      <!-- ── Promotion Ready ───────────────────────────────────────────────── -->
      <VTabsWindowItem value="promotion">
        <VList v-if="promotionReady.length" class="pa-0">
          <VListItem
            v-for="p in promotionReady"
            :key="p.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="readinessColor(p.promotion_readiness_level)" variant="tonal" size="38">
                <VIcon size="18">tabler-trending-up</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ p.employee_name }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">
                {{ tierLabel(p.talent_tier) }}
                <span v-if="p.kpi_composite_score !== null"> · KPI {{ p.kpi_composite_score }}%</span>
              </span>
            </template>
            <template #append>
              <div class="text-right">
                <p class="text-body-2 font-weight-bold">{{ p.promotion_readiness_score }}%</p>
                <VChip size="x-small" :color="readinessColor(p.promotion_readiness_level)" variant="flat">
                  {{ readinessLabel(p.promotion_readiness_level) }}
                </VChip>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">
          {{ t('pa.talent.empty_promotion') }}
        </VAlert>
      </VTabsWindowItem>

      <!-- ── Leadership Pipeline ───────────────────────────────────────────── -->
      <VTabsWindowItem value="pipeline">
        <VList v-if="leadershipPipeline.length" class="pa-0">
          <VListItem
            v-for="p in leadershipPipeline"
            :key="p.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar color="orange" variant="tonal" size="38">
                <VIcon size="18">tabler-star</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ p.employee_name }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ tierLabel(p.talent_tier) }} · {{ p.skill_breadth }} skills</span>
            </template>
            <template #append>
              <div class="text-right">
                <p class="text-body-2 font-weight-bold text-orange">{{ p.leadership_score }}%</p>
                <p class="text-caption text-medium-emphasis">{{ t('pa.talent.leadership_score') }}</p>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">
          {{ t('pa.talent.empty_pipeline') }}
        </VAlert>
      </VTabsWindowItem>

      <!-- ── Learning Recommendations ──────────────────────────────────────── -->
      <VTabsWindowItem value="recommendations">
        <VList v-if="recommendations.length" class="pa-0">
          <VListItem
            v-for="rec in recommendations"
            :key="rec.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="priorityColor(rec.priority)" variant="tonal" size="38">
                <VIcon size="16">{{ recIcon(rec.recommendation_type) }}</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ rec.title }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">
                {{ rec.employee_name }}
                <span v-if="rec.provider"> · {{ rec.provider }}</span>
                <span v-if="rec.estimated_hours"> · ~{{ rec.estimated_hours }}h</span>
              </span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="priorityColor(rec.priority)" variant="tonal">
                  {{ rec.priority }}
                </VChip>
                <VSelect
                  :model-value="rec.status"
                  :items="recStatuses"
                  density="compact"
                  variant="outlined"
                  hide-details
                  style="min-width: 130px; max-width: 130px"
                  @update:model-value="updateRecStatus(rec, $event)"
                />
                <VBtn size="x-small" icon variant="text" color="error" @click="deleteRec(rec.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">
          {{ t('pa.talent.empty_recommendations') }}
        </VAlert>
      </VTabsWindowItem>

      <!-- ── Skill Gap Analysis ────────────────────────────────────────────── -->
      <VTabsWindowItem value="gaps">
        <div class="d-flex align-center ga-3 mb-4">
          <VSelect
            v-model="gapEmployee"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('pa.talent.select_employee')"
            density="compact"
            variant="outlined"
            hide-details
            clearable
            style="max-width: 300px"
          />
          <VBtn
            variant="tonal"
            prepend-icon="tabler-search"
            :loading="loadingGaps"
            :disabled="!gapEmployee"
            @click="fetchGaps"
          >
            {{ t('pa.talent.analyse_gaps') }}
          </VBtn>
        </div>

        <div v-if="inlineGaps.length">
          <VCard
            v-for="gap in inlineGaps"
            :key="gap.skill_id"
            border
            flat
            class="mb-2 pa-3"
          >
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-body-2 font-weight-medium">{{ gap.skill_name }}</span>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" color="grey" variant="tonal">{{ gap.category }}</VChip>
                <VChip
                  size="x-small"
                  :color="gap.gap >= 3 ? 'error' : gap.gap >= 2 ? 'orange' : 'warning'"
                  variant="flat"
                >
                  {{ t('pa.talent.gap') }}: {{ gap.gap }}
                </VChip>
              </div>
            </div>
            <div class="d-flex ga-4">
              <div class="flex-1-1">
                <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.kpis.current_value') }} ({{ gap.current_level }}/5)</p>
                <VProgressLinear :model-value="(gap.current_level / 5) * 100" color="blue" height="6" rounded />
              </div>
              <div class="flex-1-1">
                <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.kpis.target_value') }} ({{ gap.target_level }}/5)</p>
                <VProgressLinear :model-value="(gap.target_level / 5) * 100" color="green" height="6" rounded />
              </div>
            </div>
          </VCard>
        </div>
        <VAlert v-else-if="gapEmployee && !loadingGaps" type="success" variant="tonal">
          {{ t('pa.talent.no_gaps') }}
        </VAlert>
        <VAlert v-else-if="!gapEmployee" type="info" variant="tonal">
          {{ t('pa.talent.select_to_view_gaps') }}
        </VAlert>
      </VTabsWindowItem>

    </VTabsWindow>

    <!-- Generate Recommendations dialog -->
    <VDialog v-model="recOpen" max-width="420">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.talent.generate_recommendations') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="recForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('pa.common.employee')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-model="recForm.cycle_id"
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
          <VBtn variant="text" @click="recOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="generating"
            :disabled="!recForm.employee_id"
            @click="submitGenerate"
          >
            {{ t('pa.talent.generate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Skill Gaps dialog (from profile card) -->
    <VDialog v-model="gapsDialogOpen" max-width="580">
      <VCard>
        <VToolbar color="secondary" density="compact">
          <VToolbarTitle class="text-body-1">Skill Gaps — {{ gapsDialogName }}</VToolbarTitle>
          <template #append>
            <VBtn icon @click="gapsDialogOpen = false"><VIcon>tabler-x</VIcon></VBtn>
          </template>
        </VToolbar>
        <VCardText class="pa-4">
          <div v-if="loadingDialogGaps" class="text-center py-6">
            <VProgressCircular indeterminate color="secondary" />
          </div>
          <template v-else-if="dialogGaps.length">
            <VCard
              v-for="gap in dialogGaps"
              :key="gap.skill_id"
              border
              flat
              class="mb-2 pa-3"
            >
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-body-2 font-weight-medium">{{ gap.skill_name }}</span>
                <VChip
                  size="x-small"
                  :color="gap.gap >= 3 ? 'error' : gap.gap >= 2 ? 'orange' : 'warning'"
                  variant="flat"
                >
                  {{ t('pa.talent.gap') }}: {{ gap.gap }}
                </VChip>
              </div>
              <div class="d-flex ga-4">
                <div class="flex-1-1">
                  <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.kpis.current_value') }} ({{ gap.current_level }}/5)</p>
                  <VProgressLinear :model-value="(gap.current_level / 5) * 100" color="blue" height="6" rounded />
                </div>
                <div class="flex-1-1">
                  <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.kpis.target_value') }} ({{ gap.target_level }}/5)</p>
                  <VProgressLinear :model-value="(gap.target_level / 5) * 100" color="green" height="6" rounded />
                </div>
              </div>
            </VCard>
          </template>
          <VAlert v-else type="success" variant="tonal">{{ t('pa.talent.no_gaps_employee') }}</VAlert>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Succession Recommendation dialog -->
    <VDialog v-model="succRecOpen" max-width="580">
      <VCard>
        <VToolbar color="primary" density="compact">
          <VToolbarTitle class="text-body-1">Succession Recommendation — {{ succRecName }}</VToolbarTitle>
          <template #append>
            <VBtn icon @click="succRecOpen = false"><VIcon>tabler-x</VIcon></VBtn>
          </template>
        </VToolbar>
        <VCardText class="pa-4">
          <div v-if="loadingSuccRec" class="text-center py-8">
            <VProgressCircular indeterminate color="primary" />
            <p class="text-body-2 mt-3 text-medium-emphasis">Generating AI recommendation…</p>
          </div>
          <p v-else class="text-body-2" style="white-space: pre-wrap">{{ succRecText }}</p>
        </VCardText>
      </VCard>
    </VDialog>

  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface TalentProfile {
  id: number
  employee_id: number
  employee_name: string
  promotion_readiness_score: number
  promotion_readiness_level: string
  leadership_score: number
  is_leadership_pipeline: boolean
  talent_tier: string
  kpi_composite_score: number | null
  skill_breadth: number
  skill_depth_avg: number
  composite_score: number
  assessed_at: string | null
}

interface Recommendation {
  id: number
  employee_id: number
  employee_name: string
  recommendation_type: string
  title: string
  description: string | null
  provider: string | null
  estimated_hours: number | null
  priority: string
  status: string
  ai_generated: boolean
}

interface SkillGap {
  skill_id: number
  skill_name: string
  category: string
  current_level: number
  target_level: number
  gap: number
}

interface Cycle    { id: number; name: string }
interface Employee { id: number; name: string }

const props = defineProps<{
  profiles:        TalentProfile[]
  recommendations: Recommendation[]
  cycles:          Cycle[]
  employees:       Employee[]
  selectedCycle:   number | null
  stats:           { top_talent: number; high_performers: number; promotion_ready: number; leadership_pipeline: number }
}>()

const tab           = ref('profiles')
const selectedCycle = ref<number | null>(props.selectedCycle)
const building      = ref(false)

const promotionReady = computed(() =>
  [...props.profiles]
    .filter(p => ['ready', 'ready_now'].includes(p.promotion_readiness_level))
    .sort((a, b) => b.promotion_readiness_score - a.promotion_readiness_score)
)

const leadershipPipeline = computed(() =>
  [...props.profiles]
    .filter(p => p.is_leadership_pipeline)
    .sort((a, b) => b.leadership_score - a.leadership_score)
)

function applyFilter() {
  router.get(route('performance.talent.index'), {
    cycle_id: selectedCycle.value ?? undefined,
  }, { preserveState: true, replace: true })
}

function buildAll() {
  building.value = true
  router.post(route('performance.talent.build.all'), { cycle_id: selectedCycle.value }, {
    onFinish: () => { building.value = false },
  })
}

// ── Generate recommendations dialog ─────────────────────────────────────────
const recOpen    = ref(false)
const generating = ref(false)
const recForm    = reactive<{ employee_id: number | null; cycle_id: number | null }>({
  employee_id: null,
  cycle_id: null,
})
const recStatuses = ['pending', 'in_progress', 'completed', 'skipped']

function submitGenerate() {
  generating.value = true
  router.post(route('performance.talent.recommendations.generate'), recForm as any, {
    onSuccess: () => { recOpen.value = false },
    onFinish:  () => { generating.value = false },
  })
}

function updateRecStatus(rec: Recommendation, status: string) {
  router.patch(route('performance.talent.recommendations.update', rec.id), { status })
}

function deleteRec(id: number) {
  router.delete(route('performance.talent.recommendations.destroy', id))
}

// ── Skill Gaps — inline tab ──────────────────────────────────────────────────
const gapEmployee = ref<number | null>(null)
const inlineGaps  = ref<SkillGap[]>([])
const loadingGaps = ref(false)

async function fetchGaps() {
  if (!gapEmployee.value) return
  loadingGaps.value = true
  inlineGaps.value  = []
  try {
    const res  = await fetch(route('performance.talent.skill-gaps', gapEmployee.value))
    const data = await res.json()
    inlineGaps.value = data.gaps ?? []
  } finally {
    loadingGaps.value = false
  }
}

// ── Skill Gaps dialog (profile card button) ──────────────────────────────────
const gapsDialogOpen    = ref(false)
const gapsDialogName    = ref('')
const dialogGaps        = ref<SkillGap[]>([])
const loadingDialogGaps = ref(false)

async function openGapsDialog(profile: TalentProfile) {
  gapsDialogName.value = profile.employee_name
  dialogGaps.value     = []
  gapsDialogOpen.value = true
  loadingDialogGaps.value = true
  try {
    const res  = await fetch(route('performance.talent.skill-gaps', profile.employee_id))
    const data = await res.json()
    dialogGaps.value = data.gaps ?? []
  } finally {
    loadingDialogGaps.value = false
  }
}

// ── Succession recommendation dialog ────────────────────────────────────────
const succRecOpen    = ref(false)
const succRecName    = ref('')
const succRecText    = ref('')
const loadingSuccRec = ref(false)

async function openSuccRec(profile: TalentProfile) {
  succRecName.value = profile.employee_name
  succRecText.value = ''
  succRecOpen.value = true
  loadingSuccRec.value = true
  try {
    const url  = route('performance.talent.succession-rec', profile.employee_id) +
      (selectedCycle.value ? `?cycle_id=${selectedCycle.value}` : '')
    const res  = await fetch(url)
    const data = await res.json()
    succRecText.value = data.recommendation ?? ''
  } finally {
    loadingSuccRec.value = false
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function tierColor(tier: string): string {
  return ({ top_talent: 'purple', high_performer: 'blue', contributor: 'teal', emerging: 'grey' } as Record<string, string>)[tier] ?? 'grey'
}

function tierIcon(tier: string): string {
  return ({
    top_talent:    'tabler-star',
    high_performer:'tabler-medal',
    contributor:   'tabler-user',
    emerging:      'tabler-plant',
  } as Record<string, string>)[tier] ?? 'tabler-user'
}

function tierLabel(tier: string): string {
  return ({
    top_talent:    'Top Talent',
    high_performer:'High Performer',
    contributor:   'Contributor',
    emerging:      'Emerging',
  } as Record<string, string>)[tier] ?? tier
}

function readinessColor(level: string): string {
  return ({ ready_now: 'green', ready: 'blue', developing: 'orange', not_ready: 'grey' } as Record<string, string>)[level] ?? 'grey'
}

function readinessLabel(level: string): string {
  return ({ ready_now: 'Ready Now', ready: 'Ready', developing: 'Developing', not_ready: 'Not Ready' } as Record<string, string>)[level] ?? level
}

function priorityColor(priority: string): string {
  return ({ critical: 'error', high: 'orange', medium: 'blue', low: 'grey' } as Record<string, string>)[priority] ?? 'grey'
}

function recIcon(type: string): string {
  return ({
    course:        'tabler-school',
    book:          'tabler-book-2',
    certification: 'tabler-certificate',
    workshop:      'tabler-tools',
    mentoring:     'tabler-users',
    project:       'tabler-briefcase',
    conference:    'tabler-presentation',
  } as Record<string, string>)[type] ?? 'tabler-bulb'
}
</script>
