<script setup lang="ts">
import QRCode from 'qrcode'
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  vehicle: {
    id: number
    registration_number: string
    make: string
    model: string
    year: number
    color: string | null
  }
  qrValue: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)

onMounted(async () => {
  if (canvas.value) {
    await QRCode.toCanvas(canvas.value, props.qrValue, {
      width: 240,
      margin: 2,
      color: { dark: '#000000', light: '#ffffff' },
    })
  }
})

function downloadQr() {
  if (!canvas.value) return
  const link = document.createElement('a')
  link.download = `${props.vehicle.registration_number}-qr.png`
  link.href = canvas.value.toDataURL()
  link.click()
}

function printQr() {
  const dataUrl = canvas.value?.toDataURL()
  if (!dataUrl) return
  const win = window.open('', '_blank')
  win?.document.write(`
    <html><head><title>QR – ${props.vehicle.registration_number}</title></head>
    <body style="text-align:center;padding:40px;">
      <h2>${props.vehicle.registration_number}</h2>
      <p>${props.vehicle.year} ${props.vehicle.make} ${props.vehicle.model}</p>
      <img src="${dataUrl}" width="240" />
      <script>window.onload=()=>{ window.print(); window.close(); }<\/script>
    </body></html>
  `)
  win?.document.close()
}
</script>

<template>
  <div class="d-flex align-center justify-center" style="min-height:100vh;background:#f5f5f5;">
    <VCard class="pa-6 text-center" max-width="420" elevation="0" border>
      <VCardTitle class="text-h5 mb-2">
        {{ vehicle.registration_number }}
      </VCardTitle>
      <VCardSubtitle class="mb-4">
        {{ t('fleet_vehicles_qr.subtitle', { year: vehicle.year, make: vehicle.make, model: vehicle.model }) }}
        <span v-if="vehicle.color"> · {{ vehicle.color }}</span>
      </VCardSubtitle>

      <div class="d-flex justify-center mb-5">
        <canvas ref="canvas" width="240" height="240" />
      </div>

      <VCardText class="text-caption text-medium-emphasis mb-2">
        {{ t('fleet_vehicles_qr.scan_hint') }}
      </VCardText>

      <VCardActions class="justify-center">
        <VBtn color="primary" prepend-icon="ri-download-2-line" @click="downloadQr">
          {{ t('fleet_vehicles_qr.download') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="ri-printer-line" @click="printQr">
          {{ t('fleet_vehicles_qr.print') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
