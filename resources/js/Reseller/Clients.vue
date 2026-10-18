<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ResellerNav from './ResellerNav.vue'
import SubscriptionBuilder from '@/Tenancy/SubscriptionBuilder.vue'
const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

interface Client { id: number; name: string; status: string; plan: string; contact_email: string | null; monthly_total: number; created_at: string | null }
interface PlanT { code: string; name: string; price_monthly: number; regular_price_monthly: number | null; max_users: number | null; ai_token_quota: number | null; sms_quota: number | null; storage_gb: number | null; all_modules: boolean }
interface ModT { code: string; name: string; category: string; price: number }
interface AddonT { type: string; code: string; name: string; unit_label: string | null; units: number | null; price: number | null }
interface Selection { plan: string; modules: string[]; addons: { code: string; quantity: number }[] }
const props = defineProps<{ clients: Client[]; plans: PlanT[]; moduleCatalog: ModT[]; addonCatalog: AddonT[] }>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const credentials = computed<any>(() => (page.props.flash as any)?.credentials ?? null)

const trialOptions = [
  { title: t('reseller_portal.trial_30'), value: 30 },
  { title: t('reseller_portal.trial_14'), value: 14 },
  { title: t('reseller_portal.trial_7'), value: 7 },
  { title: t('reseller_portal.trial_0'), value: 0 },
]

const dialog = ref(false)
const defaultPlan = () => props.plans[0]?.code ?? 'starter'
const form = useForm<any>({ company_name: '', registration_number: '', admin_name: '', email: '', phone: '', password: '', plan: defaultPlan(), trial_days: 30, modules: [] as string[], addons: [] as any[] })
const composition = reactive<Selection>({ plan: defaultPlan(), modules: [], addons: [] })

function openNew() {
  form.reset(); form.clearErrors()
  composition.plan = defaultPlan(); composition.modules = []; composition.addons = []
  dialog.value = true
}
function submit() {
  form.transform(d => ({ ...d, plan: composition.plan, modules: composition.modules, addons: composition.addons }))
    .post(route('reseller.clients.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
}

const headers = [
  { title: t('reseller_portal.client'), key: 'name' },
  { title: t('reseller_portal.plan'), key: 'plan' },
  { title: t('reseller_portal.monthly'), key: 'monthly_total', align: 'end' as const },
  { title: t('reseller_portal.status'), key: 'status' },
  { title: t('reseller_portal.since'), key: 'created_at' },
]
</script>

<template>
  <div class="pa-4">
    <ResellerNav />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('reseller_portal.my_clients') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('reseller_portal.clients_subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('reseller_portal.add_client') }}</VBtn>
    </div>

    <VAlert v-if="credentials" type="success" variant="tonal" class="mb-4" closable>
      <div class="font-weight-medium">{{ credentials.title }}</div>
      <div class="text-body-2">{{ t('reseller_portal.email') }}: <strong>{{ credentials.email }}</strong> · {{ t('reseller_portal.password') }}: <strong>{{ credentials.password }}</strong></div>
      <div class="text-caption">{{ credentials.login_url }}</div>
    </VAlert>

    <VCard elevation="0" border rounded="lg">
      <VDataTable :headers="headers" :items="clients" :items-per-page="25" density="comfortable">
        <template #item.monthly_total="{ item }">{{ money(item.monthly_total) }}</template>
        <template #item.status="{ item }"><VChip size="x-small" :color="item.status === 'active' ? 'success' : item.status === 'trial' ? 'info' : 'error'" variant="tonal">{{ item.status }}</VChip></template>
        <template #no-data><div class="text-center py-8 text-medium-emphasis">{{ t('reseller_portal.no_clients') }}</div></template>
      </VDataTable>
    </VCard>

    <!-- Add client — mirrors the platform-admin "New company" form (fields + Subscription Builder) -->
    <VDialog v-model="dialog" max-width="900" scrollable>
      <VCard>
        <VCardTitle>{{ t('reseller_portal.add_client') }}</VCardTitle>
        <VCardSubtitle class="pb-2">{{ t('reseller_portal.add_client_hint') }}</VCardSubtitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="form.company_name" :label="t('reseller_portal.f_company')" variant="outlined" density="comfortable" :error-messages="form.errors.company_name" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.registration_number" :label="t('reseller_portal.f_reg')" variant="outlined" density="comfortable" :error-messages="form.errors.registration_number" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.admin_name" :label="t('reseller_portal.f_admin')" variant="outlined" density="comfortable" :error-messages="form.errors.admin_name" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.email" type="email" :label="t('reseller_portal.f_email')" variant="outlined" density="comfortable" :error-messages="form.errors.email" /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model="form.phone" :label="t('reseller_portal.f_phone')" variant="outlined" density="comfortable" :error-messages="form.errors.phone" /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model="form.password" type="text" :label="t('reseller_portal.f_password')" variant="outlined" density="comfortable" :error-messages="form.errors.password" /></VCol>
            <VCol cols="12" sm="4"><VSelect v-model="form.trial_days" :items="trialOptions" :label="t('reseller_portal.f_trial')" variant="outlined" density="comfortable" :error-messages="form.errors.trial_days" /></VCol>
          </VRow>

          <VDivider class="my-3" />

          <SubscriptionBuilder v-if="dialog" v-model="composition" :plans="plans" :module-catalog="moduleCatalog" :addon-catalog="addonCatalog" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('reseller_portal.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.company_name || !form.email || !form.password" @click="submit">{{ t('reseller_portal.create_client') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
