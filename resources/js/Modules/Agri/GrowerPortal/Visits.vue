<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { human, num, statusColor } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  upcoming: Array<{ id: string; date: string; purpose: string; contract: string | null; notes: string | null }>
  past: Array<{ id: string; date: string; stage: string | null; outcome: string; estimated_quantity: number | null; contract: string | null; notes: string | null }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('visits_title')">
    <VCard :title="k('upcoming_visits')" class="mb-4">
      <VList density="compact">
        <VListItem v-for="v in upcoming" :key="v.id">
          <VListItemTitle>{{ v.date }} · {{ k('purpose_' + v.purpose) }}</VListItemTitle>
          <VListItemSubtitle>{{ v.contract ?? '' }}<template v-if="v.notes"> · {{ v.notes }}</template></VListItemSubtitle>
        </VListItem>
        <VListItem v-if="!upcoming.length"><VListItemSubtitle>{{ k('no_visits') }}</VListItemSubtitle></VListItem>
      </VList>
    </VCard>
    <VCard :title="k('past_inspections')">
      <VList density="compact">
        <VListItem v-for="i in past" :key="i.id">
          <VListItemTitle class="d-flex align-center ga-2">{{ i.date }} · {{ i.stage ? human(i.stage) : '' }}
            <VChip size="x-small" :color="statusColor(i.outcome === 'satisfactory' ? 'active' : i.outcome === 'concern' ? 'warning' : 'critical')">{{ human(i.outcome) }}</VChip></VListItemTitle>
          <VListItemSubtitle>{{ i.contract }}<template v-if="i.estimated_quantity"> · {{ k('estimate', { qty: num(i.estimated_quantity) }) }}</template></VListItemSubtitle>
          <div v-if="i.notes" class="text-body-2 mt-1">{{ i.notes }}</div>
        </VListItem>
        <VListItem v-if="!past.length"><VListItemSubtitle>{{ k('no_inspections') }}</VListItemSubtitle></VListItem>
      </VList>
    </VCard>
  </GrowerShell>
</template>
