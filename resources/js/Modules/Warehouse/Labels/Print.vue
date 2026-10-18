<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps<{ template: { name: string; width_mm: number; height_mm: number; fields: string[] }; labels: Array<{ barcode: string; qr: string; lines: Record<string, string> }>; kind: string }>()
const print = () => window.print()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex justify-space-between align-center mb-3 d-print-none">
      <div><h4 class="text-h5">{{ template.name }}</h4><p class="text-caption mb-0">{{ t('warehouse_management.labels_setup.browser_note') }}</p></div>
      <VBtn color="primary" prepend-icon="tabler-printer" @click="print">{{ t('warehouse_management.recalls.print') }}</VBtn>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <div v-for="(l, i) in labels" :key="i" class="label" :style="{ width: `${props.template.width_mm}mm`, height: `${props.template.height_mm}mm` }">
        <div v-for="f in props.template.fields.filter(x => !['barcode', 'sscc'].includes(x))" :key="f" class="line">{{ l.lines[f] }}</div>
        <div class="qr" v-html="l.qr" />
        <div class="value">{{ l.lines.sscc ?? l.barcode }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.label { border: 1px dashed #999; padding: 3mm; overflow: hidden; display: flex; flex-direction: column; gap: 1mm; page-break-inside: avoid; background: #fff; color: #000; }
.line { font-size: 11pt; line-height: 1.2; }
.qr :deep(svg) { width: 22mm; height: 22mm; }
.value { font-family: monospace; font-size: 10pt; }
@media print { .label { border: none; } }
</style>
