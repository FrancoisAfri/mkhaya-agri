<script setup lang="ts">
/** The incident register: every alarm, interruption, exposure and failed command, with where it is in its life. */
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ago, human, severityColor, statusColor } from '../Agriculture/prd'

const props = defineProps<{
  incidents: { data: Array<{ id: string; number: string; severity: string; status: string; kind: string; title: string; farm: string | null; unit: string | null
    opened_at: string; occurrences: number; escalation_level: number; acknowledged: boolean }>; links: any[] }
  filters: { status: string; severity: string | null }
  severities: string[]
}>()
const f = ref({ ...props.filters })
watch(f, v => router.get('/manufacturing/command/incidents', v, { preserveState: true, replace: true }), { deep: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Incidents</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Each alarm from the moment it opened to the evidence it was closed on.</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-radar" href="/manufacturing/command/centre">Command Centre</VBtn>
    </div>
    <VCard border class="mb-4"><VCardText class="d-flex gap-3 flex-wrap">
      <VSelect v-model="f.status" :items="['open', 'resolved', 'closed', 'all'].map(s => ({ value: s, title: human(s) }))" label="Status" hide-details style="max-width: 200px" />
      <VSelect v-model="f.severity" :items="severities.map(s => ({ value: s, title: human(s) }))" label="Severity" clearable hide-details style="max-width: 200px" />
    </VCardText></VCard>
    <VCard border>
      <VTable>
        <thead><tr><th>Incident</th><th>Severity</th><th>Where</th><th>Opened</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="i in incidents.data" :key="i.id">
            <td><Link :href="`/manufacturing/command/incidents/${i.id}`" class="font-weight-medium">{{ i.title }}</Link>
              <div class="text-caption text-medium-emphasis">{{ i.number }} · {{ human(i.kind) }}<template v-if="i.occurrences > 1"> · seen {{ i.occurrences }}×</template></div></td>
            <td><VChip size="small" label :color="severityColor(i.severity)">{{ i.severity }}</VChip></td>
            <td>{{ i.farm ?? '—' }}<div class="text-caption">{{ i.unit }}</div></td>
            <td>{{ ago(i.opened_at) }}<div v-if="i.escalation_level > 1" class="text-caption text-error">escalated to step {{ i.escalation_level }}</div></td>
            <td><VChip size="small" label :color="statusColor(i.status)">{{ human(i.status) }}</VChip></td>
          </tr>
          <tr v-if="!incidents.data.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No incidents.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="incidents.links?.length > 3" class="d-flex gap-1 flex-wrap">
        <VBtn v-for="(l, k) in incidents.links" :key="k" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.visit(l.url)"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
