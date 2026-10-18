<script lang="ts" setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

// `error` is the old shape, kept so imports recorded before this still render.
interface ImportIssue { row: number; reason?: string; error?: string; name?: string; email?: string; company?: string }
interface ImportRow { id: number; entity: string; file: string | null; total: number; created: number; updated: number; skipped: number; duplicates: number; status: string; reversed: boolean; by: string | null; at: string | null; issues: ImportIssue[] }
interface Preview { token: string; entity_type: string; file_name: string; headers: string[]; sample: string[][]; suggested: Record<string, string> }

const props = defineProps<{
  imports: ImportRow[]
  fields: Record<string, string[]>
  required: Record<string, string[]>
  products: { id: number; name: string; type: string }[]
}>()

const page = usePage()
const confirm = ref()
const entityType = ref<'lead' | 'contact' | 'account' | 'deal'>('lead')
const uploadForm = useForm({ file: null as File | null, entity_type: entityType.value })
const commitForm = useForm({ token: '', entity_type: 'lead', file_name: '', mapping: {} as Record<string, string>, dedup: 'skip', product_id: null as number | null })

// Hold the preview locally so it survives a failed commit. A commit that fails
// validation (e.g. a required field not mapped) returns no importPreview flash;
// keeping it means the mapping card + error message stay on screen to retry.
const preview = ref<Preview | null>((page.props.flash as any)?.importPreview ?? null)

watch(() => (page.props.flash as any)?.importPreview, (p) => {
  if (p) {
    preview.value = p
    commitForm.token = p.token; commitForm.entity_type = p.entity_type; commitForm.file_name = p.file_name; commitForm.mapping = { ...p.suggested }
  }
}, { immediate: true })

const activeEntity = computed(() => preview.value?.entity_type ?? entityType.value)
const requiredForEntity = computed<string[]>(() => props.required[activeEntity.value] ?? [])
// Product/Service linking only applies to lead/account/deal imports.
const productLinkable = computed(() => ['lead', 'account', 'deal'].includes(activeEntity.value))

// Mapping target options, marking required fields so users see what's mandatory.
const targetItems = computed(() => ['', ...(props.fields[activeEntity.value] ?? [])].map(f => ({
  value: f,
  title: f === '' ? '— ignore —' : (requiredForEntity.value.includes(f) ? `${f} (required)` : f),
})))

// Client-side guard: every required field must be mapped before committing.
const mappedFields = computed(() => Object.values(commitForm.mapping).filter(Boolean))
const missingRequired = computed(() => requiredForEntity.value.filter(f => !mappedFields.value.includes(f)))
const importError = computed<string | null>(() => (commitForm.errors as any)?.import ?? null)

// Rows skipped in the last import (missing required fields, etc.) — shown so the
// user can see exactly which rows weren't clean and decide what to do.
const lastImport = computed<any>(() => (page.props.flash as any)?.importIssues ?? null)
const lastIssues = computed<ImportIssue[]>(() => lastImport.value?.rows ?? [])
const issuesDialog = ref(false)
const issuesShown = ref<ImportIssue[]>([])
const issuesFile = ref('')
const issuesId = ref<number | null>(null)
function viewIssues(item: ImportRow) {
  issuesShown.value = item.issues ?? []
  issuesFile.value = item.file ?? ''
  issuesId.value = item.id
  issuesDialog.value = true
}

/** Old imports stored only a reason; new ones carry who the row was. */
const reasonOf = (i: ImportIssue) => i.reason ?? i.error ?? ''
const whoOf = (i: ImportIssue) => [i.name, i.email, i.company].filter(Boolean).join(' · ')

