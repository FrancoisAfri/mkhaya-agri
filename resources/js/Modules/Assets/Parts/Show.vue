<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Part = {
  id: number
  uuid: string
  part_number: string
  name: string
  description: string | null
  category: string | null
  unit_of_measure: string
  unit_cost: number
  quantity_on_hand: number
  reorder_level: number
  reorder_quantity: number
  status: string
  notes: string | null
  is_low_stock: boolean
  stock_value: number
  location: { id: number; name: string } | null
  supplier: { id: number; name: string } | null
}

type Txn = {
  id: number
  uuid: string
  type: string
  quantity: number
  balance_after: number
  unit_cost: number | null
  reference: string | null
  work_order_id: number | null
  notes: string | null
  occurred_at: string | null
}

const props = defineProps<{ part: Part; transactions: Txn[] }>()

const dialog = ref<null | 'receive' | 'issue' | 'adjust'>(null)
const processing = ref(false)

const formModel = reactive({
  quantity: null as number | null,
  unit_cost: null as number | null,
  work_order_id: null as number | null,
  reference: '',
  notes: '',
})

function openDialog(kind: 'receive' | 'issue' | 'adjust') {
  formModel.quantity = kind === 'adjust' ? props.part.quantity_on_hand : null
  formModel.unit_cost = null
  formModel.work_order_id = null
  formModel.reference = ''
  formModel.notes = ''
  dialog.value = kind
}

