<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Change = {
  action: string; warehouse: string | null; location: string | null
  changes: Record<string, [unknown, unknown]> | null; reason: string | null; actor: string | null; at: string | null
}

const props = defineProps<{
  warehouses: Array<{ id: string; code: string; name: string }>
  warehouse: string | null
  filters: { location?: string; action?: string }
  actions: string[]
  changes: { data: Change[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number }
}>()

function reload(patch: Record<string, string | null | undefined>) {
  router.get(route('warehouse.layout.history'), { warehouse: props.warehouse, ...props.filters, ...patch }, { preserveScroll: true })
}

const show = (v: unknown) => v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v)
const color = (a: string) => ({ BLOCKED: 'error', RESTRICTED: 'warning', REOPENED: 'success', DEACTIVATED: 'secondary', CREATED: 'primary' } as Record<string, string>)[a]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.history.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.history.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VSelect :model-value="warehouse" :items="warehouses.map(w => ({ value: w.id, title: `${w.code} — ${w.name}` }))" clearable
                 density="compact" hide-details style="min-width: 240px" :label="t('warehouse_management.common.warehouse')"
                 @update:model-value="v => reload({ warehouse: v, location: null })" />
        <VSelect :model-value="filters.action ?? null" :items="actions.map(a => ({ value: a, title: t(`warehouse_management.action.${a}`) }))" clearable
                 density="compact" hide-details style="min-width: 180px" :label="t('warehouse_management.history.action')"
                 @update:model-value="v => reload({ action: v })" />
        <VBtn v-if="filters.location" variant="tonal" prepend-icon="tabler-x" @click="reload({ location: null })">{{ t('warehouse_management.history.all_locations') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText v-if="!changes.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.history.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('warehouse_management.history.when') }}</th>
              <th>{{ t('warehouse_management.history.action') }}</th>
              <th>{{ t('warehouse_management.history.where') }}</th>
              <th>{{ t('warehouse_management.history.what') }}</th>
              <th>{{ t('warehouse_management.common.reason') }}</th>
              <th>{{ t('warehouse_management.history.who') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(c, i) in changes.data" :key="i">
              <td class="text-no-wrap">{{ c.at ? new Date(c.at).toLocaleString() : '' }}</td>
              <td><VChip size="small" label variant="tonal" :color="color(c.action)">{{ t(`warehouse_management.action.${c.action}`) }}</VChip></td>
              <td class="text-no-wrap">{{ c.warehouse }}<template v-if="c.location"> / {{ c.location }}</template></td>
              <td>
                <div v-for="(pair, field) in (c.changes ?? {})" :key="field" class="text-caption">
                  <span class="font-weight-medium">{{ field }}</span>: {{ show(pair[0]) }} → {{ show(pair[1]) }}
                </div>
              </td>
              <td>{{ c.reason }}</td>
              <td>{{ c.actor ?? t('warehouse_management.history.system') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="changes.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in changes.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
