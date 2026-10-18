<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Account { id: number; code: string; name: string; type: string; subtype: string | null; normal_balance: string; is_postable: boolean; is_active: boolean; parent_id: number | null }
const props = defineProps<{ accounts: Account[]; types: string[] }>()

const search = ref('')
const typeFilter = ref('all')
const dialog = ref(false)
const editing = ref<Account | null>(null)
const form = useForm({ code: '', name: '', type: 'asset', subtype: '', parent_id: null as number | null, is_postable: true, is_active: true })

// Bulk import
const importDialog = ref(false)
const importForm = useForm<{ file: File | null }>({ file: null })
const page = usePage()
const importResult = computed<any>(() => (page.props.flash as any)?.coaImport)
function runImport() {
  importForm.post(route('finance.accounting.accounts.import'), { preserveScroll: true, forceFormData: true, onSuccess: () => { importForm.reset() } })
}

const filtered = computed(() => {
  let r = props.accounts
  if (typeFilter.value !== 'all') r = r.filter(a => a.type === typeFilter.value)
  if (search.value) { const q = search.value.toLowerCase(); r = r.filter(a => a.code.includes(q) || a.name.toLowerCase().includes(q)) }
  return r
})

function openNew() { editing.value = null; form.reset(); form.type = 'asset'; form.is_postable = true; form.is_active = true; dialog.value = true }
function openEdit(a: Account) { editing.value = a; form.code = a.code; form.name = a.name; form.type = a.type; form.subtype = a.subtype ?? ''; form.parent_id = a.parent_id; form.is_postable = a.is_postable; form.is_active = a.is_active; dialog.value = true }
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value ? form.patch(route('finance.accounting.accounts.update', editing.value.id), opts) : form.post(route('finance.accounting.accounts.store'), opts)
}
const typeColor: Record<string, string> = { asset: 'primary', liability: 'warning', equity: 'purple', income: 'success', expense: 'error' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.chart_of_accounts') }}</h2>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-upload" @click="importDialog = true">{{ t('acc.import') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_account') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField v-model="search" :label="t('acc.search_code_name')" density="compact" style="max-width: 260px" prepend-inner-icon="tabler-search" hide-details />
        <VSelect v-model="typeFilter" :label="t('acc.type')" density="compact" hide-details style="max-width: 200px" :items="[{ title: t('acc.all_types'), value: 'all' }, ...types.map(t => ({ title: t, value: t }))]" class="text-capitalize" />
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t('acc.code') }}</th><th>{{ t('acc.name') }}</th><th>{{ t('acc.type') }}</th><th>{{ t('acc.col_normal') }}</th><th>{{ t('acc.postable') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="a in filtered" :key="a.id">
            <td class="font-weight-medium">{{ a.code }}</td>
            <td :style="{ paddingLeft: a.parent_id ? '24px' : undefined }">{{ a.name }}</td>
            <td><VChip size="x-small" :color="typeColor[a.type]" variant="tonal" class="text-capitalize">{{ a.type }}</VChip></td>
            <td class="text-caption text-capitalize">{{ a.normal_balance }}</td>
            <td>{{ a.is_postable ? t('acc.yes') : '—' }}</td>
            <td><VChip size="x-small" :color="a.is_active ? 'success' : 'default'" variant="tonal">{{ a.is_active ? t('acc.active') : t('acc.inactive') }}</VChip></td>
            <td class="text-right"><VBtn icon="tabler-pencil" size="small" variant="text" @click="openEdit(a)" /></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ editing ? `${t('acc.edit')} ${t('acc.account')}` : t('acc.new_account') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.code')" variant="outlined" density="comfortable" style="max-width: 160px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :label="t('acc.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.type" :items="types.map(ty => ({ title: ty, value: ty }))" :label="t('acc.type')" variant="outlined" density="comfortable" class="text-capitalize" />
            <VTextField v-model="form.subtype" :label="t('acc.subtype_optional')" variant="outlined" density="comfortable" />
          </div>
          <VSelect v-model="form.parent_id" :items="accounts.filter(a => !a.is_postable).map(a => ({ title: `${a.code} · ${a.name}`, value: a.id }))" :label="t('acc.parent_optional')" variant="outlined" density="comfortable" clearable class="mb-3" />
          <div class="d-flex gap-6">
            <VSwitch v-model="form.is_postable" :label="t('acc.postable_leaf')" color="primary" hide-details />
            <VSwitch v-model="form.is_active" :label="t('acc.active')" color="success" hide-details />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ editing ? t('acc.save') : t('acc.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Bulk import -->
    <VDialog v-model="importDialog" max-width="620">
      <VCard>
        <VCardTitle>{{ t('acc.import_coa_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('acc.import_coa_help') }}
            <br><code>code, name, type, parent_code, is_postable, subtype, tax_code</code>
            <a :href="route('finance.accounting.accounts.template')" class="text-primary">{{ t('acc.download_template') }}</a>
          </p>
          <VFileInput v-model="importForm.file" :label="t('acc.csv_xlsx_file')" accept=".csv,.txt,.xlsx,.xls" variant="outlined" density="comfortable" prepend-icon="tabler-file-spreadsheet" :error-messages="importForm.errors.file" show-size />

          <VAlert v-if="importResult" :type="importResult.skipped.length ? 'warning' : 'success'" variant="tonal" density="compact" class="mt-3">
            <div class="font-weight-medium">{{ t('acc.import_summary', { imported: importResult.imported, updated: importResult.updated, skipped: importResult.skipped.length }) }}</div>
            <VTable v-if="importResult.skipped.length" density="compact" class="mt-2 bg-transparent">
              <thead><tr><th>{{ t('acc.row') }}</th><th>{{ t('acc.code') }}</th><th>{{ t('acc.reason') }}</th></tr></thead>
              <tbody><tr v-for="(s, i) in importResult.skipped" :key="i"><td>{{ s.row || '—' }}</td><td>{{ s.code }}</td><td class="text-caption">{{ s.reason }}</td></tr></tbody>
            </VTable>
          </VAlert>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="importDialog = false">{{ t('acc.close') }}</VBtn><VBtn color="primary" :loading="importForm.processing" :disabled="!importForm.file" @click="runImport">{{ t('acc.import') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
