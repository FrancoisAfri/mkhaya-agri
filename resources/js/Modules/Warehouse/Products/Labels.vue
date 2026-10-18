<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Label = { id: string; code: string; name: string; barcode: string; level: string | null; qr: string }

defineProps<{ labels: Label[]; level: string | null; copies: number }>()

function print() {
  window.print()
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2 no-print">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.product_labels.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.product_labels.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-printer" :disabled="!labels.length" @click="print">{{ t('warehouse_management.labels.print') }}</VBtn>
    </div>

    <VCard v-if="!labels.length" border class="no-print">
      <VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.product_labels.none') }}</VCardText>
    </VCard>

    <div class="label-sheet">
      <template v-for="l in labels" :key="l.id">
        <div v-for="n in copies" :key="n" class="label">
          <!-- Server-rendered SVG from our own QR writer, never user-supplied markup. -->
          <div class="qr" v-html="l.qr" />
          <div class="code">{{ l.code }}</div>
          <div class="name">{{ l.name }}</div>
          <div class="barcode">{{ l.barcode }}<template v-if="l.level"> · {{ t(`warehouse_management.packaging_level.${l.level}`) }}</template></div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.label-sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(62mm, 1fr)); gap: 4mm; }
.label { border: 1px dashed rgba(var(--v-border-color), 0.5); border-radius: 4px; padding: 3mm; text-align: center; break-inside: avoid; background: #fff; color: #000; }
.qr :deep(svg) { width: 30mm; height: 30mm; }
.code { font-size: 16pt; font-weight: 700; }
.name { font-size: 9pt; }
.barcode { font-size: 8pt; font-family: ui-monospace, monospace; }
@media print { .no-print { display: none !important; } .label { border: 1px solid #000; } }
</style>
