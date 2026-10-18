<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Label = { id: string; code: string; name: string | null; level: string; kind: string; barcode: string; warehouse: string | null; qr: string }

const props = defineProps<{
  warehouses: Array<{ id: string; code: string; name: string }>
  warehouse: { id: string; code: string; name: string } | null
  labels: Label[]
  levels: string[]
  filters: { level: string | null; under: string | null }
}>()

function reload(patch: Record<string, string | null>) {
  router.get(route('warehouse.layout.labels'), {
    warehouse: props.warehouse?.id, level: props.filters.level, under: props.filters.under, ...patch,
  }, { preserveScroll: true })
}

function print() {
  window.print()
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2 no-print">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.labels.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.labels.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VSelect :model-value="warehouse?.id" :items="warehouses.map(w => ({ value: w.id, title: `${w.code} — ${w.name}` }))"
                 density="compact" hide-details style="min-width: 240px" :label="t('warehouse_management.common.warehouse')"
                 @update:model-value="v => reload({ warehouse: v, under: null })" />
        <VSelect :model-value="filters.level" :items="levels.map(v => ({ value: v, title: t(`warehouse_management.level.${v}`) }))" clearable
                 density="compact" hide-details style="min-width: 160px" :label="t('warehouse_management.locations.level')"
                 @update:model-value="v => reload({ level: v })" />
        <VBtn color="primary" prepend-icon="tabler-printer" :disabled="!labels.length" @click="print">{{ t('warehouse_management.labels.print') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="labels.length >= 500" type="info" variant="tonal" class="mb-3 no-print">{{ t('warehouse_management.labels.capped') }}</VAlert>
    <VCard v-if="!labels.length" border class="no-print">
      <VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.labels.none') }}</VCardText>
    </VCard>

    <div class="label-sheet">
      <div v-for="l in labels" :key="l.id" class="label">
        <!-- Server-rendered SVG from our own QR writer, never user-supplied markup. -->
        <div class="qr" v-html="l.qr" />
        <div class="code">{{ l.code }}</div>
        <div class="meta">{{ l.warehouse }} · {{ t(`warehouse_management.level.${l.level}`) }}<template v-if="l.kind !== 'STORAGE'"> · {{ t(`warehouse_management.kind.${l.kind}`) }}</template></div>
        <div class="barcode">{{ l.barcode }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.label-sheet {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(62mm, 1fr));
  gap: 4mm;
}
.label {
  border: 1px dashed rgba(var(--v-border-color), 0.5);
  border-radius: 4px;
  padding: 3mm;
  text-align: center;
  break-inside: avoid;
  background: #fff;
  color: #000;
}
.qr :deep(svg) { width: 32mm; height: 32mm; }
.code { font-size: 20pt; font-weight: 700; letter-spacing: 0.04em; font-variant-numeric: tabular-nums; }
.meta, .barcode { font-size: 8pt; }
.barcode { font-family: ui-monospace, monospace; }
@media print {
  .no-print { display: none !important; }
  .label { border: 1px solid #000; }
}
</style>
