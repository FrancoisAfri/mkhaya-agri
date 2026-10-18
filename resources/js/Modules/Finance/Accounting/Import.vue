<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

defineProps<{ systems: string[]; entities: string[]; crmActive?: boolean }>()

const systemMeta: Record<string, { label: string; icon: string; color: string }> = {
  sage:    { label: 'Sage',    icon: 'tabler-leaf',        color: '#00875a' },
  xero:    { label: 'Xero',    icon: 'tabler-circle-letter-x', color: '#13b5ea' },
  zoho:    { label: 'Zoho',    icon: 'tabler-letter-z',    color: '#e42527' },
  generic: { label: t('acc.imp_generic'), icon: 'tabler-file-spreadsheet', color: '#6b7280' },
}
const entityMeta: Record<string, { label: string; icon: string }> = {
  accounts:  { label: t('acc.imp_accounts'), icon: 'tabler-list-numbers' },
  customers: { label: t('acc.customers'), icon: 'tabler-users' },
  suppliers: { label: t('acc.suppliers'), icon: 'tabler-truck' },
  items:     { label: t('acc.items'),     icon: 'tabler-package' },
  invoices:  { label: t('acc.imp_invoices'), icon: 'tabler-file-invoice' },
  bills:     { label: t('acc.imp_bills'),    icon: 'tabler-receipt' },
  quotes:    { label: t('acc.imp_quotes'),   icon: 'tabler-file-description' },
  customer_transactions: { label: t('acc.imp_customer_ledger'), icon: 'tabler-user-dollar' },
  transactions: { label: t('acc.imp_transactions'), icon: 'tabler-arrows-exchange' },
  statements:   { label: t('acc.imp_statements'),   icon: 'tabler-file-text' },
}

const step = ref(1)
const upload = useForm<{ system: string; entity: string; file: File | null }>({ system: '', entity: 'customers', file: null })

const preview = computed<any>(() => (page.props.flash as any)?.importPreview ?? null)
const issues = computed<any[]>(() => (page.props.flash as any)?.importIssues ?? [])
const skipGroups = computed<Record<string, string[]>>(() => {
  const g: Record<string, string[]> = {}
  for (const i of issues.value) { const r = i.reason || 'Skipped'; (g[r] ||= []).push(String(i.row)) }
  return g
})

function pickSystem(s: string) { upload.system = s; step.value = 2 }
function runPreview() {
  upload.post(route('finance.accounting.import.preview'), { preserveScroll: true, forceFormData: true, onSuccess: () => { if (preview.value) step.value = 3 } })
}

/** A field can come from several columns (an address split over numbered lines). */
function sourceLabel(src: string | string[]) { return Array.isArray(src) ? src.join(' + ') : src }

const syncCrm = ref(true)
const canSyncCrm = computed(() => !!(page.props as any).crmActive && preview.value?.entity === 'customers')

const commitForm = useForm({ token: '', system: '', entity: '', ext: '', sync_crm: false })
function runImport() {
  const p = preview.value
  commitForm.token = p.token; commitForm.system = p.system; commitForm.entity = p.entity
  commitForm.sync_crm = canSyncCrm.value && syncCrm.value
  commitForm.ext = (p.file_name?.split('.').pop() || 'csv').toLowerCase()
  commitForm.post(route('finance.accounting.import.commit'), { preserveScroll: true, onSuccess: () => { step.value = 4 } })
}
function restart() { step.value = 1; upload.reset(); upload.file = null }
</script>

