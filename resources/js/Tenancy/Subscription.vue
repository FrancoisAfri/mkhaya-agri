<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import SubscriptionBuilder from './SubscriptionBuilder.vue'
const route = ziggyRoute
const confirmRef = ref()

interface Selection { plan: string; modules: string[]; addons: { code: string; quantity: number }[] }
interface PlanT { code: string; name: string; price_monthly: number; regular_price_monthly: number | null; max_users: number | null; ai_token_quota: number | null; sms_quota: number | null; storage_gb: number | null; all_modules: boolean }
interface ModT { code: string; name: string; category: string; price: number }
interface AddonT { type: string; code: string; name: string; unit_label: string | null; units: number | null; price: number | null }
const props = defineProps<{
  company: { id: number; name: string; status: string; trial_ends_at: string | null }
  snapshot: { total: number }
  plans: PlanT[]
  moduleCatalog: ModT[]
  addonCatalog: AddonT[]
  composition: Selection
}>()

const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })

const selection = reactive<Selection>({
  plan: props.composition.plan,
  modules: [...(props.composition.modules || [])],
  addons: (props.composition.addons || []).map(a => ({ ...a })),
})

const form = useForm<any>({})
function save() {
  confirmRef.value.open(
    { title: 'Update subscription?', message: 'The new monthly total applies to your next invoice.', confirmText: 'Update', confirmColor: 'primary', icon: 'tabler-package' },
    () => form.transform(() => ({ plan: selection.plan, modules: selection.modules, addons: selection.addons }))
      .post(route('tenancy.subscription.compose'), { preserveScroll: true }),
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 1100px">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Manage Subscription</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ props.company.name }} · <span class="text-capitalize">{{ props.company.status }}</span>
          <span v-if="props.company.trial_ends_at"> · trial ends {{ props.company.trial_ends_at }}</span>
        </p>
      </div>
      <VCard elevation="0" border rounded="lg">
        <VCardText class="d-flex align-center gap-4 py-3">
          <div><div class="text-caption text-medium-emphasis">Current total</div><div class="text-h5 font-weight-bold text-primary">{{ money(props.snapshot.total) }}</div></div>
          <VBtn color="primary" prepend-icon="tabler-credit-card" @click="router.visit('/subscription/pay')">Pay / Manage payment</VBtn>
        </VCardText>
      </VCard>
    </div>

    <VCard elevation="0" border rounded="lg">
      <VCardText class="pa-4 pa-sm-5">
        <SubscriptionBuilder v-model="selection" :plans="plans" :module-catalog="moduleCatalog" :addon-catalog="addonCatalog" />
        <VDivider class="my-4" />
        <div class="d-flex justify-end">
          <VBtn color="primary" size="large" :loading="form.processing" prepend-icon="tabler-check" @click="save">Update subscription</VBtn>
        </div>
      </VCardText>
    </VCard>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
