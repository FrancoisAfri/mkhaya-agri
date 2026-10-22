<script setup lang="ts">
/**
 * Satellite settings: which provider the daily fetch uses, its credentials (written only — a
 * stored secret is never sent back, a blank field keeps it), cloud limit, daily request budget,
 * how far back a first fetch reaches, and a connection check per provider.
 */
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'

type Settings = { max_cloud_pct: number; daily_budget: number; lookback_days: number; require_plan: boolean; zone_stats: boolean; images: boolean }
type Provider = { key: string; name: string; source: string; active: boolean; configured: boolean; has: Record<string, boolean>; client_id_hint: string | null; settings: Settings; last_test: null | { at: string; ok: boolean | null; message: string | null } }
const props = defineProps<{ providers: Provider[]; usage: null | { used: number; budget: number } }>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.satellite.${key}`, params ?? {})

const forms = reactive(Object.fromEntries(props.providers.map(p => [p.key, {
  active: p.active, client_id: '', client_secret: '', api_key: '', ...p.settings, processing: false,
}]))) as Record<string, any>

function save(p: Provider) {
  const f = forms[p.key]
  f.processing = true
  const { processing, ...data } = f
  router.put(route('agri.satellite.settings.save', p.key), data, {
    preserveScroll: true,
    onSuccess: () => { f.client_id = ''; f.client_secret = ''; f.api_key = '' },
    onFinish: () => { f.processing = false },
  })
}
function check(p: Provider) {
  router.post(route('agri.satellite.settings.check', p.key), {}, { preserveScroll: true })
}
const SIGNUP: Record<string, string> = { copernicus: 'https://dataspace.copernicus.eu', agromonitoring: 'https://agromonitoring.com' }
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-2"><Link :href="route('agri.satellite.index')" class="text-body-2"><VIcon icon="tabler-arrow-left" size="16" /> {{ k('title') }}</Link></div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('settings_title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('settings_subtitle') }}</p>
      </div>
      <VChip v-if="usage" variant="tonal" prepend-icon="tabler-gauge">{{ k('usage_today', { used: usage.used, budget: usage.budget }) }}</VChip>
    </div>

    <VRow>
      <VCol v-for="p in providers" :key="p.key" cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ p.name }}</VCardTitle>
            <VCardSubtitle>{{ k(`about_${p.key}`) }}</VCardSubtitle>
            <template #append><VChip v-if="p.active" color="success" size="small">{{ k('in_use') }}</VChip></template>
          </VCardItem>
          <VCardText>
            <VSwitch v-model="forms[p.key].active" color="primary" :label="k('use_provider')" hide-details class="mb-3" />
            <template v-if="p.key === 'copernicus'">
              <VTextField v-model="forms[p.key].client_id" :label="k('client_id')" :placeholder="p.client_id_hint ?? ''" :hint="p.has.client_id ? k('kept_blank') : ''" persistent-hint autocomplete="off" class="mb-3" />
              <VTextField v-model="forms[p.key].client_secret" :label="k('client_secret')" type="password" :hint="p.has.client_secret ? k('secret_set') : ''" persistent-hint autocomplete="new-password" class="mb-3" />
            </template>
            <VTextField v-else v-model="forms[p.key].api_key" :label="k('api_key')" type="password" :hint="p.has.api_key ? k('secret_set') : ''" persistent-hint autocomplete="new-password" class="mb-3" />
            <div class="text-caption text-medium-emphasis mb-4">{{ k('signup') }} <a :href="SIGNUP[p.key]" target="_blank" rel="noopener">{{ SIGNUP[p.key] }}</a></div>

            <VRow>
              <VCol cols="12" sm="4"><VTextField v-model.number="forms[p.key].max_cloud_pct" type="number" :label="k('max_cloud_pct')" suffix="%" /></VCol>
              <VCol cols="12" sm="4"><VTextField v-model.number="forms[p.key].daily_budget" type="number" :label="k('daily_budget')" :hint="k('daily_budget_hint')" persistent-hint /></VCol>
              <VCol cols="12" sm="4"><VTextField v-model.number="forms[p.key].lookback_days" type="number" :label="k('lookback_days')" :suffix="k('days')" /></VCol>
            </VRow>
            <VCheckbox v-model="forms[p.key].require_plan" :label="k('require_plan')" hide-details density="compact" />
            <VCheckbox v-model="forms[p.key].zone_stats" :label="k('zone_stats')" hide-details density="compact" />
            <VCheckbox v-model="forms[p.key].images" :label="k('images')" hide-details density="compact" />

            <VAlert v-if="p.last_test" :type="p.last_test.ok ? 'success' : 'error'" variant="tonal" density="compact" class="mt-4">
              <div class="text-caption">{{ k('last_check', { at: p.last_test.at }) }}</div>
              {{ p.last_test.message }}
            </VAlert>
          </VCardText>
          <VCardActions>
            <VBtn variant="tonal" prepend-icon="tabler-plug-connected" :disabled="!p.configured" @click="check(p)">{{ k('check') }}</VBtn>
            <VSpacer />
            <VBtn color="primary" :loading="forms[p.key].processing" @click="save(p)">{{ k('save') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
