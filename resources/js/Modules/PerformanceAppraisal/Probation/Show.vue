<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center mb-2">
      <VBtn icon variant="text" class="mr-2" @click="router.visit(route('performance.probation.index'))">
        <VIcon>tabler-arrow-left</VIcon>
      </VBtn>
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ review.employee_name }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ probationLabel(review.probation_type) }} · {{ t('pa.common.manager') }}: {{ review.manager_name }}
          <span v-if="review.cycle_name"> · {{ review.cycle_name }}</span>
        </p>
      </div>
      <div class="d-flex ga-2 align-center">
        <VChip :color="statusColor(review.status)" variant="tonal">{{ review.status }}</VChip>
        <VChip v-if="review.risk_level" :color="riskColor(review.risk_level)" variant="flat">
          {{ review.risk_level }} {{ t('pa.probation.risk') }}
        </VChip>
        <VBtn
          v-if="review.status === 'active'"
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-flag-2"
          @click="finaliseOpen = true"
        >
          {{ t('pa.probation.finalise') }}
        </VBtn>
      </div>
    </div>

    <VDivider class="mb-5" />

    <!-- KPI strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h6 font-weight-bold text-primary">{{ review.percent_elapsed }}%</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.probation.time_elapsed') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h6 font-weight-bold text-warning">{{ review.days_remaining }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.days_remaining') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h6 font-weight-bold text-success">{{ review.objectives_completion_rate }}%</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.probation.objectives_met') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p
            class="text-h6 font-weight-bold"
            :class="review.risk_score !== null ? `text-${riskColor(review.risk_level)}` : 'text-medium-emphasis'"
          >
            {{ review.risk_score ?? '—' }}
          </p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.probation.risk_score') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="objectives">
        <VIcon start>tabler-target</VIcon>{{ t('pa.pip.tab_objectives') }} ({{ objectives.length }})
      </VTab>
      <VTab value="checkins">
        <VIcon start>tabler-calendar-check</VIcon>{{ t('pa.checkins.title') }} ({{ checkins.length }})
      </VTab>
      <VTab value="feedback">
        <VIcon start>tabler-messages</VIcon>{{ t('pa.feedback.title') }} ({{ feedback.length }})
      </VTab>
      <VTab value="risk">
        <VIcon start>tabler-shield-exclamation</VIcon>{{ t('pa.probation.tab_risk_ai') }}
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Objectives ──────────────────────────────────────────────────── -->
      <VTabsWindowItem value="objectives">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="openAddObj">
            {{ t('pa.probation.add_objective') }}
          </VBtn>
        </div>

        <VRow v-if="objectives.length" dense>
          <VCol v-for="obj in objectives" :key="obj.id" cols="12" sm="6">
            <VCard border flat>
              <VCardItem>
                <VCardTitle class="text-body-2 font-weight-medium">{{ obj.title }}</VCardTitle>
                <template #append>
                  <div class="d-flex ga-1">
                    <VChip size="x-small" :color="objStatusColor(obj.status)" variant="tonal">
                      {{ obj.status }}
                    </VChip>
                    <VBtn icon size="x-small" variant="text" @click="openEditObj(obj)">
                      <VIcon>tabler-pencil</VIcon>
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="openDelObj(obj)">
                      <VIcon>tabler-trash</VIcon>
                    </VBtn>
                  </div>
                </template>
              </VCardItem>

              <VCardText class="pt-0">
                <p v-if="obj.description" class="text-body-2 text-medium-emphasis mb-2">{{ obj.description }}</p>

                <div v-if="obj.target_value !== null" class="mb-2">
                  <div class="d-flex justify-space-between text-caption mb-1">
                    <span>{{ t('pa.common.progress') }}</span>
                    <span>
                      {{ obj.achieved_value ?? 0 }} / {{ obj.target_value }} {{ obj.unit }}
                      <span v-if="obj.achievement_pct !== null">({{ obj.achievement_pct }}%)</span>
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="obj.achievement_pct ?? 0"
                    :color="objStatusColor(obj.status)"
                    height="6"
                    rounded
                  />
                </div>

                <div v-if="obj.weight" class="text-caption text-medium-emphasis">
                  {{ t('pa.probation.weight') }}: {{ obj.weight }}%
                </div>
                <div v-if="obj.manager_notes" class="text-caption mt-1">
                  <strong>{{ t('pa.kpis.notes_optional').replace(' (optional)', '') }}:</strong> {{ obj.manager_notes }}
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.probation.no_objectives') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Check-Ins ───────────────────────────────────────────────────── -->
      <VTabsWindowItem value="checkins">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="openAddCi">
            {{ t('pa.probation.schedule_checkin') }}
          </VBtn>
        </div>

        <VTimeline density="compact" side="end" v-if="checkins.length">
          <VTimelineItem
            v-for="ci in checkins"
            :key="ci.id"
            :dot-color="ciStatusColor(ci.status)"
            size="small"
          >
            <VCard border flat>
              <VCardItem>
                <VCardTitle class="text-body-2 font-weight-medium">
                  {{ ci.checkin_type === 'formal' ? t('pa.probation.formal') : t('pa.probation.informal') }} {{ t('pa.checkins.title') }}
                </VCardTitle>
                <VCardSubtitle>{{ ci.scheduled_at }}</VCardSubtitle>
                <template #append>
                  <div class="d-flex ga-1">
                    <VChip size="x-small" :color="ciStatusColor(ci.status)" variant="tonal">{{ ci.status }}</VChip>
                    <VBtn icon size="x-small" variant="text" @click="openEditCi(ci)"><VIcon>tabler-pencil</VIcon></VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="openDelCi(ci)"><VIcon>tabler-trash</VIcon></VBtn>
                  </div>
                </template>
              </VCardItem>
              <VCardText v-if="ci.agenda || ci.notes" class="pt-0">
                <p v-if="ci.agenda" class="text-caption text-medium-emphasis">{{ t('pa.probation.agenda') }}: {{ ci.agenda }}</p>
                <p v-if="ci.notes" class="text-caption mt-1">{{ t('pa.probation.notes') }}: {{ ci.notes }}</p>
              </VCardText>
            </VCard>
          </VTimelineItem>
        </VTimeline>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.probation.no_checkins') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Feedback ────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="feedback">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="fbOpen = true">
            {{ t('pa.probation.add_feedback') }}
          </VBtn>
        </div>

        <VRow v-if="feedback.length" dense>
          <VCol v-for="f in feedback" :key="f.id" cols="12" sm="6">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="fbTypeColor(f.feedback_type)" variant="tonal" size="32">
                    <VIcon size="14">{{ fbTypeIcon(f.feedback_type) }}</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2">{{ fbTypeLabel(f.feedback_type) }}</VCardTitle>
                <VCardSubtitle>{{ f.author_name }} · {{ f.created_at }}</VCardSubtitle>
                <template #append>
                  <VBtn icon size="x-small" variant="text" color="error" @click="deleteFeedback(f.id)">
                    <VIcon>tabler-trash</VIcon>
                  </VBtn>
                </template>
              </VCardItem>
              <VCardText class="pt-0">
                <p class="text-body-2">{{ f.content }}</p>
                <div v-if="f.rating !== null" class="d-flex align-center ga-1 mt-1">
                  <VIcon size="12" color="amber">tabler-star</VIcon>
                  <span class="text-caption font-weight-medium">{{ f.rating }}/5</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.probation.no_feedback') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Risk & AI ───────────────────────────────────────────────────── -->
      <VTabsWindowItem value="risk">
        <VRow>
          <VCol cols="12" md="5">
            <!-- Action buttons -->
            <VCard border flat class="mb-4 pa-4">
              <p class="text-body-2 font-weight-bold mb-3">{{ t('pa.probation.risk_analysis') }}</p>
              <VBtn
                color="primary"
                block
                prepend-icon="tabler-calculator"
                :loading="calculatingRisk"
                class="mb-2"
                @click="calculateRisk"
              >
                {{ t('pa.probation.calculate_risk') }}
              </VBtn>
              <VBtn
                color="secondary"
                block
                prepend-icon="tabler-robot"
                :loading="generatingAi"
                @click="generateAi"
              >
                {{ t('pa.probation.generate_ai_prediction') }}
              </VBtn>
            </VCard>

            <!-- Current recommendation -->
            <VCard v-if="review.recommendation" border flat class="mb-4">
              <VCardText class="text-center pa-4">
                <VIcon size="40" :color="recColor(review.recommendation)">
                  {{ recIcon(review.recommendation) }}
                </VIcon>
                <p class="text-h6 font-weight-bold mt-2" :class="`text-${recColor(review.recommendation)}`">
                  {{ review.recommendation.toUpperCase() }}
                </p>
                <p class="text-caption text-medium-emphasis">{{ t('pa.probation.current_recommendation') }}</p>
                <div v-if="review.success_probability !== null" class="mt-2">
                  <p class="text-body-2">
                    {{ t('pa.probation.success_probability') }}: <strong>{{ review.success_probability }}%</strong>
                  </p>
                </div>
              </VCardText>
            </VCard>

            <!-- Risk score history -->
            <VCard border flat>
              <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">{{ t('pa.probation.score_history') }}</VCardTitle>
              <VList density="compact" class="pa-2">
                <VListItem
                  v-for="rs in riskHistory"
                  :key="rs.id"
                  class="mb-1 border"
                >
                  <template #prepend>
                    <VAvatar :color="riskColor(rs.risk_level)" variant="tonal" size="28">
                      <span class="text-caption font-weight-bold">{{ rs.risk_score }}</span>
                    </VAvatar>
                  </template>
                  <template #title>
                    <span class="text-caption">{{ rs.risk_level }} {{ t('pa.probation.risk') }} — {{ rs.recommendation }}</span>
                  </template>
                  <template #subtitle>
                    <span class="text-caption text-medium-emphasis">
                      {{ rs.created_at }}
                      <VChip v-if="rs.is_ai_generated" size="x-small" color="secondary" variant="tonal" class="ml-1">AI</VChip>
                    </span>
                  </template>
                </VListItem>
                <VListItem v-if="!riskHistory.length">
                  <span class="text-caption text-medium-emphasis">{{ t('pa.probation.no_risk_calculations') }}</span>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <VCol cols="12" md="7">
            <!-- AI Prediction narrative -->
            <VCard v-if="review.ai_prediction" border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar color="secondary" variant="tonal" size="32">
                    <VIcon size="16">tabler-robot</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-bold">{{ t('pa.probation.ai_assessment') }}</VCardTitle>
              </VCardItem>
              <VCardText class="text-body-2" style="white-space: pre-wrap">{{ review.ai_prediction }}</VCardText>
            </VCard>
            <VAlert v-else type="info" variant="tonal">
              {{ t('pa.probation.no_ai_analysis') }}
            </VAlert>
          </VCol>
        </VRow>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- ── Objective Drawer ──────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="objOpen" location="right" width="440" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ editObj ? t('pa.probation.edit_objective') : t('pa.probation.add_objective') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="objOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>
      <VForm ref="objForm" @submit.prevent="submitObj" class="pa-4">
        <VTextField v-model="oForm.title" :label="t('pa.kpis.kpi_name')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextarea v-model="oForm.description" :label="t('pa.cycles.description')" density="compact" variant="outlined" rows="3" class="mb-3" />
        <VTextField
          v-model.number="oForm.weight"
          :label="t('pa.probation.weight_pct')"
          type="number" min="0" max="100"
          density="compact" variant="outlined" class="mb-3"
          :hint="`${t('pa.common.remaining')}: ${remainingWeight}%`"
          persistent-hint
          :rules="[v => (v ?? 0) <= remainingWeight || `Cannot exceed remaining ${remainingWeight}%`]"
        />
        <VRow dense class="mb-3">
          <VCol cols="6">
            <VTextField v-model.number="oForm.target_value" :label="t('pa.kpis.target_value')" type="number" density="compact" variant="outlined" />
          </VCol>
          <VCol cols="6">
            <VTextField v-model="oForm.unit" :label="t('pa.kpis.unit')" density="compact" variant="outlined" />
          </VCol>
        </VRow>
        <template v-if="editObj">
          <VTextField v-model.number="oForm.achieved_value" :label="t('pa.probation.achieved_value')" type="number" density="compact" variant="outlined" class="mb-3" />
          <VSelect v-model="oForm.status" :items="['pending','in_progress','met','not_met']" :label="t('pa.common.status')" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="oForm.manager_notes" :label="t('pa.probation.manager_notes')" density="compact" variant="outlined" rows="3" class="mb-3" />
        </template>
        <VBtn type="submit" color="primary" block :loading="savingObj">
          {{ editObj ? t('pa.cycles.save_changes') : t('pa.probation.add_objective') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- ── Check-In Drawer ───────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="ciOpen" location="right" width="440" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ editCi ? t('pa.probation.edit_checkin') : t('pa.probation.schedule_checkin') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="ciOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>
      <VForm ref="ciForm" @submit.prevent="submitCi" class="pa-4">
        <VSelect v-model="cForm.checkin_type" :items="['formal','informal']" :label="t('pa.probation.checkin_type')" density="compact" variant="outlined" class="mb-3" />
        <VTextField v-model="cForm.scheduled_at" :label="t('pa.probation.scheduled_at')" type="datetime-local" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextarea v-model="cForm.agenda" :label="t('pa.probation.agenda')" density="compact" variant="outlined" rows="3" class="mb-3" />
        <template v-if="editCi">
          <VTextarea v-model="cForm.notes" :label="t('pa.probation.notes')" density="compact" variant="outlined" rows="3" class="mb-3" />
          <VSelect v-model="cForm.status" :items="['scheduled','completed','missed']" :label="t('pa.common.status')" density="compact" variant="outlined" class="mb-3" />
        </template>
        <VBtn type="submit" color="primary" block :loading="savingCi">
          {{ editCi ? t('pa.cycles.save_changes') : t('pa.probation.schedule') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Feedback drawer -->
    <VNavigationDrawer v-model="fbOpen" location="right" width="440" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.probation.add_feedback') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="fbOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>
      <VForm ref="fbForm" @submit.prevent="submitFb" class="pa-4">
        <VSelect v-model="fForm.feedback_type" :items="fbTypes" item-title="label" item-value="value" :label="t('pa.feedback.feedback_type')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextarea v-model="fForm.content" :label="t('pa.feedback.feedback_label')" density="compact" variant="outlined" rows="5" :rules="[v => (v && v.length >= 20) || 'Min 20 chars']" class="mb-3" />
        <VSlider v-model="fForm.rating" :label="t('pa.feedback.rating_optional')" min="0" max="5" step="0.5" thumb-label color="amber" class="mb-4" />
        <VSwitch v-model="fForm.is_draft" :label="t('pa.feedback.save_draft')" density="compact" color="warning" class="mb-3" />
        <VBtn type="submit" color="primary" block :loading="savingFb">{{ t('pa.feedback.submit') }}</VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Finalise dialog -->
    <VDialog v-model="finaliseOpen" max-width="460">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.probation.finalise_title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="finalForm.status" :items="['confirmed','extended','terminated']" :label="t('pa.probation.outcome')" density="compact" variant="outlined" class="mb-3" />
          <VSelect v-model="finalForm.recommendation" :items="['confirm','extend','terminate']" :label="t('pa.probation.recommendation')" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="finalForm.notes" :label="t('pa.probation.final_notes')" density="compact" variant="outlined" rows="3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="finaliseOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="finalising" @click="submitFinalise">{{ t('pa.probation.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete objective dialog -->
    <VDialog v-model="delObjOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.probation.delete_objective') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delObjOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingObj" @click="confirmDelObj">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete check-in dialog -->
    <VDialog v-model="delCiOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.probation.delete_checkin') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delCiOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingCi" @click="confirmDelCi">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
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

interface ReviewData {
  id: number; employee_name: string; manager_name: string; cycle_name: string | null
  probation_type: string; start_date: string; end_date: string; status: string
  recommendation: string | null; risk_score: number | null; risk_level: string | null
  success_probability: number | null; ai_prediction: string | null; ai_summary: string | null
  notes: string | null; days_remaining: number; percent_elapsed: number; objectives_completion_rate: number
}

interface Objective {
  id: number; title: string; description: string | null; weight: number
  target_value: number | null; achieved_value: number | null; unit: string | null
  status: string; manager_notes: string | null; achievement_pct: number | null
}

interface Checkin {
  id: number; checkin_type: string; scheduled_at: string; completed_at: string | null
  agenda: string | null; notes: string | null; action_items: any[]; status: string
}

interface Feedback {
  id: number; feedback_type: string; content: string; rating: number | null
  author_name: string; created_at: string
}

interface RiskScore {
  id: number; risk_score: number; risk_level: string; success_probability: number | null
  recommendation: string; factors: any; is_ai_generated: boolean; created_at: string
}

interface Employee { id: number; name: string }

const props = defineProps<{
  review:       ReviewData
  objectives:   Objective[]
  checkins:     Checkin[]
  feedback:     Feedback[]
  risk_history: RiskScore[]
  employees:    Employee[]
}>()

const tab = ref('objectives')

// ── Objectives ────────────────────────────────────────────────────────────────
const usedWeight = computed(() => {
  const others = props.objectives.filter(o => o.id !== editObj.value?.id)
  return others.reduce((sum, o) => sum + (o.weight ?? 0), 0)
})
const remainingWeight = computed(() => 100 - usedWeight.value)

const objOpen   = ref(false)
const savingObj = ref(false)
const objForm   = ref()
const editObj   = ref<Objective | null>(null)
const delObjOpen  = ref(false)
const delObjItem  = ref<Objective | null>(null)
const deletingObj = ref(false)

const oForm = reactive({
  title: '', description: '', weight: 0,
  target_value: null as number | null, achieved_value: null as number | null,
  unit: '', status: 'pending', manager_notes: '',
})

function openAddObj() {
  editObj.value = null
  Object.assign(oForm, { title: '', description: '', weight: 0, target_value: null, achieved_value: null, unit: '', status: 'pending', manager_notes: '' })
  objOpen.value = true
}

function openEditObj(obj: Objective) {
  editObj.value = obj
  Object.assign(oForm, {
    title: obj.title, description: obj.description ?? '', weight: obj.weight,
    target_value: obj.target_value, achieved_value: obj.achieved_value,
    unit: obj.unit ?? '', status: obj.status, manager_notes: obj.manager_notes ?? '',
  })
  objOpen.value = true
}

async function submitObj() {
  const { valid } = await objForm.value.validate()
  if (!valid) return
  savingObj.value = true
  if (editObj.value) {
    router.patch(route('performance.probation.objectives.update', { review: props.review.id, objective: editObj.value.id }), oForm, {
      onSuccess: () => { objOpen.value = false },
      onFinish:  () => { savingObj.value = false },
    })
  } else {
    router.post(route('performance.probation.objectives.store', props.review.id), oForm, {
      onSuccess: () => { objOpen.value = false },
      onFinish:  () => { savingObj.value = false },
    })
  }
}

function openDelObj(obj: Objective) { delObjItem.value = obj; delObjOpen.value = true }
function confirmDelObj() {
  if (!delObjItem.value) return
  deletingObj.value = true
  router.delete(route('performance.probation.objectives.destroy', { review: props.review.id, objective: delObjItem.value.id }), {
    onSuccess: () => { delObjOpen.value = false },
    onFinish:  () => { deletingObj.value = false },
  })
}

// ── Check-Ins ─────────────────────────────────────────────────────────────────
const ciOpen   = ref(false)
const savingCi = ref(false)
const ciForm   = ref()
const editCi   = ref<Checkin | null>(null)
const delCiOpen  = ref(false)
const delCiItem  = ref<Checkin | null>(null)
const deletingCi = ref(false)

const cForm = reactive({ checkin_type: 'formal', scheduled_at: '', agenda: '', notes: '', status: 'scheduled' })

function openAddCi() {
  editCi.value = null
  Object.assign(cForm, { checkin_type: 'formal', scheduled_at: '', agenda: '', notes: '', status: 'scheduled' })
  ciOpen.value = true
}

function openEditCi(ci: Checkin) {
  editCi.value = ci
  Object.assign(cForm, { checkin_type: ci.checkin_type, scheduled_at: ci.scheduled_at, agenda: ci.agenda ?? '', notes: ci.notes ?? '', status: ci.status })
  ciOpen.value = true
}

async function submitCi() {
  const { valid } = await ciForm.value.validate()
  if (!valid) return
  savingCi.value = true
  if (editCi.value) {
    router.patch(route('performance.probation.checkins.update', { review: props.review.id, checkin: editCi.value.id }), cForm, {
      onSuccess: () => { ciOpen.value = false },
      onFinish:  () => { savingCi.value = false },
    })
  } else {
    router.post(route('performance.probation.checkins.store', props.review.id), cForm, {
      onSuccess: () => { ciOpen.value = false },
      onFinish:  () => { savingCi.value = false },
    })
  }
}

function openDelCi(ci: Checkin) { delCiItem.value = ci; delCiOpen.value = true }
function confirmDelCi() {
  if (!delCiItem.value) return
  deletingCi.value = true
  router.delete(route('performance.probation.checkins.destroy', { review: props.review.id, checkin: delCiItem.value.id }), {
    onSuccess: () => { delCiOpen.value = false },
    onFinish:  () => { deletingCi.value = false },
  })
}

// ── Feedback ──────────────────────────────────────────────────────────────────
const fbOpen   = ref(false)
const savingFb = ref(false)
const fbForm   = ref()

const fForm = reactive({ feedback_type: 'manager', content: '', rating: null as number | null, is_draft: false })

const fbTypes = [
  { value: 'manager', label: 'Manager Feedback' },
  { value: 'self',    label: 'Self-Assessment' },
  { value: 'peer',    label: 'Peer Feedback' },
]

async function submitFb() {
  const { valid } = await fbForm.value.validate()
  if (!valid) return
  savingFb.value = true
  router.post(route('performance.probation.feedback.store', props.review.id), fForm as any, {
    onSuccess: () => { fbOpen.value = false },
    onFinish:  () => { savingFb.value = false },
  })
}

function deleteFeedback(id: number) {
  router.delete(route('performance.probation.feedback.destroy', { review: props.review.id, feedback: id }))
}

// ── Risk & AI ─────────────────────────────────────────────────────────────────
const calculatingRisk = ref(false)
const generatingAi    = ref(false)
const riskHistory     = ref(props.risk_history)

function calculateRisk() {
  calculatingRisk.value = true
  router.post(route('performance.probation.risk', props.review.id), {}, {
    onFinish: () => { calculatingRisk.value = false },
  })
}

function generateAi() {
  generatingAi.value = true
  router.post(route('performance.probation.ai', props.review.id), {}, {
    onFinish: () => { generatingAi.value = false },
  })
}

// ── Finalise ──────────────────────────────────────────────────────────────────
const finaliseOpen = ref(false)
const finalising   = ref(false)
const finalForm    = reactive({ status: 'confirmed', recommendation: 'confirm', notes: '' })

function submitFinalise() {
  finalising.value = true
  router.patch(route('performance.probation.finalise', props.review.id), finalForm, {
    onSuccess: () => { finaliseOpen.value = false },
    onFinish:  () => { finalising.value = false },
  })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function probationLabel(type: string) {
  return { '3_month': '3-Month Probation', '6_month': '6-Month Probation', custom: 'Custom Probation' }[type] ?? type
}

function statusColor(status: string) {
  return { active: 'blue', extended: 'orange', confirmed: 'success', terminated: 'error' }[status] ?? 'grey'
}

function riskColor(level: string | null) {
  return { low: 'green', medium: 'orange', high: 'error', critical: 'deep-purple' }[level ?? ''] ?? 'grey'
}

function objStatusColor(status: string) {
  return { pending: 'grey', in_progress: 'blue', met: 'green', not_met: 'error' }[status] ?? 'grey'
}

function ciStatusColor(status: string) {
  return { scheduled: 'blue', completed: 'green', missed: 'error' }[status] ?? 'grey'
}

function fbTypeLabel(type: string) {
  return fbTypes.find(t => t.value === type)?.label ?? type
}

function fbTypeIcon(type: string) {
  return { manager: 'tabler-user-tie', self: 'tabler-mirror', peer: 'tabler-users' }[type] ?? 'tabler-message'
}

function fbTypeColor(type: string) {
  return { manager: 'blue', self: 'purple', peer: 'green' }[type] ?? 'grey'
}

function recIcon(rec: string) {
  return { confirm: 'tabler-circle-check', extend: 'tabler-clock', terminate: 'tabler-circle-x' }[rec] ?? 'tabler-help-circle'
}

function recColor(rec: string) {
  return { confirm: 'success', extend: 'warning', terminate: 'error' }[rec] ?? 'grey'
}
</script>
