<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, statusColor, today } from '../Components/agri'
import { countFields, healthFields, weightFields, type LivestockOptions } from './fields'

const props = defineProps<{
  group: any; animals: any[]; changes: any[]; health: any[]; weights: any[]
  options: LivestockOptions
  can: { manage: boolean; health: boolean }
}>()
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const isFlock = props.group.tracking === 'group'
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.livestock.index')">Livestock</Link> / {{ group.farm }}</div>
        <h4 class="text-h4 mb-1">{{ group.code }} · {{ group.name }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ group.species }} · {{ human(group.purpose) }} · camp {{ group.field ?? '—' }}</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip :color="statusColor(group.status)" variant="tonal">{{ human(group.status) }}</VChip>
        <VBtn v-if="can.health" color="primary" prepend-icon="tabler-vaccine" @click="dialog = 'health'">Treat group</VBtn>
        <RowMenu size="default" :actions="[
          { title: 'Change head count', icon: 'tabler-plus-minus', show: can.manage && isFlock, onClick: () => (dialog = 'count') },
          { title: 'Record group weight', icon: 'tabler-scale', show: can.manage || can.health, onClick: () => (dialog = 'weight') },
          { title: 'Edit group', icon: 'tabler-edit', show: can.manage, onClick: () => (dialog = 'edit') },
        ]" />
      </div>
    </div>

    <VAlert v-if="group.withdrawal_until" type="warning" variant="tonal" class="mb-4" icon="tabler-alert-triangle">
      Withdrawal period: animals from this group may not be sold or slaughtered before <strong>{{ group.withdrawal_until }}</strong>.
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Head</div><div class="text-h4">{{ num(group.head, 0) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Tracked</div><div class="text-h6">{{ isFlock ? 'As a flock (count)' : 'Individually' }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Budget</div><div class="text-h6">{{ money(group.budget_amount) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Since</div><div class="text-h6">{{ group.started_on ?? '—' }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard v-if="!isFlock" title="Animals">
          <VTable density="compact">
            <thead><tr><th>Tag</th><th>Breed</th><th>Sex</th><th class="text-end">Weight</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="a in animals" :key="a.id">
                <td><Link :href="route('agri.livestock.animals.show', a.id)">{{ a.tag_number }}</Link></td><td>{{ a.breed ?? '—' }}</td><td>{{ human(a.sex) }}</td>
                <td class="text-end">{{ a.last_weight_kg ? num(a.last_weight_kg, 0) + ' kg' : '—' }}</td>
                <td><VChip :color="statusColor(a.status)" size="x-small" variant="tonal">{{ human(a.status) }}</VChip></td>
              </tr>
              <tr v-if="!animals.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No animals in this group.</td></tr>
            </tbody>
          </VTable>
        </VCard>
        <VCard v-else title="Head count history">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>Reason</th><th class="text-end">Change</th><th class="text-end">Count after</th><th>Notes</th></tr></thead>
            <tbody>
              <tr v-for="c in changes" :key="c.id">
                <td>{{ c.changed_on }}</td><td>{{ human(c.reason) }}</td>
                <td class="text-end" :class="c.delta < 0 ? 'text-error' : 'text-success'">{{ c.delta > 0 ? '+' : '' }}{{ c.delta }}</td>
                <td class="text-end">{{ num(c.count_after, 0) }}</td><td class="text-caption">{{ c.notes }}<span v-if="c.value"> · {{ money(c.value) }}</span></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Health" class="mb-4">
          <VList v-if="health.length" lines="two" density="compact">
            <VListItem v-for="h in health" :key="h.id" :title="`${h.treated_on} · ${human(h.event_type)}`" :subtitle="[h.product_name, h.dose, h.animal].filter(Boolean).join(' · ')">
              <template #append><VChip v-if="h.in_withdrawal" color="warning" size="x-small">WHP {{ h.withdrawal_until }}</VChip></template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">No treatments recorded.</VCardText>
        </VCard>
        <VCard v-if="weights.length" title="Weighings">
          <VList density="compact"><VListItem v-for="w in weights" :key="w.weighed_on" :title="w.weighed_on"><template #append>{{ num(w.weight_kg, 1) }} kg avg · {{ w.head_count }} head</template></VListItem></VList>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog :model-value="dialog === 'health'" :title="`Treat ${group.code}`" :fields="healthFields(options, { farm_id: group.farm_id, group_id: group.id })"
      :values="{ farm_id: group.farm_id, group_id: group.id, treated_on: today(), head_count: group.head }" :url="route('agri.livestock.health.store')" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'count'" title="Change head count" :fields="countFields(options)" :values="{ changed_on: today() }"
      :url="route('agri.livestock.groups.count', group.id)" intro="Every change needs a reason; the count is never simply overwritten." @update:model-value="close" />
    <FormDialog :model-value="dialog === 'weight'" title="Group weighing" :fields="weightFields({ animal: false })" :values="{ farm_id: group.farm_id, group_id: group.id, weighed_on: today(), head_count: group.head }"
      :url="route('agri.livestock.weights.store')" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'edit'" title="Edit group" :url="route('agri.livestock.groups.update', group.id)" method="patch"
      :values="{ ...group, field_id: group.field_id }" :fields="[
        { key: 'name', label: 'Name', required: true },
        { key: 'purpose', label: 'Purpose', type: 'select', items: options.purposes },
        { key: 'field_id', label: 'Camp', type: 'select', items: (options.farms.find(f => f.id === group.farm_id)?.fields ?? []).map(f => ({ title: f.code + ' · ' + f.name, value: f.id })) },
        { key: 'status', label: 'Status', type: 'select', items: ['active', 'closed'] },
        { key: 'budget_amount', label: 'Budget', type: 'number' },
        { key: 'started_on', label: 'Started on', type: 'date' },
        { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
      ]" @update:model-value="close" />
  </div>
</template>
