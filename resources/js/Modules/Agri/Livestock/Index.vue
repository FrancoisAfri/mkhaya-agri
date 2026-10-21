<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, num, statusColor, today } from '../Components/agri'
import { animalFields, feedFields, groupFields, healthFields, produceFields, type LivestockOptions } from './fields'

const props = defineProps<{
  groups: any[]; animals: any[]; health: any[]
  composition: any[]; vaccinations_due: any[]; rations: any[]; programmes: any[]; production: any[]
  filters: { farm: string | null; status: string; search: string; tab: string }
  options: LivestockOptions
  can: { manage: boolean; health: boolean; view_health?: boolean; release?: boolean; finance?: boolean }
}>()
const keep = { preserveScroll: true }
const editing = ref<any>(null)
const clearRecord = (h: any) => { const n = prompt('How was the release verified (test result, vet, reference)?'); if (n) router.post(route('agri.livestock.health.clear', h.id), { note: n }, keep) }
const lotAction = (l: any, action: 'sell' | 'discard') => {
  if (action === 'sell') { const v = prompt(`Sale value for ${l.quantity} ${l.unit} ${l.product} (${l.lot_code})?`); if (v) router.post(route('agri.livestock.production.update', l.id), { action, value: Number(v) }, keep) }
  else { const r = prompt('Why is it discarded?'); if (r) router.post(route('agri.livestock.production.update', l.id), { action, reason: r }, keep) }
}
// A ration is edited as rows of item + share; the shares add up to 100.
const rationForm = ref<{ id?: string; name: string; species_id: number | null; items: Array<{ stock_item_id: number | null; share_pct: number | null }> } | null>(null)
const rationTotal = () => (rationForm.value?.items ?? []).reduce((a, i) => a + Number(i.share_pct || 0), 0)
const saveRation = () => {
  const r = rationForm.value!
  const body = { name: r.name, species_id: r.species_id, items: r.items.filter(i => i.stock_item_id && i.share_pct) }
  r.id ? router.patch(route('agri.livestock.rations.update', r.id), body, { ...keep, onSuccess: () => { rationForm.value = null } })
    : router.post(route('agri.livestock.rations.store'), body, { ...keep, onSuccess: () => { rationForm.value = null } })
}

const tab = ref(props.filters.tab || 'groups')
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const search = ref(props.filters.search)
let t: ReturnType<typeof setTimeout> | undefined
const reload = (extra: Record<string, any> = {}) => router.get(route('agri.livestock.index'),
  { farm: props.filters.farm || undefined, status: props.filters.status, search: search.value || undefined, tab: 'animals', ...extra }, { preserveState: true, replace: true })
