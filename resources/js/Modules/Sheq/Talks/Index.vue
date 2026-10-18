<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  talks: Array<Record<string, any>>
  coverage: { talks_held: number; people_reached: number; workforce: number; coverage_pct: number } | null
  notReached: { total: number; people: Array<{ id: number; name: string; last: { topic: string; held_on: string; uuid: string } | null }> }
  insights: Array<Record<string, any>>
  people: Array<{ id: number; name: string }>
  sites: Array<{ id: number; name: string }>
  noCompany?: boolean
}>()

const expanded = ref<string | null>(null)

const dismiss = useForm({ note: '' })

const peopleOptions = computed(() => props.people.map(p => ({ title: p.name, value: p.id })))
const siteOptions = computed(() => props.sites.map(s => ({ title: s.name, value: s.id })))

const today = () => new Date().toISOString().slice(0, 10)

/*
 * The picker collects person ids; the endpoint wants `[{hr_person_id, name}]`.
 *
 * Derived on the way out rather than kept as a second piece of state alongside the chips, which
 * is how an attendee ends up on one list and not the other.
 */
const asAttendees = (values: Record<string, unknown>) => ({
  ...values,
  attendees: ((values.attendees as number[] | undefined) ?? []).map(id => ({
    hr_person_id: id,
    name: props.people.find(p => p.id === id)?.name ?? '',
  })),
})

/** Findings worth acting on first. */
const critical = computed(() => props.insights.filter(i => i.severity === 'CRITICAL').length)

const severityColour = (s: string) =>
  ({ CRITICAL: 'error', WARNING: 'warning' }[s] ?? 'info')

/** Turn a finding straight into the talk topic — the loop this screen exists to close. */
const brief = (insight: Record<string, any>) => {
  form.topic = insight.title
  form.content = `${insight.finding}\n\n${insight.recommendation ?? ''}`.trim()
  recording.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const formatDate = (v: string | null) => {
  if (!v) return '—'
  try { return d(new Date(v), 'short') } catch { return v }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.talks.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.talks.subtitle') }}</p>
      </div>
      <AddEntryDialog
        :title="t('sheq.talks.record')" action="/sheq/talks"
        :button-label="t('sheq.talks.record')" :note="t('sheq.talks.record_note')"
        :transform="asAttendees"
        :fields="[
          { key: 'topic', label: t('sheq.talks.topic'), required: true, cols: 12 },
          { key: 'held_on', label: t('sheq.talks.held_on'), type: 'date' as const,
            default: today() },
          { key: 'duration_minutes', label: t('sheq.talks.minutes'), type: 'number' as const,
            default: 15 },
          { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select' as const,
            items: siteOptions },
          { key: 'content', label: t('sheq.talks.content'), type: 'textarea' as const, cols: 12 },
          { key: 'attendees', label: t('sheq.talks.attendees'), type: 'multiselect' as const,
            items: peopleOptions, cols: 12, hint: t('sheq.talks.attendees_hint') },
          { key: 'concerns_raised', label: t('sheq.talks.concerns'), type: 'textarea' as const,
            cols: 12, hint: t('sheq.talks.concerns_hint') },
        ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="critical" type="error" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ t('sheq.talks.critical_insights', { count: critical }) }}
      </VAlert>

      <VRow class="mb-1">
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.talks.coverage') }}
            </div>
            <div class="text-h4 font-weight-bold">{{ coverage?.coverage_pct ?? 0 }}%</div>
            <div class="text-caption text-medium-emphasis">
              {{ coverage?.people_reached ?? 0 }} / {{ coverage?.workforce ?? 0 }}
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.talks.held') }}
            </div>
            <div class="text-h4 font-weight-bold">{{ coverage?.talks_held ?? 0 }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('sheq.talks.this_month') }}</div>
          </VCard>
        </VCol>
      </VRow>

      <!--
        The other side of the percentage.

        "68% reached" is not something a supervisor can brief. These are the people the figure
        leaves out, by name, with the last talk each of them did attend — somebody who has never
        sat one is a different conversation from somebody who missed this month.
      -->
      <VCard v-if="notReached.total" elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.talks.not_reached', { count: notReached.total }) }}
        </VCardTitle>
        <VDivider />
        <VTable density="compact">
          <thead><tr>
            <th>{{ t('sheq.talks.person') }}</th><th>{{ t('sheq.talks.last_attended') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="p in notReached.people" :key="p.id">
              <td class="text-body-2">{{ p.name }}</td>
              <td class="text-body-2">
                <template v-if="p.last">
                  {{ p.last.topic }}
                  <span class="text-medium-emphasis">· {{ p.last.held_on }}</span>
                </template>
                <VChip v-else size="x-small" variant="tonal" color="error">
                  {{ t('sheq.talks.never_attended') }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-if="notReached.total > notReached.people.length"
                   class="text-caption text-medium-emphasis py-2">
          {{ t('sheq.talks.showing_first', { shown: notReached.people.length, total: notReached.total }) }}
        </VCardText>
      </VCard>

      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.talks.title') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!talks.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-account-group-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.talks.none') }}</div>
            </div>
            <div v-for="talk in talks" :key="talk.uuid" class="pa-4 border-b">
              <div class="d-flex align-center flex-wrap ga-2">
                <span class="text-body-2 font-weight-medium">{{ talk.topic }}</span>
                <VChip size="x-small" variant="tonal">
                  {{ talk.attendee_count }} {{ t('sheq.talks.people') }}
                </VChip>
                <VChip v-if="talk.action_id" size="x-small" color="warning" variant="tonal">
                  {{ t('sheq.talks.action_raised') }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ formatDate(talk.held_on) }} · {{ talk.presented_by }}
                <template v-if="talk.site_name"> · {{ talk.site_name }}</template>
              </div>
              <div v-if="talk.concerns_raised" class="text-caption mt-1">
                <VIcon icon="mdi-comment-alert-outline" size="12" class="me-1" />
                {{ talk.concerns_raised }}
              </div>
            </div>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.talks.insights') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!insights.length" class="pa-4 text-body-2 text-medium-emphasis">
              {{ t('sheq.talks.no_insights') }}
            </div>
            <div v-for="i in insights" :key="i.uuid" class="pa-4 border-b">
              <div class="d-flex align-center ga-2 mb-1">
                <VChip size="x-small" :color="severityColour(i.severity)" variant="tonal">
                  {{ i.insight_type.toLowerCase() }}
                </VChip>
                <span class="text-body-2 font-weight-medium">{{ i.title }}</span>
              </div>
              <div class="text-caption text-medium-emphasis">{{ i.finding }}</div>
              <div v-if="i.recommendation" class="text-caption mt-1">{{ i.recommendation }}</div>

              <!-- The evidence, so the claim can be checked rather than believed. -->
              <div v-if="i.evidence?.length" class="text-caption text-medium-emphasis mt-1">
                {{ t('sheq.talks.based_on', { n: i.sample_size }) }}:
                {{ i.evidence.slice(0, 5).map((e: any) => e.reference).filter(Boolean).join(', ') }}
              </div>

              <div class="d-flex ga-2 mt-2">
                <VBtn size="x-small" variant="outlined" @click="brief(i)">
                  {{ t('sheq.talks.brief_crew') }}
                </VBtn>
                <VBtn size="x-small" variant="text"
                  @click="dismiss.post(`/sheq/insights/${i.uuid}/dismiss`, { preserveScroll: true })">
                  {{ t('sheq.talks.dismiss') }}
                </VBtn>
              </div>
            </div>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
