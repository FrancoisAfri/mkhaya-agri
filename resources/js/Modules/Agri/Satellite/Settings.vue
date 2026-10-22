<script setup lang="ts">
/**
 * Satellite settings: which provider the daily fetch uses, its credentials (written only — a
 * stored secret is never sent back, a blank field keeps it), cloud limit, daily request budget,
 * how far back a first fetch reaches, and a connection check per provider.
 */
import { reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'

type Settings = { max_cloud_pct: number; daily_budget: number; lookback_days: number; require_plan: boolean; zone_stats: boolean; images: boolean }
type Provider = { key: string; name: string; source: string; active: boolean; configured: boolean; has: Record<string, boolean>; client_id_hint: string | null; settings: Settings; last_test: null | { at: string; ok: boolean | null; message: string | null } }
type DropRule = { id?: string; crop_type_id: number | null; crop?: string | null; category?: string | null; activity_types: string[]; after_harvest: boolean; within_days: number; is_active?: boolean; note: string | null }
const props = defineProps<{ providers: Provider[]; usage: null | { used: number; budget: number }; drop_rules: DropRule[]; default_drop_rules: DropRule[];
  crop_types: Array<{ id: number; name: string }>; activity_types: string[]; can_rules: boolean }>()

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
const d = (key: string, params?: Record<string, unknown>) => t(`agri.drop_rules.${key}`, params ?? {})
const ruleDialog = ref<null | { row: DropRule | null }>(null)
const ruleFields: FormField[] = [
  { key: 'crop_type_id', label: d('crop'), type: 'select', items: [{ title: d('any_crop'), value: null }, ...props.crop_types.map(c => ({ title: c.name, value: c.id }))] },
  { key: 'activity_types', label: d('activities'), type: 'multiselect', items: props.activity_types.map(a => ({ title: d(`activity_${a}`), value: a })) },
  { key: 'within_days', label: d('within_days'), type: 'number', required: true, cols: 6 }, { key: 'after_harvest', label: d('after_harvest'), type: 'switch', cols: 6 },
  { key: 'is_active', label: d('active'), type: 'switch', cols: 6 }, { key: 'note', label: d('note'), cols: 12 },
]
const removeRule = (r: DropRule) => { if (r.id && confirm(d('confirm_remove'))) router.delete(route('agri.satellite.drop-rules.destroy', r.id), { preserveScroll: true }) }
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

    <VCard class="mt-6" :title="d('title')" :subtitle="d('subtitle')">
      <VCardText>
        <VTable density="compact">
          <thead><tr><th>{{ d('crop') }}</th><th>{{ d('activities') }}</th><th>{{ d('within_days') }}</th><th>{{ d('note') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="(r, i) in (drop_rules.length ? drop_rules : default_drop_rules)" :key="r.id ?? `default-${i}`" :class="r.is_active === false ? 'text-disabled' : ''">
              <td>{{ r.crop ?? (r.category ? d(`category_${r.category}`) : d('any_crop')) }}</td>
              <td>{{ [...r.activity_types.map(a => d(`activity_${a}`)), ...(r.after_harvest ? [d('a_harvest')] : [])].join(', ') }}</td>
              <td>{{ d('days', { n: r.within_days }) }}</td>
              <td class="text-caption">{{ r.note }}</td>
              <td class="text-end text-no-wrap"><template v-if="can_rules && r.id">
                <VBtn size="x-small" variant="tonal" @click="ruleDialog = { row: r }">{{ d('edit') }}</VBtn>
                <VBtn size="x-small" variant="text" color="error" @click="removeRule(r)">{{ d('remove') }}</VBtn></template></td>
            </tr>
          </tbody>
        </VTable>
        <p v-if="!drop_rules.length" class="text-caption text-medium-emphasis mt-2 mb-0">{{ d('using_defaults') }}</p>
      </VCardText>
      <VCardActions v-if="can_rules"><VBtn variant="tonal" prepend-icon="tabler-plus" @click="ruleDialog = { row: null }">{{ d('add') }}</VBtn></VCardActions>
    </VCard>
    <FormDialog v-if="ruleDialog" :model-value="true" :title="ruleDialog.row ? d('edit') : d('add')" :method="ruleDialog.row ? 'patch' : 'post'"
      :url="ruleDialog.row?.id ? route('agri.satellite.drop-rules.update', ruleDialog.row.id) : route('agri.satellite.drop-rules.store')" :fields="ruleFields"
      :values="ruleDialog.row ? { ...ruleDialog.row } : { crop_type_id: null, activity_types: ['mowing', 'harvesting'], after_harvest: true, within_days: 21, is_active: true }"
      @update:model-value="(v: boolean) => { if (!v) ruleDialog = null }" />
  </div>
</template>
