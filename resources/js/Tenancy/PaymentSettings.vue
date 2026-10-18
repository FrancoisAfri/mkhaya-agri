<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Gateway { id: number; code: string; label: string; is_enabled: boolean; is_active: boolean; mode: string; fields: string[]; configured: Record<string, boolean> }
const props = defineProps<{ gateways: Gateway[] }>()

const fieldLabel: Record<string, string> = {
  merchant_id: 'Merchant ID', merchant_key: 'Merchant Key', passphrase: 'Passphrase',
  paygate_id: 'PayGate ID', encryption_key: 'Encryption Key',
  site_code: 'Site Code', private_key: 'Private Key', api_key: 'API Key',
}

// One form per gateway (credential inputs blank = keep existing).
const forms = reactive<Record<number, any>>({})
for (const g of props.gateways) {
  forms[g.id] = useForm({ mode: g.mode, is_enabled: g.is_enabled, credentials: Object.fromEntries(g.fields.map(f => [f, ''])) })
}

const confirmRef = ref()
function save(g: Gateway) { forms[g.id].put(route('tenancy.payment-settings.update', g.id), { preserveScroll: true }) }
function activate(g: Gateway) {
  confirmRef.value.open(
    { title: `Make ${g.label} the active gateway?`, message: `All company payments will be processed through ${g.label} (${forms[g.id].mode} mode). Any other active gateway is switched off.`, confirmText: 'Set active', confirmColor: 'primary', icon: 'tabler-credit-card' },
    () => router.post(route('tenancy.payment-settings.activate', g.id), {}, { preserveScroll: true }),
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 1100px">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Payment Settings</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Configure SA payment gateways. Enable the ones you use and mark one active — companies check out through it (card + Instant EFT).</p>
      </div>
      <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
    </div>

    <VRow>
      <VCol v-for="g in props.gateways" :key="g.id" cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100" :color="g.is_active ? 'primary' : undefined" :variant="g.is_active ? 'tonal' : 'elevated'">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="text-h6 font-weight-bold">{{ g.label }}</div>
              <VChip v-if="g.is_active" size="small" color="primary" label>Active</VChip>
            </div>

            <VSelect v-model="forms[g.id].mode" :items="[{title:'Test / Sandbox',value:'test'},{title:'Live',value:'live'}]" item-title="title" item-value="value" label="Mode" density="compact" class="mb-3" />
            <VSwitch v-model="forms[g.id].is_enabled" label="Enabled" color="success" hide-details class="mb-3" />

            <div class="text-caption text-medium-emphasis mb-2">Credentials <span class="text-disabled">(leave blank to keep saved value)</span></div>
            <VTextField v-for="f in g.fields" :key="f" v-model="forms[g.id].credentials[f]"
              :label="fieldLabel[f] || f" density="compact" class="mb-2"
              :placeholder="g.configured[f] ? '•••••• saved' : 'not set'"
              :prepend-inner-icon="g.configured[f] ? 'tabler-check' : 'tabler-alert-circle'" />

            <div class="d-flex gap-2 mt-2">
              <VBtn color="primary" size="small" :loading="forms[g.id].processing" @click="save(g)">Save</VBtn>
              <VBtn v-if="!g.is_active" size="small" variant="tonal" :disabled="!g.is_enabled" @click="activate(g)">Set active</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
