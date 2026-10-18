<script setup lang="ts">
import { computed, ref, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Template = {
  id: number; uuid: string; name: string; description: string | null
  department: string | null; job_title: string | null; employment_type: string | null
  is_active: boolean; task_count: number; created_at: string
}
type Option = { id: number; name: string }

const props = defineProps<{
  templates: { data: Template[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; active: number; inactive: number }
  departments: Option[]; positions: Option[]
}>()

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function employmentTypeLabel(val: string | null) { if (!val) return '—'; const map: Record<string, string> = { permanent: t('onboarding_templates.employment_permanent'), contract: t('onboarding_templates.employment_contract'), part_time: t('onboarding_templates.employment_part_time'), fixed_term: t('onboarding_templates.employment_fixed_term') }; return map[val] ?? val }

const statCards = computed(() => [
  { label: t('onboarding_templates.total_templates'), value: props.stats.total, icon: 'mdi-file-document-multiple-outline', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('onboarding_templates.active'), value: props.stats.active, icon: 'mdi-check-circle-outline', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('onboarding_templates.inactive'), value: props.stats.inactive, icon: 'mdi-pause-circle-outline', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
])

const headers = computed(() => [
  { title: t('onboarding_templates.table_name'), key: 'name', sortable: false },
  { title: t('onboarding_templates.table_department'), key: 'department', sortable: false, width: '150px' },
  { title: t('onboarding_templates.table_job_title'), key: 'job_title', sortable: false, width: '150px' },
  { title: t('onboarding_templates.table_employment_type'), key: 'employment_type', sortable: false, width: '160px' },
  { title: t('onboarding_templates.table_tasks'), key: 'task_count', sortable: false, width: '80px' },
  { title: t('onboarding_templates.table_active'), key: 'is_active', sortable: false, width: '100px' },
  { title: t('onboarding_templates.table_actions'), key: 'actions', sortable: false, width: '120px' },
])

const rows = computed(() => props.templates?.data ?? [])
const links = computed(() => props.templates?.links ?? [])
const meta = computed(() => props.templates?.meta ?? {})
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const createDialog = ref(false); const submitting = ref(false)
const createForm = reactive({ name: '', description: '', department: '', job_title: '', employment_type: '', is_active: true })
function resetCreateForm() { createForm.name = ''; createForm.description = ''; createForm.department = ''; createForm.job_title = ''; createForm.employment_type = ''; createForm.is_active = true }
function submitCreate() { submitting.value = true; router.post(route('onboarding.templates.store'), { ...createForm }, { preserveScroll: true, onFinish: () => { submitting.value = false; createDialog.value = false; resetCreateForm() } }) }
watch(createDialog, open => { if (!open) resetCreateForm() })

const deleteDialog = ref(false); const deletingItem = ref<Template | null>(null); const deleting = ref(false)
function openDeleteDialog(item: Template) { deletingItem.value = item; deleteDialog.value = true }
function confirmDelete() { if (!deletingItem.value) return; deleting.value = true; router.delete(route('onboarding.templates.destroy', [deletingItem.value.uuid]), { preserveScroll: true, onFinish: () => { deleting.value = false; deleteDialog.value = false; deletingItem.value = null } }) }
watch(deleteDialog, open => { if (!open) deletingItem.value = null })

const departmentOptions = computed(() => props.departments.map(d => ({ title: d.name, value: d.name })))
const positionOptions = computed(() => props.positions.map(p => ({ title: p.name, value: p.name })))
const employmentTypes = computed(() => [
  { title: t('onboarding_templates.employment_permanent'), value: 'permanent' },
  { title: t('onboarding_templates.employment_contract'), value: 'contract' },
  { title: t('onboarding_templates.employment_part_time'), value: 'part_time' },
  { title: t('onboarding_templates.employment_fixed_term'), value: 'fixed_term' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VBtn icon="mdi-arrow-left" variant="text" size="small" @click="router.visit(route('onboarding.index'))" />
          <h4 class="text-h4 font-weight-bold">{{ t('onboarding_templates.title') }}</h4>
        </div>
        <p class="text-medium-emphasis mb-0 ms-10">{{ t('onboarding_templates.subtitle') }}</p>
      </div>
      <VBtn color="primary" variant="flat" prepend-icon="mdi-plus" @click="createDialog = true">{{ t('onboarding_templates.new_template') }}</VBtn>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="4">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="28" /></div>
            <div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p></div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard rounded="lg" elevation="0" border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="20" hover @click:row="(_: unknown, row: any) => router.visit(route('onboarding.templates.show', [row.item.uuid]))">
        <template #item.name="{ item }"><div><p class="text-body-2 font-weight-semibold mb-0">{{ item.name }}</p><p v-if="item.description" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width: 260px;">{{ item.description }}</p></div></template>
        <template #item.department="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.department ?? '—' }}</span></template>
        <template #item.job_title="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.job_title ?? '—' }}</span></template>
        <template #item.employment_type="{ item }"><VChip v-if="item.employment_type" size="x-small" color="default" variant="tonal" label>{{ employmentTypeLabel(item.employment_type) }}</VChip><span v-else class="text-body-2 text-medium-emphasis">—</span></template>
        <template #item.task_count="{ item }"><VChip size="x-small" color="primary" variant="tonal" label>{{ item.task_count }}</VChip></template>
        <template #item.is_active="{ item }"><VChip :color="item.is_active ? 'success' : 'default'" size="x-small" label variant="tonal" class="font-weight-medium">{{ item.is_active ? t('onboarding_templates.active') : t('onboarding_templates.inactive') }}</VChip></template>
        <template #item.actions="{ item }"><div class="d-flex gap-1" @click.stop><VBtn size="x-small" color="primary" variant="text" icon="mdi-eye-outline" @click="router.visit(route('onboarding.templates.show', [item.uuid]))" /><VBtn size="x-small" color="error" variant="text" icon="mdi-delete-outline" @click="openDeleteDialog(item)" /></div></template>
        <template #no-data><div class="text-center py-12"><VIcon icon="mdi-file-document-multiple-outline" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('onboarding_templates.no_templates') }}</p><p class="text-body-2 text-medium-emphasis mb-4">{{ t('onboarding_templates.no_templates_sub') }}</p><VBtn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="createDialog = true">{{ t('onboarding_templates.new_template') }}</VBtn></div></template>
      </VDataTable>
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding_templates.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</p>
        <div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div>
      </div>
    </VCard>

    <VDialog v-model="createDialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding_templates.dialog_create_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VTextField v-model="createForm.name" :label="t('onboarding_templates.field_template_name')" :placeholder="t('onboarding_templates.field_template_name_placeholder')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextarea v-model="createForm.description" :label="t('onboarding_templates.field_description_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="createForm.department" :items="departmentOptions" :label="t('onboarding_templates.field_department_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="createForm.job_title" :items="positionOptions" :label="t('onboarding_templates.field_job_title_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" /></VCol>
          </VRow>
          <VSelect v-model="createForm.employment_type" :items="employmentTypes" :label="t('onboarding_templates.field_employment_type_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" />
          <VSwitch v-model="createForm.is_active" :label="t('onboarding_templates.field_active')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submitting" @click="createDialog = false">{{ t('onboarding_templates.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submitting" :disabled="!createForm.name" @click="submitCreate">{{ t('onboarding_templates.create_template') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard rounded="lg">
        <VCardText class="pa-6">
          <div class="d-flex flex-column align-center text-center gap-3"><VIcon icon="mdi-delete-outline" size="52" color="error" /><div><p class="text-body-1 font-weight-semibold mb-1">{{ t('onboarding_templates.delete_title') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding_templates.delete_confirm', { name: deletingItem?.name }) }}</p></div></div>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="deleting" @click="deleteDialog = false">{{ t('onboarding_templates.cancel') }}</VBtn><VBtn color="error" variant="flat" :loading="deleting" @click="confirmDelete">{{ t('onboarding_templates.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
