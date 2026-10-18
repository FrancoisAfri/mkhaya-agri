<script setup lang="ts">
/**
 * Who is on site, who was, and who never signed out (CON-16).
 *
 * The one screen in this module somebody opens in a hurry. During an evacuation the roll-call
 * is the entire product, so it is the first thing on the page, it reads at arm's length, and it
 * works with no site chosen — a marshal does not filter first.
 *
 * The manual sign-in is on the same page rather than behind a menu, because the circumstance
 * that needs it most is a flat reader and a queue, and the register that is three clicks away
 * is the register that gets replaced by a sheet of paper nobody types up.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Stay = {
  uuid: string
  site_id: number | null
  site_name: string | null
  subject_type: string
  subject_id: number | null
  person_name: string | null
  contractor_name: string | null
  entered_at: string
  entry_method: string | null
  activity: string | null
  minutes_on_site: number
}

type Movement = {
  uuid: string
  person_name: string | null
  subject_type: string
  direction: string
  method: string | null
  activity: string | null
  allowed: boolean
  reasons: string[]
  device_ref: string | null
  occurred_at: string
  was_monitor_only: boolean
  site_name: string | null
  contractor_name: string | null
}

const props = defineProps<{
  onSite: Stay[]
  movements: Movement[]
  overstaying: Stay[]
  overstayHours: number
  sites: { value: number; title: string }[]
  workers: { value: string; title: string; subtitle: string; on_site: boolean }[]
  activities: { value: string; title: string }[]
  siteId: number | null
  day: string
  isToday: boolean
  canManage?: boolean
  noCompany?: boolean
}>()

const search = ref('')

const roll = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return props.onSite

  return props.onSite.filter(s =>
    [s.person_name ?? '', s.contractor_name ?? '', s.site_name ?? '', s.activity ?? '']
      .join(' ').toLowerCase().includes(needle))
})

/** Grouped by site, because that is the unit a marshal is responsible for. */
const bySite = computed(() => {
  const groups = new Map<string, Stay[]>()

  for (const stay of roll.value) {
    const key = stay.site_name ?? t('sheq.attendance.no_site')
    groups.set(key, [...(groups.get(key) ?? []), stay])
  }

  return [...groups.entries()].sort((a, b) => a[0].localeCompare(b[0]))
})

const contractorCount = computed(() =>
  new Set(props.onSite.map(s => s.contractor_name).filter(Boolean)).size)

const duration = (minutes: number) => {
  const h = Math.floor(minutes / 60)
  return h ? `${h}h ${String(minutes % 60).padStart(2, '0')}m` : `${minutes}m`
}

const isLong = (minutes: number) => minutes >= props.overstayHours * 60

const time = (value: string) => {
  try { return d(new Date(value), 'short') } catch { return value }
}

const directionColour = (direction: string) =>
  ({ ENTRY: 'success', CLOCK_IN: 'success', EXIT: 'grey', CLOCK_OUT: 'grey' }[direction] ?? 'info')

const methodLabel = (method: string | null) =>
  method ? t('sheq.attendance.methods.' + method) : '—'

// ── Filters ───────────────────────────────────────────────────────────────────────────────────
function filterBy(siteId: number | null, day: string) {
  router.get('/sheq/attendance',
    { ...(siteId ? { site_id: siteId } : {}), ...(day ? { day } : {}) },
    { preserveState: false, preserveScroll: true })
}

// ── Signing in and out by hand ────────────────────────────────────────────────────────────────
const dialog = ref(false)
const mode = ref<'entry' | 'exit'>('entry')

const form = useForm({
  worker_uuid: null as string | null,
  site_id: props.siteId,
  activity: null as string | null,
  occurred_at: '',
})

/** Signing in offers the people who are out; signing out offers the people who are in. */
const choices = computed(() => props.workers.filter(w =>
  mode.value === 'entry' ? !w.on_site : w.on_site))

function open(which: 'entry' | 'exit') {
  mode.value = which
  form.reset()
  form.clearErrors()
  form.site_id = props.siteId
  dialog.value = true
}

