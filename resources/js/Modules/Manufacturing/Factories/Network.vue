<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Option = { id: number; name: string }
const props = defineProps<{
  factories: Array<{ id: number; code: string; name: string; default_warehouse: string | null; warehouses: Array<{ id: number; code: string; role: string }> }>
  warehouses: Option[]
  restricted: Array<{ id: string; product: string | null; factories: number[] }>
  products: Array<{ id: string; name: string; scope: string }>
  can: { configure: boolean }
}>()

const add = ref<Record<number, { warehouse_id: number | null; role: string }>>(Object.fromEntries(props.factories.map(f => [f.id, { warehouse_id: null, role: 'GENERAL' }])))
const assign = (factoryId: number, warehouseId: number, role: string, remove = false) =>
  router.post(route('manufacturing.multi.network.warehouses'), { factory_id: factoryId, warehouse_id: warehouseId, role, remove }, { preserveScroll: true })
const product = ref<string | null>(null)
const scope = ref('LISTED')
const chosen = ref<number[]>([])
const pick = (id: string | null) => {
  const r = props.restricted.find(x => x.id === id)
  scope.value = r ? 'LISTED' : (props.products.find(p => p.id === id)?.scope ?? 'ALL'); chosen.value = r ? [...r.factories] : []
}
const saveScope = () => product.value && router.post(route('manufacturing.multi.network.products', product.value), { scope: scope.value, factories: chosen.value }, { preserveScroll: true })
const factoryCodes = (ids: number[]) => ids.map(id => props.factories.find(f => f.id === id)?.code ?? id).join(', ')
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.multi.network_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.multi.network_subtitle') }}</p>
    </div>
    <VRow>
      <VCol cols="12" lg="7">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.multi.factory_warehouses') }}</VCardTitle></VCardItem>
          <VTable density="compact">
            <tbody>
              <tr v-for="f in factories" :key="f.id">
                <td class="font-weight-medium" style="width: 160px">{{ f.code }}<div class="text-caption text-medium-emphasis">{{ f.name }}</div></td>
                <td>
                  <VChip v-if="f.default_warehouse" size="small" label class="me-1 mb-1">{{ f.default_warehouse }} · {{ t('manufacturing.multi.default') }}</VChip>
                  <VChip v-for="w in f.warehouses" :key="w.id" size="small" label class="me-1 mb-1" :closable="can.configure" @click:close="assign(f.id, w.id, w.role, true)">
                    {{ w.code }} · {{ t(`manufacturing.multi.roles.${w.role}`) }}</VChip>
                  <div v-if="can.configure" class="d-flex gap-2 mt-1">
                    <VSelect v-model="add[f.id].warehouse_id" :items="warehouses" item-title="name" item-value="id" density="compact" hide-details style="min-width: 200px" :label="t('manufacturing.multi.warehouse')" />
                    <VSelect v-model="add[f.id].role" :items="['STORES', 'FINISHED', 'GENERAL'].map(r => ({ value: r, title: t(`manufacturing.multi.roles.${r}`) }))" density="compact" hide-details style="max-width: 150px" />
                    <VBtn size="small" variant="tonal" :disabled="!add[f.id].warehouse_id" @click="assign(f.id, add[f.id].warehouse_id!, add[f.id].role)">{{ t('manufacturing.multi.add') }}</VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.multi.product_scope') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.multi.product_scope_hint') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <tbody>
              <tr v-for="r in restricted" :key="r.id"><td>{{ r.product }}</td><td class="text-caption">{{ factoryCodes(r.factories) }}</td></tr>
              <tr v-if="!restricted.length"><td class="text-medium-emphasis">{{ t('manufacturing.multi.all_shared') }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-if="can.configure">
            <VAutocomplete v-model="product" :items="products" item-title="name" item-value="id" :label="t('manufacturing.multi.product')" class="mb-2" @update:model-value="pick" />
            <VRadioGroup v-model="scope" inline hide-details class="mb-2">
              <VRadio value="ALL" :label="t('manufacturing.multi.scope_all')" /><VRadio value="LISTED" :label="t('manufacturing.multi.scope_listed')" />
            </VRadioGroup>
            <VSelect v-if="scope === 'LISTED'" v-model="chosen" :items="factories.map(f => ({ value: f.id, title: f.code }))" multiple chips :label="t('manufacturing.multi.factories')" class="mb-2" />
            <VBtn color="primary" :disabled="!product" @click="saveScope">{{ t('manufacturing.multi.save') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
