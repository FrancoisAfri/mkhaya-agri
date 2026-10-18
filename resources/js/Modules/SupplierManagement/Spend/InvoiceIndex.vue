<script setup lang="ts">
import { reactive, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  company_name: string
}

interface ContractOption {
  id: number
  label: string
  supplier_id: number | null
}

interface PoOption {
  id: number
  po_number: string
  supplier_id: number | null
}

interface Invoice {
  id: number
  invoice_number: string
  amount: number
  currency: string
  status: string
  contract_id: number | null
  purchase_order_id: number | null
  invoice_date: string | null
  due_date: string | null
  supplier?: Supplier | null
  purchase_order?: { id: number; po_number: string } | null
}

interface Pagination {
  data: Invoice[]
  links: any[]
  current_page: number
  last_page: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  invoices: Pagination
  filters: { status?: string; supplier_id?: number }
  suppliers: Supplier[]
  orders: PoOption[]
  contracts: ContractOption[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// Loading states
const createLoading = ref<boolean>(false)
const editLoading = ref<boolean>(false)
const deleteLoading = ref<number | null>(null)
const applyFilterLoading = ref<boolean>(false)

// ── Status options ────────────────────────────────────────────────────────────
const statusOptions = ['pending', 'matched', 'paid', 'disputed']

const statusColors: Record<string, string> = {
  pending: 'warning',
  matched: 'info',
  paid: 'success',
  disputed: 'error',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function formatCurrency(amount: number, currency: string): string {
  return `${currency} ${Number(amount).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: t('supplier_invoices.statuses.pending'),
    matched: t('supplier_invoices.statuses.matched'),
    paid: t('supplier_invoices.statuses.paid'),
    disputed: t('supplier_invoices.statuses.disputed'),
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
  supplier_id: props.filters.supplier_id ?? null,
})

const filterStatusOptions = computed(()=> [
  { title: t('supplier_invoices.filters.all'), value: null },
  ...statusOptions.map(s => ({
    title: getStatusLabel(s),
    value: s
  }))
])

function applyFilters(): void {
  applyFilterLoading.value = true
  router.get(
    route('suppliers.invoices.index'),
    {
      status: filterState.status ?? undefined,
      supplier_id: filterState.supplier_id ?? undefined,
    },
    {
      preserveState: true,
      replace: true,
      onFinish: () => { applyFilterLoading.value = false },
    }
  )
}

function clearFilters(): void {
  filterState.status = null
  filterState.supplier_id = null
  applyFilters()
}

// ── Create dialog ────────────────────────────────────────────────────────────
const createDialog = ref(false)
const createForm = reactive({
  invoice_number: '',
  supplier_id: null as number | null,
  contract_id: null as number | null,
  purchase_order_id: null as number | null,
  amount: 0,
  currency: 'ZAR',
  invoice_date: null as string | null,
  due_date: null as string | null,
})

const contractsForSupplier = computed(() => {
  if (!createForm.supplier_id) return props.contracts
  return props.contracts.filter(c => c.supplier_id === null || c.supplier_id === createForm.supplier_id)
})

const ordersForSupplier = computed(() => {
  if (!createForm.supplier_id) return props.orders
  return props.orders.filter(o => o.supplier_id === createForm.supplier_id)
})

function openCreate(): void {
  createForm.invoice_number = ''
  createForm.supplier_id = null
  createForm.contract_id = null
  createForm.purchase_order_id = null
  createForm.amount = 0
  createForm.currency = 'ZAR'
  createForm.invoice_date = null
  createForm.due_date = null
  createDialog.value = true
}

function saveCreate(): void {
  createLoading.value = true
  router.post(
    route('suppliers.invoices.store'),
    { ...createForm },
    {
      preserveScroll: true,
      onSuccess: () => {
        createDialog.value = false
        createLoading.value = false
      },
      onFinish: () => {
        createLoading.value = false
      },
    }
  )
}

// ── Edit dialog ──────────────────────────────────────────────────────────────
const editDialog = ref(false)
const editId = ref<number | null>(null)
const editForm = reactive({
  status: 'pending',
  contract_id: null as number | null,
  purchase_order_id: null as number | null,
  amount: 0,
  due_date: null as string | null,
})

function openEdit(inv: Invoice): void {
  editId.value = inv.id
  editForm.status = inv.status
  editForm.contract_id = inv.contract_id ?? null
  editForm.purchase_order_id = inv.purchase_order_id ?? null
  editForm.amount = inv.amount
  editForm.due_date = inv.due_date ?? null
  editDialog.value = true
}

function saveEdit(): void {
  if (!editId.value) return
  editLoading.value = true
  router.patch(
    route('suppliers.invoices.update', editId.value),
    { ...editForm },
    {
      preserveScroll: true,
      onSuccess: () => {
        editDialog.value = false
        editLoading.value = false
      },
      onFinish: () => {
        editLoading.value = false
      },
    }
  )
}

function destroy(inv: Invoice): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_invoices.dialogs.delete.title'),
      message: t('supplier_invoices.dialogs.delete.message', { number: inv.invoice_number }),
      confirmText: t('supplier_invoices.dialogs.delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      deleteLoading.value = inv.id
      router.delete(
        route('suppliers.invoices.destroy', inv.id),
        {
          preserveScroll: true,
          onFinish: () => { deleteLoading.value = null },
        }
      )
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h4">{{ t('supplier_invoices.page_title') }}</h1>
      <VBtn
        color="primary"
        prepend-icon="mdi-plus"
        :loading="createLoading"
        @click="openCreate"
      >
        {{ t('supplier_invoices.new_invoice') }}
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
          :label="t('supplier_invoices.filters.supplier')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 240px"
        />
        <VSelect
          v-model="filterState.status"
          :items="filterStatusOptions"
          item-title="title"
          item-value="value"
          :label="t('supplier_invoices.filters.status')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 200px"
        />
        <VBtn
          color="primary"
          variant="tonal"
          :loading="applyFilterLoading"
          @click="applyFilters"
        >
          {{ t('supplier_invoices.filters.apply') }}
        </VBtn>
        <VBtn variant="text" @click="clearFilters">
          {{ t('supplier_invoices.filters.clear') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_invoices.table.invoice') }}</th>
            <th>{{ t('supplier_invoices.table.supplier') }}</th>
            <th class="text-right">{{ t('supplier_invoices.table.amount') }}</th>
            <th>{{ t('supplier_invoices.table.status') }}</th>
            <th>{{ t('supplier_invoices.table.contract') }}</th>
            <th>{{ t('supplier_invoices.table.po') }}</th>
            <th>{{ t('supplier_invoices.table.invoice_date') }}</th>
            <th class="text-right">{{ t('supplier_invoices.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!invoices.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_invoices.no_invoices') }}
            </td>
          </tr>
          <tr v-for="inv in invoices.data" :key="inv.id">
            <td class="font-weight-medium">{{ inv.invoice_number }}</td>
            <td>{{ inv.supplier?.company_name ?? '—' }}</td>
            <td class="text-right">{{ formatCurrency(inv.amount, inv.currency) }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColors[inv.status] ?? 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(inv.status) }}
              </VChip>
            </td>
            <td>
              <VChip
                v-if="inv.contract_id"
                size="small"
                color="success"
                variant="tonal"
                prepend-icon="mdi-link-variant"
              >
                {{ t('supplier_invoices.contract.linked_label') }}
              </VChip>
              <VChip
                v-else
                size="small"
                color="warning"
                variant="tonal"
                prepend-icon="mdi-link-variant-off"
              >
                {{ t('supplier_invoices.contract.off_contract_label') }}
              </VChip>
            </td>
            <td>{{ inv.purchase_order?.po_number ?? '—' }}</td>
            <td>{{ formatDate(inv.invoice_date) }}</td>
            <td class="text-right">
              <VBtn
                size="small"
                variant="text"
                icon="mdi-pencil"
                @click="openEdit(inv)"
              />
              <VBtn
                size="small"
                variant="text"
                color="error"
                icon="mdi-delete"
                :loading="deleteLoading === inv.id"
                @click="destroy(inv)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <VCardActions v-if="invoices.last_page > 1" class="justify-center">
        <VBtn
          v-for="link in invoices.links"
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
        <VCardTitle>{{ t('supplier_invoices.dialogs.create.title') }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model="createForm.invoice_number"
            :label="t('supplier_invoices.dialogs.create.invoice_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VSelect
            v-model="createForm.supplier_id"
            :items="suppliers"
            item-title="company_name"
            item-value="id"
            :label="t('supplier_invoices.dialogs.create.supplier_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VRow>
            <VCol cols="8">
              <VTextField
                v-model.number="createForm.amount"
                :label="t('supplier_invoices.dialogs.create.amount_label')"
                type="number"
                min="0"
                step="0.01"
                density="compact"
                variant="outlined"
              />
            </VCol>
            <VCol cols="4">
              <VTextField
                v-model="createForm.currency"
                :label="t('supplier_invoices.dialogs.create.currency_label')"
                density="compact"
                variant="outlined"
              />
            </VCol>
          </VRow>
          <VSelect
            v-model="createForm.purchase_order_id"
            :items="ordersForSupplier"
            item-title="po_number"
            item-value="id"
            :label="t('supplier_invoices.dialogs.create.po_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
          />
          <VSelect
            v-model="createForm.contract_id"
            :items="contractsForSupplier"
            item-title="label"
            item-value="id"
            :label="t('supplier_invoices.dialogs.create.contract_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model="createForm.invoice_date"
                :label="t('supplier_invoices.dialogs.create.invoice_date_label')"
                type="date"
                density="compact"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="createForm.due_date"
                :label="t('supplier_invoices.dialogs.create.due_date_label')"
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
            {{ t('supplier_invoices.dialogs.create.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="createLoading"
            :disabled="!createForm.invoice_number || !createForm.supplier_id"
            @click="saveCreate"
          >
            {{ t('supplier_invoices.dialogs.create.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Dialog -->
    <VDialog v-model="editDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('supplier_invoices.dialogs.edit.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="editForm.status"
            :items="statusOptions"
            :label="t('supplier_invoices.dialogs.edit.status_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          >
            <template #item="{ item, props: p }">
              <VListItem v-bind="p" :title="getStatusLabel(item.value)" />
            </template>
            <template #selection="{ item }">
              <span>{{ getStatusLabel(item.value) }}</span>
            </template>
          </VSelect>
          <VSelect
            v-model="editForm.purchase_order_id"
            :items="orders"
            item-title="po_number"
            item-value="id"
            :label="t('supplier_invoices.dialogs.edit.po_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
          />
          <VSelect
            v-model="editForm.contract_id"
            :items="contracts"
            item-title="label"
            item-value="id"
            :label="t('supplier_invoices.dialogs.edit.contract_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="editForm.amount"
                :label="t('supplier_invoices.dialogs.edit.amount_label')"
                type="number"
                min="0"
                step="0.01"
                density="compact"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="editForm.due_date"
                :label="t('supplier_invoices.dialogs.edit.due_date_label')"
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
            {{ t('supplier_invoices.dialogs.edit.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="editLoading"
            @click="saveEdit"
          >
            {{ t('supplier_invoices.dialogs.edit.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