function submit() {
  form.post(`/sheq/attendance/${mode.value}`, {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

/** Sign one person out straight from the roll-call, which is how it is actually used. */
function signOutFrom(stay: Stay) {
  const worker = props.workers.find(w => w.title === stay.person_name && w.on_site)
  if (!worker) return

  router.post('/sheq/attendance/exit', { worker_uuid: worker.value, site_id: stay.site_id },
    { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.attendance.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.attendance.subtitle') }}</p>
      </div>
      <div v-if="canManage" class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="mdi-logout" @click="open('exit')">
          {{ t('sheq.attendance.sign_out') }}
        </VBtn>
        <VBtn color="primary" variant="flat" prepend-icon="mdi-login" @click="open('entry')">
          {{ t('sheq.attendance.sign_in') }}
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow dense class="mb-1">
        <VCol cols="12" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.attendance.on_site_now') }}
            </div>
            <div class="text-h3 font-weight-bold">{{ onSite.length }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.attendance.contractors_on_site') }}
            </div>
            <div class="text-h3 font-weight-bold">{{ contractorCount }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.attendance.movements_today') }}
            </div>
            <div class="text-h3 font-weight-bold">{{ movements.length }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.attendance.long_stays') }}
            </div>
            <div class="text-h3 font-weight-bold" :class="overstaying.length ? 'text-warning' : ''">
              {{ overstaying.length }}
            </div>
          </VCard>
        </VCol>
      </VRow>

      <!-- Not an alarm: night shift exists. But it is the figure a supervisor checks before
           going home, and the one that says whether anybody is signing out at all. -->
      <VAlert v-if="overstaying.length" type="warning" variant="tonal" class="mb-4"
              role="status" aria-live="polite">
        {{ t('sheq.attendance.long_stays_hint', {
          n: overstaying.length, hours: overstayHours,
        }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
          <VSelect
            :model-value="siteId" :items="[{ value: null, title: t('sheq.attendance.all_sites') },
                                           ...sites]"
            density="compact" variant="outlined" hide-details style="max-width: 240px"
            :label="t('sheq.registers.col.site')"
            @update:model-value="(v: number | null) => filterBy(v, day)"
          />
          <VTextField
            :model-value="day" type="date" density="compact" variant="outlined" hide-details
            style="max-width: 190px" :label="t('sheq.attendance.movements_for')"
            @update:model-value="(v: string) => filterBy(siteId, v)"
          />
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify" style="max-width: 280px"
            :label="t('sheq.attendance.search')"
          />
        </VCardText>
      </VCard>

      <VRow>
        <!-- ── The roll-call ─────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3 d-flex align-center ga-2">
              {{ t('sheq.attendance.roll_call') }}
              <VChip size="x-small" variant="tonal" color="primary">{{ roll.length }}</VChip>
            </VCardTitle>
            <VDivider />

            <div v-if="!roll.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-account-off-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.attendance.nobody_on_site') }}</div>
            </div>

            <template v-for="[site, stays] in bySite" v-else :key="site">
              <div class="px-4 py-2 text-caption text-uppercase text-medium-emphasis bg-surface-variant">
                {{ site }} · {{ stays.length }}
              </div>
              <VTable density="compact">
                <tbody>
                  <tr v-for="stay in stays" :key="stay.uuid">
                    <td class="py-2">
                      <div class="text-body-2 font-weight-medium">
                        {{ stay.person_name ?? '—' }}
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        {{ stay.contractor_name ?? t('sheq.attendance.no_employer') }}
                        <template v-if="stay.activity"> · {{ stay.activity }}</template>
                      </div>
                    </td>
                    <td style="width: 130px">
                      <div class="text-caption text-medium-emphasis">
                        {{ t('sheq.attendance.in_at', { time: time(stay.entered_at) }) }}
                      </div>
                      <VChip size="x-small" variant="tonal"
                             :color="isLong(stay.minutes_on_site) ? 'warning' : 'default'">
                        {{ duration(stay.minutes_on_site) }}
                      </VChip>
                    </td>
                    <td style="width: 80px" class="text-caption text-medium-emphasis">
                      {{ methodLabel(stay.entry_method) }}
                    </td>
                    <td style="width: 100px" class="text-end">
                      <VBtn v-if="canManage" size="x-small" variant="text"
                            @click="signOutFrom(stay)">
                        {{ t('sheq.attendance.out') }}
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>
          </VCard>
        </VCol>

        <!-- ── Movements ─────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ isToday ? t('sheq.attendance.movements_today')
                         : t('sheq.attendance.movements_on', { date: day }) }}
            </VCardTitle>
            <VDivider />

            <div v-if="!movements.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">{{ t('sheq.attendance.no_movements') }}</div>
            </div>

            <VTable v-else density="compact" class="movement-list">
              <tbody>
                <tr v-for="m in movements" :key="m.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal" :color="directionColour(m.direction)">
                        {{ t('sheq.attendance.directions.' + m.direction, m.direction) }}
                      </VChip>
                      <span class="text-body-2">{{ m.person_name ?? '—' }}</span>
                      <VIcon v-if="!m.allowed" icon="mdi-cancel" size="14" color="error" />
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ time(m.occurred_at) }}
                      <template v-if="m.site_name"> · {{ m.site_name }}</template>
                      <template v-if="m.contractor_name"> · {{ m.contractor_name }}</template>
                      · {{ methodLabel(m.method) }}
                      <template v-if="m.device_ref"> · {{ m.device_ref }}</template>
                    </div>
                    <!-- The reason a refusal happened, where CON-16 requires it. A log that
                         records only that somebody was turned away answers nothing. -->
                    <div v-if="!m.allowed && m.reasons.length"
                         class="text-caption text-error mt-1">
                      {{ m.reasons.join(' ') }}
                    </div>
                    <div v-if="m.was_monitor_only" class="text-caption text-warning">
                      {{ t('sheq.attendance.monitor_only') }}
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ── Signing in or out by hand ─────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="640" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ mode === 'entry' ? t('sheq.attendance.sign_in') : t('sheq.attendance.sign_out') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.attendance.manual_note') }}
          </p>

          <VAutocomplete
            v-model="form.worker_uuid" :items="choices" item-title="title" item-value="value"
            variant="outlined" density="comfortable" class="mb-2"
            :label="t('sheq.attendance.person')"
            :no-data-text="mode === 'entry' ? t('sheq.attendance.everyone_in')
                                            : t('sheq.attendance.nobody_to_sign_out')"
            :error-messages="form.errors.worker_uuid"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
            </template>
          </VAutocomplete>

          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="form.site_id" :items="sites" clearable
                       variant="outlined" density="comfortable"
                       :label="t('sheq.registers.col.site')"
                       :error-messages="form.errors.site_id" />
            </VCol>
            <VCol v-if="mode === 'entry' && activities.length" cols="12" md="6">
              <VSelect v-model="form.activity" :items="activities" clearable
                       variant="outlined" density="comfortable"
                       :label="t('sheq.activities.activity')"
                       :error-messages="form.errors.activity" />
            </VCol>
            <VCol cols="12">
              <!-- For the common case: a guard writing up the last hour from a paper list.
                   The past only — a register that can record an arrival that has not happened
                   is one that can be used to say somebody was here when they were not. -->
              <VTextField v-model="form.occurred_at" type="datetime-local"
                          variant="outlined" density="comfortable"
                          :label="t('sheq.attendance.when')"
                          :hint="t('sheq.attendance.when_hint')" persistent-hint
                          :error-messages="form.errors.occurred_at" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="form.processing || !form.worker_uuid" @click="submit">
            {{ mode === 'entry' ? t('sheq.attendance.sign_in') : t('sheq.attendance.sign_out') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.movement-list { max-height: 640px; overflow-y: auto; }
</style>
