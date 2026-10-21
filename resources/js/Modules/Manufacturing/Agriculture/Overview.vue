<script setup lang="ts">
/** Every production cycle running now, by kind of production. */
import { Link } from '@inertiajs/vue3'
import { CATEGORY_ICON, human, num } from './prd'

defineProps<{
  byCategory: Array<{ category: string; cycles: number; count: number; count_unit: string | null; area: number }>
  cycles: Array<Record<string, any>>
  units: number
  held: number
  can: { manage: boolean; record: boolean; process: boolean }
}>()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Agricultural production</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Flocks, crops, herds, ponds, tunnels and processing batches on one production engine.</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-radar" href="/manufacturing/command/centre">Command Centre</VBtn>
        <VBtn color="primary" prepend-icon="tabler-list" href="/manufacturing/agriculture/cycles">All cycles</VBtn>
      </div>
    </div>
    <VRow class="mb-2">
      <VCol v-for="c in byCategory" :key="c.category" cols="6" md="3">
        <VCard border><VCardText>
          <div class="d-flex align-center gap-2 text-medium-emphasis"><VIcon :icon="CATEGORY_ICON[c.category] ?? 'tabler-leaf'" /> {{ human(c.category) }}</div>
          <div class="text-h5">{{ c.cycles }} cycle{{ c.cycles === 1 ? '' : 's' }}</div>
          <div class="text-caption"><template v-if="c.count">{{ num(c.count, 0) }} {{ c.count_unit }}</template><template v-if="c.area"> · {{ num(c.area, 1) }} ha</template></div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-medium-emphasis">Houses, fields &amp; lines</div><div class="text-h5">{{ units }}</div>
        <div class="text-caption" :class="{ 'text-warning': held }">{{ held }} hold{{ held === 1 ? '' : 's' }} or withdrawal{{ held === 1 ? '' : 's' }} running</div>
      </VCardText></VCard></VCol>
    </VRow>
    <VCard border>
      <VCardTitle>Running now</VCardTitle>
      <VTable density="comfortable">
        <thead><tr><th>Cycle</th><th>Type</th><th>Where</th><th>Stage</th><th class="text-end">Day</th><th class="text-end">Count / area</th></tr></thead>
        <tbody>
          <tr v-for="c in cycles" :key="c.id">
            <td><Link :href="`/manufacturing/agriculture/cycles/${c.id}`" class="font-weight-medium">{{ c.code }}</Link><div class="text-caption text-medium-emphasis">{{ c.name }}</div></td>
            <td><VIcon :icon="CATEGORY_ICON[c.category] ?? 'tabler-leaf'" size="16" /> {{ c.type }}</td>
            <td>{{ c.farm }}<div class="text-caption">{{ c.unit }}</div></td>
            <td>{{ human(c.stage) }}</td>
            <td class="text-end">{{ c.age_days ?? '—' }}</td>
            <td class="text-end"><template v-if="c.current_count !== null">{{ num(c.current_count, 0) }} {{ c.count_unit }}</template><template v-else-if="c.area">{{ num(c.area, 1) }} {{ c.area_unit }}</template></td>
          </tr>
          <tr v-if="!cycles.length"><td colspan="6" class="text-center text-medium-emphasis py-6">Nothing running. Start a cycle from the cycles page.</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
