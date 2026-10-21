<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog from '@/Modules/Agri/Components/FormDialog.vue'
import FarmFilter from '@/Modules/Agri/Components/FarmFilter.vue'
import RowMenu from '@/Modules/Agri/Components/RowMenu.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, money, statusColor, today } from '@/Modules/Agri/Components/agri'

const props = defineProps<{
  workers: any[]; crews: any[]
  filters: { farm: string | null; status: string; tab: string }
  options: { farms: Array<{ id: string; name: string }>; categories: string[]; rule_sets: Array<{ id: number; name: string }>; crews: any[]; people: Array<{ id: number; name: string }>; competencies: any[] }
  can: { manage: boolean }
}>()
const tab = ref(props.filters.tab || 'workers')
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const open = (k: string, w: any = null) => { target.value = w; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }
const keep = { preserveScroll: true }

const pasted = ref('')
const bulk = ref({ farm_id: (props.filters.farm === 'organisation' ? null : props.filters.farm) ?? props.options.farms[0]?.id ?? null, category: 'seasonal', contract_start: today(), contract_end: '', hourly_cost_rate: null as number | null, crew_id: null })
const bulkErrors = ref<Record<string, string>>({})
/** "First Surname, phone, ID" — one person per line, as pasted from a spreadsheet or a list. */
const parsed = () => pasted.value.split('\n').map(l => l.trim()).filter(Boolean).map(l => {
  const [name, phone, id] = l.split(/[,\t;]/).map(x => x.trim())
  const parts = (name ?? '').split(/\s+/)
  return { first_name: parts.shift() ?? '', surname: parts.join(' '), phone_number: phone || null, id_number: id || null }
})
function registerMany() {
  router.post(route('attendance.people.bulk'), { ...bulk.value, contract_end: bulk.value.contract_end || null, people: parsed() }, {
    preserveScroll: true, onSuccess: () => { dialog.value = null; pasted.value = '' }, onError: e => (bulkErrors.value = e),
  })
}
function setStatus(w: any, status: string) {
  const reason = prompt(`Reason for setting ${w.name} ${status}?`)
  if (reason) router.post(route('attendance.people.status', w.uuid), { status, reason }, keep)
}
function renewBadge(w: any) { if (confirm(`Issue ${w.name} a new badge? The old one stops working.`)) router.post(route('attendance.people.badge.renew', w.uuid), {}, keep) }
const badgeUrl = (w: any) => route('attendance.people.badge', w.uuid)
const filter = (status: string) => router.get(route('attendance.people.index'), { farm: props.filters.farm || undefined, status }, { preserveState: false })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Workforce</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Everyone whose time is kept — the organisation's staff and each farm's permanent, seasonal and casual workers. Personal details live in HR; this is how their time is worked and paid.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" organisation />
        <VMenu v-if="can.manage">
          <template #activator="{ props: p }"><VBtn v-bind="p" color="primary" prepend-icon="tabler-user-plus">Add</VBtn></template>
          <VList density="compact">
            <VListItem prepend-icon="tabler-users-plus" title="Register a season's workers" @click="open('bulk')" />
            <VListItem prepend-icon="tabler-user-check" title="Add an existing employee" @click="open('enlist')" />
            <VListItem prepend-icon="tabler-users-group" title="New crew" @click="open('crew')" />
          </VList>
        </VMenu>
      </div>
    </div>
    <WorkforceTabs current="workforce" />

    <VCard>
      <VTabs v-model="tab"><VTab value="workers">Workers <VChip size="x-small" class="ms-2">{{ workers.length }}</VChip></VTab><VTab value="crews">Crews</VTab></VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="workers">
          <VCardText class="d-flex gap-2">
            <VChip v-for="s in ['active', 'suspended', 'terminated', 'all']" :key="s" size="small" :variant="filters.status === s ? 'flat' : 'tonal'" :color="filters.status === s ? 'primary' : undefined" @click="filter(s)">{{ human(s) }}</VChip>
          </VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Worker</th><th>Category</th><th>Site / crew</th><th>Contract</th><th class="text-end">Cost rate</th><th>Consent</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="w in workers" :key="w.uuid">
                <td><strong>{{ w.name }}</strong><div class="text-caption">{{ w.employee_number }}<template v-if="w.phone"> · {{ w.phone }}</template><template v-if="!w.has_login"> · no login</template></div>
                  <div v-if="w.job_title || w.emergency_contact" class="text-caption"><template v-if="w.job_title">{{ w.job_title }}</template><template v-if="w.emergency_contact"> · <VIcon icon="tabler-sos" size="12" /> {{ w.emergency_contact }}</template></div></td>
                <td>{{ human(w.category) }}<div class="text-caption">{{ w.rule_set ?? 'Default rules' }}</div></td>
                <td>{{ w.farm ?? 'Organisation' }}<div class="text-caption">{{ w.crew ?? '—' }}</div></td>
                <td class="text-caption">{{ w.contract_start ?? '—' }} → {{ w.contract_end ?? 'open' }}<div>{{ w.contract_type }}</div></td>
                <td class="text-end">{{ w.hourly_cost_rate ? money(w.hourly_cost_rate) + '/h' : '—' }}</td>
                <td class="text-caption"><VIcon :icon="w.gps_allowed ? 'tabler-map-pin-check' : 'tabler-map-pin-off'" size="16" /> <VIcon :icon="w.biometric_allowed ? 'tabler-fingerprint' : 'tabler-fingerprint-off'" size="16" /></td>
                <td><VChip :color="statusColor(w.status)" size="small" variant="tonal">{{ human(w.status) }}</VChip></td>
                <td class="text-end"><RowMenu :actions="[
                  { title: 'Edit', icon: 'tabler-edit', show: can.manage, onClick: () => open('edit', w) },
                  { title: 'Print QR badge', icon: 'tabler-qrcode', show: can.manage, onClick: () => open('badge', w) },
                  { title: 'Record consent', icon: 'tabler-shield-check', show: can.manage, onClick: () => open('consent', w) },
                  { title: 'Suspend', icon: 'tabler-player-pause', show: can.manage && w.status === 'active', onClick: () => setStatus(w, 'suspended') },
                  { title: 'Reactivate', icon: 'tabler-player-play', show: can.manage && w.status !== 'active', onClick: () => setStatus(w, 'active') },
                  { title: 'Terminate', icon: 'tabler-user-x', color: 'error', show: can.manage && w.status !== 'terminated', onClick: () => setStatus(w, 'terminated') },
                ]" /></td>
              </tr>
              <tr v-if="!workers.length"><td colspan="8" class="text-center text-medium-emphasis py-6">No workers here yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
        <VWindowItem value="crews">
          <VTable density="comfortable">
            <thead><tr><th>Crew</th><th>Site</th><th class="text-end">People</th></tr></thead>
            <tbody>
              <tr v-for="c in crews" :key="c.uuid"><td><strong>{{ c.code }}</strong> {{ c.name }}</td><td>{{ c.farm ?? 'Organisation' }}</td><td class="text-end">{{ c.workers }}</td></tr>
              <tr v-if="!crews.length"><td colspan="3" class="text-center text-medium-emphasis py-6">No crews yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <VDialog :model-value="dialog === 'bulk'" max-width="760" @update:model-value="close">
      <VCard title="Register a season's workers">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">One person per line: <em>First Surname, phone, ID number</em> (phone and ID optional). Each becomes an HR employee without a login, within your plan's non-login allowance, and a farm worker.</p>
          <VRow>
            <VCol cols="12" sm="6"><VSelect v-model="bulk.farm_id" :items="[{ title: 'Organisation staff (no site)', value: null }, ...options.farms.map(f => ({ title: f.name, value: f.id }))]" label="Site" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="bulk.category" :items="options.categories.map(c => ({ title: human(c), value: c }))" label="Category *" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model="bulk.contract_start" type="date" label="Contract start" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model="bulk.contract_end" type="date" label="Contract end" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="bulk.hourly_cost_rate" type="number" label="Cost rate / h" /></VCol>
            <VCol cols="6" sm="3"><VSelect v-model="bulk.crew_id" :items="options.crews.map(c => ({ title: c.code, value: c.id }))" label="Crew" clearable /></VCol>
            <VCol cols="12"><VTextarea v-model="pasted" rows="8" label="People" placeholder="Thabo Mokoena, 0821234567&#10;Lindiwe Dube, 0839876543" :error-messages="bulkErrors.people" /></VCol>
          </VRow>
          <div class="text-caption">{{ parsed().length }} people ready.</div>
          <VAlert v-if="Object.keys(bulkErrors).length" type="error" variant="tonal" density="compact" class="mt-2"><div v-for="(e, k) in bulkErrors" :key="k">{{ e }}</div></VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = null">Cancel</VBtn><VBtn color="primary" :disabled="!parsed().length" @click="registerMany">Register {{ parsed().length }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog :model-value="dialog === 'enlist'" title="Add an employee to Time & Attendance" :url="route('attendance.people.store')" :values="{ farm_id: filters.farm === 'organisation' ? null : (filters.farm ?? null), category: 'permanent' }" :fields="[
      { key: 'farm_id', label: 'Site', type: 'select', items: [{ title: 'Organisation staff (no site)', value: null }, ...options.farms.map(f => ({ title: f.name, value: f.id }))] },
      { key: 'hr_person_id', label: 'Employee (from HR)', type: 'autocomplete', required: true, items: options.people.map(p => ({ title: p.name, value: p.id })) },
      { key: 'category', label: 'Category', type: 'select', required: true, items: options.categories },
      { key: 'crew_id', label: 'Crew', type: 'select', items: options.crews.map(c => ({ title: c.code + ' · ' + c.name, value: c.id })) },
      { key: 'labour_rule_set_id', label: 'Labour rules', type: 'select', items: options.rule_sets.map(r => ({ title: r.name, value: r.id })), hint: 'Blank = the default for the category' },
      { key: 'hourly_cost_rate', label: 'Costing rate per hour', type: 'number', hint: 'For labour costing; payroll decides pay' },
      { key: 'contract_start', label: 'Contract start', type: 'date' }, { key: 'contract_end', label: 'Contract end', type: 'date' },
      { key: 'contract_type', label: 'Contract type' },
    ]" @update:model-value="close" />
    <FormDialog v-if="target" :model-value="dialog === 'edit'" :title="`Edit ${target.name}`" method="patch" :url="route('attendance.people.update', target.uuid)" :values="target" :fields="[
      { key: 'category', label: 'Category', type: 'select', required: true, items: options.categories },
      { key: 'crew_id', label: 'Crew', type: 'select', items: options.crews.map(c => ({ title: c.code + ' · ' + c.name, value: c.id })) },
      { key: 'labour_rule_set_id', label: 'Labour rules', type: 'select', items: options.rule_sets.map(r => ({ title: r.name, value: r.id })) },
      { key: 'hourly_cost_rate', label: 'Costing rate per hour', type: 'number' },
      { key: 'contract_start', label: 'Contract start', type: 'date' }, { key: 'contract_end', label: 'Contract end', type: 'date' },
      { key: 'contract_type', label: 'Contract type' },
    ]" @update:model-value="close" />
    <FormDialog v-if="target" :model-value="dialog === 'consent'" :title="`Consent — ${target.name}`" :url="route('attendance.people.consent', target.uuid)" :values="target"
      intro="Location and biometric data are only kept for workers who agreed to it. Record what they agreed to today; it can be withdrawn the same way." :fields="[
        { key: 'gps_allowed', label: 'Location may be recorded when clocking', type: 'switch', cols: 12 },
        { key: 'biometric_allowed', label: 'Biometric clocking may be used', type: 'switch', cols: 12 },
      ]" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'crew'" title="New crew" :url="route('attendance.crews.store')" :values="{ farm_id: filters.farm === 'organisation' ? null : (filters.farm ?? null) }" :fields="[
      { key: 'farm_id', label: 'Site', type: 'select', items: [{ title: 'Organisation staff (no site)', value: null }, ...options.farms.map(f => ({ title: f.name, value: f.id }))] },
      { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
    ]" @update:model-value="close" />
    <VDialog :model-value="dialog === 'badge'" max-width="380" @update:model-value="close">
      <VCard v-if="target" :title="target.name">
        <VCardText class="text-center">
          <img :src="badgeUrl(target)" alt="QR badge" style="width: 240px; height: 240px" />
          <div class="text-body-2 mt-2">{{ target.employee_number }} · {{ human(target.category) }}</div>
          <div class="text-caption text-medium-emphasis">Scan at a badge kiosk to clock in and out.</div>
        </VCardText>
        <VCardActions><VBtn variant="text" prepend-icon="tabler-refresh" @click="renewBadge(target)">New badge</VBtn><VSpacer /><VBtn variant="text" @click="dialog = null">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
