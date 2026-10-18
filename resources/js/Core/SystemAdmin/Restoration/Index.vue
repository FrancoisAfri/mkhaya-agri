<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface SummaryItem {
  key: string
  label: string
  module: string
  count: number
}

interface RegistryEntry {
  key: string
  label: string
  module: string
}

interface Meta {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

const props = defineProps<{
  summary:      SummaryItem[]
  totalDeleted: number
  records:      Record<string, any>[]
  meta:         Meta
  columns:      string[]
  registryKeys: RegistryEntry[]
  modules:      string[]
  filters:      { model?: string; search?: string; module?: string }
}>()

// ── State ──────────────────────────────────────────────────────────────────
const selectedModel  = ref(props.filters.model ?? '')
const search         = ref(props.filters.search ?? '')
const moduleFilter   = ref(props.filters.module ?? '')
const selectedIds    = ref<number[]>([])
const showForceModal = ref(false)
const forceTarget    = ref<{ type: 'single' | 'bulk'; id?: number; label?: string } | null>(null)
const forceReason    = ref('')
const toast          = ref<{ msg: string; type: 'success' | 'error' } | null>(null)

// ── Derived ────────────────────────────────────────────────────────────────
const filteredSummary = computed(() => {
  let list = props.summary
  if (moduleFilter.value) list = list.filter(s => s.module === moduleFilter.value)
  return list.filter(s => s.count > 0)
})

const currentEntry = computed(() =>
  props.registryKeys.find(r => r.key === selectedModel.value)
)

const allSelected = computed(() =>
  props.records.length > 0 && selectedIds.value.length === props.records.length
)

// ── Navigation ─────────────────────────────────────────────────────────────
function selectModel(key: string) {
  selectedModel.value = key
  selectedIds.value   = []
  apply()
}

function apply() {
  router.get(route('settings.restoration.index'), {
    model:  selectedModel.value,
    search: search.value,
    module: moduleFilter.value,
  }, { preserveState: true, replace: true })
}

function goPage(page: number) {
  router.get(route('settings.restoration.index'), {
    model:  selectedModel.value,
    search: search.value,
    module: moduleFilter.value,
    page,
  }, { preserveState: true })
}

// ── Selection ──────────────────────────────────────────────────────────────
function toggleAll() {
  if (allSelected.value) {
    selectedIds.value = []
  } else {
    selectedIds.value = props.records.map(r => r._id)
  }
}

function toggleRow(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(idx, 1)
}

// ── Restore ────────────────────────────────────────────────────────────────
function restoreSingle(id: number) {
  router.post(route('settings.restoration.restore', { modelKey: selectedModel.value, id }), {}, {
    onSuccess: () => showToast(t('system_restoration_page.toast_restored'), 'success'),
    onError:   () => showToast(t('system_restoration_page.toast_restore_failed'), 'error'),
  })
}

function bulkRestore() {
  if (!selectedIds.value.length) return
  const count = selectedIds.value.length
  router.post(route('settings.restoration.bulk-restore'), {
    model_key: selectedModel.value,
    ids:       selectedIds.value,
  }, {
    onSuccess: () => { selectedIds.value = []; showToast(t('system_restoration_page.toast_bulk_restored', { count }), 'success') },
    onError:   () => showToast(t('system_restoration_page.toast_bulk_restore_failed'), 'error'),
  })
}

// ── Force delete ───────────────────────────────────────────────────────────
function openForceDeleteSingle(id: number, label: string) {
  forceTarget.value  = { type: 'single', id, label }
  forceReason.value  = ''
  showForceModal.value = true
}

function openForceDeleteBulk() {
  if (!selectedIds.value.length) return
  forceTarget.value  = { type: 'bulk' }
  forceReason.value  = ''
  showForceModal.value = true
}

function confirmForceDelete() {
  if (!forceReason.value.trim() || !forceTarget.value) return

  if (forceTarget.value.type === 'single' && forceTarget.value.id !== undefined) {
    router.delete(route('settings.restoration.force-delete', {
      modelKey: selectedModel.value,
      id:       forceTarget.value.id,
    }), {
      data: { reason: forceReason.value },
      onSuccess: () => { showForceModal.value = false; showToast(t('system_restoration_page.toast_force_deleted'), 'success') },
      onError:   () => showToast(t('system_restoration_page.toast_force_delete_failed'), 'error'),
    })
  } else {
    const count = selectedIds.value.length
    router.delete(route('settings.restoration.bulk-force-delete'), {
      data: { model_key: selectedModel.value, ids: selectedIds.value, reason: forceReason.value },
      onSuccess: () => { showForceModal.value = false; selectedIds.value = []; showToast(t('system_restoration_page.toast_bulk_force_deleted'), 'success') },
      onError:   () => showToast(t('system_restoration_page.toast_bulk_force_delete_failed'), 'error'),
    })
  }
}

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg: string, type: 'success' | 'error') {
  toast.value = { msg, type }
  setTimeout(() => { toast.value = null }, 3500)
}

