<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, num, statusColor, today } from '../Components/agri'
import { animalFields, groupFields, healthFields, type LivestockOptions } from './fields'

const props = defineProps<{
  groups: any[]; animals: any[]; health: any[]
  filters: { farm: string | null; status: string; search: string; tab: string }
  options: LivestockOptions
  can: { manage: boolean; health: boolean }
}>()

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
          </VList>
        </VMenu>
      </div>
    </div>

    <VCard>
      <VTabs v-model="tab">
        <VTab value="groups">Herds &amp; flocks <VChip size="x-small" class="ms-2">{{ groups.length }}</VChip></VTab>
        <VTab value="animals">Animals</VTab>
        <VTab value="health">Health register</VTab>
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
            <thead><tr><th>Date</th><th>Animal / group</th><th>Type</th><th>Product</th><th>Withdrawal until</th><th>Next due</th></tr></thead>
            <tbody>
              <tr v-for="h in health" :key="h.id">
                <td>{{ h.treated_on }}</td>
                <td><Link v-if="h.animal_id" :href="route('agri.livestock.animals.show', h.animal_id)">{{ h.animal }}</Link><span v-else>{{ h.group }} <span class="text-caption">({{ h.head_count }} head)</span></span><div class="text-caption">{{ h.farm }}</div></td>
                <td>{{ human(h.event_type) }}<div class="text-caption">{{ h.condition }}</div></td>
                <td>{{ h.product_name ?? '—' }}<div class="text-caption">{{ h.dose }}</div></td>
                <td><VChip v-if="h.withdrawal_until" :color="h.in_withdrawal ? 'warning' : 'secondary'" size="small" variant="tonal">{{ h.withdrawal_until }}</VChip><span v-else>—</span></td>
                <td>{{ h.next_due_on ?? '—' }}</td>
              </tr>
              <tr v-if="!health.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No health records yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <FormDialog :model-value="dialog === 'group'" title="New herd or flock" :fields="groupFields(options)" :url="route('agri.livestock.groups.store')" :values="defaults()" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'animal'" title="Register an animal" :fields="animalFields(options)" :url="route('agri.livestock.animals.store')" :values="defaults()" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'health'" title="Health record" :fields="healthFields(options)" :url="route('agri.livestock.health.store')" :values="{ ...defaults(), treated_on: today() }" @update:model-value="close" />
  </div>
</template>
