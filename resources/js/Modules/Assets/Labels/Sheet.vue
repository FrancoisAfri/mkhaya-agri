<script setup lang="ts">
import { ref, onMounted } from 'vue'
import QRCode from 'qrcode'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type LabelAsset = {
  id: number; asset_number: string | null; name: string; code: string
}

const props = defineProps<{ assets: LabelAsset[] }>()

const qrMap = ref<Record<number, string>>({})

onMounted(async () => {
  const entries = await Promise.all(
    props.assets.map(async (a) => {
      const payload = `${window.location.origin}/assets/${a.id}`
      const url = await QRCode.toDataURL(payload, { margin: 1, width: 200 })
      return [a.id, url] as const
    }),
  )
  qrMap.value = Object.fromEntries(entries)
})

function print() {
  window.print()
}
</script>

<template>
  <div class="pa-4">
    <div class="no-print mb-4 d-flex justify-space-between align-center">
      <div>{{ t('Asset labels') }} ({{ assets.length }})</div>
      <VBtn color="primary" prepend-icon="mdi-printer" @click="print">
        {{ t('Print') }}
      </VBtn>
    </div>

    <div v-if="!assets.length" class="no-print">
      <VAlert type="info" variant="tonal">{{ t('No assets selected.') }}</VAlert>
    </div>

    <div class="labels-grid">
      <div v-for="a in assets" :key="a.id" class="asset-label">
        <img v-if="qrMap[a.id]" :src="qrMap[a.id]" alt="QR code" class="qr" />
        <div class="meta">
          <div class="name">{{ a.name }}</div>
          <div class="number">{{ a.asset_number }}</div>
          <div class="code">{{ a.code }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.labels-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}
.asset-label {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid #cccccc;
  border-radius: 8px;
  padding: 12px;
  background: #ffffff;
  color: #000000;
  break-inside: avoid;
}
.asset-label .qr {
  width: 100px;
  height: 100px;
}
.asset-label .meta .name {
  font-weight: 700;
  font-size: 14px;
  line-height: 1.2;
}
.asset-label .meta .number {
  font-size: 13px;
  margin-top: 3px;
}
.asset-label .meta .code {
  font-family: monospace;
  font-size: 11px;
  margin-top: 5px;
  color: #444444;
}

@media print {
  .no-print { display: none !important; }
  :deep(header),
  :deep(nav),
  :deep(aside) { display: none !important; }
  .labels-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .asset-label { border: 1px solid #000000; }
}
</style>
