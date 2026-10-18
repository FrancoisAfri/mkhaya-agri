<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Usage = {
  bom_uuid: string | null; bom_revision: number | null; bom_status: string | null
  parent_item_uuid: string | null; parent_item_kind: string | null; line_no: number
  quantity_per: number; uom: string | null; scrap_percent: number; is_phantom: boolean
}

const props = defineProps<{
  components: Array<{ id: number; code: string; name: string }>
  selected: number | null
  include_retired: boolean
  usages: Usage[]
}>()

const component = ref<number | null>(props.selected)
const retired = ref(props.include_retired)

watch([component, retired], () => {
  router.get(route('manufacturing.engineering.where-used.index'), {
    stock_item_id: component.value || undefined,
    include_retired: retired.value ? 1 : undefined,
  }, { preserveState: true, replace: true })
})

const statusColor = (s: string | null) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s ?? ''] ?? 'default'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.where_used.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.where_used.subtitle') }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-4 align-center flex-wrap">
        <VAutocomplete v-model="component" :items="components" item-value="id" clearable density="compact"
                       hide-details style="max-width: 420px"
                       :item-title="(i: any) => `${i.code} — ${i.name}`"
                       :label="t('manufacturing.where_used.component')" />
        <VSwitch v-model="retired" color="primary" hide-details density="compact"
                 :label="t('manufacturing.where_used.include_retired')" />
      </VCardText>
    </VCard>

    <VCard border>
      <VAlert v-if="!component" type="info" variant="tonal" class="ma-4">
        {{ t('manufacturing.where_used.choose') }}
      </VAlert>
      <VTable v-else-if="usages.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('manufacturing.where_used.recipe') }}</th>
            <th>{{ t('manufacturing.bom.status') }}</th>
            <th class="text-end">{{ t('manufacturing.bom.net_qty') }}</th>
            <th class="text-end">{{ t('manufacturing.bom.scrap') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in usages" :key="`${u.bom_uuid}-${u.line_no}`">
            <td>
              <a :href="route('manufacturing.engineering.boms.show', u.bom_uuid!)">
                {{ t('manufacturing.bom.revision_n', { n: u.bom_revision }) }}
              </a>
              <span class="text-caption text-medium-emphasis ms-2">
                {{ u.parent_item_kind ? t(`manufacturing.kind.${u.parent_item_kind}`) : '' }}
              </span>
              <VChip v-if="u.is_phantom" size="x-small" label color="deep-purple" variant="tonal" class="ms-2">
                {{ t('manufacturing.bom.phantom') }}
              </VChip>
            </td>
            <td>
              <VChip size="small" label variant="tonal" :color="statusColor(u.bom_status)">
                {{ u.bom_status ? t(`manufacturing.status.${u.bom_status}`) : '' }}
              </VChip>
            </td>
            <td class="text-end">{{ u.quantity_per }} {{ u.uom }}</td>
            <td class="text-end">{{ u.scrap_percent }}%</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.where_used.none') }}</VCardText>
    </VCard>
  </div>
</template>
