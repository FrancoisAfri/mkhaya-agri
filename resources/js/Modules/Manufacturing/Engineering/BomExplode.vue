<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Req = {
  stock_item_id: number; item_code: string; quantity: number; uom: string | null
  line_kind: string; level: number; lines: string[]
}

const props = defineProps<{
  bom: {
    id: string; revision: number; status: string; product_code: string | null
    product_name: string | null; yield_quantity: number; yield_uom: string | null; batch_size: number | null
  }
  quantity: number
  whole_batches: boolean
  result: null | {
    batches: number; yield_per_batch: number; produced: number
    requirements: Req[]; by_products: Req[]
  }
  error: string | null
}>()

const qty = ref(props.quantity)
const whole = ref(props.whole_batches)

function run() {
  router.get(route('manufacturing.engineering.boms.explode', props.bom.id), {
    quantity: qty.value, whole_batches: whole.value ? 1 : 0,
  }, { preserveState: true, replace: true })
}

const fmt = (n: number) => Number(n.toFixed(4)).toString()
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
          :href="route('manufacturing.engineering.boms.show', bom.id)">
      {{ t('manufacturing.explode.back') }}
    </VBtn>
    <h4 class="text-h4">{{ t('manufacturing.explode.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">
      {{ bom.product_code }} — {{ bom.product_name }} · {{ t('manufacturing.bom.revision_n', { n: bom.revision }) }}
      · {{ t(`manufacturing.status.${bom.status}`) }}
    </p>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-4 align-center flex-wrap">
        <VTextField v-model.number="qty" type="number" min="0" step="any" density="compact" hide-details
                    style="max-width: 200px" :suffix="bom.yield_uom ?? ''" :label="t('manufacturing.explode.quantity')" />
        <!-- Whole batches by default: most processes cannot be run part-way, so 250 kg from a
             100 kg recipe is three batches, not 2.5. A costing question wants the linear figure. -->
        <VSwitch v-model="whole" color="primary" hide-details :label="t('manufacturing.explode.whole_batches')" />
        <VBtn color="primary" prepend-icon="tabler-calculator" @click="run">{{ t('manufacturing.explode.calculate') }}</VBtn>
      </VCardText>
    </VCard>

    <VAlert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</VAlert>

    <template v-if="result">
      <VRow class="mb-2">
        <VCol cols="6" md="3">
          <VCard border><VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.explode.batches') }}</div>
            <div class="text-h5">{{ result.batches }}</div>
          </VCardText></VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border><VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.explode.produced') }}</div>
            <div class="text-h5">{{ fmt(result.produced) }} {{ bom.yield_uom }}</div>
          </VCardText></VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border><VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.explode.per_batch') }}</div>
            <div class="text-h5">{{ fmt(result.yield_per_batch) }} {{ bom.yield_uom }}</div>
          </VCardText></VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border><VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.explode.materials') }}</div>
            <div class="text-h5">{{ result.requirements.length }}</div>
          </VCardText></VCard>
        </VCol>
      </VRow>

      <VCard border class="mb-4">
        <VCardItem><VCardTitle>{{ t('manufacturing.explode.requirements') }}</VCardTitle></VCardItem>
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ t('manufacturing.bom.component') }}</th>
              <th>{{ t('manufacturing.bom.kind') }}</th>
              <th>{{ t('manufacturing.explode.level') }}</th>
              <th class="text-end">{{ t('manufacturing.explode.required') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in result.requirements" :key="r.stock_item_id">
              <td>{{ r.item_code }}</td>
              <td>{{ t(`manufacturing.line_kind.${r.line_kind}`) }}</td>
              <td>{{ r.level }}</td>
              <td class="text-end font-weight-medium">{{ fmt(r.quantity) }} {{ r.uom }}</td>
            </tr>
            <tr v-if="!result.requirements.length">
              <td colspan="4" class="text-medium-emphasis">{{ t('manufacturing.explode.none') }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VCard v-if="result.by_products.length" border>
        <VCardItem>
          <VCardTitle>{{ t('manufacturing.explode.by_products') }}</VCardTitle>
          <VCardSubtitle>{{ t('manufacturing.explode.by_products_hint') }}</VCardSubtitle>
        </VCardItem>
        <VTable density="comfortable">
          <tbody>
            <tr v-for="r in result.by_products" :key="r.stock_item_id">
              <td>{{ r.item_code }}</td>
              <td class="text-end">{{ fmt(r.quantity) }} {{ r.uom }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
