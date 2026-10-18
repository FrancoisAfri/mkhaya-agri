<script setup lang="ts">
import { ref, onMounted } from 'vue'
import QRCode from 'qrcode'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type LabelAsset = {
  id: number; asset_number: string | null; name: string; code: string
}

const props = defineProps<{ asset: LabelAsset }>()

const qrDataUrl = ref('')

onMounted(async () => {
  // Encode a deep link to the asset so a phone camera opens the record directly;
  // a fixed-camera scanner still reads the embedded code string just fine.
  const payload = `${window.location.origin}/assets/${props.asset.id}`
  qrDataUrl.value = await QRCode.toDataURL(payload, { margin: 1, width: 256 })
})

function print() {
  window.print()
}
</script>

<template>
  <div class="pa-4">
    <div class="no-print mb-4 d-flex justify-end">
      <VBtn color="primary" prepend-icon="mdi-printer" @click="print">
        {{ t('Print') }}
      </VBtn>
    </div>

    <div class="label-sheet">
      <div class="asset-label">
        <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR code" class="qr" />
        <div class="meta">
          <div class="name">{{ asset.name }}</div>
          <div class="number">{{ asset.asset_number }}</div>
          <div class="code">{{ asset.code }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.label-sheet {
  display: flex;
  justify-content: center;
}
.asset-label {
  display: flex;
  align-items: center;
  gap: 16px;
  border: 1px solid #cccccc;
  border-radius: 8px;
  padding: 16px;
  width: 320px;
  background: #ffffff;
  color: #000000;
}
.asset-label .qr {
  width: 120px;
  height: 120px;
}
.asset-label .meta .name {
  font-weight: 700;
  font-size: 16px;
  line-height: 1.2;
}
.asset-label .meta .number {
  font-size: 14px;
  margin-top: 4px;
}
.asset-label .meta .code {
  font-family: monospace;
  font-size: 12px;
  margin-top: 6px;
  color: #444444;
}

@media print {
  .no-print { display: none !important; }
  :deep(header),
  :deep(nav),
  :deep(aside) { display: none !important; }
  .asset-label { border: 1px solid #000000; }
}
</style>
