<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type PO = {
  id: number
  po_number: string
  supplier_id: number
  amount: number
  budget_id: number | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  suppliers: { id: number; company_name: string }[]
  purchaseOrders: PO[]
  preselectPo: number | null
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const mode = ref<'from_po' | 'manual'>(props.preselectPo ? 'from_po' : 'from_po')

interface InvoiceForm {
  purchase_order_id: number | null
  supplier_id: number | null
  budget_id: number | null
  invoice_number: string
  amount: number | null
  invoice_date: string
  due_date: string
  run_ai: boolean
}

const form = reactive<InvoiceForm>({
  purchase_order_id: props.preselectPo as number | null,
  supplier_id: null,
  budget_id: null,
  invoice_number: '',
  amount: null,
  invoice_date: '',
  due_date: '',
  run_ai: false,
})

const file = ref<File | null>(null)
const submitting = ref(false)

// ── Computed ──────────────────────────────────────────────────────────────────
const poItems = computed(() =>
  props.purchaseOrders.map(p => ({
    title: `${p.po_number} — R${p.amount}`,
    value: p.id,
  }))
)

const supplierItems = computed(() =>
  props.suppliers.map(s => ({
    title: s.company_name,
    value: s.id,
  }))
)

// ── Methods ────────────────────────────────────────────────────────────────────
function goToIndex(): void {
  router.get(route('invoices.index'))
}

function submit(): void {
  submitting.value = true
  router.post(
    route('invoices.store'),
    {
      mode: mode.value,
      purchase_order_id: mode.value === 'from_po' ? form.purchase_order_id : null,
      supplier_id: form.supplier_id,
      budget_id: form.budget_id,
      invoice_number: form.invoice_number || undefined,
      amount: form.amount ?? undefined,
      invoice_date: form.invoice_date || undefined,
      due_date: form.due_date || undefined,
      run_ai: form.run_ai,
      file: file.value,
    },
    {
      forceFormData: true,
      onFinish: () => (submitting.value = false),
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" @click="goToIndex" />
      <h4 class="text-h4 mb-0">{{ t('invoices_create.page_title') }}</h4>
    </div>

    <VRow>
      <!-- ── Left Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="7">
        <VCard border>
          <VCardText>
            <VBtnToggle
              v-model="mode"
              mandatory
              color="primary"
              variant="outlined"
              density="comfortable"
              class="mb-4"
            >
              <VBtn value="from_po">{{ t('invoices_create.from_po') }}</VBtn>
              <VBtn value="manual">{{ t('invoices_create.manual') }}</VBtn>
            </VBtnToggle>

            <!-- From PO -->
            <VSelect
              v-if="mode === 'from_po'"
              v-model="form.purchase_order_id"
              :items="poItems"
              :label="t('invoices_create.purchase_order')"
              variant="outlined"
              density="compact"
              class="mb-3"
              hide-details
            />

            <!-- Manual -->
            <VSelect
              v-if="mode === 'manual'"
              v-model="form.supplier_id"
              :items="supplierItems"
              :label="t('invoices_create.supplier')"
              variant="outlined"
              density="compact"
              class="mb-3"
              hide-details
              clearable
            />

            <!-- Invoice number -->
            <VTextField
              v-model="form.invoice_number"
              :label="t('invoices_create.invoice_number')"
              variant="outlined"
              density="compact"
              class="mb-3"
              hide-details
            />

            <!-- Amount + Dates -->
            <div class="d-flex gap-3 mb-3">
              <VTextField
                v-model.number="form.amount"
                type="number"
                :label="t('invoices_create.amount')"
                variant="outlined"
                density="compact"
                hide-details
              />
              <VTextField
                v-model="form.invoice_date"
                type="date"
                :label="t('invoices_create.invoice_date')"
                variant="outlined"
                density="compact"
                hide-details
              />
              <VTextField
                v-model="form.due_date"
                type="date"
                :label="t('invoices_create.due_date')"
                variant="outlined"
                density="compact"
                hide-details
              />
            </div>

            <!-- File upload -->
            <VFileInput
              v-model="file"
              :label="t('invoices_create.invoice_document')"
              variant="outlined"
              density="compact"
              prepend-icon="tabler-paperclip"
              hide-details
              class="mb-3"
              @update:model-value="(f: any) => file = Array.isArray(f) ? f[0] : f"
            />

            <!-- AI checkbox -->
            <VCheckbox
              v-model="form.run_ai"
              :label="t('invoices_create.run_ai_extraction')"
              hide-details
              density="compact"
              :disabled="!file"
            />

            <!-- Submit -->
            <VBtn
              color="primary"
              class="mt-4"
              :loading="submitting"
              prepend-icon="tabler-check"
              @click="submit"
            >
              {{ t('invoices_create.capture_invoice') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Right Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <VCard border>
          <VCardTitle>{{ t('invoices_create.how_it_works') }}</VCardTitle>
          <VCardText class="text-body-2">
            <p>1. <strong>{{ t('invoices_create.from_po') }}</strong> {{ t('invoices_create.step1') }}</p>
            <p>2. {{ t('invoices_create.step2') }}</p>
            <p>3. {{ t('invoices_create.step3') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