function upload() {
  uploadForm.entity_type = entityType.value
  uploadForm.post(route('crm.import.preview'), { preserveScroll: true, forceFormData: true })
}
// What Run import would do, answered against the mapping actually on screen.
// Re-checked whenever the mapping, the duplicate rule or the product changes, because each of those
// changes the answer — a stale count beside a live button is worse than no count.
const dry = ref<{ total: number; create: number; update: number; skip: number; duplicates: number; rows: ImportIssue[] } | null>(null)
const drying = ref(false)
const dryError = ref('')

async function checkFile() {
  if (!preview.value) return
  drying.value = true
  dryError.value = ''

  try {
    const { data } = await axios.post(route('crm.import.dry-run'), {
      token: commitForm.token,
      entity_type: commitForm.entity_type,
      mapping: commitForm.mapping,
      dedup: commitForm.dedup,
      product_id: commitForm.product_id,
    })
    dry.value = data
  } catch (e: any) {
    dryError.value = e?.response?.data?.message ?? 'That file could not be checked.'
  } finally {
    drying.value = false
  }
}

// Any of these changes the outcome, so an answer taken before them is no longer true.
watch(() => [JSON.stringify(commitForm.mapping), commitForm.dedup, commitForm.product_id], () => { dry.value = null })

function runImport() {
  commitForm.post(route('crm.import.commit'), { preserveScroll: true, onSuccess: () => { commitForm.reset(); preview.value = null } })
}
function reverseImport(item: ImportRow) {
  confirm.value.open(
    { title: 'Reverse import', message: `Remove the ${item.created} record(s) created by “${item.file}”? Updated records are not changed. They are soft-deleted and can be restored by an admin.`, confirmText: 'Reverse', confirmColor: 'error' },
    () => useForm({}).post(route('crm.import.reverse', item.id), { preserveScroll: true }),
  )
}

