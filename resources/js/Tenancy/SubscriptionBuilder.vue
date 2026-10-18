<script setup lang="ts">
import { reactive, computed, watch } from 'vue'

interface PlanT { code: string; name: string; price_monthly: number; regular_price_monthly: number | null; max_users: number | null; ai_token_quota: number | null; sms_quota: number | null; storage_gb: number | null; all_modules: boolean }
interface ModT { code: string; name: string; category: string; price: number }
interface AddonT { type: string; code: string; name: string; unit_label: string | null; units: number | null; price: number | null }
interface Selection { plan: string; modules: string[]; addons: { code: string; quantity: number }[] }

const props = defineProps<{ plans: PlanT[]; moduleCatalog: ModT[]; addonCatalog: AddonT[]; modelValue: Selection }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: Selection): void }>()

const sel = reactive<Selection>({
  plan: props.modelValue.plan || props.plans[0]?.code || '',
  modules: [...(props.modelValue.modules || [])],
  addons: (props.modelValue.addons || []).map(a => ({ ...a })),
})

const money = (n: number) => 'R ' + Number(n || 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const num = (n: number | null) => n === null ? '—' : Number(n).toLocaleString('en-ZA')

const plan = computed(() => props.plans.find(p => p.code === sel.plan) ?? null)
const allIncluded = computed(() => plan.value?.all_modules ?? false)
const modulesByCat = computed(() => {
  const g: Record<string, ModT[]> = {}
  for (const m of props.moduleCatalog) (g[m.category] ||= []).push(m)
  return g
})
const aiPacks = computed(() => props.addonCatalog.filter(a => a.type === 'ai_pack' && a.price != null))
const storagePacks = computed(() => props.addonCatalog.filter(a => a.type === 'storage_pack'))

/**
 * Extra trading businesses for Accounting.
 *
 * Sold per business rather than in packs, so it is a quantity like extra users rather than a
 * pick-one dropdown like the AI and storage packs: somebody opening a third company adds one,
 * and the smallest package is the unit they think in.
 */
const entityAddon = computed(() => props.addonCatalog
  .filter(a => a.type === 'entities' && a.price != null)
  .sort((a, b) => (a.units ?? 1) - (b.units ?? 1))[0] ?? null)
const userAddon = computed(() => props.addonCatalog.find(a => a.type === 'user') ?? null)
const employeeAddon = computed(() => props.addonCatalog.find(a => a.type === 'employee') ?? null)

function qty(code: string) { return sel.addons.find(a => a.code === code)?.quantity ?? 0 }
function setQty(code: string, q: number) {
  q = Math.max(0, Math.floor(Number(q) || 0))
  const ex = sel.addons.find(a => a.code === code)
  if (q <= 0) { sel.addons = sel.addons.filter(a => a.code !== code); return }
  if (ex) ex.quantity = q
  else sel.addons.push({ code, quantity: q })
}
const nonLoginEmployees = computed<number>({
  get: () => qty(employeeAddon.value?.code ?? ''),
  set: v => { if (employeeAddon.value) setQty(employeeAddon.value.code, v) },
})
const extraBusinesses = computed<number>({
  get: () => qty(entityAddon.value?.code ?? ''),
  set: v => { if (entityAddon.value) setQty(entityAddon.value.code, v) },
})
const extraUsers = computed<number>({
  get: () => qty(userAddon.value?.code ?? ''),
  set: v => { if (userAddon.value) setQty(userAddon.value.code, v) },
})

const modulesTotal = computed(() => allIncluded.value ? 0 : sel.modules.reduce((s, c) => s + (props.moduleCatalog.find(m => m.code === c)?.price ?? 0), 0))
const addonsTotal = computed(() => sel.addons.reduce((s, a) => s + (props.addonCatalog.find(x => x.code === a.code)?.price ?? 0) * a.quantity, 0))
const baseTotal = computed(() => plan.value?.price_monthly ?? 0)
const total = computed(() => baseTotal.value + modulesTotal.value + addonsTotal.value)

// Included users vs extra: warn when the plan cap is exceeded (extra users cover it).
const includedUsers = computed(() => plan.value?.max_users ?? null)

// When the plan already includes every module, don't emit à-la-carte module codes
// (they'd otherwise be charged on top of an all-inclusive plan).
watch(sel, () => emit('update:modelValue', {
  plan: sel.plan,
  modules: allIncluded.value ? [] : [...sel.modules],
  addons: sel.addons.map(a => ({ ...a })),
}), { deep: true })
defineExpose({ total })
</script>

<template>
  <div>
    <!-- Base plan -->
    <div class="text-subtitle-2 font-weight-bold mb-2">1 · Base platform <span class="text-caption text-medium-emphasis">(mandatory)</span></div>
    <VRow dense class="mb-2">
      <VCol v-for="p in plans" :key="p.code" cols="6" md="3">
        <VCard :color="sel.plan === p.code ? 'primary' : undefined" :variant="sel.plan === p.code ? 'flat' : 'outlined'" rounded="lg" class="h-100" @click="sel.plan = p.code" style="cursor: pointer">
          <VCardText class="pa-3">
            <div class="d-flex align-center justify-space-between">
              <span class="font-weight-bold">{{ p.name }}</span>
              <VIcon v-if="sel.plan === p.code" icon="tabler-circle-check-filled" size="18" />
            </div>
            <div class="text-h6 font-weight-bold">{{ money(p.price_monthly) }}<span class="text-caption">/mo</span></div>
            <div class="text-caption" :class="sel.plan === p.code ? '' : 'text-medium-emphasis'">
              {{ p.max_users ?? '∞' }} users · {{ num(p.ai_token_quota) }} AI · {{ p.storage_gb ?? '—' }} GB
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Modules -->
    <div class="text-subtitle-2 font-weight-bold mb-1">2 · Modules <span class="text-caption text-medium-emphasis">(à la carte)</span></div>
    <VAlert v-if="allIncluded" type="success" variant="tonal" density="compact" class="mb-3">
      <VIcon start icon="tabler-star-filled" size="16" />This plan includes every module — no à-la-carte selection needed.
    </VAlert>
    <VExpansionPanels v-else variant="accordion" multiple class="mb-3">
      <VExpansionPanel v-for="(mods, cat) in modulesByCat" :key="cat">
        <VExpansionPanelTitle>
          <span class="font-weight-medium">{{ cat }}</span>
          <span class="text-caption text-medium-emphasis ms-2">{{ mods.filter(m => sel.modules.includes(m.code)).length }}/{{ mods.length }}</span>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <VRow dense>
            <VCol v-for="m in mods" :key="m.code" cols="12" sm="6">
              <VCheckbox v-model="sel.modules" :value="m.code" density="compact" hide-details>
                <template #label><span>{{ m.name }} <span class="text-caption text-medium-emphasis">· {{ money(m.price) }}</span></span></template>
              </VCheckbox>
            </VCol>
          </VRow>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- Add-ons -->
    <div class="text-subtitle-2 font-weight-bold mb-2">3 · Add-ons <span class="text-caption text-medium-emphasis">(optional)</span></div>
    <VRow dense class="mb-1">
      <VCol cols="12" md="4">
        <VTextField v-model.number="extraUsers" type="number" min="0" density="compact" variant="outlined"
          :label="`Extra users (${userAddon ? money(userAddon.price ?? 0) : ''}/user)`" hide-details />
        <div v-if="includedUsers !== null" class="text-caption text-medium-emphasis mt-1">
          {{ includedUsers }} included · {{ includedUsers + extraUsers }} total
        </div>
      </VCol>
      <VCol v-if="employeeAddon" cols="12" md="4">
        <VTextField v-model.number="nonLoginEmployees" type="number" min="0" density="compact" variant="outlined"
          :label="`Non-login employees (${money(employeeAddon.price ?? 0)}/employee)`" hide-details />
        <div class="text-caption text-medium-emphasis mt-1">
          Staff who don't sign in (e.g. cleaners) — leave managed by a supervisor
        </div>
      </VCol>
      <VCol cols="12" md="4">
        <VSelect :model-value="aiPacks.find(p => qty(p.code) > 0)?.code ?? null"
          :items="[{ title: 'None', value: null }, ...aiPacks.map(p => ({ title: `${p.name} · +${num(p.units)} · ${money(p.price ?? 0)}`, value: p.code }))]"
          label="AI token pack" density="compact" variant="outlined" hide-details clearable
          @update:model-value="(v:any) => { aiPacks.forEach(p => setQty(p.code, 0)); if (v) setQty(v, 1) }" />
      </VCol>
      <VCol v-if="entityAddon" cols="12" md="4">
        <VTextField v-model.number="extraBusinesses" type="number" min="0" density="compact" variant="outlined"
          :label="`Extra businesses (${money(entityAddon.price ?? 0)}/${entityAddon.units && entityAddon.units > 1 ? `${entityAddon.units} businesses` : 'business'})`" hide-details />
        <div class="text-caption text-medium-emphasis mt-1">
          A second set of books in Accounting — its own customers, chart of accounts and letterhead
        </div>
      </VCol>
      <VCol cols="12" md="4">
        <VSelect :model-value="storagePacks.find(p => qty(p.code) > 0)?.code ?? null"
          :items="[{ title: 'None', value: null }, ...storagePacks.map(p => ({ title: `${p.name} · ${money(p.price ?? 0)}`, value: p.code }))]"
          label="Extra storage" density="compact" variant="outlined" hide-details clearable
          @update:model-value="(v:any) => { storagePacks.forEach(p => setQty(p.code, 0)); if (v) setQty(v, 1) }" />
      </VCol>
    </VRow>

    <!-- Live total -->
    <VDivider class="my-3" />
    <div class="d-flex justify-space-between text-body-2"><span>Base platform ({{ plan?.name }})</span><span>{{ money(baseTotal) }}</span></div>
    <div v-if="!allIncluded && modulesTotal > 0" class="d-flex justify-space-between text-body-2"><span>Modules ({{ sel.modules.length }})</span><span>{{ money(modulesTotal) }}</span></div>
    <div v-if="addonsTotal > 0" class="d-flex justify-space-between text-body-2"><span>Add-ons</span><span>{{ money(addonsTotal) }}</span></div>
    <div class="d-flex justify-space-between text-h6 font-weight-bold mt-1"><span>Total / month</span><span class="text-primary">{{ money(total) }}</span></div>
    <div class="text-caption text-medium-emphasis text-right">excl. VAT</div>
  </div>
</template>
