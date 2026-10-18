<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Scar {
  id: number
  scar_number: string
  title: string
  description: string
  severity: string
  status: string
  root_cause: string | null
  corrective_action: string | null
  due_date: string | null
  assigned_to: number | null
  supplier?: { id: number; company_name: string } | null
}

interface Supplier {
  id: number
  company_name: string
}

interface Pagination {
  data: Scar[]
  links: any[]
  current_page: number
  last_page: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  scars: Pagination
  filters: { status?: string; severity?: string; supplier_id?: number }
  suppliers: Supplier[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

const severityOptions = ['low', 'medium', 'high', 'critical']
const statusOptions = ['open', 'investigating', 'action_plan', 'verifying', 'closed', 'rejected']

const severityColors: Record<string, string> = {
  low: 'grey',
  medium: 'info',
  high: 'warning',
  critical: 'error',
}

const statusColors: Record<string, string> = {
  open: 'error',
  investigating: 'warning',
  action_plan: 'info',
  verifying: 'primary',
  closed: 'success',
  rejected: 'grey',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function getSeverityLabel(severity: string): string {
  const map: Record<string, string> = {
    low: t('supplier_scars.severity.low'),
    medium: t('supplier_scars.severity.medium'),
    high: t('supplier_scars.severity.high'),
    critical: t('supplier_scars.severity.critical'),
  }
  return map[severity] || severity
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    open: t('supplier_scars.statuses.open'),
    investigating: t('supplier_scars.statuses.investigating'),
    action_plan: t('supplier_scars.statuses.action_plan'),
    verifying: t('supplier_scars.statuses.verifying'),
    closed: t('supplier_scars.statuses.closed'),
    rejected: t('supplier_scars.statuses.rejected'),
  }
  return map[status] || status
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

// ── Filters ──────────────────────────────────────────────────────────────────
const filterState = reactive({
  status: props.filters.status ?? null,
  severity: props.filters.severity ?? null,
  supplier_id: props.filters.supplier_id ?? null,
})

function applyFilters(): void {
  router.get(
    route('suppliers.scars.index'),
    {
      status: filterState.status ?? undefined,
      severity: filterState.severity ?? undefined,
      supplier_id: filterState.supplier_id ?? undefined,
    },
    { preserveState: true, replace: true }
  )
}

function clearFilters(): void {
  filterState.status = null
  filterState.severity = null
  filterState.supplier_id = null
  applyFilters()
}

// ── Create dialog ────────────────────────────────────────────────────────────
const createDialog = ref(false)
const createForm = reactive({
  supplier_id: null as number | null,
  title: '',
  description: '',
  severity: 'medium',
  due_date: null as string | null,
})

function openCreate(): void {
  createForm.supplier_id = null
  createForm.title = ''
  createForm.description = ''
  createForm.severity = 'medium'
  createForm.due_date = null
  createDialog.value = true
}

function saveCreate(): void {
  router.post(
    route('suppliers.scars.store'),
    { ...createForm },
    {
      preserveScroll: true,
      onSuccess: () => { createDialog.value = false },
    }
  )
}

// ── Edit dialog ──────────────────────────────────────────────────────────────
const editDialog = ref(false)
const editId = ref<number | null>(null)
const editForm = reactive({
  status: 'open',
  root_cause: '' as string | null,
  corrective_action: '' as string | null,
  assigned_to: null as number | null,
  due_date: null as string | null,
})

function openEdit(scar: Scar): void {
  editId.value = scar.id
  editForm.status = scar.status
  editForm.root_cause = scar.root_cause ?? ''
  editForm.corrective_action = scar.corrective_action ?? ''
  editForm.assigned_to = scar.assigned_to ?? null
  editForm.due_date = scar.due_date ?? null
  editDialog.value = true
}

function saveEdit(): void {
  if (!editId.value) return
  router.patch(
    route('suppliers.scars.update', editId.value),
    { ...editForm },
    {
      preserveScroll: true,
      onSuccess: () => { editDialog.value = false },
    }
  )
}

function destroy(scar: Scar): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_scars.dialogs.delete.title'),
      message: t('supplier_scars.dialogs.delete.message', { number: scar.scar_number }),
      confirmText: t('supplier_scars.dialogs.delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(route('suppliers.scars.destroy', scar.id), { preserveScroll: true })
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h4">{{ t('supplier_scars.page_title') }}</h1>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreate">
        {{ t('supplier_scars.raise_scar') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VSelect
          v-model="filterState.supplier_id"
          :items="suppliers"
          item-title="company_name"
          item-value="id"
          :label="t('supplier_scars.filters.supplier')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 240px"
        />
        <VSelect
          v-model="filterState.status"
          :items="statusOptions"
          :label="t('supplier_scars.filters.status')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 200px"
          :item-title="(v: string) => getStatusLabel(v)"
        />
        <VSelect
          v-model="filterState.severity"
          :items="severityOptions"
          :label="t('supplier_scars.filters.severity')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 200px"
          :item-title="(v: string) => getSeverityLabel(v)"
        />
        <VBtn color="primary" variant="tonal" @click="applyFilters">
          {{ t('supplier_scars.filters.apply') }}
        </VBtn>
        <VBtn variant="text" @click="clearFilters">
          {{ t('supplier_scars.filters.clear') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_scars.table.scar') }}</th>
            <th>{{ t('supplier_scars.table.supplier') }}</th>
            <th>{{ t('supplier_scars.table.title') }}</th>
            <th>{{ t('supplier_scars.table.severity') }}</th>
            <th>{{ t('supplier_scars.table.status') }}</th>
            <th>{{ t('supplier_scars.table.due') }}</th>
            <th class="text-right">{{ t('supplier_scars.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!scars.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_scars.no_scars') }}
            </td>
          </tr>
          <tr v-for="s in scars.data" :key="s.id">
            <td class="font-weight-medium">{{ s.scar_number }}</td>
            <td>{{ s.supplier?.company_name ?? '—' }}</td>
            <td>{{ s.title }}</td>
            <td>
              <VChip
                size="small"
                :color="severityColors[s.severity] ?? 'grey'"
                variant="tonal"
              >
                {{ getSeverityLabel(s.severity) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="statusColors[s.status] ?? 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(s.status) }}
              </VChip>
            </td>
            <td>{{ formatDate(s.due_date) }}</td>
            <td class="text-right">
              <VBtn
                size="small"
                variant="text"
                icon="mdi-pencil"
                @click="openEdit(s)"
              />
              <VBtn
                size="small"
                variant="text"
                color="error"
                icon="mdi-delete"
                @click="destroy(s)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <VCardActions v-if="scars.last_page > 1" class="justify-center">
        <VBtn
          v-for="link in scars.links"
          :key="link.label"
          :disabled="!link.url"
          :active="link.active"
          size="small"
          variant="text"
          @click="link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
        >
          <span v-html="link.label" />
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('supplier_scars.dialogs.create.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="createForm.supplier_id"
            :items="suppliers"
            item-title="company_name"
            item-value="id"
            :label="t('supplier_scars.dialogs.create.supplier_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VTextField
            v-model="createForm.title"
            :label="t('supplier_scars.dialogs.create.title_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VTextarea
            v-model="createForm.description"
            :label="t('supplier_scars.dialogs.create.description_label')"
            rows="3"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VRow>
            <VCol cols="6">
              <VSelect
                v-model="createForm.severity"
                :items="severityOptions"
                :label="t('supplier_scars.dialogs.create.severity_label')"
                density="compact"
                variant="outlined"
                :item-title="(v: string) => getSeverityLabel(v)"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="createForm.due_date"
                :label="t('supplier_scars.dialogs.create.due_date_label')"
                type="date"
                density="compact"
                variant="outlined"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">
            {{ t('supplier_scars.dialogs.create.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!createForm.supplier_id || !createForm.title || !createForm.description"
            @click="saveCreate"
          >
            {{ t('supplier_scars.dialogs.create.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Dialog -->
    <VDialog v-model="editDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('supplier_scars.dialogs.edit.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="editForm.status"
            :items="statusOptions"
            :label="t('supplier_scars.dialogs.edit.status_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
            :item-title="(v: string) => getStatusLabel(v)"
          />
          <VTextarea
            v-model="editForm.root_cause"
            :label="t('supplier_scars.dialogs.edit.root_cause_label')"
            rows="3"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VTextarea
            v-model="editForm.corrective_action"
            :label="t('supplier_scars.dialogs.edit.corrective_action_label')"
            rows="3"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="editForm.assigned_to"
                :label="t('supplier_scars.dialogs.edit.assigned_to_label')"
                type="number"
                density="compact"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="editForm.due_date"
                :label="t('supplier_scars.dialogs.edit.due_date_label')"
                type="date"
                density="compact"
                variant="outlined"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">
            {{ t('supplier_scars.dialogs.edit.cancel') }}
          </VBtn>
          <VBtn color="primary" @click="saveEdit">
            {{ t('supplier_scars.dialogs.edit.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
