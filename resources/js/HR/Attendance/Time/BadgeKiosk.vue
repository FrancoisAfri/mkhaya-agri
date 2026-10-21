<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FarmFilter from '@/Modules/Agri/Components/FarmFilter.vue'

/** A shared tablet or a supervisor's phone: scan a badge or tap a name to clock people in and out. */
const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  workers: Array<{ id: number; name: string; crew: string | null; in: boolean }>
  today: any[]
}>()
const search = ref('')
const scanning = ref(false)
const video = ref<HTMLVideoElement | null>(null)
let reader: any = null
let controls: any = null
const keep = { preserveScroll: true }
const clock = (w: { id: number; in: boolean }) => router.post(route('attendance.timesheets.clock'), { worker_id: w.id, kind: w.in ? 'out' : 'in', method: 'kiosk', farm_id: props.farm?.id }, keep)

async function scan() {
  scanning.value = true
  const { BrowserQRCodeReader } = await import('@zxing/browser')
  reader = new BrowserQRCodeReader()
  controls = await reader.decodeFromVideoDevice(undefined, video.value!, (result: any) => {
    if (!result) return
    const badge = String(result.getText())
    stop()
    // The server decides in or out from the worker's open timesheet; the badge only says who.
    router.post(route('attendance.timesheets.clock'), { badge, kind: 'auto', method: 'qr', farm_id: props.farm?.id }, keep)
  })
}
function stop() { controls?.stop(); scanning.value = false }
onBeforeUnmount(stop)
const shown = () => props.workers.filter(w => !search.value || w.name.toLowerCase().includes(search.value.toLowerCase()))
</script>

<template>
  <div class="mx-auto" style="max-width: 900px">
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4 mb-0">Clock in &amp; out</h4><div class="text-body-2 text-medium-emphasis">{{ farm?.name ?? 'Organisation staff' }} · {{ new Date().toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long' }) }}</div></div>
      <div class="d-flex gap-2"><FarmFilter :farms="farms" :model-value="farm?.id ?? 'organisation'" :allow-all="false" organisation />
        <VBtn size="large" color="primary" prepend-icon="tabler-qrcode" @click="scanning ? stop() : scan()">{{ scanning ? 'Stop' : 'Scan badge' }}</VBtn></div>
    </div>
    <VCard v-show="scanning" class="mb-4"><video ref="video" style="width: 100%; max-height: 360px; object-fit: cover" /></VCard>
    <VTextField v-model="search" placeholder="Find a name" prepend-inner-icon="tabler-search" class="mb-3" clearable hide-details />
    <VRow>
      <VCol v-for="w in shown()" :key="w.id" cols="6" sm="4" md="3">
        <VCard :color="w.in ? 'success' : undefined" :variant="w.in ? 'tonal' : 'elevated'" class="text-center" @click="clock(w)">
          <VCardText><div class="text-body-1 font-weight-medium">{{ w.name }}</div><div class="text-caption">{{ w.crew ?? '' }}</div>
            <VChip size="small" class="mt-2" :color="w.in ? 'success' : undefined">{{ w.in ? 'In — tap to clock out' : 'Tap to clock in' }}</VChip></VCardText>
        </VCard>
      </VCol>
    </VRow>
    <VCard v-if="today.length" title="Today" class="mt-4">
      <VTable density="compact"><tbody><tr v-for="t in today" :key="t.id"><td>{{ t.worker }}</td><td>{{ t.in ?? '—' }} → {{ t.out ?? '…' }}</td><td class="text-end">{{ t.hours || '' }}</td></tr></tbody></VTable>
    </VCard>
  </div>
</template>
