<script setup lang="ts">
/**
 * Every QR scan at every gate (CON-23).
 *
 * The page exists for the scans that matched nobody. Those left no trace at all before this —
 * verification returned "not found" before reaching anything that writes — and they are the ones
 * worth investigating: a card replaced after a loss, a card from another site, a photograph of
 * somebody else's pass forwarded on WhatsApp, or somebody at a boom trying codes.
 *
 * Grouped by the code, not listed flat. Eleven scans of one unknown card is a lost card; eleven
 * different unknown codes is somebody testing the gate. Flat, those look the same.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import { useReasonLabel } from '@/Modules/Sheq/Partials/useReasonLabel'

type Scan = {
  uuid: string
  outcome: string
  person_name: string | null
  subject_type: string | null
  activity: string | null
  device_ref: string | null
  ip_address: string | null
  scanned_at: string
  reasons: string[]
  codes: string[]
  token_ref: string
  site_name: string | null
  contractor_name: string | null
  scanned_by: string | null
}

const props = defineProps<{
  days: number
  scans: Scan[]
  counts: { total: number; allowed: number; denied: number; unknown_pass: number; review: number }
  unknownCodes: {
    token_ref: string; site_name: string | null; attempts: number
    first_seen: string; last_seen: string
  }[]
  noCompany?: boolean
}>()

const reasonLabel = useReasonLabel()

const days = ref(props.days)
const search = ref('')
const outcomeFilter = ref<string | null>(null)

watch(days, value => {
  router.get('/sheq/pass-scans', { days: value },
    { preserveState: true, preserveScroll: true, replace: true })
})

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.scans.filter(s => {
    if (outcomeFilter.value && s.outcome !== outcomeFilter.value) return false
    if (!needle) return true

    return [s.person_name ?? '', s.contractor_name ?? '', s.site_name ?? '', s.token_ref,
      s.device_ref ?? '', s.ip_address ?? ''].join(' ').toLowerCase().includes(needle)
  })
})

const outcomeColour = (outcome: string) => ({
  ALLOWED: 'success',
  DENIED: 'error',
  UNKNOWN_PASS: 'purple',
  REVIEW_REQUIRED: 'warning',
  UNKNOWN: 'warning',
}[outcome] ?? 'grey')

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Pass scans</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Every code scanned at a gate — including the ones that matched nobody
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <div class="d-flex justify-end mb-3">
        <VSelect
          v-model="days" density="compact" variant="outlined" hide-details
          style="max-width: 190px" label="Period"
          :items="[
            { value: 1, title: 'Last 24 hours' },
            { value: 7, title: 'Last 7 days' },
            { value: 30, title: 'Last 30 days' },
            { value: 90, title: 'Last 90 days' },
          ]"
        />
      </div>

      <VRow dense class="mb-1">
        <VCol v-for="tile in [
          { label: 'Scans', value: counts.total, colour: '' },
          { label: 'Admitted', value: counts.allowed, colour: '' },
          { label: 'Refused', value: counts.denied, colour: counts.denied ? 'text-error' : '' },
          { label: 'Unknown codes', value: counts.unknown_pass,
            colour: counts.unknown_pass ? 'text-purple' : '' },
        ]" :key="tile.label" cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">{{ tile.label }}</div>
            <div class="text-h4 font-weight-bold" :class="tile.colour">{{ tile.value }}</div>
          </VCard>
        </VCol>
      </VRow>

      <!-- First on the page, because this is the thing nothing else could show. -->
      <VCard v-if="unknownCodes.length" elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Codes that matched nobody
        </VCardTitle>
        <VDivider />
        <VCardText class="pb-2">
          <p class="text-body-2 text-medium-emphasis mb-0">
            Grouped by the code. One code scanned repeatedly is usually a card that was replaced
            and never collected. Many different codes at one gate is somebody trying things.
          </p>
        </VCardText>
        <VTable density="compact">
          <thead>
            <tr>
              <th class="text-left">Code</th>
              <th class="text-left">Gate</th>
              <th class="text-end">Attempts</th>
              <th class="text-left">First seen</th>
              <th class="text-left">Last seen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in unknownCodes" :key="row.token_ref + (row.site_name ?? '')">
              <td><code class="text-caption">{{ row.token_ref }}</code></td>
              <td class="text-body-2">{{ row.site_name ?? '—' }}</td>
              <td class="text-end text-body-2 font-weight-medium"
                  :class="row.attempts > 3 ? 'text-error' : ''">
                {{ row.attempts }}
              </td>
              <td class="text-caption">{{ row.first_seen }}</td>
              <td class="text-caption">{{ row.last_seen }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Scans</VCardTitle>
        <VDivider />
        <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify" style="max-width: 280px"
            label="Person, contractor, gate, code or device"
          />
          <VSelect
            v-model="outcomeFilter" density="compact" variant="outlined" hide-details clearable
            style="max-width: 200px" label="Result"
            :items="['ALLOWED', 'DENIED', 'UNKNOWN_PASS', 'REVIEW_REQUIRED']
              .map(o => ({ value: o, title: label(o) }))"
          />
        </VCardText>
        <VDivider />

        <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-qrcode-scan" size="32" class="mb-2" />
          <div class="text-body-2">
            {{ scans.length ? 'Nothing matches that.' : 'No scans in this period.' }}
          </div>
        </div>

        <VTable v-else density="compact" class="scan-list">
          <tbody>
            <tr v-for="scan in visible" :key="scan.uuid">
              <td class="py-2">
                <div class="d-flex align-center flex-wrap ga-2">
                  <VChip size="x-small" variant="tonal" :color="outcomeColour(scan.outcome)">
                    {{ label(scan.outcome) }}
                  </VChip>
                  <span class="text-body-2">
                    {{ scan.person_name ?? 'Unrecognised code' }}
                  </span>
                  <span v-if="scan.contractor_name" class="text-caption text-medium-emphasis">
                    {{ scan.contractor_name }}
                  </span>
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ scan.scanned_at }}
                  <template v-if="scan.site_name"> · {{ scan.site_name }}</template>
                  <template v-if="scan.activity"> · {{ label(scan.activity) }}</template>
                  · <code>{{ scan.token_ref }}</code>
                  <template v-if="scan.device_ref"> · {{ scan.device_ref }}</template>
                  <template v-else-if="scan.ip_address"> · {{ scan.ip_address }}</template>
                  <template v-if="scan.scanned_by"> · {{ scan.scanned_by }}</template>
                </div>
                <div v-if="scan.reasons.length" class="text-caption text-error mt-1">
                  {{ scan.reasons.join(' ') }}
                </div>
                <div v-if="scan.codes.length" class="d-flex flex-wrap ga-1 mt-1">
                  <VChip v-for="code in scan.codes" :key="code" size="x-small" variant="text"
                         :title="code">
                    {{ reasonLabel(code) }}
                  </VChip>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.scan-list { max-height: 720px; overflow-y: auto; }
</style>
