<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Snap { unlimited: boolean; quota: number; used: number; remaining: number | null; percent: number; low: boolean; exceeded: boolean; blocked: boolean; credit: number }
interface StorageSnap { unlimited: boolean; quota: number; used: number; files: number; data: number; remaining: number | null; percent: number; low: boolean; exceeded: boolean; packs: number; credits: number }
type Kind = 'ai' | 'sms' | 'storage'
const props = defineProps<{
  company: { id: number; name: string; plan: string }
  ai: Snap & { rand_spent: number }
  sms: Snap
  storage: StorageSnap
  requests: { id: number; type: string; amount: number; mode: string; total_price: number | null; status: string; note: string | null; created_at: string }[]
  pricing: { ai: { pack: number; price: number }; sms: { pack: number; price: number }; storage: { pack: number; price: number } }
}>()

const num = (n: any) => Number(n ?? 0).toLocaleString()
const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// Human-readable bytes (storage values come through in bytes).
const gb = (bytes: any) => {
  const b = Number(bytes ?? 0)
  if (b >= 1073741824) return (b / 1073741824).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' GB'
  if (b >= 1048576) return (b / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MB'
  if (b >= 1024) return (b / 1024).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' KB'
  return b + ' B'
}
const barColor = (s: { exceeded: boolean; low: boolean }) => s.exceeded ? 'error' : (s.low ? 'warning' : 'success')
const statusColor: Record<string, string> = { pending: 'warning', approved: 'success', granted: 'success', rejected: 'error' }
const typeLabel: Record<string, string> = { ai: 'AI tokens', sms: 'SMS', storage: 'Storage (GB)' }

// Buy dialog
const buyOpen = ref(false)
const buyType = ref<Kind>('ai')
const buyForm = useForm({ type: 'ai', packs: 1 })
const buyPack = computed(() => props.pricing[buyType.value])
const buyUnit = computed(() => buyType.value === 'ai' ? 'tokens' : buyType.value === 'sms' ? 'SMS' : 'GB')
const buyTotal = computed(() => (buyForm.packs || 0) * buyPack.value.price)
function openBuy(type: Kind) { buyType.value = type; buyForm.type = type; buyForm.packs = 1; buyOpen.value = true }
function submitBuy() { buyForm.post(route('tenancy.usage.buy'), { preserveScroll: true, onSuccess: () => { buyOpen.value = false } }) }

// Request dialog
const reqOpen = ref(false)
const reqType = ref<Kind>('ai')
const reqForm = useForm({ type: 'ai', amount: 100000, mode: 'request', note: '' })
const reqUnit = computed(() => reqType.value === 'ai' ? 'Tokens needed' : reqType.value === 'sms' ? 'SMS needed' : 'GB needed')
function openReq(type: Kind) { reqType.value = type; reqForm.type = type; reqForm.amount = type === 'ai' ? 100000 : type === 'sms' ? 100 : 5; reqForm.mode = 'request'; reqForm.note = ''; reqOpen.value = true }
function submitReq() { reqForm.post(route('tenancy.usage.request'), { preserveScroll: true, onSuccess: () => { reqOpen.value = false } }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 1100px">
    <div class="mb-6">
      <h3 class="text-h5 font-weight-bold mb-1">Usage &amp; Add-ons</h3>
      <p class="text-body-2 text-medium-emphasis mb-0">AI, SMS &amp; storage allowance for {{ props.company.name }} · plan: {{ props.company.plan }}</p>
    </div>

    <VRow>
      <!-- AI -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-subtitle-1 font-weight-bold"><VIcon icon="tabler-robot" class="me-2" color="primary" />AI tokens</div>
              <VChip v-if="ai.unlimited" color="primary" variant="tonal" size="small" label>Unlimited</VChip>
            </div>
            <template v-if="!ai.unlimited">
              <VProgressLinear :model-value="ai.percent" :color="barColor(ai)" height="10" rounded class="mb-2" />
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ num(ai.used) }} / {{ num(ai.quota) }} used</span>
                <span class="font-weight-bold">{{ num(ai.remaining) }} left</span>
              </div>
              <div class="text-caption text-medium-emphasis mb-3">
                ≈ {{ money(ai.rand_spent) }} spent this month<span v-if="ai.credit"> · includes {{ num(ai.credit) }} top-up</span>
              </div>
              <VAlert v-if="ai.blocked" type="error" variant="tonal" density="compact" class="mb-3">AI is paused — you've used your monthly allowance.</VAlert>
              <VAlert v-else-if="ai.low" type="warning" variant="tonal" density="compact" class="mb-3">Running low on AI tokens this month.</VAlert>
            </template>
            <div class="d-flex gap-2">
              <VBtn color="primary" size="small" prepend-icon="tabler-shopping-cart" @click="openBuy('ai')">Buy tokens</VBtn>
              <VBtn variant="tonal" size="small" prepend-icon="tabler-send" @click="openReq('ai')">Request more</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- SMS -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-subtitle-1 font-weight-bold"><VIcon icon="tabler-message" class="me-2" color="info" />SMS</div>
              <VChip v-if="sms.unlimited" color="info" variant="tonal" size="small" label>Unlimited</VChip>
            </div>
            <template v-if="!sms.unlimited">
              <VProgressLinear :model-value="sms.percent" :color="barColor(sms)" height="10" rounded class="mb-2" />
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ num(sms.used) }} / {{ num(sms.quota) }} used</span>
                <span class="font-weight-bold">{{ num(sms.remaining) }} left</span>
              </div>
              <div class="text-caption text-medium-emphasis mb-3"><span v-if="sms.credit">includes {{ num(sms.credit) }} top-up</span><span v-else>&nbsp;</span></div>
              <VAlert v-if="sms.blocked" type="error" variant="tonal" density="compact" class="mb-3">SMS paused — non-critical messages held until you top up.</VAlert>
              <VAlert v-else-if="sms.low" type="warning" variant="tonal" density="compact" class="mb-3">Running low on SMS this month.</VAlert>
            </template>
            <div class="d-flex gap-2">
              <VBtn color="info" size="small" prepend-icon="tabler-shopping-cart" @click="openBuy('sms')">Buy SMS</VBtn>
              <VBtn variant="tonal" size="small" prepend-icon="tabler-send" @click="openReq('sms')">Request more</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Storage -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-subtitle-1 font-weight-bold"><VIcon icon="tabler-database" class="me-2" color="deep-purple" />Storage</div>
              <VChip v-if="storage.unlimited" color="deep-purple" variant="tonal" size="small" label>Unlimited</VChip>
            </div>
            <template v-if="!storage.unlimited">
              <VProgressLinear :model-value="storage.percent" :color="barColor(storage)" height="10" rounded class="mb-2" />
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ gb(storage.used) }} / {{ gb(storage.quota) }} used</span>
                <span class="font-weight-bold">{{ gb(storage.remaining) }} left</span>
              </div>
              <div class="text-caption text-medium-emphasis mb-3">
                <span><i class="tabler-database-cog" /> {{ gb(storage.data) }} data · {{ gb(storage.files) }} files</span>
                <span v-if="storage.packs || storage.credits"> · +{{ gb(storage.packs + storage.credits) }} bought</span>
              </div>
              <VAlert v-if="storage.exceeded" type="error" variant="tonal" density="compact" class="mb-3">Storage is full — new uploads are blocked until you free up or buy more.</VAlert>
              <VAlert v-else-if="storage.low" type="warning" variant="tonal" density="compact" class="mb-3">Storage is running low. Buy more to keep uploading.</VAlert>
            </template>
            <div class="d-flex gap-2">
              <VBtn color="deep-purple" size="small" prepend-icon="tabler-shopping-cart" @click="openBuy('storage')">Buy storage</VBtn>
              <VBtn variant="tonal" size="small" prepend-icon="tabler-send" @click="openReq('storage')">Request more</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg" class="mt-4">
      <VCardItem><VCardTitle class="text-subtitle-1">Recent requests &amp; purchases</VCardTitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>When</th><th>Type</th><th class="text-end">Amount</th><th>Kind</th><th class="text-end">Price</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="r in props.requests" :key="r.id">
            <td class="text-caption">{{ r.created_at }}</td>
            <td>{{ typeLabel[r.type] ?? r.type }}</td>
            <td class="text-end">{{ num(r.amount) }}</td>
            <td>{{ r.mode }}</td>
            <td class="text-end">{{ r.total_price !== null ? money(r.total_price) : '—' }}</td>
            <td><VChip size="small" :color="statusColor[r.status] ?? 'secondary'" variant="tonal" label>{{ r.status }}</VChip></td>
          </tr>
          <tr v-if="!props.requests.length"><td colspan="6" class="text-center py-8 text-medium-emphasis">No requests yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Buy dialog -->
    <VDialog v-model="buyOpen" max-width="440">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 text-h6 font-weight-bold">Buy {{ typeLabel[buyType] }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <p class="text-body-2 text-medium-emphasis mb-4">Each pack = {{ num(buyPack.pack) }} {{ buyUnit }} for {{ money(buyPack.price) }}. <span v-if="buyType === 'storage'">Added to your storage allowance permanently.</span><span v-else>Added to this month's allowance immediately.</span></p>
          <VTextField v-model.number="buyForm.packs" label="Packs" type="number" min="1" max="100" />
          <div class="d-flex justify-space-between text-body-1 font-weight-bold mt-2">
            <span>{{ num((buyForm.packs || 0) * buyPack.pack) }} {{ buyUnit }}</span>
            <span>{{ money(buyTotal) }}</span>
          </div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3"><VSpacer /><VBtn variant="tonal" @click="buyOpen = false">Cancel</VBtn><VBtn color="primary" :loading="buyForm.processing" @click="submitBuy">Buy now</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Request dialog -->
    <VDialog v-model="reqOpen" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 text-h6 font-weight-bold">Request more {{ typeLabel[reqType] }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VTextField v-model.number="reqForm.amount" :label="reqUnit" type="number" class="mb-2" />
          <VSelect v-model="reqForm.mode" :items="[{title:'Just ask (admin approves)',value:'request'},{title:'Add to my subscription (billed)',value:'subscription'}]" item-title="title" item-value="value" label="How" class="mb-2" />
          <VTextarea v-model="reqForm.note" label="Note (optional)" rows="2" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3"><VSpacer /><VBtn variant="tonal" @click="reqOpen = false">Cancel</VBtn><VBtn color="primary" :loading="reqForm.processing" @click="submitReq">Submit request</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
