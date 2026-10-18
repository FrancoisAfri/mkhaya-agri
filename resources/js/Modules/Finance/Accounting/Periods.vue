<script lang="ts" setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Period { id: number; period_no: number; label: string; start_date: string; end_date: string; status: string; status_color: string; actions: string[]; checks: { level: string; message: string }[] }
interface Year { id: number; label: string; status: string; start_date: string; end_date: string; is_current: boolean }
const props = defineProps<{
  years: Year[]
  selected: (Year & { summary: { by_status: Record<string, number>; outstanding: number; progress: { total: number; done: number; percent: number } } }) | null
  periods: Period[]
  perms: { tier: number; allowed: Record<string, boolean>; matrix: any[] }
  fy_config: { start_month: number; months: Record<string, string> }
}>()

const selectedYearId = ref(props.selected?.id ?? null)
function switchView(id: number) { router.get(route('finance.accounting.periods'), { year: id }, { preserveState: false, preserveScroll: true }) }

const statusLabel = (s: string) => t('acc.pstat_' + s)
const actionColor: Record<string, string> = { soft_close: 'warning', hard_close: 'orange', reopen: 'info', lock: 'error', unlock: 'deep-purple' }
const needsReason = (a: string) => a === 'reopen' || a === 'unlock'
function actionAllowed(a: string, status: string) {
  if (a === 'reopen') return status === 'hard_close' ? props.perms.allowed.reopen_hard : props.perms.allowed.reopen_soft
  return props.perms.allowed[a] ?? false
}

// ── period transition (with closing wizard for soft/hard close) ──
const confirm = ref()
const wizard = ref(false)
const wizardPeriod = ref<Period | null>(null)
const wizardAction = ref('')
const checklist = ref<any[]>([])
const wizardLoading = ref(false)
const comment = ref('')
const blocking = computed(() => checklist.value.some(c => c.type === 'computed' && c.status === 'fail'))

async function startAction(p: Period, action: string) {
  if (action === 'soft_close' || action === 'hard_close') {
    wizardPeriod.value = p; wizardAction.value = action; comment.value = ''; wizard.value = true; wizardLoading.value = true; checklist.value = []
    const res = await fetch(route('finance.accounting.periods.checklist', p.id), { headers: { Accept: 'application/json' } })
    checklist.value = (await res.json()).checklist; wizardLoading.value = false
    return
  }
  const reason = needsReason(action) ? (window.prompt(t('acc.reason_required')) || '') : ''
  if (needsReason(action) && !reason) return
  confirm.value.open(
    { title: t('acc.act_' + action), message: t('acc.confirm_' + action, { period: p.label }), confirmText: t('acc.' + action), confirmColor: actionColor[action], icon: 'tabler-alert-triangle' },
    () => useForm({ action, reason }).submit('patch', route('finance.accounting.periods.update', p.id), { preserveScroll: true }),
  )
}
function toggleCheck(item: string, done: boolean) {
  useForm({ item, done }).post(route('finance.accounting.periods.checklist.toggle', wizardPeriod.value!.id), {
    preserveScroll: true, onSuccess: async () => {
      const res = await fetch(route('finance.accounting.periods.checklist', wizardPeriod.value!.id), { headers: { Accept: 'application/json' } })
      checklist.value = (await res.json()).checklist
    },
  })
}
function commitClose() {
  useForm({ action: wizardAction.value, reason: comment.value }).submit('patch', route('finance.accounting.periods.update', wizardPeriod.value!.id), {
    preserveScroll: true, onSuccess: () => { wizard.value = false },
  })
}

// ── financial-year management ──
function createYear() { useForm({}).post(route('finance.accounting.fiscal-years.create'), { preserveScroll: true }) }
function archiveYear() {
  confirm.value.open({ title: t('acc.archive_year'), message: t('acc.archive_year_confirm', { year: props.selected?.label }), confirmText: t('acc.archive'), confirmColor: 'error', icon: 'tabler-archive' },
    () => useForm({}).post(route('finance.accounting.fiscal-years.archive', props.selected!.id), { preserveScroll: true }))
}
function switchCurrent() { useForm({}).post(route('finance.accounting.fiscal-years.switch', props.selected!.id), { preserveScroll: true }) }
const editDialog = ref(false); const editForm = useForm({ label: '' })
function openEdit() { editForm.label = props.selected?.label ?? ''; editDialog.value = true }
function saveEdit() { editForm.patch(route('finance.accounting.fiscal-years.update', props.selected!.id), { preserveScroll: true, onSuccess: () => { editDialog.value = false } }) }

// FY start month setting
const fyDialog = ref(false); const fyForm = useForm({ start_month: props.fy_config.start_month })
function saveFyStart() { fyForm.post(route('finance.accounting.fiscal-settings.start-month'), { preserveScroll: true, onSuccess: () => { fyDialog.value = false } }) }

