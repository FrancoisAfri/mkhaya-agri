<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  plans?:          any
  rules?:          any
  ruleTypes?:      Record<string, string>
  scopeTypes?:     Record<string, string>
  blockingCount?:  number
  courses?:        { id: number; title: string }[]
  filters?:        Record<string, any>
  activeTab?:      string
  trainingStatus?: any
  blockStatus?:    any
  employeeName?:   string
  planId?:         number
  userId?:         number
}>()

const confirmDialog      = ref()
const activeTab          = ref(props.activeTab ?? 'plans')
const page               = ref(1)
const selectedPlanId     = ref<number | null>(props.planId ?? null)
const selectedEmployeeName = ref(props.employeeName ?? '')
const syncingPlan        = ref<number | null>(null)
const remindersLoading   = ref(false)

const trainingStatus     = ref(props.trainingStatus ?? null)
const blockStatus        = ref(props.blockStatus    ?? null)

const planHeaders = computed(() => [
  { title: t('lms_onboarding.table.employee'),     key: 'employee_name' },
  { title: t('lms_onboarding.table.start_date'),   key: 'start_date' },
  { title: t('lms_onboarding.table.plan_status'),  key: 'plan_status' },
  { title: t('lms_onboarding.table.progress'),     key: 'completion_pct' },
  { title: t('lms_onboarding.table.total'),        key: 'total',    width: 60 },
  { title: t('lms_onboarding.table.done'),         key: 'completed',width: 60 },
  { title: t('lms_onboarding.table.failed'),       key: 'failed',   width: 60 },
  { title: t('lms_onboarding.table.block_status'), key: 'is_blocked' },
  { title: '', key: 'actions', sortable: false },
])

const ruleHeaders = computed(() => [
  { title: t('lms_onboarding.table.course'),    key: 'course.title' },
  { title: t('lms_onboarding.table.rule_type'), key: 'rule_type' },
  { title: t('lms_onboarding.table.scope'),     key: 'scope' },
  { title: t('lms_onboarding.table.due_days'),  key: 'due_within_days' },
  { title: t('lms_onboarding.table.blocks'),    key: 'blocks_onboarding_completion' },
  { title: t('lms_onboarding.table.status'),    key: 'is_active' },
  { title: t('lms_onboarding.table.assigned'),  key: 'assignments_count' },
  { title: '', key: 'actions', sortable: false },
])

const assignmentHeaders = computed(() => [
  { title: t('lms_onboarding.table.course'),        key: 'course_title' },
  { title: t('lms_onboarding.table.rule_type'),     key: 'rule_type' },
  { title: t('lms_onboarding.table.status'),        key: 'status' },
  { title: t('lms_onboarding.table.due'),           key: 'due_date' },
  { title: t('lms_onboarding.table.pass_score'),    key: 'pass_score' },
  { title: t('lms_onboarding.table.understanding'), key: 'understanding_score' },
  { title: t('lms_onboarding.table.blocks'),        key: 'blocks_completion' },
])

const detailTiles = computed(() => {
  const s = trainingStatus.value
  if (!s) return []
  return [
    { label: t('lms_onboarding.detail.total'),     value: s.total,          color: 'primary' },
    { label: t('lms_onboarding.detail.completed'), value: s.completed,      color: 'success' },
    { label: t('lms_onboarding.table.failed'),     value: s.failed,         color: 'error' },
    { label: t('lms_onboarding.detail.progress'),  value: s.completion_pct + '%', color: s.completion_pct >= 100 ? 'success' : 'warning' },
  ]
})

const ruleDialog  = ref(false)
const editingRule = ref<any>(null)
const ruleForm    = useForm({
  course_id: null as number | null, rule_type: 'induction', scope_type: 'all',
  scope_id: null as number | null, scope_label: '', due_within_days: 30,
  is_mandatory: true, blocks_onboarding_completion: false,
  min_pass_score: null as number | null, min_understanding_score: null as number | null, is_active: true,
})
const ruleErrors = computed(() => ruleForm.errors)

const ruleTypeItems  = computed(() => Object.entries(props.ruleTypes ?? {}).map(([value, title]) => ({ value, title })))
const scopeTypeItems = computed(() => Object.entries(props.scopeTypes ?? {}).map(([value, title]) => ({ value, title })))

