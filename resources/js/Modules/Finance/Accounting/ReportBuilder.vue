<script lang="ts" setup>
import { ref, reactive, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Saved { id: number; name: string; source: string; dimensions: string[]; measures: string[]; filters: any; is_shared: boolean; mine: boolean }
const props = defineProps<{ catalog: Record<string, any>; saved: Saved[] }>()

const def = reactive<any>({ source: 'gl', dimensions: [] as string[], measures: [] as string[], filters: {} as any })
const result = ref<{ columns: any[]; rows: any[] } | null>(null)
const running = ref(false)
const confirm = ref()

const meta = computed(() => props.catalog[def.source] ?? { dimensions: {}, measures: {}, filters: [] })
const dimOptions = computed(() => Object.entries(meta.value.dimensions).map(([k, v]: any) => ({ value: k, title: v.label })))
const measOptions = computed(() => Object.entries(meta.value.measures).map(([k, v]: any) => ({ value: k, title: v.label })))
function changeSource() { def.dimensions = []; def.measures = []; def.filters = {}; result.value = null }

const money = (n: any) => isNaN(Number(n)) ? n : Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const isMeasure = (key: string) => result.value?.columns.find(c => c.key === key)?.measure

async function run() {
  running.value = true
  try {
    const res = await fetch(route('finance.accounting.report-builder.run'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]) ?? '') },
      credentials: 'same-origin',
      body: JSON.stringify(def),
    })
    result.value = await res.json()
  } finally { running.value = false }
}
function exportCsv() { window.open(route('finance.accounting.report-builder.export', { ...def, name: saveForm.name || 'report' }), '_blank') }

// Save / load
const saveDialog = ref(false)
const saveForm = useForm<any>({ name: '', is_shared: false })
function save() {
  saveForm.transform(() => ({ ...def, name: saveForm.name, is_shared: saveForm.is_shared }))
    .post(route('finance.accounting.report-builder.save'), { preserveScroll: true, onSuccess: () => { saveDialog.value = false; saveForm.reset() } })
}
function load(s: Saved) { def.source = s.source; def.dimensions = [...(s.dimensions ?? [])]; def.measures = [...(s.measures ?? [])]; def.filters = { ...(s.filters ?? {}) }; run() }
function remove(s: Saved) {
  confirm.value.open({ title: t('acc.delete_report'), message: t('acc.delete_report_confirm', { name: s.name }), confirmText: t('acc.delete'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('finance.accounting.report-builder.destroy', s.id), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_reporting') }}</div>
      <h2 class="text-h5 mb-0">{{ t('acc.report_builder') }}</h2>
      <div class="text-caption text-medium-emphasis">{{ t('acc.report_builder_sub') }}</div>
    </div>

    <VRow>
      <VCol cols="12" md="3">
        <VCard border class="mb-3">
          <VCardText>
            <VSelect v-model="def.source" :items="Object.entries(catalog).map(([k, v]) => ({ value: k, title: v.label }))" :label="t('acc.data_source')" variant="outlined" density="comfortable" class="mb-3" @update:model-value="changeSource" />
            <VSelect v-model="def.dimensions" :items="dimOptions" :label="t('acc.group_by_dims')" multiple chips closable-chips variant="outlined" density="comfortable" class="mb-3" />
            <VSelect v-model="def.measures" :items="measOptions" :label="t('acc.measures')" multiple chips variant="outlined" density="comfortable" class="mb-3" />
            <div class="text-caption text-medium-emphasis mb-1">{{ t('acc.filters') }}</div>
            <div v-if="meta.filters.includes('date_from')" class="d-flex gap-2 mb-2">
              <VTextField v-model="def.filters.date_from" type="date" :label="t('acc.from')" density="compact" variant="outlined" hide-details />
              <VTextField v-model="def.filters.date_to" type="date" :label="t('acc.to')" density="compact" variant="outlined" hide-details />
            </div>
            <VTextField v-if="meta.filters.includes('account_type')" v-model="def.filters.account_type" :label="t('acc.account_type')" placeholder="asset / income …" density="compact" variant="outlined" hide-details class="mb-2" />
            <VTextField v-if="meta.filters.includes('module')" v-model="def.filters.module" :label="t('acc.source_module')" density="compact" variant="outlined" hide-details class="mb-2" />
            <VTextField v-if="meta.filters.includes('direction')" v-model="def.filters.direction" :label="t('acc.direction')" placeholder="receivable / payable" density="compact" variant="outlined" hide-details class="mb-2" />
            <VTextField v-if="meta.filters.includes('status')" v-model="def.filters.status" :label="t('acc.status')" density="compact" variant="outlined" hide-details class="mb-2" />
            <div class="d-flex gap-2 mt-3">
              <VBtn color="primary" :loading="running" block @click="run">{{ t('acc.run') }}</VBtn>
            </div>
            <div class="d-flex gap-2 mt-2">
              <VBtn variant="tonal" size="small" prepend-icon="tabler-device-floppy" @click="saveDialog = true">{{ t('acc.save') }}</VBtn>
              <VBtn variant="tonal" size="small" prepend-icon="tabler-download" :disabled="!result" @click="exportCsv">CSV</VBtn>
            </div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle class="text-subtitle-2">{{ t('acc.saved_reports') }}</VCardTitle>
          <VList density="compact" class="py-0">
            <VListItem v-for="s in saved" :key="s.id" @click="load(s)">
              <VListItemTitle class="text-body-2">{{ s.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ catalog[s.source]?.label }}<VIcon v-if="s.is_shared" icon="tabler-users" size="12" class="ms-1" /></VListItemSubtitle>
              <template #append><VBtn v-if="s.mine" size="x-small" variant="text" color="error" icon="tabler-trash" @click.stop="remove(s)" /></template>
            </VListItem>
            <VListItem v-if="!saved.length"><VListItemTitle class="text-caption text-medium-emphasis py-2">{{ t('acc.none_yet') }}</VListItemTitle></VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="9">
        <VCard border>
          <VCardText v-if="!result" class="text-center text-medium-emphasis py-12">{{ t('acc.configure_run') }}</VCardText>
          <div v-else style="overflow-x: auto">
            <VTable density="compact">
              <thead><tr><th v-for="c in result.columns" :key="c.key" :class="c.measure ? 'text-right' : ''">{{ c.label }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in result.rows" :key="i">
                  <td v-for="c in result.columns" :key="c.key" :class="c.measure ? 'text-right' : ''">{{ c.measure ? money(row[c.key]) : row[c.key] }}</td>
                </tr>
                <tr v-if="!result.rows.length"><td :colspan="result.columns.length" class="text-center text-medium-emphasis py-6">{{ t('acc.no_data_criteria') }}</td></tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
        <div v-if="result" class="text-caption text-medium-emphasis mt-1">{{ result.rows.length >= 5000 ? t('acc.rows_capped', { n: result.rows.length }) : t('acc.rows_count', { n: result.rows.length }) }}</div>
      </VCol>
    </VRow>

    <VDialog v-model="saveDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('acc.save_report') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="saveForm.name" :label="t('acc.report_name')" variant="outlined" density="comfortable" :error-messages="saveForm.errors.name" />
          <VSwitch v-model="saveForm.is_shared" :label="t('acc.share_team')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="saveDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="saveForm.processing" :disabled="!saveForm.name" @click="save">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