// ── year-end wizard ──
const yeDialog = ref(false); const yeSteps = ref<any[]>([]); const yeLoading = ref(false)
async function openYearEnd() {
  yeDialog.value = true; yeLoading.value = true; yeSteps.value = []
  const res = await fetch(route('finance.accounting.fiscal-years.year-end.steps', props.selected!.id), { headers: { Accept: 'application/json' } })
  yeSteps.value = (await res.json()).steps; yeLoading.value = false
}
function commitYearEnd() { useForm({}).post(route('finance.accounting.fiscal-years.year-end', props.selected!.id), { preserveScroll: true, onSuccess: () => { yeDialog.value = false } }) }

// ── audit history ──
const histDrawer = ref(false); const history = ref<any[]>([]); const histLoading = ref(false); const histPeriod = ref<Period | null>(null)
async function openHistory(p: Period) {
  histPeriod.value = p; histDrawer.value = true; histLoading.value = true; history.value = []
  const res = await fetch(route('finance.accounting.periods.history', p.id), { headers: { Accept: 'application/json' } })
  history.value = (await res.json()).history; histLoading.value = false
}

const matrixDialog = ref(false)
const checkIcon = (s: string) => ({ pass: 'tabler-circle-check', done: 'tabler-circle-check', fail: 'tabler-alert-circle', warn: 'tabler-alert-triangle', todo: 'tabler-circle' }[s] || 'tabler-circle')
const checkColor = (s: string) => ({ pass: 'success', done: 'success', fail: 'error', warn: 'warning', todo: 'grey' }[s] || 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.gl_crumb_short') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.fiscal_periods') }}</h2>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('acc.periods_help') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-shield-lock" @click="matrixDialog = true">{{ t('acc.permissions') }}</VBtn>
        <VBtn v-if="perms.allowed.year_end && selected" color="primary" prepend-icon="tabler-calendar-check" @click="openYearEnd">{{ t('acc.year_end_close') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="5">
        <VCard border class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-caption text-medium-emphasis">{{ t('acc.current_financial_year') }}</div>
              <VChip v-if="selected" size="x-small" :color="selected.status === 'open' ? 'success' : (selected.status === 'archived' ? 'grey' : 'orange')" variant="tonal">{{ t('acc.ystat_' + selected.status) }}</VChip>
            </div>
            <VSelect :model-value="selectedYearId" :items="years.map(y => ({ title: y.label + (y.is_current ? ' ★' : ''), value: y.id }))" density="compact" variant="plain" hide-details class="text-h6 font-weight-bold mb-2" @update:model-value="switchView" />
            <VRow class="mb-1">
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('acc.start_date') }}</div><div>{{ selected?.start_date }}</div></VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('acc.end_date') }}</div><div>{{ selected?.end_date }}</div></VCol>
            </VRow>
            <template v-if="selected">
              <div class="d-flex justify-space-between text-caption mb-1">
                <span>{{ selected.summary.progress.done }} {{ t('acc.of') }} {{ selected.summary.progress.total }} {{ t('acc.periods_closed') }}</span>
                <span class="font-weight-medium">{{ selected.summary.progress.percent }}%</span>
              </div>
              <VProgressLinear :model-value="selected.summary.progress.percent" color="primary" height="8" rounded class="mb-3" />
            </template>
            <div class="d-flex gap-2 flex-wrap">
              <VBtn v-if="perms.allowed.manage_year" size="small" variant="tonal" prepend-icon="tabler-plus" @click="createYear">{{ t('acc.create_year') }}</VBtn>
              <VBtn v-if="perms.allowed.manage_year" size="small" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit">{{ t('acc.edit') }}</VBtn>
              <VBtn v-if="perms.allowed.manage_year && !selected?.is_current" size="small" variant="tonal" prepend-icon="tabler-switch" @click="switchCurrent">{{ t('acc.switch_year') }}</VBtn>
              <VBtn v-if="perms.allowed.manage_year && selected?.status !== 'archived'" size="small" variant="text" color="error" prepend-icon="tabler-archive" @click="archiveYear">{{ t('acc.archive') }}</VBtn>
              <VBtn v-if="perms.allowed.settings" size="small" variant="text" prepend-icon="tabler-calendar-cog" @click="fyDialog = true">{{ t('acc.fy_start') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard border class="h-100"><VCardText>
          <div class="text-caption text-medium-emphasis mb-3">{{ selected?.label }} · {{ t('acc.summary') }}</div>
          <VRow>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5 text-success">{{ selected?.summary.by_status.open ?? 0 }}</div><div class="text-caption">{{ t('acc.pstat_open') }}</div></VCol>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5 text-warning">{{ selected?.summary.by_status.soft_close ?? 0 }}</div><div class="text-caption">{{ t('acc.pstat_soft_close') }}</div></VCol>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5" style="color:rgb(var(--v-theme-orange))">{{ selected?.summary.by_status.hard_close ?? 0 }}</div><div class="text-caption">{{ t('acc.pstat_hard_close') }}</div></VCol>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5 text-error">{{ selected?.summary.by_status.locked ?? 0 }}</div><div class="text-caption">{{ t('acc.pstat_locked') }}</div></VCol>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5">{{ selected?.summary.outstanding ?? 0 }}</div><div class="text-caption">{{ t('acc.outstanding_checks') }}</div></VCol>
            <VCol cols="6" sm="2" class="text-center"><div class="text-h5">{{ selected?.summary.progress.percent ?? 0 }}%</div><div class="text-caption">{{ t('acc.complete') }}</div></VCol>
          </VRow>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border class="mt-3">
      <VTable>
        <thead><tr>
          <th>{{ t('acc.period') }}</th><th>{{ t('acc.range') }}</th><th>{{ t('acc.status') }}</th>
          <th>{{ t('acc.pre_close_checks') }}</th><th class="text-right">{{ t('acc.actions') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="p in periods" :key="p.id">
            <td class="font-weight-medium">
              <VIcon v-if="p.status === 'locked'" icon="tabler-lock" size="15" color="error" class="me-1" />{{ p.label }}
            </td>
            <td class="text-caption">{{ p.start_date }} → {{ p.end_date }}</td>
            <td><VChip size="small" :color="p.status_color" variant="tonal">{{ statusLabel(p.status) }}</VChip></td>
            <td>
              <a class="cursor-pointer text-primary" @click="startAction(p, p.status === 'open' ? 'soft_close' : 'hard_close')">
                <span v-if="!p.checks.length" class="text-success">✓ {{ t('acc.clear') }}</span>
                <span v-else class="text-warning">{{ p.checks.length }} {{ t('acc.issues') }} · {{ t('acc.view') }}</span>
              </a>
            </td>
            <td class="text-right text-no-wrap">
              <template v-for="a in p.actions" :key="a">
                <VBtn v-if="actionAllowed(a, p.status)" size="x-small" variant="tonal" :color="actionColor[a]" class="ms-1" @click="startAction(p, a)">{{ t('acc.' + a) }}</VBtn>
              </template>
              <VBtn icon="tabler-history" size="small" variant="text" class="ms-1" :title="t('acc.history')" @click="openHistory(p)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Closing wizard -->
    <VDialog v-model="wizard" max-width="560">
      <VCard>
        <VCardTitle>{{ wizardAction === 'soft_close' ? t('acc.soft_close') : t('acc.hard_close') }} — {{ wizardPeriod?.label }}</VCardTitle>
        <VCardText>
          <div v-if="wizardLoading" class="text-center py-6"><VProgressCircular indeterminate color="primary" /></div>
          <template v-else>
            <VList density="compact" class="mb-2">
              <VListItem v-for="c in checklist" :key="c.key">
                <template #prepend>
                  <VIcon v-if="c.type === 'computed'" :icon="checkIcon(c.status)" :color="checkColor(c.status)" />
                  <VCheckboxBtn v-else :model-value="c.status === 'done'" @update:model-value="v => toggleCheck(c.key, v)" />
                </template>
                <VListItemTitle>{{ t('acc.chk_' + c.key) !== 'acc.chk_' + c.key ? t('acc.chk_' + c.key) : c.label }}</VListItemTitle>
                <VListItemSubtitle v-if="c.message">{{ c.message }}</VListItemSubtitle>
              </VListItem>
            </VList>
            <VAlert v-if="blocking && wizardAction === 'hard_close'" type="error" variant="tonal" density="compact" class="mb-3">{{ t('acc.close_blocked') }}</VAlert>
            <VTextarea v-model="comment" :label="t('acc.comment_optional')" variant="outlined" density="comfortable" rows="2" auto-grow />
          </template>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="wizard = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn :color="wizardAction === 'soft_close' ? 'warning' : 'orange'" :disabled="blocking && wizardAction === 'hard_close'" @click="commitClose">{{ t('acc.' + wizardAction) }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Year-end wizard -->
    <VDialog v-model="yeDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.year_end_close') }} — {{ selected?.label }}</VCardTitle>
        <VCardText>
          <div v-if="yeLoading" class="text-center py-6"><VProgressCircular indeterminate color="primary" /></div>
          <VList v-else density="compact">
            <VListItem v-for="(s, i) in yeSteps" :key="s.key">
              <template #prepend><VIcon :icon="s.done ? 'tabler-circle-check' : 'tabler-circle-dot'" :color="s.done ? 'success' : 'grey'" /></template>
              <VListItemTitle>{{ t('acc.step') }} {{ i + 1 }}: {{ s.label }}</VListItemTitle>
              <VListItemSubtitle v-if="s.detail">{{ s.detail }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="yeDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" @click="commitYearEnd">{{ t('acc.run_year_end') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit year -->
    <VDialog v-model="editDialog" max-width="380"><VCard>
      <VCardTitle>{{ t('acc.edit') }} — {{ selected?.label }}</VCardTitle>
      <VCardText><VTextField v-model="editForm.label" :label="t('acc.label')" variant="outlined" density="comfortable" /></VCardText>
      <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="editDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="editForm.processing" @click="saveEdit">{{ t('acc.save') }}</VBtn></VCardActions>
    </VCard></VDialog>

    <!-- FY start month -->
    <VDialog v-model="fyDialog" max-width="420"><VCard>
      <VCardTitle>{{ t('acc.fy_start') }}</VCardTitle>
      <VCardText>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('acc.fy_start_help') }}</p>
        <VSelect v-model="fyForm.start_month" :items="Object.entries(fy_config.months).map(([v, title]) => ({ title, value: Number(v) }))" :label="t('acc.fy_start_month')" variant="outlined" density="comfortable" />
      </VCardText>
      <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="fyDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="fyForm.processing" @click="saveFyStart">{{ t('acc.save') }}</VBtn></VCardActions>
    </VCard></VDialog>

    <!-- Permissions matrix -->
    <VDialog v-model="matrixDialog" max-width="560"><VCard>
      <VCardTitle>{{ t('acc.permissions') }}</VCardTitle>
      <VCardText>
        <p class="text-body-2 text-medium-emphasis mb-2">{{ t('acc.permissions_help') }}</p>
        <VTable density="compact">
          <thead><tr><th>{{ t('acc.action') }}</th><th class="text-center">{{ t('acc.p_accountant') }}</th><th class="text-center">{{ t('acc.p_finance_manager') }}</th><th class="text-center">{{ t('acc.p_cfo') }}</th><th class="text-center">{{ t('acc.p_super_admin') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in perms.matrix" :key="row.action">
              <td>{{ t('acc.act_' + row.action) }}</td>
              <td class="text-center"><VIcon :icon="row.accountant ? 'tabler-check' : 'tabler-x'" :color="row.accountant ? 'success' : 'error'" size="16" /></td>
              <td class="text-center"><VIcon :icon="row.finance_manager ? 'tabler-check' : 'tabler-x'" :color="row.finance_manager ? 'success' : 'error'" size="16" /></td>
              <td class="text-center"><VIcon :icon="row.cfo ? 'tabler-check' : 'tabler-x'" :color="row.cfo ? 'success' : 'error'" size="16" /></td>
              <td class="text-center"><VIcon :icon="row.super_admin ? 'tabler-check' : 'tabler-x'" :color="row.super_admin ? 'success' : 'error'" size="16" /></td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
      <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="matrixDialog = false">{{ t('acc.close') }}</VBtn></VCardActions>
    </VCard></VDialog>

    <!-- Audit history drawer -->
    <VNavigationDrawer v-model="histDrawer" location="right" temporary width="400">
      <div class="pa-4">
        <div class="d-flex justify-space-between align-center mb-3"><h3 class="text-h6">{{ t('acc.history') }} — {{ histPeriod?.label }}</h3><VBtn icon="tabler-x" variant="text" size="small" @click="histDrawer = false" /></div>
        <div v-if="histLoading" class="text-center py-6"><VProgressCircular indeterminate color="primary" /></div>
        <VTimeline v-else-if="history.length" side="end" density="compact" truncate-line="both">
          <VTimelineItem v-for="(h, i) in history" :key="i" size="x-small" dot-color="primary">
            <div class="d-flex justify-space-between"><strong>{{ h.to ? t('acc.pstat_' + h.to) : h.description }}</strong><span class="text-caption text-medium-emphasis">{{ h.at }}</span></div>
            <div v-if="h.from" class="text-caption text-medium-emphasis">{{ t('acc.pstat_' + h.from) }} → {{ h.to ? t('acc.pstat_' + h.to) : '' }}</div>
            <div v-if="h.reason" class="text-caption">"{{ h.reason }}"</div>
            <div class="text-caption text-medium-emphasis">{{ t('acc.by_prefix') }} {{ h.by }}</div>
          </VTimelineItem>
        </VTimeline>
        <div v-else class="text-center text-medium-emphasis py-6">{{ t('acc.no_activity') }}</div>
      </div>
    </VNavigationDrawer>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