function editRule(rule: any) {
  editingRule.value = rule
  ruleForm.rule_type = rule.rule_type; ruleForm.scope_type = rule.scope_type
  ruleForm.scope_id = rule.scope_id; ruleForm.scope_label = rule.scope_label ?? ''
  ruleForm.due_within_days = rule.due_within_days; ruleForm.is_mandatory = rule.is_mandatory
  ruleForm.blocks_onboarding_completion = rule.blocks_onboarding_completion
  ruleForm.min_pass_score = rule.min_pass_score; ruleForm.min_understanding_score = rule.min_understanding_score
  ruleForm.is_active = rule.is_active
  ruleDialog.value = true
}

function closeRuleDialog() { ruleDialog.value = false; editingRule.value = null; ruleForm.reset() }

function submitRule() {
  if (editingRule.value) { ruleForm.patch(route('lms.admin.onboarding.rules.update', editingRule.value.id), { onSuccess: closeRuleDialog }) }
  else { ruleForm.post(route('lms.admin.onboarding.rules.store'), { onSuccess: closeRuleDialog }) }
}

function deleteRule(rule: any) {
  confirmDialog.value.open(
    { title: t('lms_onboarding.dialog.delete_title'), message: t('lms_onboarding.dialog.delete_message'), confirmText: t('lms_onboarding.dialog.delete_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { router.delete(route('lms.admin.onboarding.rules.destroy', rule.id)) },
  )
}

const assignDialog = ref(false)
const assignForm   = useForm({ plan_id: null as number | null, user_id: null as number | null })
const assignErrors = computed(() => assignForm.errors)

function submitAssign() { assignForm.post(route('lms.admin.onboarding.assign'), { onSuccess: () => { assignDialog.value = false; assignForm.reset() } }) }

function syncPlan(planId: number) { syncingPlan.value = planId; router.post(route('lms.admin.onboarding.sync'), { plan_id: planId }, { preserveScroll: true, onFinish: () => { syncingPlan.value = null } }) }
function checkBlock(planId: number) { router.post(route('lms.admin.onboarding.check-block'), { plan_id: planId }, { preserveScroll: true }) }
function sendReminders() { remindersLoading.value = true; router.post(route('lms.admin.onboarding.reminders'), {}, { preserveScroll: true, onFinish: () => { remindersLoading.value = false } }) }
function openPlanDetail(item: any) { router.get(route('lms.admin.onboarding.plan', item.plan_id)) }
function changePage(p: number) { router.get(route('lms.admin.onboarding'), { page: p }, { preserveScroll: true }) }

function planStatusColor(status: string): string {
  const map: Record<string, string> = { pending: 'warning', active: 'info', completed: 'success', cancelled: 'error' }
  return map[status] ?? 'grey'
}
function ruleTypeColor(type: string): string {
  const map: Record<string, string> = { induction: 'primary', department: 'secondary', role_based: 'warning', policy: 'info' }
  return map[type] ?? 'grey'
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_onboarding.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('lms_onboarding.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="mdi-bell-outline" @click="sendReminders" :loading="remindersLoading">{{ t('lms_onboarding.send_reminders') }}</VBtn>
        <VBtn v-if="activeTab === 'rules'" color="primary" prepend-icon="mdi-plus" @click="ruleDialog = true">{{ t('lms_onboarding.add_rule') }}</VBtn>
        <VBtn v-if="activeTab === 'plans'" color="primary" prepend-icon="mdi-account-plus" @click="assignDialog = true">{{ t('lms_onboarding.assign_training') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-4" v-if="activeTab === 'plans'">
      <VCol cols="6" md="3"><VCard variant="outlined" class="text-center pa-3"><div class="text-h5 font-weight-bold text-primary">{{ plans?.total ?? 0 }}</div><div class="text-caption text-medium-emphasis">{{ t('lms_onboarding.kpi.active_plans') }}</div></VCard></VCol>
      <VCol cols="6" md="3"><VCard variant="outlined" class="text-center pa-3"><div class="text-h5 font-weight-bold text-error">{{ blockingCount ?? 0 }}</div><div class="text-caption text-medium-emphasis">{{ t('lms_onboarding.kpi.blocking_onboarding') }}</div></VCard></VCol>
    </VRow>

    <VTabs v-model="activeTab" class="mb-4">
      <VTab value="plans">{{ t('lms_onboarding.tabs.plans') }}</VTab>
      <VTab value="rules">{{ t('lms_onboarding.tabs.rules') }}</VTab>
      <VTab v-if="trainingStatus" value="detail">{{ t('lms_onboarding.tabs.detail') }}</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="plans">
        <VCard variant="outlined">
          <VDataTable :headers="planHeaders" :items="plans?.data ?? []" density="compact" hover>
            <template #item.completion_pct="{ item }">
              <div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.completion_pct" :color="item.completion_pct >= 100 ? 'success' : item.completion_pct > 0 ? 'warning' : 'grey'" height="8" rounded style="width:80px" /><span class="text-body-2">{{ item.completion_pct }}%</span></div>
            </template>
            <template #item.is_blocked="{ item }">
              <VChip v-if="item.is_blocked" color="error" size="small" variant="tonal" prepend-icon="mdi-lock">{{ t('lms_onboarding.table.blocked_count', { count: item.blocking_count }) }}</VChip>
              <VChip v-else-if="item.total === 0" color="grey" size="small" variant="tonal">{{ t('lms_onboarding.table.no_training') }}</VChip>
              <VChip v-else color="success" size="small" variant="tonal" prepend-icon="mdi-check">{{ t('lms_onboarding.table.clear') }}</VChip>
            </template>
            <template #item.plan_status="{ item }"><VChip :color="planStatusColor(item.plan_status)" size="small" variant="tonal">{{ item.plan_status }}</VChip></template>
            <template #item.actions="{ item }">
              <VBtn size="small" variant="text" icon="mdi-magnify" @click="openPlanDetail(item)" :title="t('lms_onboarding.table.view_detail')" />
              <VBtn size="small" variant="text" icon="mdi-sync" @click="syncPlan(item.plan_id)" :title="t('lms_onboarding.table.sync_completions')" :loading="syncingPlan === item.plan_id" />
              <VBtn size="small" variant="text" icon="mdi-shield-check" @click="checkBlock(item.plan_id)" :title="t('lms_onboarding.table.check_block')" />
            </template>
          </VDataTable>
        </VCard>
        <div v-if="plans?.last_page > 1" class="d-flex justify-center mt-3"><VPagination v-model="page" :length="plans.last_page" @update:model-value="changePage" /></div>
      </VWindowItem>

      <VWindowItem value="rules">
        <VCard variant="outlined">
          <VDataTable :headers="ruleHeaders" :items="rules?.data ?? []" density="compact" hover>
            <template #item.rule_type="{ item }"><VChip :color="ruleTypeColor(item.rule_type)" size="small" variant="tonal">{{ ruleTypes?.[item.rule_type] ?? item.rule_type }}</VChip></template>
            <template #item.scope="{ item }"><span class="text-body-2">{{ scopeTypes?.[item.scope_type] ?? item.scope_type }}<span v-if="item.scope_label" class="text-medium-emphasis"> — {{ item.scope_label }}</span></span></template>
            <template #item.blocks_onboarding_completion="{ item }"><VIcon v-if="item.blocks_onboarding_completion" icon="mdi-lock" color="error" size="18" /><VIcon v-else icon="mdi-lock-open-outline" color="grey" size="18" /></template>
            <template #item.is_active="{ item }"><VChip :color="item.is_active ? 'success' : 'grey'" size="small" variant="tonal">{{ item.is_active ? t('lms_onboarding.table.active') : t('lms_onboarding.table.inactive') }}</VChip></template>
            <template #item.actions="{ item }"><VBtn size="small" variant="text" icon="mdi-pencil" @click="editRule(item)" /><VBtn size="small" variant="text" icon="mdi-delete" color="error" @click="deleteRule(item)" /></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="detail">
        <template v-if="trainingStatus">
          <div class="d-flex align-center gap-3 mb-4">
            <VBtn variant="text" prepend-icon="mdi-arrow-left" @click="activeTab = 'plans'">{{ t('lms_onboarding.detail.back') }}</VBtn>
            <h2 class="text-h6 font-weight-bold">{{ selectedEmployeeName }}</h2>
            <VChip v-if="blockStatus?.is_blocked" color="error" prepend-icon="mdi-lock">{{ t('lms_onboarding.detail.onboarding_blocked', { count: blockStatus.blocking_count }) }}</VChip>
            <VChip v-else color="success" prepend-icon="mdi-check-circle">{{ t('lms_onboarding.detail.no_blocks') }}</VChip>
          </div>
          <VRow class="mb-4"><VCol cols="6" sm="3" v-for="tile in detailTiles" :key="tile.label"><VCard variant="outlined" class="text-center pa-3"><div class="text-h5 font-weight-bold" :class="`text-${tile.color}`">{{ tile.value }}</div><div class="text-caption text-medium-emphasis">{{ tile.label }}</div></VCard></VCol></VRow>
          <VCard variant="outlined">
            <VCardTitle class="pa-4">{{ t('lms_onboarding.detail.training_assignments') }}</VCardTitle>
            <VDataTable :headers="assignmentHeaders" :items="trainingStatus.assignments" density="compact" hover>
              <template #item.status="{ item }"><VChip :color="item.status_color" size="small" variant="tonal">{{ item.status }}</VChip></template>
              <template #item.blocks_completion="{ item }"><VIcon v-if="item.blocks_completion" icon="mdi-lock" color="error" size="18" /><span v-else class="text-medium-emphasis">—</span></template>
              <template #item.pass_score="{ item }">{{ item.pass_score !== null ? item.pass_score + '%' : '—' }}</template>
              <template #item.understanding_score="{ item }">{{ item.understanding_score !== null ? item.understanding_score : '—' }}</template>
            </VDataTable>
          </VCard>
        </template>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="ruleDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-4">{{ editingRule ? t('lms_onboarding.dialog.rule_title_edit') : t('lms_onboarding.dialog.rule_title_new') }}</VCardTitle>
        <VCardText>
          <VSelect v-if="!editingRule" v-model="ruleForm.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_onboarding.dialog.course')" :error-messages="ruleErrors.course_id" class="mb-3" />
          <VRow><VCol cols="6"><VSelect v-model="ruleForm.rule_type" :items="ruleTypeItems" :label="t('lms_onboarding.dialog.rule_type')" :error-messages="ruleErrors.rule_type" /></VCol><VCol cols="6"><VSelect v-model="ruleForm.scope_type" :items="scopeTypeItems" :label="t('lms_onboarding.dialog.applies_to')" :error-messages="ruleErrors.scope_type" /></VCol></VRow>
          <VTextField v-if="ruleForm.scope_type !== 'all'" v-model="ruleForm.scope_label" :label="t('lms_onboarding.dialog.scope_label')" class="mt-3" :error-messages="ruleErrors.scope_label" />
          <VRow class="mt-1"><VCol cols="6"><VTextField v-model.number="ruleForm.due_within_days" :label="t('lms_onboarding.dialog.due_within_days')" type="number" min="1" max="365" :error-messages="ruleErrors.due_within_days" /></VCol><VCol cols="6"><VTextField v-model.number="ruleForm.min_pass_score" :label="t('lms_onboarding.dialog.min_pass_score')" type="number" min="0" max="100" clearable :error-messages="ruleErrors.min_pass_score" /></VCol></VRow>
          <VRow class="mt-1"><VCol cols="6"><VTextField v-model.number="ruleForm.min_understanding_score" :label="t('lms_onboarding.dialog.min_understanding_score')" type="number" min="0" max="100" clearable :error-messages="ruleErrors.min_understanding_score" /></VCol></VRow>
          <div class="d-flex gap-4 mt-3">
            <VCheckbox v-model="ruleForm.is_mandatory" :label="t('lms_onboarding.dialog.mandatory')" hide-details density="compact" />
            <VCheckbox v-model="ruleForm.blocks_onboarding_completion" :label="t('lms_onboarding.dialog.blocks_onboarding')" hide-details density="compact" color="error" />
            <VCheckbox v-model="ruleForm.is_active" :label="t('lms_onboarding.dialog.active')" hide-details density="compact" />
          </div>
        </VCardText>
        <VCardActions class="pa-4 pt-0"><VSpacer /><VBtn variant="text" @click="closeRuleDialog">{{ t('lms_onboarding.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="ruleForm.processing" @click="submitRule">{{ editingRule ? t('lms_onboarding.dialog.update') : t('lms_onboarding.dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="assignDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_onboarding.dialog.assign_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('lms_onboarding.dialog.assign_body') }}</p>
          <VTextField v-model.number="assignForm.plan_id" :label="t('lms_onboarding.dialog.plan_id')" type="number" :error-messages="assignErrors.plan_id" class="mb-3" />
          <VTextField v-model.number="assignForm.user_id" :label="t('lms_onboarding.dialog.user_id')" type="number" :error-messages="assignErrors.user_id" class="mb-3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0"><VSpacer /><VBtn variant="text" @click="assignDialog = false">{{ t('lms_onboarding.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="assignForm.processing" @click="submitAssign">{{ t('lms_onboarding.dialog.assign') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