watch(search, () => { clearTimeout(t); t = setTimeout(() => reload(), 350) })
const defaults = () => ({ farm_id: props.filters.farm ?? props.options.farms.find(f => f.status === 'active')?.id })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Livestock</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Herds, flocks and individual animals — health, weights, breeding and exits.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VMenu>
          <template #activator="{ props: p }"><VBtn v-bind="p" color="primary" prepend-icon="tabler-plus">Record</VBtn></template>
          <VList density="compact">
            <VListItem v-if="can.manage" prepend-icon="tabler-users-group" title="New herd / flock" @click="dialog = 'group'" />
            <VListItem v-if="can.manage" prepend-icon="tabler-tag" title="Register an animal" @click="dialog = 'animal'" />
            <VListItem v-if="can.health" prepend-icon="tabler-vaccine" title="Health / treatment" @click="dialog = 'health'" />
            <VListItem v-if="can.manage || can.health" prepend-icon="tabler-bowl" title="Feeding" @click="dialog = 'feed'" />
            <VListItem v-if="can.manage || can.health" prepend-icon="tabler-milk" title="Milk, eggs, wool" @click="dialog = 'produce'" />
          </VList>
        </VMenu>
      </div>
    </div>

    <VCard>
      <VTabs v-model="tab">
        <VTab value="groups">Herds &amp; flocks <VChip size="x-small" class="ms-2">{{ groups.length }}</VChip></VTab>
        <VTab value="animals">Animals</VTab>
        <VTab v-if="can.view_health" value="health">Health register</VTab>
        <VTab value="herd">Herd planning</VTab>
        <VTab value="vaccinations">Vaccinations <VChip v-if="vaccinations_due.filter(v => v.overdue).length" size="x-small" color="error" class="ms-2">{{ vaccinations_due.filter(v => v.overdue).length }}</VChip></VTab>
        <VTab value="production">Production</VTab>
        <VTab value="rations">Feed rations</VTab>
      </VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="groups">
          <VTable density="comfortable">
            <thead><tr><th>Group</th><th>Species</th><th>Farm / camp</th><th>Purpose</th><th class="text-end">Head</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="g in groups" :key="g.id">
                <td><Link :href="route('agri.livestock.groups.show', g.id)" class="font-weight-medium">{{ g.code }}</Link><div class="text-caption">{{ g.name }}</div></td>
                <td>{{ g.species }}</td>
                <td>{{ g.farm }}<div class="text-caption">{{ g.field ?? '—' }}</div></td>
                <td>{{ human(g.purpose) }}</td>
                <td class="text-end"><strong>{{ num(g.head, 0) }}</strong></td>
                <td><VChip :color="statusColor(g.status)" size="small" variant="tonal">{{ human(g.status) }}</VChip></td>
              </tr>
              <tr v-if="!groups.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No herds or flocks yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="animals">
          <VCardText class="d-flex gap-3 flex-wrap">
            <VTextField v-model="search" placeholder="Tag or name" prepend-inner-icon="tabler-search" density="compact" hide-details clearable style="max-width: 280px" />
            <VBtnToggle :model-value="filters.status" density="compact" variant="outlined" divided mandatory @update:model-value="v => reload({ status: v })">
              <VBtn value="active">Active</VBtn><VBtn value="exited">Exited</VBtn><VBtn value="all">All</VBtn>
            </VBtnToggle>
          </VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Tag</th><th>Species / breed</th><th>Sex</th><th>Age</th><th>Group / camp</th><th class="text-end">Last weight</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="a in animals" :key="a.id">
                <td><Link :href="route('agri.livestock.animals.show', a.id)" class="font-weight-medium">{{ a.tag_number }}</Link><div v-if="a.name" class="text-caption">{{ a.name }}</div></td>
                <td>{{ a.species }}<div class="text-caption">{{ a.breed ?? '' }}</div></td>
                <td>{{ human(a.sex) }}</td>
                <td>{{ a.age_months !== null ? (a.age_months >= 24 ? Math.floor(a.age_months / 12) + ' y' : a.age_months + ' m') : '—' }}</td>
                <td>{{ a.group ?? '—' }}<div class="text-caption">{{ a.farm }} {{ a.field ? '· ' + a.field : '' }}</div></td>
                <td class="text-end">{{ a.last_weight_kg ? num(a.last_weight_kg, 0) + ' kg' : '—' }}</td>
                <td><VChip :color="statusColor(a.status)" size="small" variant="tonal">{{ human(a.status) }}</VChip></td>
              </tr>
              <tr v-if="!animals.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No animals match.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="health">
          <VTable density="comfortable">
            <thead><tr><th>Date</th><th>Animal / group</th><th>Type</th><th>Product</th><th>Withdrawal until</th><th>Next due</th><th>Release</th></tr></thead>
            <tbody>
              <tr v-for="h in health" :key="h.id">
                <td>{{ h.treated_on }}</td>
                <td><Link v-if="h.animal_id" :href="route('agri.livestock.animals.show', h.animal_id)">{{ h.animal }}</Link><span v-else>{{ h.group }} <span class="text-caption">({{ h.head_count }} head)</span></span><div class="text-caption">{{ h.farm }}</div></td>
                <td>{{ human(h.event_type) }}<div class="text-caption">{{ h.condition }}</div></td>
                <td>{{ h.product_name ?? '—' }}<div class="text-caption">{{ h.dose }}</div></td>
                <td><VChip v-if="h.withdrawal_until" :color="h.in_withdrawal ? 'warning' : 'secondary'" size="small" variant="tonal">{{ h.withdrawal_until }}</VChip><span v-else>—</span></td>
                <td>{{ h.next_due_on ?? '—' }}</td>
                <td><template v-if="h.requires_clearance"><VChip v-if="h.cleared_at" size="x-small" color="success" :title="h.clearance_note">Verified</VChip>
                  <VBtn v-else-if="can.release" size="x-small" variant="tonal" color="warning" @click="clearRecord(h)">Verify release</VBtn><VChip v-else size="x-small" color="warning">Awaiting release</VChip></template>
                  <VChip v-if="h.notifiable" size="x-small" color="error" class="ms-1">Notifiable</VChip></td>
              </tr>
              <tr v-if="!health.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No health records yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="herd">
          <VCardText v-for="c in composition" :key="c.farm + c.species">
            <div class="text-h6 mb-2">{{ c.species }} · {{ c.farm }} <span class="text-body-2">({{ c.total }} head)</span></div>
            <VRow dense>
              <VCol cols="6" md="3"><div class="text-caption">Breeding females</div><div class="text-h5">{{ c.breeding_females }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption">Replacements coming on</div><div class="text-h5">{{ c.replacements_coming }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption">Due to cull (age)</div><div class="text-h5" :class="c.due_to_cull ? 'text-warning' : ''">{{ c.due_to_cull }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption">Replacements needed</div><div class="text-h5" :class="c.replacement_gap ? 'text-error' : ''">{{ c.replacement_gap ?? '—' }}</div>
                <div class="text-caption">{{ c.target ? `target ${c.target} head` : 'set a target on the herd' }}</div></VCol>
            </VRow>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <VChip v-for="(n, k) in c.by_sex" :key="k" size="small" variant="outlined">{{ human(String(k)) }} {{ n }}</VChip>
              <VChip v-for="(n, k) in c.by_age" v-show="n" :key="k" size="small" variant="tonal">{{ k }}: {{ n }}</VChip>
            </div>
          </VCardText>
          <VCardText v-if="!composition.length" class="text-medium-emphasis">Herd make-up appears once individual animals are registered.</VCardText>
        </VWindowItem>

        <VWindowItem value="vaccinations">
          <VCardText class="d-flex justify-space-between align-center flex-wrap gap-2"><span class="text-body-2 text-medium-emphasis">Due in the next 30 days under the active programmes.</span>
            <VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="editing = { kind: 'programme' }">Programme</VBtn></VCardText>
          <VTable density="compact">
            <thead><tr><th>Due</th><th>Who</th><th>Programme</th><th>Farm</th></tr></thead>
            <tbody><tr v-for="(v, i) in vaccinations_due" :key="i" :class="v.overdue ? 'text-error' : ''"><td>{{ v.due_on }}<VChip v-if="v.overdue" size="x-small" color="error" class="ms-1">overdue</VChip></td>
              <td>{{ v.who }}</td><td>{{ v.programme }}</td><td>{{ v.farm }}</td></tr>
              <tr v-if="!vaccinations_due.length"><td colspan="4" class="text-center text-medium-emphasis py-4">Nothing due.</td></tr></tbody>
          </VTable>
          <VCardText><div class="text-subtitle-2 mb-1">Programmes</div>
            <VChip v-for="p in programmes" :key="p.id" class="me-2 mb-2" variant="outlined" @click="can.manage && (editing = { kind: 'programme', row: p })">
              {{ p.name }} · first at {{ p.first_at_age_days ?? '—' }} d · every {{ p.repeat_every_days ?? '—' }} d<template v-if="!p.is_active"> · off</template></VChip></VCardText>
        </VWindowItem>

        <VWindowItem value="production">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>From</th><th>Product</th><th class="text-end">Quantity</th><th>Lot</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="l in production" :key="l.id"><td>{{ l.recorded_on }}</td><td>{{ l.animal ?? l.group }}</td><td>{{ human(l.product) }}<span v-if="l.grade" class="text-caption"> · {{ l.grade }}</span></td>
                <td class="text-end">{{ num(l.quantity) }} {{ l.unit }}</td><td class="text-caption">{{ l.lot_code }}</td>
                <td><VChip v-if="l.withheld && l.status === 'recorded'" size="x-small" color="error" :title="l.withheld_reason">Withheld</VChip><VChip v-else size="x-small" :color="statusColor(l.status)">{{ human(l.status) }}</VChip></td>
                <td class="text-end text-no-wrap"><template v-if="can.manage && l.status === 'recorded'">
                  <VBtn v-if="!l.withheld" size="x-small" variant="tonal" @click="lotAction(l, 'sell')">Sell</VBtn>
                  <VBtn size="x-small" variant="text" @click="lotAction(l, 'discard')">Discard</VBtn></template></td></tr>
              <tr v-if="!production.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No production recorded.</td></tr>
            </tbody>
          </VTable>
          <VCardText class="text-caption text-medium-emphasis">Production from animals under withdrawal, in quarantine or awaiting a verified release is withheld: it cannot be sold, only discarded.</VCardText>
        </VWindowItem>

        <VWindowItem value="rations">
          <VCardText class="d-flex justify-end"><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="rationForm = { name: '', species_id: null, items: [{ stock_item_id: null, share_pct: null }] }">Ration</VBtn></VCardText>
          <VList lines="two"><VListItem v-for="r in rations" :key="r.id" :title="r.name" :subtitle="r.items.map((i: any) => `${i.share_pct}% ${i.item}`).join(' · ')"
            @click="can.manage && (rationForm = { id: r.id, name: r.name, species_id: r.species_id, items: r.items.map((i: any) => ({ stock_item_id: i.stock_item_id, share_pct: i.share_pct })) })" />
            <VListItem v-if="!rations.length" title="No rations yet" subtitle="A ration splits each feeding into its components, each issued from the farm store." /></VList>
        </VWindowItem>
      </VWindow>
    </VCard>
    <FormDialog :model-value="dialog === 'feed'" title="Feeding" :fields="feedFields(options, {})" :url="route('agri.livestock.feedings.store')" :values="{ ...defaults(), fed_on: today() }"
      intro="Issued from the farm store to the group's costs; a ration is split by its shares." @update:model-value="close" />
    <FormDialog :model-value="dialog === 'produce'" title="Milk, eggs, wool" :fields="produceFields(options, {})" :url="route('agri.livestock.production.store')" :values="{ ...defaults(), recorded_on: today(), product: 'milk', unit: 'l' }" @update:model-value="close" />
    <FormDialog v-if="editing?.kind === 'programme'" :model-value="true" :title="editing.row ? 'Edit programme' : 'New vaccination programme'" :method="editing.row ? 'patch' : 'post'"
      :url="editing.row ? route('agri.livestock.programmes.update', editing.row.uuid) : route('agri.livestock.programmes.store')" :values="editing.row ?? { applies_to: 'all', is_active: true }" :fields="[
        { key: 'species_id', label: 'Species', type: 'select', required: true, items: options.species.map(s => ({ title: s.name, value: s.id })) },
        { key: 'name', label: 'Programme', required: true }, { key: 'product', label: 'Product' },
        { key: 'stock_item_id', label: 'Stock item', type: 'autocomplete', items: options.stock_items.map(i => ({ title: i.name, value: i.id })) },
        { key: 'applies_to', label: 'For', type: 'select', items: [{ title: 'All', value: 'all' }, { title: 'Females', value: 'female' }, { title: 'Males', value: 'male' }] },
        { key: 'first_at_age_days', label: 'First dose at age (days)', type: 'number' }, { key: 'repeat_every_days', label: 'Then every (days)', type: 'number' },
        { key: 'withdrawal_days', label: 'Withdrawal (days)', type: 'number' }, { key: 'is_active', label: 'Active', type: 'switch' },
      ]" @update:model-value="v => !v && (editing = null)" />
    <VDialog :model-value="!!rationForm" max-width="620" @update:model-value="v => !v && (rationForm = null)">
      <VCard v-if="rationForm" :title="rationForm.id ? 'Edit ration' : 'New ration'">
        <VCardText>
          <VRow dense><VCol cols="8"><VTextField v-model="rationForm.name" label="Name *" /></VCol>
            <VCol cols="4"><VSelect v-model="rationForm.species_id" :items="options.species.map(s => ({ title: s.name, value: s.id }))" label="Species" clearable /></VCol></VRow>
          <VRow v-for="(it, i) in rationForm.items" :key="i" dense class="align-center">
            <VCol cols="7"><VAutocomplete v-model="it.stock_item_id" :items="options.stock_items.map(x => ({ title: x.name, value: x.id }))" label="Feed" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model.number="it.share_pct" type="number" label="% of weight" density="compact" hide-details /></VCol>
            <VCol cols="2"><VBtn icon="tabler-x" size="small" variant="text" @click="rationForm.items.splice(i, 1)" /></VCol>
          </VRow>
          <div class="d-flex justify-space-between align-center mt-2"><VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="rationForm.items.push({ stock_item_id: null, share_pct: null })">Component</VBtn>
            <span :class="Math.abs(rationTotal() - 100) > 0.01 ? 'text-error' : 'text-success'">Total {{ rationTotal() }}%</span></div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="rationForm = null">Cancel</VBtn><VBtn color="primary" :disabled="!rationForm.name || Math.abs(rationTotal() - 100) > 0.01" @click="saveRation">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog :model-value="dialog === 'group'" title="New herd or flock" :fields="groupFields(options)" :url="route('agri.livestock.groups.store')" :values="defaults()" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'animal'" title="Register an animal" :fields="animalFields(options)" :url="route('agri.livestock.animals.store')" :values="defaults()" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'health'" title="Health record" :fields="healthFields(options)" :url="route('agri.livestock.health.store')" :values="{ ...defaults(), treated_on: today() }" @update:model-value="close" />
  </div>
</template>