// ── Formatters ─────────────────────────────────────────────────────────────
function fmt(col: string): string {
  return col.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function cellValue(row: Record<string, any>, col: string): string {
  const v = row[col]
  if (v === null || v === undefined) return '—'
  if (typeof v === 'boolean') return v ? t('system_restoration_page.yes') : t('system_restoration_page.no')
  if (typeof v === 'object') return JSON.stringify(v).slice(0, 60)
  const s = String(v)
  return s.length > 60 ? s.slice(0, 57) + '…' : s
}

const moduleColours: Record<string, string> = {
  Fleet:          'bg-blue-100 text-blue-800',
  Attendance:     'bg-purple-100 text-purple-800',
  Leave:          'bg-green-100 text-green-800',
  HelpDesk:       'bg-orange-100 text-orange-800',
  Policies:       'bg-red-100 text-red-800',
  Assets:         'bg-yellow-100 text-yellow-800',
  Communications: 'bg-pink-100 text-pink-800',
}

function moduleBadge(module: string): string {
  return moduleColours[module] ?? 'bg-gray-100 text-gray-700'
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="bg-white border-b border-gray-200 px-6 py-4">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <VIcon icon="tabler-restore" class="text-red-600" size="28" />
            {{ t('system_restoration_page.title') }}
          </h1>
          <p class="text-sm text-gray-500 mt-0.5">
            {{ t('system_restoration_page.subtitle') }}
          </p>
        </div>
        <div class="flex items-center gap-3">
          <VChip color="error" variant="flat" class="font-mono font-bold">
            {{ t('system_restoration_page.deleted_records', { count: totalDeleted.toLocaleString() }) }}
          </VChip>
        </div>
      </div>
    </div>

    <div class="flex h-[calc(100vh-73px)]">

      <!-- ── Sidebar — model list ──────────────────────────────────────────── -->
      <aside class="w-72 bg-white border-r border-gray-200 flex flex-col overflow-hidden">
        <div class="p-3 border-b">
          <VSelect
            v-model="moduleFilter"
            :items="['', ...modules]"
            :label="t('system_restoration_page.filter_by_module')"
            density="compact"
            variant="outlined"
            clearable
            hide-details
            @update:model-value="apply"
          />
        </div>

        <div class="overflow-y-auto flex-1">
          <div
            v-for="item in filteredSummary"
            :key="item.key"
            class="flex items-center justify-between px-4 py-2.5 cursor-pointer border-b border-gray-50 hover:bg-gray-50 transition-colors"
            :class="selectedModel === item.key ? 'bg-red-50 border-l-4 border-l-red-500' : ''"
            @click="selectModel(item.key)"
          >
            <div>
              <p class="text-sm font-medium text-gray-800">{{ item.label }}</p>
              <span class="text-xs px-1.5 py-0.5 rounded-full font-medium" :class="moduleBadge(item.module)">
                {{ item.module }}
              </span>
            </div>
            <VChip size="x-small" :color="item.count > 0 ? 'error' : 'success'" variant="flat">
              {{ item.count }}
            </VChip>
          </div>

          <div v-if="!filteredSummary.length" class="p-6 text-center text-gray-400 text-sm">
            {{ t('system_restoration_page.no_deleted_records') }}
          </div>
        </div>
      </aside>

      <!-- ── Main content ──────────────────────────────────────────────────── -->
      <main class="flex-1 overflow-y-auto p-6">

        <!-- Empty state -->
        <div v-if="!selectedModel" class="flex flex-col items-center justify-center h-full text-center text-gray-400">
          <VIcon icon="tabler-trash" size="64" class="mb-4 opacity-30" />
          <p class="text-lg font-medium">{{ t('system_restoration_page.select_model') }}</p>
          <p class="text-sm mt-1">{{ t('system_restoration_page.select_model_sub') }}</p>
        </div>

        <template v-else>
          <!-- Title row -->
          <div class="flex items-center justify-between mb-4">
            <div>
              <h2 class="text-lg font-semibold text-gray-800">{{ currentEntry?.label }}</h2>
              <p class="text-sm text-gray-500">{{ t('system_restoration_page.records_count', { count: meta.total }) }}</p>
            </div>

            <!-- Bulk actions -->
            <div v-if="selectedIds.length" class="flex items-center gap-2">
              <VChip color="primary" variant="tonal" size="small">
                {{ t('system_restoration_page.selected_count', { count: selectedIds.length }) }}
              </VChip>
              <VBtn
                color="success" size="small" variant="flat" prepend-icon="tabler-restore"
                @click="bulkRestore"
              >{{ t('system_restoration_page.restore_all') }}</VBtn>
              <VBtn
                color="error" size="small" variant="flat" prepend-icon="tabler-trash-x"
                @click="openForceDeleteBulk"
              >{{ t('system_restoration_page.delete_forever') }}</VBtn>
            </div>
          </div>

          <!-- Search bar -->
          <VTextField
            v-model="search"
            :label="t('system_restoration_page.search_records')"
            prepend-inner-icon="tabler-search"
            density="compact"
            variant="outlined"
            hide-details
            clearable
            class="mb-4"
            @update:model-value="apply"
          />

          <!-- Records table -->
          <VCard variant="outlined" class="overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                  <tr>
                    <th class="px-3 py-3 w-10">
                      <VCheckbox
                        :model-value="allSelected"
                        hide-details density="compact"
                        @change="toggleAll"
                      />
                    </th>
                    <th class="px-3 py-3">{{ t('system_restoration_page.table_id') }}</th>
                    <th class="px-3 py-3">{{ t('system_restoration_page.table_summary') }}</th>
                    <th v-for="col in columns" :key="col" class="px-3 py-3">{{ fmt(col) }}</th>
                    <th class="px-3 py-3">{{ t('system_restoration_page.table_deleted') }}</th>
                    <th class="px-3 py-3 text-right">{{ t('system_restoration_page.table_actions') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="row in records"
                    :key="row._id"
                    class="border-t border-gray-100 hover:bg-gray-50"
                    :class="selectedIds.includes(row._id) ? 'bg-red-50' : ''"
                  >
                    <td class="px-3 py-2">
                      <VCheckbox
                        :model-value="selectedIds.includes(row._id)"
                        hide-details density="compact"
                        @change="toggleRow(row._id)"
                      />
                    </td>
                    <td class="px-3 py-2 text-gray-400 font-mono text-xs">{{ row._id }}</td>
                    <td class="px-3 py-2 font-medium text-gray-800">{{ row._label }}</td>
                    <td v-for="col in columns" :key="col" class="px-3 py-2 text-gray-600 max-w-[160px] truncate">
                      {{ cellValue(row, col) }}
                    </td>
                    <td class="px-3 py-2 text-gray-400 text-xs whitespace-nowrap">{{ row._deleted_at }}</td>
                    <td class="px-3 py-2 text-right">
                      <div class="flex items-center justify-end gap-1">
                        <VBtn
                          size="x-small" color="success" variant="tonal"
                          icon="tabler-restore"
                          :title="t('system_restoration_page.restore')"
                          @click="restoreSingle(row._id)"
                        />
                        <VBtn
                          size="x-small" color="error" variant="tonal"
                          icon="tabler-trash-x"
                          :title="t('system_restoration_page.permanently_delete')"
                          @click="openForceDeleteSingle(row._id, row._label)"
                        />
                      </div>
                    </td>
                  </tr>

                  <tr v-if="!records.length">
                    <td :colspan="columns.length + 5" class="px-4 py-10 text-center text-gray-400">
                      {{ t('system_restoration_page.no_records_found') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div v-if="meta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
              <p class="text-sm text-gray-500">
                {{ t('system_restoration_page.page_of', { current: meta.current_page, last: meta.last_page, total: meta.total }) }}
              </p>
              <div class="flex gap-1">
                <VBtn
                  v-for="page in meta.last_page"
                  :key="page"
                  size="x-small"
                  :variant="page === meta.current_page ? 'flat' : 'text'"
                  :color="page === meta.current_page ? 'primary' : undefined"
                  @click="goPage(page)"
                >{{ page }}</VBtn>
              </div>
            </div>
          </VCard>
        </template>

      </main>
    </div>

    <!-- ── Force Delete Confirmation Modal ───────────────────────────────── -->
    <VDialog v-model="showForceModal" max-width="520">
      <VCard>
        <VCardTitle class="bg-red-600 text-white flex items-center gap-2 py-4 px-5">
          <VIcon icon="tabler-trash-x" />
          {{ t('system_restoration_page.force_delete_title') }}
        </VCardTitle>
        <VCardText class="pt-5">
          <VAlert type="error" variant="tonal" class="mb-4">
            <strong>{{ t('system_restoration_page.force_delete_warning') }}</strong>
            {{ forceTarget?.type === 'single'
              ? t('system_restoration_page.force_delete_single', { name: forceTarget.label })
              : t('system_restoration_page.force_delete_bulk', { count: selectedIds.length })
            }}
            {{ t('system_restoration_page.force_delete_irreversible') }}
          </VAlert>

          <VTextarea
            v-model="forceReason"
            :label="t('system_restoration_page.force_delete_reason_label')"
            rows="3"
            variant="outlined"
            :placeholder="t('system_restoration_page.force_delete_reason_placeholder')"
            :rules="[v => !!v?.trim() || t('system_restoration_page.force_delete_reason_rule')]"
            counter="500"
            maxlength="500"
          />
        </VCardText>
        <VCardActions class="px-5 pb-5 gap-2 justify-end">
          <VBtn variant="text" @click="showForceModal = false">
            {{ t('system_restoration_page.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            variant="flat"
            :disabled="!forceReason.trim()"
            prepend-icon="tabler-trash-x"
            @click="confirmForceDelete"
          >
            {{ t('system_restoration_page.permanently_delete_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Toast notification ─────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="slide-up">
        <div
          v-if="toast"
          class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium"
          :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
        >
          <VIcon :icon="toast.type === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="20" />
          {{ toast.msg }}
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<style scoped>
.slide-up-enter-active, .slide-up-leave-active { transition: all 0.3s ease; }
.slide-up-enter-from, .slide-up-leave-to { opacity: 0; transform: translateY(12px); }
</style>