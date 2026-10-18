<script setup lang="ts">
import { reactive, ref, computed, onMounted, watch } from 'vue'
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

interface PurchaseOrder {
  id: number
  po_number: string
  title: string
  amount: number
  currency: string
  status: string
  contract_id: number | null
  order_date: string | null
  notes: string | null
  supplier?: Supplier | null
}

interface Pagination {
  data: PurchaseOrder[]
  links: any[]
  current_page: number
  last_page: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  orders: Pagination
  filters: { status?: string; supplier_id?: number }
  suppliers: Supplier[]
  contracts: ContractOption[]
  openCreate?: boolean
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// Loading states
const createLoading = ref<boolean>(false)
const editLoading = ref<boolean>(false)
const deleteLoading = ref<number | null>(null)
const applyFilterLoading = ref<boolean>(false)

// ── Status options (valeurs en base de données) ─────────────────────────────
const statusOptions = ['draft', 'approved', 'received', 'closed', 'cancelled']

const statusColors: Record<string, string> = {
  draft: 'grey',
  approved: 'info',
  received: 'primary',
  closed: 'success',
  cancelled: 'error',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function formatCurrency(amount: number, currency: string): string {
  return `${currency} ${Number(amount).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

// ═══ CORRECTION ICI ═══
// La fonction retourne la traduction du statut, pas la valeur brute
function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    draft: t('supplier_purchase_orders.statuses.draft'),
    approved: t('supplier_purchase_orders.statuses.approved'),
    received: t('supplier_purchase_orders.statuses.received'),
    closed: t('supplier_purchase_orders.statuses.closed'),
    cancelled: t('supplier_purchase_orders.statuses.cancelled'),
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

// ═══ CORRECTION ICI ═══
// Les options du filtre utilisent la valeur en base (pour la requête)
// mais affichent la traduction
const filterStatusOptions = computed(()=> [
  { title: t('supplier_purchase_orders.filters.all'), value: null },
  ...statusOptions.map(s => ({
    title: getStatusLabel(s),
    value: s
  }))
])

function applyFilters(): void {
  applyFilterLoading.value = true
  router.get(
    route('suppliers.purchase-orders.index'),
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
  supplier_id: null as number | null,
  contract_id: null as number | null,
  title: '',
  amount: 0,
  currency: 'ZAR',
  order_date: null as string | null,
  notes: '' as string | null,
})

const contractsForSupplier = computed(() => {
  if (!createForm.supplier_id) return props.contracts
  return props.contracts.filter(c => c.supplier_id === null || c.supplier_id === createForm.supplier_id)
})

function openCreate(): void {
  createForm.supplier_id = null
  createForm.contract_id = null
  createForm.title = ''
  createForm.amount = 0
  createForm.currency = 'ZAR'
  createForm.order_date = null
  createForm.notes = ''
  createDialog.value = true
}

function saveCreate(): void {
  createLoading.value = true
  router.post(
    route('suppliers.purchase-orders.store'),
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
// The order being edited, held as its route key rather than its row id.
const editUuid = ref<string | null>(null)
const editForm = reactive({
  status: 'draft',
  contract_id: null as number | null,
  amount: 0,
  order_date: null as string | null,
  notes: '' as string | null,
})

function openEdit(po: PurchaseOrder): void {
  editUuid.value = po.uuid
  editForm.status = po.status
  editForm.contract_id = po.contract_id ?? null
  editForm.amount = po.amount
  editForm.order_date = po.order_date ?? null
  editForm.notes = po.notes ?? ''
  editDialog.value = true
}

function saveEdit(): void {
  if (!editUuid.value) return
  editLoading.value = true
  router.patch(
    route('suppliers.purchase-orders.update', editUuid.value),
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

function destroy(po: PurchaseOrder): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_purchase_orders.dialogs.delete.title'),
      message: t('supplier_purchase_orders.dialogs.delete.message', { number: po.po_number }),
      confirmText: t('supplier_purchase_orders.dialogs.delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      deleteLoading.value = po.id
      router.delete(
        route('suppliers.purchase-orders.destroy', po.uuid),
        {
          preserveScroll: true,
          onFinish: () => { deleteLoading.value = null },
        }
      )
    }
  )
}

watch(editDialog, (newVal) => {
  if (!newVal) {
    editUuid.value = null
  }
})

onMounted(() => {
  if (props.openCreate) openCreate()
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h4">{{ t('supplier_purchase_orders.page_title') }}</h1>
      <VBtn
        color="primary"
        prepend-icon="mdi-plus"
        :loading="createLoading"
        @click="openCreate"
      >
        {{ t('supplier_purchase_orders.new_po') }}
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
          :label="t('supplier_purchase_orders.filters.supplier')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 240px"
        />
        <!-- ═══ CORRECTION ICI ═══ -->
        <VSelect
          v-model="filterState.status"
          :items="filterStatusOptions"
          item-title="title"
          item-value="value"
          :label="t('supplier_purchase_orders.filters.status')"
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
          {{ t('supplier_purchase_orders.filters.apply') }}
        </VBtn>
        <VBtn variant="text" @click="clearFilters">
          {{ t('supplier_purchase_orders.filters.clear') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_purchase_orders.table.po') }}</th>
            <th>{{ t('supplier_purchase_orders.table.supplier') }}</th>
            <th>{{ t('supplier_purchase_orders.table.title') }}</th>
            <th class="text-right">{{ t('supplier_purchase_orders.table.amount') }}</th>
            <th>{{ t('supplier_purchase_orders.table.status') }}</th>
            <th>{{ t('supplier_purchase_orders.table.contract') }}</th>
            <th>{{ t('supplier_purchase_orders.table.order_date') }}</th>
            <th class="text-right">{{ t('supplier_purchase_orders.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!orders.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_purchase_orders.no_orders') }}
            </td>
          </tr>
          <tr v-for="po in orders.data" :key="po.id">
            <td class="font-weight-medium">{{ po.po_number }}</td>
            <td>{{ po.supplier?.company_name ?? '—' }}</td>
            <td>{{ po.title }}</td>
            <td class="text-right">{{ formatCurrency(po.amount, po.currency) }}</td>
            <td>
              <!-- ═══ CORRECTION ICI ═══ -->
              <!-- La valeur en base est po.status, mais on affiche la traduction -->
              <VChip
                size="small"
                :color="statusColors[po.status] ?? 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(po.status) }}
              </VChip>
            </td>
            <td>
              <VChip
                v-if="po.contract_id"
                size="small"
                color="success"
                variant="tonal"
                prepend-icon="mdi-link-variant"
              >
                {{ t('supplier_purchase_orders.contract.linked_label') }}
              </VChip>
              <VChip
                v-else
                size="small"
                color="warning"
                variant="tonal"
                prepend-icon="mdi-link-variant-off"
              >
                {{ t('supplier_purchase_orders.contract.off_contract_label') }}
              </VChip>
            </td>
            <td>{{ formatDate(po.order_date) }}</td>
            <td class="text-right">
              <VBtn
                size="small"
                variant="text"
                icon="mdi-pencil"
                @click="openEdit(po)"
              />
              <VBtn
                size="small"
                variant="text"
                color="error"
                icon="mdi-delete"
                :loading="deleteLoading === po.id"
                @click="destroy(po)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <VCardActions v-if="orders.last_page > 1" class="justify-center">
        <VBtn
          v-for="link in orders.links"
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
        <VCardTitle>{{ t('supplier_purchase_orders.dialogs.create.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="createForm.supplier_id"
            :items="suppliers"
            item-title="company_name"
            item-value="id"
            :label="t('supplier_purchase_orders.dialogs.create.supplier_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VTextField
            v-model="createForm.title"
            :label="t('supplier_purchase_orders.dialogs.create.title_label')"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VRow>
            <VCol cols="8">
              <VTextField
                v-model.number="createForm.amount"
                :label="t('supplier_purchase_orders.dialogs.create.amount_label')"
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
                :label="t('supplier_purchase_orders.dialogs.create.currency_label')"
                density="compact"
                variant="outlined"
              />
            </VCol>
          </VRow>
          <VSelect
            v-model="createForm.contract_id"
            :items="contractsForSupplier"
            item-title="label"
            item-value="id"
            :label="t('supplier_purchase_orders.dialogs.create.contract_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
            :hint="contractsForSupplier.length ? '' : t('supplier_purchase_orders.contract.no_contracts')"
            persistent-hint
          />
          <VTextField
            v-model="createForm.order_date"
            :label="t('supplier_purchase_orders.dialogs.create.order_date_label')"
            type="date"
            density="compact"
            variant="outlined"
            class="mb-2"
          />
          <VTextarea
            v-model="createForm.notes"
            :label="t('supplier_purchase_orders.dialogs.create.notes_label')"
            rows="2"
            density="compact"
            variant="outlined"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">
            {{ t('supplier_purchase_orders.dialogs.create.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="createLoading"
            :disabled="!createForm.supplier_id || !createForm.title"
            @click="saveCreate"
          >
            {{ t('supplier_purchase_orders.dialogs.create.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Dialog -->
    <VDialog v-model="editDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('supplier_purchase_orders.dialogs.edit.title') }}</VCardTitle>
        <VCardText>
          <!-- ═══ CORRECTION ICI ═══ -->
          <!-- editForm.status stocke la valeur en base (draft, approved, etc.) -->
          <!-- On affiche la traduction correspondante -->
          <VSelect
            v-model="editForm.status"
            :items="statusOptions"
            :label="t('supplier_purchase_orders.dialogs.edit.status_label')"
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
            v-model="editForm.contract_id"
            :items="contracts"
            item-title="label"
            item-value="id"
            :label="t('supplier_purchase_orders.dialogs.edit.contract_label')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-2"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="editForm.amount"
                :label="t('supplier_purchase_orders.dialogs.edit.amount_label')"
                type="number"
                min="0"
                step="0.01"
                density="compact"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="editForm.order_date"
                :label="t('supplier_purchase_orders.dialogs.edit.order_date_label')"
                type="date"
                density="compact"
                variant="outlined"
              />
            </VCol>
          </VRow>
          <VTextarea
            v-model="editForm.notes"
            :label="t('supplier_purchase_orders.dialogs.edit.notes_label')"
            rows="2"
            density="compact"
            variant="outlined"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">
            {{ t('supplier_purchase_orders.dialogs.edit.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="editLoading"
            @click="saveEdit"
          >
            {{ t('supplier_purchase_orders.dialogs.edit.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
