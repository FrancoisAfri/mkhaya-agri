<script setup lang="ts">
import { computed, ref, watch, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const { t } = useI18n()
const route = ziggyRoute

type Plan = {
  id: number; uuid: string; status: { value: string; label: string; color: string }
  termination_type: string; last_working_day: string | null; completion: number
  hr_person: { id: number; candidate: { full_name: string; email: string } | null }
}
type Template = { id: number; title: string; notice_period_days: number }
type Person = { id: number; name: string; email: string }

const props = defineProps<{
  plans: { data: Plan[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; active: number; completed: number; pending: number }
  templates: Template[]; persons: Person[]
}>()

const search = ref<string>(props.filters?.search ?? '')
const status = ref<string>(props.filters?.status ?? '')
let searchTimer: ReturnType<typeof setTimeout>

function applyFilters() { router.get(route('offboarding.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) })
watch(status, applyFilters)
function clearFilters() { search.value = ''; status.value = ''; router.get(route('offboarding.index'), {}, { replace: true }) }

const statCards = computed(() => [
  { label: t('offboarding.total_plans'), value: props.stats.total, icon: 'tabler-user-share', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('offboarding.active'), value: props.stats.active, icon: 'tabler-player-play', color: 'info', bg: 'rgba(0,207,232,0.12)' },
  { label: t('offboarding.completed'), value: props.stats.completed, icon: 'tabler-circle-check', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('offboarding.pending'), value: props.stats.pending, icon: 'tabler-clock', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
])

const headers = computed(() => [
  { title: t('offboarding.table_employee'), key: 'hr_person', sortable: false },
  { title: t('offboarding.table_termination_type'), key: 'termination_type', sortable: false, width: '180px' },
  { title: t('offboarding.table_last_working_day'), key: 'last_working_day', sortable: false, width: '160px' },
  { title: t('offboarding.table_status'), key: 'status', sortable: false, width: '130px' },
  { title: t('offboarding.table_completion'), key: 'completion', sortable: false, width: '180px' },
  { title: t('offboarding.table_actions'), key: 'actions', sortable: false, width: '100px' },
])

const rows = computed(() => props.plans?.data ?? [])
const links = computed(() => props.plans?.links ?? [])
const meta = computed(() => props.plans?.meta ?? {})
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function terminationLabel(val: string) { const map: Record<string, string> = { resignation: t('offboarding.termination_resignation'), retrenchment: t('offboarding.termination_retrenchment'), dismissal: t('offboarding.termination_dismissal'), retirement: t('offboarding.termination_retirement'), contract_end: t('offboarding.termination_contract_end'), death: t('offboarding.termination_death') }; return map[val] ?? val }
function terminationColor(val: string) { if (val === 'dismissal') return 'error'; if (val === 'retrenchment') return 'warning'; if (val === 'resignation') return 'info'; if (val === 'retirement') return 'success'; return 'default' }
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const quickFilters = computed(() => [
  { label: t('offboarding.quick_filter_all'), value: '' },
  { label: t('offboarding.quick_filter_pending'), value: 'pending', color: 'warning' },
  { label: t('offboarding.quick_filter_active'), value: 'active', color: 'info' },
  { label: t('offboarding.quick_filter_completed'), value: 'completed', color: 'success' },
  { label: t('offboarding.quick_filter_cancelled'), value: 'cancelled', color: 'error' },
])

const createDialog = ref(false); const submitting = ref(false)
const createForm = reactive({ person_id: null as number | null, template_id: null as number | null, termination_type: '', resignation_date: '', last_working_day: '', notes: '' })
function resetCreateForm() { createForm.person_id = null; createForm.template_id = null; createForm.termination_type = ''; createForm.resignation_date = ''; createForm.last_working_day = ''; createForm.notes = '' }
function submitCreate() { if (!createForm.person_id) return; submitting.value = true; router.post(route('offboarding.persons.plans.store', [createForm.person_id]), { template_id: createForm.template_id || undefined, termination_type: createForm.termination_type, resignation_date: createForm.resignation_date || undefined, last_working_day: createForm.last_working_day || undefined, notes: createForm.notes || undefined }, { preserveScroll: true, onFinish: () => { submitting.value = false; createDialog.value = false; resetCreateForm() } }) }
watch(createDialog, open => { if (!open) resetCreateForm() })

const terminationTypes = computed(() => [
  { title: t('offboarding.termination_resignation'), value: 'resignation' },
  { title: t('offboarding.termination_retrenchment'), value: 'retrenchment' },
  { title: t('offboarding.termination_dismissal'), value: 'dismissal' },
  { title: t('offboarding.termination_retirement'), value: 'retirement' },
  { title: t('offboarding.termination_contract_end'), value: 'contract_end' },
  { title: t('offboarding.termination_death'), value: 'death' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('offboarding.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('offboarding.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn color="default" variant="tonal" prepend-icon="tabler-files" @click="router.visit(route('offboarding.templates.index'))">{{ t('offboarding.templates_btn') }}</VBtn>
        <VBtn color="primary" variant="flat" prepend-icon="tabler-plus" @click="createDialog = true">{{ t('offboarding.new_offboarding') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
        <VCard elevation="0" border><VCardText class="d-flex align-center gap-4 pa-5"><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="28" /></div><div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p></div></VCardText></VCard>
      </VCol>
    </VRow>

    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="qf in quickFilters" :key="qf.value" size="default" variant="tonal" :color="status === qf.value ? (qf.color ?? 'primary') : 'default'" class="cursor-pointer font-weight-medium" @click="status = qf.value">{{ qf.label }}</VChip>
    </div>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="5"><AppTextField v-model="search" :label="t('offboarding.search_placeholder')" prepend-inner-icon="tabler-search" clearable hide-details /></VCol>
          <VCol cols="auto" class="ms-auto"><VBtn v-if="search || status" variant="text" color="default" prepend-icon="tabler-x" @click="clearFilters">{{ t('offboarding.clear') }}</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="20" hover>
        <template #item.hr_person="{ item }">
          <div class="d-flex align-center gap-2">
            <VAvatar size="36" color="primary" variant="tonal"><span class="text-caption font-weight-bold">{{ (item.hr_person.candidate?.full_name ?? '?')[0].toUpperCase() }}</span></VAvatar>
            <div><p class="text-body-2 font-weight-semibold mb-0">{{ item.hr_person.candidate?.full_name ?? t('offboarding.unknown') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ item.hr_person.candidate?.email ?? '' }}</p></div>
          </div>
        </template>
        <template #item.termination_type="{ item }"><VChip :color="terminationColor(item.termination_type)" size="small" label variant="tonal" class="font-weight-medium">{{ terminationLabel(item.termination_type) }}</VChip></template>
        <template #item.last_working_day="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.last_working_day) }}</span></template>
        <template #item.status="{ item }"><VChip :color="item.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ item.status.label }}</VChip></template>
        <template #item.completion="{ item }"><div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.completion" :color="item.status.value === 'completed' ? 'success' : 'primary'" rounded height="6" style="min-width: 80px;" /><span class="text-caption font-weight-medium">{{ item.completion }}%</span></div></template>
        <template #item.actions="{ item }"><div @click.stop><VBtn size="x-small" color="primary" variant="text" icon="tabler-eye" @click="router.visit(route('offboarding.persons.plans.show', [item.hr_person.id, item.id]))" /></div></template>
        <template #no-data><div class="text-center py-12"><VIcon icon="tabler-user-share" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('offboarding.no_plans') }}</p><p class="text-body-2 text-medium-emphasis mb-4">{{ t('offboarding.no_plans_sub') }}</p><VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="createDialog = true">{{ t('offboarding.new_offboarding') }}</VBtn></div></template>
      </VDataTable>
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('offboarding.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</p>
        <div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div>
      </div>
    </VCard>

    <VDialog v-model="createDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('offboarding.dialog_create_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VAutocomplete v-model="createForm.person_id" :items="persons" item-title="name" item-value="id" :label="t('offboarding.field_employee')" variant="outlined" density="comfortable" hide-details="auto" clearable>
            <template #item="{ item, props: itemProps }"><VListItem v-bind="itemProps"><template #prepend><VAvatar size="32" color="primary" variant="tonal"><span class="text-caption font-weight-bold">{{ item.raw.name[0] }}</span></VAvatar></template><template #subtitle>{{ item.raw.email }}</template></VListItem></template>
          </VAutocomplete>
          <VSelect v-model="createForm.termination_type" :items="terminationTypes" :label="t('offboarding.field_termination_type')" variant="outlined" density="comfortable" hide-details="auto" />
          <VSelect v-model="createForm.template_id" :items="templates" item-title="title" item-value="id" :label="t('offboarding.field_template_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" />
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="createForm.resignation_date" :label="t('offboarding.field_resignation_date')" type="date" variant="outlined" density="comfortable" hide-details="auto" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="createForm.last_working_day" :label="t('offboarding.field_last_working_day')" type="date" variant="outlined" density="comfortable" hide-details="auto" /></VCol>
          </VRow>
          <VTextarea v-model="createForm.notes" :label="t('offboarding.field_notes_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submitting" @click="createDialog = false">{{ t('offboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submitting" :disabled="!createForm.person_id || !createForm.termination_type" @click="submitCreate">{{ t('offboarding.create_plan') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
