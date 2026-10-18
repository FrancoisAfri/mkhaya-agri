<script setup lang="ts">
/**
 * One contractor's whole story, and why the gate says what it says (CON-22, COM-5).
 *
 * Every event on this page already existed, in six different places: the audit log, the safety
 * file transitions, the document versions, the access register, the scores, the incidents. So the
 * question a host actually gets asked — by an auditor, a client, or an attorney after somebody is
 * hurt — could only be answered by whoever knew all six and was willing to read them side by side
 * with a pen.
 *
 * Read-only by design. A history a screen can edit is not a history.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import { useReasonLabel } from '@/Modules/Sheq/Partials/useReasonLabel'

type Event = {
  kind: string
  at: string
  title: string
  detail: string
  actor: string | null
  source: string
  sealed: boolean
}

type Why = {
  outcome: string
  allowed: boolean
  subject: { worker_name: string | null; contractor_name: string | null }
  timeline: {
    code: string
    severity: string
    detail: string
    check: string | null
    caused_by: { at: string; title: string; actor: string | null } | null
  }[]
  unverified: string[]
}

const props = defineProps<{
  contractors: { uuid: string; name: string; access_status: string | null }[]
  selected: { uuid: string; name: string; access_status: string | null } | null
  kinds: string[]
  activeKinds: string[]
  history: { events: Event[]; counts: Record<string, number> }
  why: Why | null
  workers: { uuid: string; name: string }[]
  sites: { id: number; name: string }[]
  worker: string | null
  site: number | null
  noCompany?: boolean
}>()

const reasonLabel = useReasonLabel()

const contractor = ref(props.selected?.uuid ?? null)
const worker = ref(props.worker)
const site = ref(props.site)
const chosenKinds = ref<string[]>([...props.activeKinds])
const search = ref('')

function reload() {
  router.get('/sheq/contractor-history', {
    contractor: contractor.value,
    worker: worker.value ?? undefined,
    site: site.value ?? undefined,
    kinds: chosenKinds.value.join(','),
  }, { preserveState: true, preserveScroll: true, replace: true })
}

watch([contractor, worker, site, chosenKinds], reload)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  if (!needle) return props.history.events

  return props.history.events.filter(e =>
    `${e.title} ${e.detail} ${e.actor ?? ''}`.toLowerCase().includes(needle))
})

const kindColour = (kind: string) => ({
  APPROVAL: 'success',
  REJECTION: 'error',
  BLOCK: 'error',
  UNBLOCK: 'success',
  DOCUMENT: 'blue',
  SAFETY_FILE: 'indigo',
  ACCESS_DECISION: 'deep-orange',
  SCORE: 'teal',
  INCIDENT: 'red',
  AUDIT: 'purple',
}[kind] ?? 'grey')

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const outcomeColour = computed(() => {
  switch (props.why?.outcome) {
    case 'ALLOWED': return 'success'
    case 'DENIED': return 'error'
    default: return 'warning'
  }
})
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Contractor history</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Every approval, refusal, block, document and access decision — in order, and unalterable
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect
            v-model="contractor"
            :items="contractors.map(c => ({ value: c.uuid, title: c.name }))"
            density="compact" variant="outlined" hide-details style="max-width: 300px"
            label="Contractor"
          />
          <VSelect
            v-model="worker"
            :items="workers.map(w => ({ value: w.uuid, title: w.name }))"
            density="compact" variant="outlined" hide-details clearable style="max-width: 240px"
            label="Person (for the explanation)"
          />
          <VSelect
            v-model="site"
            :items="sites.map(s => ({ value: s.id, title: s.name }))"
            density="compact" variant="outlined" hide-details clearable style="max-width: 220px"
            label="Site"
          />
        </VCardText>
      </VCard>

      <VRow>
        <!-- ── COM-5: why ───────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard v-if="why" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Why the answer is what it is
            </VCardTitle>
            <VDivider />
            <VAlert :type="outcomeColour === 'success' ? 'success'
                      : outcomeColour === 'error' ? 'error' : 'warning'"
                    variant="tonal" rounded="0" density="compact">
              {{ label(why.outcome) }}
              <template v-if="why.subject.worker_name"> · {{ why.subject.worker_name }}</template>
            </VAlert>

            <div v-if="!why.timeline.length" class="text-center py-8 text-medium-emphasis">
              <VIcon icon="mdi-check-circle-outline" size="32" class="mb-2" />
              <div class="text-body-2">Nothing is standing in the way.</div>
            </div>

            <VList v-else density="compact" lines="three">
              <VListItem v-for="(item, i) in why.timeline" :key="i">
                <template #prepend>
                  <VIcon :icon="item.severity === 'BLOCKING' ? 'mdi-close-circle' : 'mdi-information'"
                         :color="item.severity === 'BLOCKING' ? 'error' : 'grey'" size="20" />
                </template>
                <VListItemTitle class="text-body-2">{{ item.detail }}</VListItemTitle>
                <VListItemSubtitle>
                  <VChip size="x-small" variant="tonal" class="mr-1" :title="item.code"
                         :color="item.severity === 'BLOCKING' ? 'error' : 'grey'">
                    {{ reasonLabel(item.code) }}
                  </VChip>
                  <span v-if="item.severity !== 'BLOCKING'" class="text-caption">
                    noted, not refusing
                  </span>
                  <!-- The evidence and the timestamp COM-5 asks for. Absent rather than
                       invented when no event can be traced: "we cannot show you why" is honest
                       and a guessed cause is not. -->
                  <div v-if="item.caused_by" class="text-caption mt-1">
                    {{ item.caused_by.at }} — {{ item.caused_by.title }}
                    <template v-if="item.caused_by.actor"> · {{ item.caused_by.actor }}</template>
                  </div>
                </VListItemSubtitle>
              </VListItem>
            </VList>

            <VCardText v-if="why.unverified.length" class="pt-0">
              <VAlert type="warning" variant="tonal" density="compact">
                Could not be checked: {{ why.unverified.map(label).join(', ') }}.
              </VAlert>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">What is on record</VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="kind in kinds" :key="kind">
                  <td class="text-body-2">
                    <VChip size="x-small" variant="tonal" :color="kindColour(kind)">
                      {{ label(kind) }}
                    </VChip>
                  </td>
                  <td class="text-end text-body-2 font-weight-medium">
                    {{ history.counts[kind] ?? 0 }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── CON-22: the history ──────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              History
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · newest first
              </span>
            </VCardTitle>
            <VDivider />
            <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
              <VTextField
                v-model="search" density="compact" variant="outlined" hide-details clearable
                prepend-inner-icon="mdi-magnify" style="max-width: 260px" label="Search"
              />
              <VSelect
                v-model="chosenKinds" :items="kinds.map(k => ({ value: k, title: label(k) }))"
                density="compact" variant="outlined" hide-details multiple chips closable-chips
                style="min-width: 240px" label="Only these kinds"
              />
            </VCardText>
            <VDivider />

            <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-history" size="32" class="mb-2" />
              <div class="text-body-2">Nothing recorded for this contractor yet.</div>
            </div>

            <VTable v-else density="compact" class="history-list">
              <tbody>
                <tr v-for="(event, i) in visible" :key="i">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal" :color="kindColour(event.kind)">
                        {{ label(event.kind) }}
                      </VChip>
                      <span class="text-body-2">{{ event.title }}</span>
                      <!-- Part of the sealed chain: the audit log is append-only and each row
                           carries the previous row's hash, enforced by the database rather than
                           promised by the application. -->
                      <VTooltip v-if="event.sealed" text="Part of the sealed audit chain">
                        <template #activator="{ props: tip }">
                          <VIcon v-bind="tip" icon="mdi-lock-check" size="14" color="success" />
                        </template>
                      </VTooltip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ event.at }}
                      <template v-if="event.actor"> · {{ event.actor }}</template>
                      <template v-if="event.detail"> · {{ event.detail }}</template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.history-list { max-height: 760px; overflow-y: auto; }
</style>
