<script setup lang="ts">
import { computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Mod { id: number; code: string; name: string; category: string | null; price_monthly: number; is_sellable: boolean; is_active: boolean; websites_per_month: number | null; backlinks_per_month: number | null }
interface Addon { id: number; type: string; code: string; name: string; unit_label: string; units: number; price_monthly: number; is_active: boolean }
const props = defineProps<{ modules: Mod[]; addons: Addon[] }>()

/**
 * A colour and a readable name per add-on type.
 *
 * The chip used to be "primary if backlinks, else green", which was fine while those were the only
 * two types and quietly wrong once there were six — every other add-on rendered as though it were
 * a website package.
 */
const TYPE_STYLES: Record<string, { label: string; colour: string }> = {
  backlinks:    { label: 'Backlinks',    colour: 'primary' },
  websites:     { label: 'Websites',     colour: 'success' },
  entities:     { label: 'Businesses',   colour: 'indigo' },
  user:         { label: 'Users',        colour: 'teal' },
  employee:     { label: 'Employees',    colour: 'cyan' },
  ai_pack:      { label: 'AI tokens',    colour: 'purple' },
  storage_pack: { label: 'Storage',      colour: 'orange' },
}

function typeLabel(type: string) {
  return TYPE_STYLES[type]?.label ?? type.replace(/_/g, ' ')
}

function typeColour(type: string) {
  return TYPE_STYLES[type]?.colour ?? 'grey'
}

const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })

// Finance module codes → finance i18n keys (localised in all 11 SA languages).
const { t, te } = useI18n()
const FIN_KEY: Record<string, string> = {
  accounting: 'accounting', payroll: 'payroll', payroll_bureau: 'payroll_bureau',
  claims: 'claims', staff_loans: 'staff_loans', petty_cash: 'petty_cash', budget_management: 'budget',
}
function label(code: string, fallback: string) {
  const k = FIN_KEY[code]
  return k && te(`fin.${k}`) ? t(`fin.${k}`) : fallback
}

const grouped = computed(() => {
  const g: Record<string, Mod[]> = {}
  for (const m of props.modules) { (g[m.category || 'Other'] ??= []).push(m) }
  return g
})

function save(m: Mod) {
  router.put(route('tenancy.modules.update', m.id), {
    price_monthly: m.price_monthly, is_sellable: m.is_sellable, is_active: m.is_active,
    websites_per_month: m.websites_per_month, backlinks_per_month: m.backlinks_per_month,
  }, { preserveScroll: true })
}
function saveAddon(a: Addon) {
  router.put(route('tenancy.modules.addons.update', a.id), {
    name: a.name, units: a.units, price_monthly: a.price_monthly, is_active: a.is_active,
  }, { preserveScroll: true })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Modules &amp; Pricing</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Set the individual monthly price for each module. Companies can buy modules à la carte or as part of a bundle (Plans).</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
        <Link :href="route('tenancy.plans.index')"><VBtn variant="tonal" prepend-icon="tabler-package">Bundles (Plans)</VBtn></Link>
      </div>
    </div>

    <VCard v-for="(mods, category) in grouped" :key="category" elevation="0" border rounded="lg" class="mb-4">
      <VCardItem><VCardTitle class="text-subtitle-1">{{ category }}</VCardTitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>Module</th><th style="width:150px">Price / month</th><th style="width:130px">Websites / mo</th><th style="width:130px">Backlinks / mo</th><th style="width:100px">Sellable</th><th style="width:100px">Active</th><th style="width:90px"></th></tr></thead>
        <tbody>
          <tr v-for="m in mods" :key="m.id">
            <td class="font-weight-medium">{{ label(m.code, m.name) }}<div class="text-caption text-medium-emphasis">{{ m.code }}</div></td>
            <td>
              <VTextField v-model.number="m.price_monthly" type="number" density="compact" variant="outlined" hide-details prefix="R" :disabled="!m.is_sellable" style="max-width:130px" />
            </td>
            <td>
              <VTextField v-if="m.code === 'content_marketing'" v-model.number="m.websites_per_month" type="number" density="compact" variant="outlined" hide-details style="max-width:110px" />
              <span v-else class="text-disabled">—</span>
            </td>
            <td>
              <VTextField v-if="m.code === 'content_marketing'" v-model.number="m.backlinks_per_month" type="number" density="compact" variant="outlined" hide-details style="max-width:110px" />
              <span v-else class="text-disabled">—</span>
            </td>
            <td><VSwitch v-model="m.is_sellable" color="primary" hide-details density="compact" /></td>
            <td><VSwitch v-model="m.is_active" color="success" hide-details density="compact" /></td>
            <td><VBtn size="small" color="primary" variant="tonal" @click="save(m)">Save</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Add-on packages: extra backlinks & extra websites -->
    <VCard v-if="addons.length" elevation="0" border rounded="lg" class="mb-4">
      <VCardItem>
        <VCardTitle class="text-subtitle-1">Add-on Packages</VCardTitle>
        <template #subtitle>Sold on top of a subscription — extra users, businesses, AI tokens, storage, websites and backlinks.</template>
      </VCardItem>
      <VTable density="compact">
        <thead><tr><th>Package</th><th style="width:120px">Type</th><th style="width:130px">Units</th><th style="width:160px">Price / month</th><th style="width:100px">Active</th><th style="width:90px"></th></tr></thead>
        <tbody>
          <tr v-for="a in addons" :key="a.id">
            <td><VTextField v-model="a.name" density="compact" variant="outlined" hide-details style="max-width:220px" /><div class="text-caption text-medium-emphasis">{{ a.code }}</div></td>
            <td><VChip size="small" variant="tonal" :color="typeColour(a.type)" class="text-capitalize">{{ typeLabel(a.type) }}</VChip></td>
            <td>
              <VTextField v-model.number="a.units" type="number" density="compact" variant="outlined" hide-details style="max-width:90px" />
              <div class="text-caption text-medium-emphasis mt-1">{{ a.unit_label }}</div>
            </td>
            <td><VTextField v-model.number="a.price_monthly" type="number" density="compact" variant="outlined" hide-details prefix="R" style="max-width:130px" /></td>
            <td><VSwitch v-model="a.is_active" color="success" hide-details density="compact" /></td>
            <td><VBtn size="small" color="primary" variant="tonal" @click="saveAddon(a)">Save</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
