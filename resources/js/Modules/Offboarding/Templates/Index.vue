<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Template = {
  id: number; uuid: string; title: string; description: string | null
  notice_period_days: number; template_tasks_count: number; created_at: string | null
}

const props = defineProps<{
  templates: { data: Template[]; links: any[]; meta: any }
  filters: Record<string, string>
  stats: { total: number; task_count: number }
}>()

const confirmDialog = ref()
const search = ref(props.filters.search ?? '')
let searchTimer: ReturnType<typeof setTimeout>
function applySearch() { clearTimeout(searchTimer); searchTimer = setTimeout(() => { router.get(route('offboarding.templates.index'), { search: search.value || undefined }, { preserveState: true, replace: true }) }, 400) }

const dialog = ref(false); const submitting = ref(false)
const form = ref({ title: '', description: '', notice_period_days: 14 })

function openCreate() {
  form.value = { title: '', description: '', notice_period_days: 14 }
  dialog.value = true
}

function submitCreate() {
  submitting.value = true
  router.post(route('offboarding.templates.store'), form.value, {
    onFinish: () => { submitting.value = false },
    onSuccess: () => { dialog.value = false },
  })
}

function deleteTemplate(t: Template) {
  confirmDialog.value.open(
    { title: 'Delete Template', message: `Delete "${t.title}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => { router.delete(route('offboarding.templates.destroy', { template: t.id })) },
  )
}

const rows = computed(() => props.templates.data)
const links = computed(() => props.templates.links ?? [])
const meta = computed(() => props.templates.meta ?? {})

const headers = computed(() => [
  { title: t('offboarding_templates.table_title'), key: 'title', sortable: false },
  { title: t('offboarding_templates.table_tasks'), key: 'template_tasks_count', sortable: false, align: 'center' as const },
  { title: t('offboarding_templates.table_notice_period'), key: 'notice_period_days', sortable: false },
  { title: t('offboarding_templates.table_created'), key: 'created_at', sortable: false },
  { title: t('offboarding_templates.table_actions'), key: 'actions', sortable: false, align: 'end' as const },
])

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('offboarding.index'))" />
        <div>
          <h4 class="text-h4">{{ t('offboarding_templates.title') }}</h4>
          <p class="text-medium-emphasis mb-0">{{ t('offboarding_templates.subtitle') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('offboarding_templates.new_template') }}</VBtn>
    </div>

    <VRow class="mb-5">
      <VCol cols="12" sm="6"><VCard><VCardText class="d-flex align-center gap-4 pa-4"><VAvatar color="primary" variant="tonal" size="48" rounded><VIcon icon="tabler-file-description" size="24" /></VAvatar><div><p class="text-body-2 text-medium-emphasis mb-1">{{ t('offboarding_templates.total_templates') }}</p><h5 class="text-h5">{{ stats.total }}</h5></div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6"><VCard><VCardText class="d-flex align-center gap-4 pa-4"><VAvatar color="success" variant="tonal" size="48" rounded><VIcon icon="tabler-checklist" size="24" /></VAvatar><div><p class="text-body-2 text-medium-emphasis mb-1">{{ t('offboarding_templates.total_tasks') }}</p><h5 class="text-h5">{{ stats.task_count }}</h5></div></VCardText></VCard></VCol>
    </VRow>

    <VCard class="mb-5"><VCardText class="pa-4"><VTextField v-model="search" :label="t('offboarding_templates.search_placeholder')" prepend-inner-icon="tabler-search" variant="outlined" density="compact" hide-details clearable style="max-width: 360px;" @update:model-value="applySearch" /></VCardText></VCard>

    <VCard>
      <VDataTable :headers="headers" :items="rows" hide-default-footer :items-per-page="20" hover>
        <template #item.title="{ item }"><div><a class="text-primary font-weight-medium text-decoration-none" href="#" @click.prevent="router.visit(route('offboarding.templates.show', { template: item.id }))">{{ item.title }}</a><p v-if="item.description" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width: 280px;">{{ item.description }}</p></div></template>
        <template #item.template_tasks_count="{ item }"><VChip color="primary" size="small" variant="tonal">{{ item.template_tasks_count }}</VChip></template>
        <template #item.notice_period_days="{ item }"><span class="text-body-2">{{ item.notice_period_days }} {{ t('offboarding_templates.days_suffix') }}</span></template>
        <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
        <template #item.actions="{ item }"><div class="d-flex justify-end gap-1"><VBtn icon="tabler-eye" size="small" variant="text" color="primary" @click="router.visit(route('offboarding.templates.show', { template: item.id }))" /><VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteTemplate(item)" /></div></template>
        <template #no-data><div class="text-center pa-10"><VIcon icon="tabler-file-description" size="56" color="secondary" class="mb-3" /><p class="text-body-1 text-medium-emphasis">{{ t('offboarding_templates.no_templates') }}</p></div></template>
      </VDataTable>
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('offboarding_templates.showing', { from: meta.from, to: meta.to, total: meta.total }) }}</p>
        <div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div>
      </div>
    </VCard>

    <VDialog v-model="dialog" max-width="500">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ t('offboarding_templates.dialog_create_title') }}</VCardTitle><VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.title" :label="t('offboarding_templates.field_title')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('offboarding_templates.field_description')" variant="outlined" density="compact" rows="2" /></VCol>
            <VCol cols="12"><VTextField v-model="form.notice_period_days" :label="t('offboarding_templates.field_notice_period_days')" type="number" variant="outlined" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('offboarding_templates.cancel') }}</VBtn><VBtn color="primary" :loading="submitting" :disabled="!form.title" @click="submitCreate">{{ t('offboarding_templates.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