function money(n: number | null): string {
  if (n == null) return '—'
  return `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function qty(n: number): string {
  return Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 2 })
}

const typeColor: Record<string, string> = {
  receipt: 'success',
  issue: 'error',
  adjustment: 'info',
}

function submit() {
  if (!dialog.value || formModel.quantity == null) return
  const kind = dialog.value
  const payload: Record<string, any> = {
    quantity: formModel.quantity,
    reference: formModel.reference || null,
    notes: formModel.notes || null,
  }
  if (kind !== 'adjust') {
    payload.unit_cost = formModel.unit_cost
    payload.work_order_id = formModel.work_order_id || null
  }
  processing.value = true
  router.post(route(`assets.parts.${kind}`, props.part.uuid), payload, {
    preserveScroll: true,
    onSuccess: () => { dialog.value = null },
    onFinish: () => { processing.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <VBtn icon="mdi-arrow-left" variant="text" :to="route('assets.parts.index')" class="mr-2" />
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ props.part.name }}</h1>
        <div class="text-medium-emphasis">Part #{{ props.part.part_number }}</div>
      </div>
      <VChip v-if="props.part.is_low_stock" color="warning" size="small" class="ml-3">Low stock</VChip>
      <VSpacer />
      <VBtn color="success" variant="tonal" class="mr-2" prepend-icon="mdi-tray-arrow-down" @click="openDialog('receive')">Receive</VBtn>
      <VBtn color="error" variant="tonal" class="mr-2" prepend-icon="mdi-tray-arrow-up" @click="openDialog('issue')">Issue</VBtn>
      <VBtn color="info" variant="tonal" prepend-icon="mdi-tune" @click="openDialog('adjust')">Adjust</VBtn>
    </div>

    <VRow>
      <!-- On-hand summary -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardItem>
            <div class="text-caption text-medium-emphasis">On Hand</div>
            <div class="text-h4 font-weight-bold" :class="props.part.is_low_stock ? 'text-warning' : ''">
              {{ qty(props.part.quantity_on_hand) }}
              <span class="text-body-2 text-medium-emphasis">{{ props.part.unit_of_measure }}</span>
            </div>
            <VDivider class="my-3" />
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Reorder level</span><span>{{ qty(props.part.reorder_level) }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Reorder qty</span><span>{{ qty(props.part.reorder_quantity) }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Unit cost</span><span>{{ money(props.part.unit_cost) }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2">
              <span class="text-medium-emphasis">Stock value</span><span class="font-weight-bold">{{ money(props.part.stock_value) }}</span>
            </div>
          </VCardItem>
        </VCard>
      </VCol>

      <!-- Details -->
      <VCol cols="12" md="8">
        <VCard>
          <VCardItem>
            <div class="text-subtitle-1 font-weight-bold mb-3">Details</div>
            <VRow dense>
              <VCol cols="6" md="4"><div class="text-caption text-medium-emphasis">Category</div>{{ props.part.category || '—' }}</VCol>
              <VCol cols="6" md="4"><div class="text-caption text-medium-emphasis">Status</div>{{ props.part.status }}</VCol>
              <VCol cols="6" md="4"><div class="text-caption text-medium-emphasis">UoM</div>{{ props.part.unit_of_measure }}</VCol>
              <VCol cols="6" md="4"><div class="text-caption text-medium-emphasis">Location</div>{{ props.part.location?.name || '—' }}</VCol>
              <VCol cols="6" md="4"><div class="text-caption text-medium-emphasis">Supplier</div>{{ props.part.supplier?.name || '—' }}</VCol>
            </VRow>
            <template v-if="props.part.description">
              <VDivider class="my-3" />
              <div class="text-caption text-medium-emphasis">Description</div>
              <div>{{ props.part.description }}</div>
            </template>
            <template v-if="props.part.notes">
              <VDivider class="my-3" />
              <div class="text-caption text-medium-emphasis">Notes</div>
              <div>{{ props.part.notes }}</div>
            </template>
          </VCardItem>
        </VCard>
      </VCol>
    </VRow>

    <!-- Transaction history -->
    <VCard class="mt-4">
      <VCardItem>
        <div class="text-subtitle-1 font-weight-bold mb-2">Transaction History</div>
        <VTable>
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Balance</th>
              <th class="text-right">Unit Cost</th>
              <th>Reference</th>
              <th>Work Order</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in props.transactions" :key="t.id">
              <td>{{ t.occurred_at ? new Date(t.occurred_at).toLocaleString() : '—' }}</td>
              <td><VChip :color="typeColor[t.type] || 'default'" size="x-small">{{ t.type }}</VChip></td>
              <td class="text-right">
                <span :class="t.type === 'issue' ? 'text-error' : t.type === 'receipt' ? 'text-success' : ''">
                  {{ t.type === 'issue' ? '-' : t.type === 'receipt' ? '+' : '' }}{{ qty(t.quantity) }}
                </span>
              </td>
              <td class="text-right">{{ qty(t.balance_after) }}</td>
              <td class="text-right">{{ money(t.unit_cost) }}</td>
              <td>{{ t.reference || '—' }}</td>
              <td>{{ t.work_order_id ?? '—' }}</td>
              <td>{{ t.notes || '—' }}</td>
            </tr>
            <tr v-if="props.transactions.length === 0">
              <td colspan="8" class="text-center text-medium-emphasis py-6">No movements yet.</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>

    <!-- Action dialog -->
    <VDialog :model-value="dialog !== null" max-width="480" @update:model-value="v => { if (!v) dialog = null }">
      <VCard v-if="dialog">
        <VCardTitle class="text-capitalize">{{ dialog }} stock</VCardTitle>
        <VCardText>
          <VAlert v-if="dialog === 'adjust'" type="info" variant="tonal" density="compact" class="mb-3">
            Enter the counted, absolute on-hand quantity. Current on hand: {{ qty(props.part.quantity_on_hand) }}.
          </VAlert>
          <VAlert v-else-if="dialog === 'issue'" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ qty(props.part.quantity_on_hand) }} {{ props.part.unit_of_measure }} available. Issuing more is blocked.
          </VAlert>

          <VTextField
            v-model.number="formModel.quantity"
            type="number"
            min="0"
            step="0.01"
            :label="dialog === 'adjust' ? 'New on-hand quantity *' : 'Quantity *'"
            autofocus
          />
          <VTextField
            v-if="dialog !== 'adjust'"
            v-model.number="formModel.unit_cost"
            type="number"
            min="0"
            step="0.01"
            label="Unit cost (R)"
          />
          <VTextField
            v-if="dialog === 'issue'"
            v-model.number="formModel.work_order_id"
            type="number"
            label="Work order # (optional)"
          />
          <VTextField v-model="formModel.reference" label="Reference (optional)" />
          <VTextarea v-model="formModel.notes" label="Notes (optional)" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = null">Cancel</VBtn>
          <VBtn color="primary" :loading="processing" @click="submit">Confirm</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