const headers = [
  { title: 'Entity', key: 'entity' }, { title: 'File', key: 'file' }, { title: 'Rows', key: 'total' },
  { title: 'Created', key: 'created' }, { title: 'Updated', key: 'updated' },
  { title: 'Skipped', key: 'skipped' }, { title: 'Dupes', key: 'duplicates' }, { title: 'When', key: 'at' },
  { title: '', key: 'actions', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <h5 class="text-h5 font-weight-semibold mb-1">{{ t('crm_import_index.page_title') }}</h5>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ t('crm_import_index.description') }}</p>

    <!-- Rows skipped in the last import — the good rows were imported anyway. -->
    <!-- Every row that did not land, and why. Shown as a table rather than a sentence, because the
         case that most needs it is the one with hundreds of rows in it and "590 skipped" is not
         something anybody can act on. -->
    <VAlert v-if="lastIssues.length" type="warning" variant="tonal" class="mb-4" closable>
      <div class="d-flex flex-wrap align-center ga-3 mb-2">
        <div class="font-weight-medium">
          {{ lastImport?.skipped ?? lastIssues.length }} row(s) were not imported — the rest went in.
        </div>
        <VSpacer />
        <VBtn
          v-if="lastImport?.import"
          size="small"
          variant="tonal"
          prepend-icon="tabler-download"
          :href="route('crm.import.skipped', lastImport.import)"
        >
          Download these rows
        </VBtn>
      </div>
      <VTable density="compact" class="bg-transparent" style="max-height: 260px; overflow-y: auto;">
        <thead>
          <tr><th style="width:70px">Row</th><th>Who</th><th>Why it was not imported</th></tr>
        </thead>
        <tbody>
          <tr v-for="(iss, k) in lastIssues" :key="k">
            <td class="text-caption">{{ iss.row }}</td>
            <td class="text-caption">{{ whoOf(iss) || '—' }}</td>
            <td class="text-caption">{{ reasonOf(iss) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VAlert>

    <VCard class="mb-4" :title="t('crm_import_index.step_upload')"><VCardText>
      <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('crm_import_index.template_hint') }} <a :href="route('crm.import.template', { entity_type: entityType })" class="font-weight-medium">{{ t('crm_import_index.template_link', { entity: entityType }) }}</a>, {{ t('crm_import_index.template_hint_end') }}</VAlert>
      <VRow align="center"><VCol cols="12" md="4"><VSelect v-model="entityType" :label="t('crm_import_index.import_into')" :items="Object.keys(fields)" /></VCol><VCol cols="12" md="6"><VFileInput :label="t('crm_import_index.csv_file')" accept=".csv,text/csv" prepend-icon="tabler-file-spreadsheet" :error-messages="uploadForm.errors.file" @update:model-value="(f: any) => uploadForm.file = Array.isArray(f) ? f[0] : f" /></VCol><VCol cols="12" md="2"><VBtn color="primary" block :loading="uploadForm.processing" :disabled="!uploadForm.file" @click="upload">{{ t('crm_import_index.preview') }}</VBtn></VCol></VRow>
      <VRow v-if="productLinkable && products.length" align="center" class="mt-0">
        <VCol cols="12" md="6">
          <VSelect v-model="commitForm.product_id" label="Link all imported records to a Product / Service (optional)"
            :items="products.map(p => ({ title: `${p.name} (${p.type})`, value: p.id }))" clearable placeholder="— none —"
            prepend-inner-icon="tabler-package" hint="Every record in this file is linked to this product/service (a mapped ‘product’ column overrides it per row)." persistent-hint />
        </VCol>
      </VRow>
    </VCardText></VCard>

    <!-- Step 2: map + import -->
    <VCard v-if="preview" class="mb-4" :title="`2 · Map columns — ${preview.file_name}`">
      <VCardText>
        <VTable density="comfortable" class="mb-4">
          <thead>
            <tr><th>CSV column</th><th>Sample</th><th>→ Maps to CRM field</th></tr>
          </thead>
          <tbody>
            <tr v-for="(h, i) in preview.headers" :key="h">
              <td class="font-weight-medium" style="white-space: nowrap">{{ h }}</td>
              <td class="text-medium-emphasis text-caption">
                <div
                  :title="preview.sample[0]?.[i] ?? ''"
                  style="max-width: 420px; max-height: 3.1em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word;"
                >{{ preview.sample[0]?.[i] ?? '' }}</div>
              </td>
              <td style="max-width: 240px">
                <VSelect v-model="commitForm.mapping[h]" :items="targetItems" density="compact" hide-details
                  :placeholder="'— ignore —'" clearable />
              </td>
            </tr>
          </tbody>
        </VTable>

        <VAlert v-if="importError" type="error" variant="tonal" density="compact" class="mb-3" style="white-space: pre-line">{{ importError }}</VAlert>
        <VAlert v-else-if="missingRequired.length" type="warning" variant="tonal" density="compact" class="mb-3">
          Required field(s) not mapped: <strong>{{ missingRequired.join(', ') }}</strong>. Rows missing these will be skipped and listed after import — the rest still import.
        </VAlert>

        <div class="d-flex flex-wrap align-center gap-4">
          <VSelect v-model="commitForm.dedup" label="On duplicate" style="max-width: 260px" density="compact" hide-details
            :items="[
              { title: 'Skip duplicates', value: 'skip' },
              { title: 'Update existing', value: 'update' },
              { title: 'Create anyway', value: 'create' },
            ]" />
          <VSpacer />
          <VBtn variant="tonal" prepend-icon="tabler-list-check" :loading="drying" @click="checkFile">
            Check the file
          </VBtn>
          <VBtn color="success" prepend-icon="tabler-database-import" :loading="commitForm.processing"
            @click="runImport">Run import</VBtn>
        </div>

        <VAlert v-if="dryError" type="error" variant="tonal" density="compact" class="mt-4">{{ dryError }}</VAlert>

        <!-- The answer to "what happens if I press it now", produced by running the real import and
             rolling it back — so the figures cannot promise one thing and deliver another. -->
        <VAlert v-if="dry" :type="dry.skip > 0 ? 'warning' : 'success'" variant="tonal" class="mt-4">
          <div class="d-flex flex-wrap ga-6 mb-2">
            <div>
              <div class="text-caption">Rows in the file</div>
              <div class="text-h6">{{ dry.total }}</div>
            </div>
            <div>
              <div class="text-caption">Will be created</div>
              <div class="text-h6 text-success">{{ dry.create }}</div>
            </div>
            <div v-if="dry.update > 0">
              <div class="text-caption">Will be updated</div>
              <div class="text-h6">{{ dry.update }}</div>
            </div>
            <div>
              <div class="text-caption">Will be skipped</div>
              <div class="text-h6" :class="dry.skip ? 'text-warning' : ''">{{ dry.skip }}</div>
            </div>
            <div v-if="dry.duplicates > 0">
              <div class="text-caption">Already on file</div>
              <div class="text-h6">{{ dry.duplicates }}</div>
            </div>
          </div>

          <div v-if="dry.skip === 0" class="text-body-2">Every row will import. Nothing has been written yet.</div>

          <template v-else>
            <div class="text-body-2 mb-2">Nothing has been written yet. These rows will not come in:</div>
            <VTable density="compact" class="bg-transparent" style="max-height: 240px; overflow-y: auto;">
              <thead>
                <tr><th style="width:70px">Row</th><th>Who</th><th>Why</th></tr>
              </thead>
              <tbody>
                <tr v-for="(iss, k) in dry.rows" :key="k">
                  <td class="text-caption">{{ iss.row }}</td>
                  <td class="text-caption">{{ whoOf(iss) || '—' }}</td>
                  <td class="text-caption">{{ reasonOf(iss) }}</td>
                </tr>
              </tbody>
            </VTable>
          </template>
        </VAlert>
      </VCardText>
    </VCard>

    <!-- History -->
    <VCard title="Import history">
      <VDataTable :headers="headers" :items="imports" :items-per-page="10" density="comfortable">
        <template #item.entity="{ item }"><VChip size="x-small" label class="text-capitalize">{{ item.entity }}</VChip></template>
        <template #item.duplicates="{ item }">
          <span :class="item.duplicates ? 'text-warning' : ''">{{ item.duplicates }}</span>
        </template>
        <template #item.skipped="{ item }">
          <span :class="item.skipped ? 'text-warning' : ''">{{ item.skipped }}</span>
          <VBtn v-if="item.issues && item.issues.length" size="x-small" variant="text" color="warning" class="ms-1" @click="viewIssues(item)">view</VBtn>
        </template>
        <template #item.actions="{ item }">
          <VChip v-if="item.reversed" size="x-small" color="error" label variant="tonal">Reversed</VChip>
          <VBtn v-else-if="item.created > 0" size="x-small" variant="tonal" color="error" prepend-icon="tabler-arrow-back-up"
            @click="reverseImport(item)">Reverse</VBtn>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="issuesDialog" max-width="760">
      <VCard>
        <VCardTitle>Skipped rows — {{ issuesFile }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">These rows were not imported. Fix them in your file and re-upload, or relax the required fields in Import rules.</p>
          <VTable density="compact" style="max-height: 320px; overflow-y: auto;">
            <thead>
              <tr><th style="width:70px">Row</th><th>Who</th><th>Why</th></tr>
            </thead>
            <tbody>
              <tr v-for="(iss, k) in issuesShown" :key="k">
                <td class="text-caption">{{ iss.row }}</td>
                <td class="text-caption">{{ whoOf(iss) || '—' }}</td>
                <td class="text-caption">{{ reasonOf(iss) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
        <VCardActions class="pa-4">
          <VBtn v-if="issuesId" variant="tonal" size="small" prepend-icon="tabler-download" :href="route('crm.import.skipped', issuesId)">
            Download as CSV
          </VBtn>
          <VSpacer /><VBtn variant="text" @click="issuesDialog = false">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>