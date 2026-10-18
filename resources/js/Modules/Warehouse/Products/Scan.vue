<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  code: string
  result: null | {
    item: { id: string; code: string; name: string; status: string; uom: string }
    packaging: string | null; qty_in_base: number; matched_by: string
    batch?: string | null; serial?: string | null; expiry?: string | null; production_date?: string | null
    best_before?: string | null; count?: number | null; net_weight_kg?: number | null
    gs1?: { other: Record<string, string>; sscc: string | null } | null
  }
  error: string | null
}>()

// A hardware scanner types the code and presses Enter; a person can type it the same way.
const input = ref(props.code)
function lookup() {
  router.get(route('warehouse.products.scan'), { code: input.value }, { preserveState: false })
}
const fields = ['batch', 'serial', 'expiry', 'best_before', 'production_date', 'count', 'net_weight_kg'] as const
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.scan.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.scan.subtitle') }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-2 flex-wrap">
        <VTextField v-model="input" autofocus prepend-inner-icon="tabler-scan" hide-details style="min-width: 320px"
                    :label="t('warehouse_management.scan.input')" @keyup.enter="lookup" />
        <VBtn color="primary" size="large" @click="lookup">{{ t('warehouse_management.scan.read') }}</VBtn>
      </VCardText>
      <VCardText class="pt-0 text-caption text-medium-emphasis">{{ t('warehouse_management.scan.hint') }}</VCardText>
    </VCard>

    <VAlert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</VAlert>

    <VCard v-if="result" border>
      <VCardItem>
        <VCardTitle><a :href="route('warehouse.products.show', result.item.id)">{{ result.item.code }}</a> — {{ result.item.name }}</VCardTitle>
        <VCardSubtitle>
          {{ t(`warehouse_management.scan.matched_by.${result.matched_by}`) }}
          · {{ result.packaging ? t(`warehouse_management.packaging_level.${result.packaging}`) : t('warehouse_management.products.base_unit') }}
          = {{ result.qty_in_base }} {{ result.item.uom }}
        </VCardSubtitle>
        <template #append>
          <VChip label variant="tonal" :color="result.item.status === 'ACTIVE' ? 'success' : 'error'">{{ t(`warehouse_management.item_status.${result.item.status}`) }}</VChip>
        </template>
      </VCardItem>
      <VCardText>
        <VTable density="compact">
          <tbody>
            <template v-for="f in fields" :key="f">
              <tr v-if="result[f] !== null && result[f] !== undefined">
                <th class="text-medium-emphasis" style="width: 220px">{{ t(`warehouse_management.scan.field.${f}`) }}</th>
                <td style="font-family: ui-monospace, monospace">{{ result[f] }}</td>
              </tr>
            </template>
            <tr v-for="(v, ai) in (result.gs1?.other ?? {})" :key="ai">
              <th class="text-medium-emphasis">AI ({{ ai }})</th>
              <td style="font-family: ui-monospace, monospace">{{ v }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>
  </div>
</template>
