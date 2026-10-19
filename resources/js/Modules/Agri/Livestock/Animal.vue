<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, statusColor, today } from '../Components/agri'
import { exitFields, healthFields, weightFields, type LivestockOptions } from './fields'

const props = defineProps<{
  animal: any; health: any[]; events: any[]; weights: any[]; offspring: any[]
  options: LivestockOptions
  can: { manage: boolean; health: boolean }
}>()
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const active = computed(() => props.animal.status === 'active')
const maxW = computed(() => Math.max(1, ...props.weights.map(w => w.weight_kg)))
const groupsHere = computed(() => props.options.groups.filter(g => g.farm_id === props.animal.farm_id && g.species_id === props.animal.species_id))
const camps = computed(() => props.options.farms.find(f => f.id === props.animal.farm_id)?.fields ?? [])
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.livestock.index', { tab: 'animals' })">Livestock</Link> / {{ animal.farm }}</div>
        <h4 class="text-h4 mb-1">{{ animal.tag_number }}<span v-if="animal.name" class="text-medium-emphasis"> “{{ animal.name }}”</span></h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ animal.species }} · {{ animal.breed ?? 'breed not recorded' }} · {{ human(animal.sex) }}<template v-if="animal.birth_date"> · born {{ animal.birth_date }}</template></p>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip :color="statusColor(animal.status)" variant="tonal">{{ human(animal.status) }}</VChip>
        <VBtn v-if="active && can.health" color="primary" prepend-icon="tabler-vaccine" @click="dialog = 'health'">Treat</VBtn>
        <RowMenu size="default" :actions="[
          { title: 'Record weight', icon: 'tabler-scale', show: active && (can.manage || can.health), onClick: () => (dialog = 'weight') },
          { title: 'Breeding / life event', icon: 'tabler-heart', show: active && (can.manage || can.health), onClick: () => (dialog = 'event') },
          { title: 'Move', icon: 'tabler-arrows-move', show: active && can.manage, onClick: () => (dialog = 'move') },
          { title: 'Record exit (sold, died…)', icon: 'tabler-logout', color: 'error', show: active && can.manage, onClick: () => (dialog = 'exit') },
        ]" />
      </div>
    </div>

    <VAlert v-if="animal.withdrawal_until" type="warning" variant="tonal" class="mb-4" icon="tabler-alert-triangle">
      In a withdrawal period until <strong>{{ animal.withdrawal_until }}</strong> — may not be sold or slaughtered before then.
    </VAlert>
    <VAlert v-if="!active" type="info" variant="tonal" class="mb-4">Left the herd on {{ animal.exited_on }} ({{ animal.status }}){{ animal.exit_reason ? ': ' + animal.exit_reason : '' }}{{ animal.exit_value ? ' · ' + money(animal.exit_value) : '' }}. The record is kept.</VAlert>

    <VRow>
      <VCol cols="12" md="4">
        <VCard title="Details">
          <VList density="compact">
            <VListItem title="Group"><template #append>{{ animal.group ?? '—' }}</template></VListItem>
            <VListItem title="Camp"><template #append>{{ animal.field ?? '—' }}</template></VListItem>
            <VListItem title="RFID"><template #append>{{ animal.electronic_id ?? '—' }}</template></VListItem>
            <VListItem title="Came from"><template #append>{{ human(animal.acquisition) }} {{ animal.acquired_on ?? '' }}</template></VListItem>
            <VListItem v-if="animal.acquisition_cost" title="Purchase price"><template #append>{{ money(animal.acquisition_cost) }}</template></VListItem>
            <VListItem title="Dam / sire"><template #append>{{ animal.dam ?? '—' }} / {{ animal.sire ?? '—' }}</template></VListItem>
            <VListItem title="Last weight"><template #append>{{ animal.last_weight_kg ? num(animal.last_weight_kg, 0) + ' kg' : '—' }}</template></VListItem>
          </VList>
        </VCard>
        <VCard v-if="offspring.length" title="Offspring" class="mt-4">
          <VList density="compact"><VListItem v-for="o in offspring" :key="o.id" :href="route('agri.livestock.animals.show', o.id)" :title="o.tag_number" :subtitle="human(o.sex) + (o.birth_date ? ' · ' + o.birth_date : '')" /></VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VCard v-if="weights.length" title="Weight" class="mb-4">
          <VCardText>
            <div class="d-flex align-end gap-2" style="height: 120px">
              <div v-for="w in weights" :key="w.weighed_on" class="text-center flex-grow-1" :title="`${w.weighed_on}: ${w.weight_kg} kg`">
                <div class="bg-primary rounded" :style="{ height: (w.weight_kg / maxW) * 90 + 'px' }" />
                <div class="text-caption">{{ num(w.weight_kg, 0) }}</div>
              </div>
            </div>
          </VCardText>
        </VCard>
        <VCard title="Health" class="mb-4">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>Type</th><th>Product / dose</th><th>Withdrawal until</th><th>Vet</th></tr></thead>
            <tbody>
              <tr v-for="h in health" :key="h.id">
                <td>{{ h.treated_on }}</td><td>{{ human(h.event_type) }}<div class="text-caption">{{ h.condition }}</div></td>
                <td>{{ h.product_name ?? '—' }} <span class="text-caption">{{ h.dose }}</span></td>
                <td><VChip v-if="h.withdrawal_until" :color="h.in_withdrawal ? 'warning' : 'secondary'" size="x-small" variant="tonal">{{ h.withdrawal_until }}</VChip></td>
                <td>{{ h.vet_name ?? '' }}</td>
              </tr>
              <tr v-if="!health.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No health records.</td></tr>
            </tbody>
          </VTable>
        </VCard>
        <VCard title="Life events">
          <VTimeline v-if="events.length" density="compact" side="end" class="pa-4">
            <VTimelineItem v-for="(e, i) in events" :key="i" size="x-small" :dot-color="e.event_type === 'exit' ? 'error' : 'primary'">
              <strong>{{ human(e.event_type) }}</strong> · {{ e.occurred_on }}<div class="text-caption">{{ e.outcome }}</div>
            </VTimelineItem>
          </VTimeline>
          <VCardText v-else class="text-medium-emphasis">No events recorded.</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog :model-value="dialog === 'health'" :title="`Treat ${animal.tag_number}`" :fields="healthFields(options, { farm_id: animal.farm_id, animal_id: animal.id })"
      :values="{ farm_id: animal.farm_id, animal_id: animal.id, treated_on: today() }" :url="route('agri.livestock.health.store')" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'weight'" title="Record weight" :fields="weightFields({ animal: true })"
      :values="{ farm_id: animal.farm_id, animal_id: animal.id, weighed_on: today() }" :url="route('agri.livestock.weights.store')" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'exit'" :title="`${animal.tag_number} leaves the herd`" :fields="exitFields(options)" :values="{ exited_on: today() }"
      :url="route('agri.livestock.animals.exit', animal.id)" intro="The animal's record and history are kept; it simply takes no new records." @update:model-value="close" />
    <FormDialog :model-value="dialog === 'event'" title="Breeding or life event" :url="route('agri.livestock.animals.events', animal.id)" :values="{ occurred_on: today() }"
      :fields="[{ key: 'event_type', label: 'Event', type: 'select', required: true, items: options.event_types }, { key: 'occurred_on', label: 'Date', type: 'date', required: true },
        { key: 'outcome', label: 'Outcome / notes', cols: 12 }]" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'move'" :title="`Move ${animal.tag_number}`" :url="route('agri.livestock.animals.move', animal.id)" :values="{ moved_on: today() }"
      :fields="[{ key: 'group_id', label: 'To group', type: 'select', items: groupsHere.map(g => ({ title: g.code + ' · ' + g.name, value: g.id })) },
        { key: 'field_id', label: 'To camp', type: 'select', items: camps.map(f => ({ title: f.code + ' · ' + f.name, value: f.id })) },
        { key: 'moved_on', label: 'Date', type: 'date', required: true }, { key: 'reason', label: 'Reason' }]" @update:model-value="close" />
  </div>
</template>