<template>
  <div class="pa-4" style="max-width: 1100px; margin: 0 auto">
    <div class="mb-4">
      <h1 class="text-h5 font-weight-bold mb-0">{{ t('acc.import_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('acc.import_subtitle') }}</p>
    </div>

    <!-- Stepper -->
    <div class="d-flex align-center gap-2 mb-4 text-caption">
      <template v-for="(s, i) in [t('acc.imp_step_system'), t('acc.imp_step_upload'), t('acc.imp_step_preview'), t('acc.imp_step_done')]" :key="i">
        <VChip size="small" :color="step > i ? 'primary' : (step === i + 1 ? 'primary' : undefined)" :variant="step >= i + 1 ? 'flat' : 'tonal'">{{ i + 1 }}. {{ s }}</VChip>
        <VIcon v-if="i < 3" size="14" color="disabled">tabler-chevron-right</VIcon>
      </template>
    </div>

    <!-- Step 1: choose source system -->
    <VRow v-if="step === 1">
      <VCol v-for="s in systems" :key="s" cols="6" md="3">
        <VCard border rounded="lg" class="text-center pa-4 cursor-pointer" @click="pickSystem(s)" style="transition:.15s" hover>
          <VAvatar size="52" variant="tonal" :color="systemMeta[s]?.color" rounded="lg"><VIcon size="30" :color="systemMeta[s]?.color">{{ systemMeta[s]?.icon }}</VIcon></VAvatar>
          <div class="text-body-1 font-weight-semibold mt-2">{{ systemMeta[s]?.label }}</div>
          <div class="text-caption text-medium-emphasis">{{ s === 'generic' ? t('acc.imp_generic_hint') : t('acc.imp_from', { name: systemMeta[s]?.label }) }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Step 2: choose data + upload -->
    <VCard v-if="step === 2" border rounded="lg">
      <VCardText>
        <div class="d-flex align-center gap-2 mb-3">
          <VBtn size="small" variant="text" prepend-icon="tabler-arrow-left" @click="step = 1">{{ t('acc.back') }}</VBtn>
          <VChip size="small" :color="systemMeta[upload.system]?.color" variant="tonal"><VIcon start size="16">{{ systemMeta[upload.system]?.icon }}</VIcon>{{ systemMeta[upload.system]?.label }}</VChip>
        </div>
        <p class="text-body-2 mb-2">{{ t('acc.imp_choose_data') }}</p>
        <div class="d-flex gap-2 flex-wrap mb-4">
          <VBtn v-for="e in entities" :key="e" :variant="upload.entity === e ? 'flat' : 'tonal'" :color="upload.entity === e ? 'primary' : undefined" :prepend-icon="entityMeta[e]?.icon" @click="upload.entity = e">{{ entityMeta[e]?.label }}</VBtn>
        </div>
        <VAlert type="info" variant="tonal" density="compact" class="mb-3">{{ t('acc.imp_upload_hint', { name: systemMeta[upload.system]?.label }) }}</VAlert>
        <VFileInput :label="t('acc.imp_file')" accept=".csv,.xlsx,.xls,text/csv" prepend-icon="tabler-file-spreadsheet" :error-messages="(upload.errors as any).file" @update:model-value="(f: any) => upload.file = Array.isArray(f) ? f[0] : f" />
        <div class="d-flex justify-end">
          <VBtn color="primary" :loading="upload.processing" :disabled="!upload.file" @click="runPreview">{{ t('acc.imp_preview_btn') }}</VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Step 3: preview mapping -->
    <VCard v-if="step === 3 && preview" border rounded="lg">
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
          <div>
            <span class="text-body-1 font-weight-semibold">{{ preview.grouped ? t('acc.imp_docs_found', { docs: preview.documents, rows: preview.rows }) : t('acc.imp_preview_title', { rows: preview.rows }) }}</span>
            <div class="text-caption text-medium-emphasis">{{ preview.file_name }} · {{ systemMeta[preview.system]?.label }} · {{ entityMeta[preview.entity]?.label }}</div>
          </div>
          <VBtn size="small" variant="text" prepend-icon="tabler-arrow-left" @click="step = 2">{{ t('acc.imp_change_file') }}</VBtn>
        </div>

        <VAlert v-if="(preview.grouped && preview.entity !== 'quotes') || preview.entity === 'transactions'" type="warning" variant="tonal" density="compact" class="mb-3">{{ t('acc.imp_gl_warning') }}</VAlert>
        <VAlert v-if="preview.entity === 'statements'" type="info" variant="tonal" density="compact" class="mb-3">{{ t('acc.imp_statements_note') }}</VAlert>
        <VAlert v-if="preview.entity === 'accounts'" type="info" variant="tonal" density="compact" class="mb-3">{{ t('acc.imp_accounts_note') }}</VAlert>
        <VAlert v-if="preview.entity === 'customer_transactions'" type="info" variant="tonal" density="compact" class="mb-3">{{ t('acc.imp_customer_ledger_note') }}</VAlert>

        <p class="text-body-2 font-weight-medium mb-1">{{ t('acc.imp_detected_map') }}</p>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <VChip v-for="(src, field) in preview.mapped" :key="field" size="small" variant="tonal" color="success">
            {{ field }} <VIcon size="12" class="mx-1">tabler-arrow-left</VIcon> {{ sourceLabel(src) }}
          </VChip>
          <VChip v-if="!Object.keys(preview.mapped).length" size="small" color="error" variant="tonal">{{ t('acc.imp_no_match') }}</VChip>
        </div>
        <div v-if="preview.unmapped?.length" class="text-caption text-medium-emphasis mb-3">
          {{ t('acc.imp_ignored') }}: {{ preview.unmapped.join(', ') }}
        </div>

        <VTable v-if="preview.sample?.length" density="compact" class="mb-3" style="border:1px solid rgba(127,127,127,.2);border-radius:6px">
          <thead><tr><th v-for="(src, field) in preview.mapped" :key="field">{{ field }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in preview.sample" :key="i">
              <td v-for="(src, field) in preview.mapped" :key="field">{{ row[field] ?? '—' }}</td>
            </tr>
          </tbody>
        </VTable>

        <!-- Customers can be put on the CRM's books in the same pass. -->
        <VCheckbox v-if="canSyncCrm" v-model="syncCrm" density="compact" hide-details class="mb-2">
          <template #label>
            <span class="text-body-2">{{ t('acc.imp_sync_crm') }}</span>
          </template>
        </VCheckbox>
        <div v-if="canSyncCrm" class="text-caption text-medium-emphasis mb-3 ms-8">{{ t('acc.imp_sync_crm_hint') }}</div>

        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="step = 2">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="commitForm.processing" :disabled="!Object.keys(preview.mapped).length" @click="runImport">{{ t('acc.imp_import_btn', { n: preview.rows }) }}</VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Step 4: done -->
    <VCard v-if="step === 4" border rounded="lg">
      <VCardText class="text-center py-8">
        <VAvatar size="56" color="success" variant="tonal" rounded><VIcon size="32">tabler-check</VIcon></VAvatar>
        <div class="text-h6 mt-2">{{ (page.props.flash as any)?.success || t('acc.imp_done') }}</div>
        <!-- Why rows were skipped (grouped by reason) so the user can fix and retry -->
        <div v-if="issues.length" class="mt-4 text-start mx-auto" style="max-width: 620px">
          <VAlert type="warning" variant="tonal" density="compact">
            <div class="text-body-2 font-weight-medium mb-1">{{ t('acc.imp_skipped_reasons', { n: issues.length }) }}</div>
            <div v-for="(grp, reason) in skipGroups" :key="reason" class="text-caption">
              · <strong>{{ grp.length }}×</strong> {{ reason }} <span class="text-medium-emphasis">({{ grp.slice(0,5).join(', ') }}{{ grp.length > 5 ? '…' : '' }})</span>
            </div>
          </VAlert>
        </div>
        <div class="d-flex justify-center gap-2 mt-4">
          <VBtn variant="tonal" @click="restart">{{ t('acc.imp_another') }}</VBtn>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
