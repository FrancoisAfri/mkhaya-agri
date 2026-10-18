<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  driver: {
    id: number; licence_number: string | null; licence_type: string | null
    licence_expiry: string | null; pdp_number: string | null; status: string
    total_trips: number; total_km: number; incidents_count: number
    is_eligible: boolean
  } | null
  stats: {
    total_trips: number; total_km: number; total_litres: number
    fuel_cost: number; outstanding_fines: number; incident_count: number
  }
  recentBookings: any[]
  weekBookings:   any[]
  weekStart:      string
  incidents:      any[]
  fines:          any[]
  fuelLogs:       any[]
  behaviourRecords: any[]
  hasBehaviourApi:  boolean
}>()

const tab = ref('overview')

// ── Week calendar ─────────────────────────────────────────────────────────
const weekDays = computed(() => {
  const start = new Date(props.weekStart + 'T00:00:00')
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    return {
      label: d.toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' }),
      iso:   d.toISOString().slice(0, 10),
      iso1:  i + 1, // 1=Mon … 7=Sun (dayOfWeekIso)
    }
  })
})

function bookingsForDay(dayIso1: number) {
  return props.weekBookings.filter(b => b.day === dayIso1)
}

const todayIso = new Date().toISOString().slice(0, 10)

const stateColor = (s: string) =>
  ({
    pending:        'warning',
    approved:       'success',
    collected:      'teal',
    security_out:   'primary',
    security_in:    'deep-orange',
    returned:       'grey',
    cancelled:      'error',
    rejected:       'error',
    turned_back:    'error',
  })[s] ?? 'grey'

function stateLabel(b: any): string {
  const s: string = b.workflow_state ?? ''
  if (s === 'pending') {
    if (b.current_step_number && b.total_steps) {
      const label = b.current_step_label ? ` — ${b.current_step_label}` : ''
      return `Awaiting Approval (Step ${b.current_step_number} of ${b.total_steps}${label})`
    }
    return 'Pending Approval'
  }
  return ({
    approved:       'Approved — Awaiting Collection',
    collected:      'Keys Collected — At Security Gate',
    security_out:   'On Trip',
    security_in:    'Returned to Gate — Awaiting Fleet',
    returned:       'Completed',
    cancelled:      'Cancelled',
    rejected:       'Rejected',
    turned_back:    'Turned Back at Gate',
  })[s] ?? s.replace(/_/g, ' ')
}

const fineStatusColor = (s: string) =>
  ({ paid: 'success', unpaid: 'error', outstanding: 'error', disputed: 'warning' })[s] ?? 'grey'

const severityColor = (s: string) =>
  ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' })[s] ?? 'grey'

// ── Print / Download ──────────────────────────────────────────────────────
function printHistory() {
  window.print()
}

function downloadCSV() {
  const headers = [
    t('fleet.reference'),
    t('fleet.vehicle'),
    t('fleet.purpose'),
    t('fleet.destination'),
    t('fleet.departure'),
    t('fleet.return'),
    'KM',
    t('fleet.status'),
  ]
  const rows = props.recentBookings.map(b => [
    b.reference, b.vehicle, b.purpose ?? '', b.destination ?? '',
    b.departure_at, b.return_at, b.km_travelled ?? '', b.workflow_state,
  ])
  const csv = [headers, ...rows]
    .map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `my-booking-history-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}
</script>

<template>
  <div class="pa-4">

    <!-- Print header (only visible when printing) -->
    <div class="print-only mb-4">
      <h2 class="text-h6 font-weight-bold">{{ t('fleet.my_bookings') }}</h2>
      <p class="text-caption">Printed: {{ new Date().toLocaleDateString('en-ZA') }}</p>
    </div>

    <!-- Header -->
    <div class="d-flex align-center flex-wrap ga-2 mb-4 no-print">
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ t('fleet.my_dashboard') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ driver ? `Driver — Licence ${driver.licence_number ?? 'N/A'}` : t('fleet.not_registered_as_driver') }}
        </p>
      </div>
      <VBtn
        v-if="driver?.is_eligible"
        color="primary"
        variant="flat"
        prepend-icon="tabler-plus"
        @click="router.visit(route('fleet.my-bookings.create'))"
      >{{ t('fleet.new_booking') }}</VBtn>
      <VTooltip v-else-if="driver && !driver.is_eligible" text="Your driver record is inactive or your licence has expired.">
        <template #activator="{ props: tp }">
          <VBtn v-bind="tp" color="grey" variant="tonal" prepend-icon="tabler-plus" disabled>{{ t('fleet.new_booking') }}</VBtn>
        </template>
      </VTooltip>
      <VBtn variant="tonal" prepend-icon="tabler-download" @click="downloadCSV">{{ t('fleet.download_csv') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-printer" @click="printHistory">{{ t('fleet.print') }}</VBtn>
    </div>

    <!-- No-driver notice -->
    <VAlert v-if="!driver" type="info" variant="tonal" class="mb-4 no-print">
      {{ t('fleet.no_driver_profile') }}
    </VAlert>

    <!-- Stats row -->
    <VRow class="mb-4" dense>
      <VCol cols="6" sm="4" md="2" v-for="s in [
        { label: t('fleet.total_trips'),       value: stats.total_trips,       icon:'tabler-car',           color:'primary',  fmt:'num'      },
        { label: t('fleet.total_km'),         value: stats.total_km,          icon:'tabler-gauge',   color:'blue',     fmt:'km'       },
        { label: t('fleet.fuel_consumed'),    value: stats.total_litres,      icon:'tabler-gas-station',   color:'teal',     fmt:'litres'   },
        { label: t('fleet.fuel_cost'),        value: stats.fuel_cost,         icon:'tabler-currency-dollar',  color:'purple',   fmt:'rand'     },
        { label: t('fleet.outstanding_fines'),value: stats.outstanding_fines, icon:'tabler-cash-off',   color:'error',    fmt:'rand'     },
        { label: t('fleet.incidents'),        value: stats.incident_count,    icon:'tabler-alert-circle',  color:'warning',  fmt:'num'      },
      ]" :key="s.label">
        <VCard variant="tonal" :color="s.color" class="text-center pa-3 h-100">
          <VIcon size="28" class="mb-1">{{ s.icon }}</VIcon>
          <div class="text-subtitle-1 font-weight-bold">
            <span v-if="s.fmt==='rand'">R {{ Number(s.value).toLocaleString('en-ZA', {minimumFractionDigits:2}) }}</span>
            <span v-else-if="s.fmt==='km'">{{ Number(s.value).toLocaleString() }} km</span>
            <span v-else-if="s.fmt==='litres'">{{ Number(s.value).toLocaleString() }} L</span>
            <span v-else>{{ Number(s.value).toLocaleString() }}</span>
          </div>
          <div class="text-caption">{{ s.label }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard class="no-print">
      <VTabs v-model="tab" color="primary" show-arrows density="compact">
        <VTab value="overview">
          <VIcon start size="18">tabler-layout-dashboard</VIcon>{{ t('fleet.overview') }}
        </VTab>
        <VTab value="all-bookings">
          <VIcon start size="18">tabler-calendar-check</VIcon>{{ t('fleet.all_bookings') }}
          <VChip v-if="recentBookings.length" size="x-small" class="ml-1">{{ recentBookings.length }}</VChip>
        </VTab>
        <VTab value="incidents">
          <VIcon start size="18">tabler-alert-circle</VIcon>{{ t('fleet.incidents') }}
          <VChip v-if="incidents.length" size="x-small" color="warning" class="ml-1">{{ incidents.length }}</VChip>
        </VTab>
        <VTab value="fines">
          <VIcon start size="18">tabler-cash-off</VIcon>{{ t('fleet.fines') }}
          <VChip v-if="fines.length" size="x-small" color="error" class="ml-1">{{ fines.length }}</VChip>
        </VTab>
        <VTab value="fuel">
          <VIcon start size="18">tabler-gas-station</VIcon>{{ t('fleet.fuel') }}
          <VChip v-if="fuelLogs.length" size="x-small" class="ml-1">{{ fuelLogs.length }}</VChip>
        </VTab>
        <VTab value="behaviour">
          <VIcon start size="18">tabler-shield</VIcon>{{ t('fleet.driver_behaviour') }}
        </VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">

        <!-- ── Overview ─────────────────────────────────────────────────────── -->
        <VWindowItem value="overview">
          <VRow class="pa-4" dense>

            <!-- Recent bookings -->
            <VCol cols="12">
              <div class="text-subtitle-2 font-weight-bold mb-2">
                <VIcon start size="16">tabler-clock</VIcon>{{ t('fleet.recent_bookings') }}
              </div>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('fleet.reference') }}</th><th>{{ t('fleet.vehicle') }}</th><th>{{ t('fleet.destination') }}</th>
                    <th>{{ t('fleet.departure') }}</th><th>{{ t('fleet.return') }}</th><th>{{ t('fleet.status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="b in recentBookings.slice(0,8)" :key="b.id">
                    <td class="text-caption font-weight-medium">
                      {{ b.reference }}
                      <div v-if="b.project" class="text-caption text-medium-emphasis">
                        <VIcon size="10">tabler-briefcase</VIcon> {{ b.project }}
                      </div>
                      <div v-if="b.driver_user_id && b.driver_user_id !== b.requester_id && b.driver_name" class="text-caption text-medium-emphasis">
                        <VIcon size="10">tabler-steering-wheel</VIcon> {{ b.driver_name }}
                      </div>
                    </td>
                    <td class="text-caption">{{ b.vehicle }}</td>
                    <td class="text-caption">{{ b.destination ?? '—' }}</td>
                    <td class="text-caption text-no-wrap">{{ b.departure_at }}</td>
                    <td class="text-caption text-no-wrap">{{ b.return_at }}</td>
                    <td>
                      <VChip size="x-small" :color="stateColor(b.workflow_state)" label>
                        {{ stateLabel(b) }}
                      </VChip>
                    </td>
                  </tr>
                  <tr v-if="!recentBookings.length">
                    <td colspan="6" class="text-center text-medium-emphasis pa-4">{{ t('fleet.no_bookings_yet') }}</td>
                  </tr>
                </tbody>
              </VTable>
              <div class="text-right mt-2" v-if="recentBookings.length">
                <VBtn size="small" variant="text" color="primary" @click="tab='all-bookings'">
                  {{ t('fleet.view_all_bookings') }} <VIcon end>tabler-arrow-right</VIcon>
                </VBtn>
              </div>
            </VCol>

            <!-- Week calendar -->
            <VCol cols="12">
              <div class="d-flex align-center justify-space-between mb-2">
                <div class="text-subtitle-2 font-weight-bold">
                  <VIcon start size="16">tabler-calendar-week</VIcon>{{ t('fleet.this_weeks_schedule') }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('fleet.this_weeks_bookings') }}: {{ weekDays[0]?.label }} - {{ weekDays[6]?.label }}
                </div>
              </div>

              <div v-if="!weekBookings.length" class="text-center text-medium-emphasis pa-6">
                <VIcon size="40" class="mb-2 d-block text-disabled">tabler-calendar</VIcon>
                {{ t('fleet.no_bookings_scheduled') }}
              </div>

              <div v-else style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;min-width:700px">
                  <thead>
                    <tr>
                      <th v-for="day in weekDays" :key="day.iso1"
                        style="padding:6px 4px;border-bottom:2px solid rgba(0,0,0,0.12);text-align:left;min-width:130px"
                        :style="day.iso === todayIso ? 'color:rgb(var(--v-theme-primary))' : ''">
                        <div class="text-caption font-weight-bold">{{ day.label }}</div>
                        <VChip v-if="bookingsForDay(day.iso1).length" size="x-small" class="mt-1"
                          :color="day.iso === todayIso ? 'primary' : 'default'">
                          {{ bookingsForDay(day.iso1).length }} booking{{ bookingsForDay(day.iso1).length > 1 ? 's' : '' }}
                        </VChip>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td v-for="day in weekDays" :key="day.iso1"
                        style="vertical-align:top;padding:4px;border-right:1px solid rgba(0,0,0,0.07)"
                        :style="day.iso === todayIso ? 'background:rgba(var(--v-theme-primary),0.04)' : ''">

                        <div v-if="!bookingsForDay(day.iso1).length"
                          class="text-caption text-disabled text-center pa-3">—</div>

                        <div v-for="b in bookingsForDay(day.iso1).slice(0, 10)" :key="b.id"
                          class="mb-2 pa-2 rounded"
                          :style="`background:rgba(var(--v-theme-${stateColor(b.workflow_state)}),0.12);border-left:3px solid rgb(var(--v-theme-${stateColor(b.workflow_state)}))`">

                          <!-- Time row -->
                          <div class="d-flex align-center ga-1 mb-1">
                            <VIcon size="12" :color="stateColor(b.workflow_state)">tabler-clock</VIcon>
                            <span class="text-caption font-weight-bold">{{ b.departure_time }}</span>
                            <span v-if="b.return_time" class="text-caption text-disabled">→ {{ b.return_time }}</span>
                          </div>

                          <!-- Vehicle row -->
                          <div class="d-flex align-center ga-1 mb-1">
                            <VIcon size="12" color="grey">tabler-car</VIcon>
                            <span class="text-caption font-weight-medium" style="line-height:1.2">
                              {{ b.reg }}<br>
                              <span class="text-disabled">{{ b.vehicle }}</span>
                            </span>
                          </div>

                          <!-- Booked by row -->
                          <div class="d-flex align-center ga-1 mb-1">
                            <VIcon size="12" color="grey">tabler-user</VIcon>
                            <span class="text-caption text-medium-emphasis" style="font-size:10px!important">
                              {{ b.requester }}
                            </span>
                          </div>

                          <!-- Destination / purpose -->
                          <div v-if="b.destination || b.purpose" class="d-flex align-center ga-1 mb-1">
                            <VIcon size="12" color="grey">tabler-map-pin</VIcon>
                            <span class="text-caption text-disabled" style="font-size:10px!important;line-height:1.2">
                              {{ b.destination || b.purpose }}
                            </span>
                          </div>

                          <!-- Status chip -->
                          <VChip :color="stateColor(b.workflow_state)" size="x-small" label class="text-capitalize mt-1">
                            {{ b.workflow_state?.replace(/_/g,' ') }}
                          </VChip>
                        </div>

                        <!-- "+X more" if > 10 -->
                        <div v-if="bookingsForDay(day.iso1).length > 10"
                          class="text-caption text-medium-emphasis text-center mt-1">
                          + {{ bookingsForDay(day.iso1).length - 10 }} more
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </VCol>

          </VRow>
        </VWindowItem>

        <!-- ── All Bookings ──────────────────────────────────────────────────── -->
        <VWindowItem value="all-bookings">
          <VTable density="compact" class="ma-3">
            <thead>
              <tr>
                <th>{{ t('fleet.reference') }}</th><th>{{ t('fleet.vehicle') }}</th><th>{{ t('fleet.purpose') }}</th><th>{{ t('fleet.destination') }}</th>
                <th>{{ t('fleet.departure') }}</th><th>{{ t('fleet.return') }}</th><th>KM</th><th>{{ t('fleet.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in recentBookings" :key="b.id">
                <td class="text-caption font-weight-medium">
                  {{ b.reference }}
                  <div v-if="b.project" class="text-caption text-medium-emphasis">
                    <VIcon size="10">tabler-briefcase</VIcon> {{ b.project }}
                  </div>
                  <div v-if="b.driver_user_id && b.driver_user_id !== b.requester_id && b.driver_name" class="text-caption text-medium-emphasis">
                    <VIcon size="10">tabler-steering-wheel</VIcon> {{ b.driver_name }}
                  </div>
                </td>
                <td class="text-caption">{{ b.vehicle }}</td>
                <td class="text-caption">{{ b.purpose ?? '—' }}</td>
                <td class="text-caption">{{ b.destination ?? '—' }}</td>
                <td class="text-caption text-no-wrap">{{ b.departure_at }}</td>
                <td class="text-caption text-no-wrap">{{ b.return_at }}</td>
                <td>{{ b.km_travelled ? `${b.km_travelled} km` : '—' }}</td>
                <td>
                  <VChip size="x-small" :color="stateColor(b.workflow_state)" label>
                    {{ stateLabel(b) }}
                  </VChip>
                </td>
              </tr>
              <tr v-if="!recentBookings.length">
                <td colspan="8" class="text-center text-medium-emphasis pa-4">{{ t('fleet.no_bookings_on_record') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- ── Incidents ─────────────────────────────────────────────────────── -->
        <VWindowItem value="incidents">
          <VTable density="compact" class="ma-3">
            <thead>
              <tr>
                <th>Booking</th><th>Vehicle</th><th>Type</th><th>Severity</th>
                <th>Date</th><th>Location</th><th>Police Case</th><th>Description</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in incidents" :key="i.id">
                <td class="text-caption">{{ i.booking_reference }}</td>
                <td class="text-caption">{{ i.registration_number }} {{ i.make }} {{ i.model }}</td>
                <td class="text-capitalize text-caption">{{ i.incident_type?.replace(/_/g,' ') }}</td>
                <td>
                  <VChip size="x-small" :color="severityColor(i.severity)" label class="text-capitalize">
                    {{ i.severity }}
                  </VChip>
                </td>
                <td class="text-caption text-no-wrap">
                  {{ i.incident_at ? new Date(i.incident_at).toLocaleDateString('en-ZA') : '—' }}
                </td>
                <td class="text-caption">{{ i.location ?? '—' }}</td>
                <td class="text-caption">{{ i.police_case_number ?? '—' }}</td>
                <td class="text-caption" style="max-width:200px;white-space:pre-wrap">{{ i.description ?? '—' }}</td>
              </tr>
              <tr v-if="!incidents.length">
                <td colspan="8" class="text-center text-medium-emphasis pa-6">
                  <VIcon size="32" class="mb-2 d-block">tabler-circle-check</VIcon>
                  {{ t('fleet.no_incidents') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- ── Fines ─────────────────────────────────────────────────────────── -->
        <VWindowItem value="fines">
          <VTable density="compact" class="ma-3">
            <thead>
              <tr>
                <th>Fine Number</th><th>Vehicle</th><th>Offence</th><th>Date</th>
                <th>Location</th><th>Amount</th><th>Outstanding</th>
                <th>AARTO Pts</th><th>Due Date</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in fines" :key="f.id">
                <td class="text-caption font-weight-medium">{{ f.fine_number ?? '—' }}</td>
                <td class="text-caption">{{ f.registration_number }} {{ f.make }}</td>
                <td class="text-caption" style="max-width:180px">{{ f.offence_description ?? f.offence_code ?? '—' }}</td>
                <td class="text-caption text-no-wrap">
                  {{ f.offence_date ? new Date(f.offence_date).toLocaleDateString('en-ZA') : '—' }}
                </td>
                <td class="text-caption">{{ f.location ?? '—' }}</td>
                <td class="text-no-wrap">R {{ Number(f.amount).toLocaleString('en-ZA',{minimumFractionDigits:2}) }}</td>
                <td class="text-no-wrap font-weight-medium"
                  :class="Number(f.outstanding_amount)>0 ? 'text-error' : ''">
                  R {{ Number(f.outstanding_amount).toLocaleString('en-ZA',{minimumFractionDigits:2}) }}
                </td>
                <td class="text-center">{{ f.aarto_points ?? '—' }}</td>
                <td class="text-caption text-no-wrap">
                  {{ f.due_date ? new Date(f.due_date).toLocaleDateString('en-ZA') : '—' }}
                </td>
                <td>
                  <VChip size="x-small" :color="fineStatusColor(f.status)" label class="text-capitalize">
                    {{ f.status }}
                  </VChip>
                </td>
              </tr>
              <tr v-if="!fines.length">
                <td colspan="10" class="text-center text-medium-emphasis pa-6">
                  <VIcon size="32" class="mb-2 d-block">tabler-circle-check</VIcon>
                  {{ t('fleet.no_fines') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- ── Fuel ──────────────────────────────────────────────────────────── -->
        <VWindowItem value="fuel">
          <div class="d-flex ga-3 pa-3 flex-wrap">
            <VCard variant="tonal" color="teal" class="pa-3 text-center" min-width="140">
              <VIcon size="24" class="mb-1">tabler-droplet</VIcon>
              <div class="text-subtitle-1 font-weight-bold">{{ Number(stats.total_litres).toLocaleString() }} L</div>
              <div class="text-caption">Total Fuel</div>
            </VCard>
            <VCard variant="tonal" color="purple" class="pa-3 text-center" min-width="140">
              <VIcon size="24" class="mb-1">tabler-currency-dollar</VIcon>
              <div class="text-subtitle-1 font-weight-bold">
                R {{ Number(stats.fuel_cost).toLocaleString('en-ZA',{minimumFractionDigits:2}) }}
              </div>
              <div class="text-caption">Total Cost</div>
            </VCard>
          </div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead>
              <tr>
                <th>Date</th><th>Vehicle</th><th>Fuel Type</th><th>Litres</th>
                <th>Cost/L</th><th>Total Cost</th><th>Odometer</th>
                <th>Consumption</th><th>Station</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in fuelLogs" :key="f.id">
                <td class="text-caption text-no-wrap">
                  {{ f.filled_at ? new Date(f.filled_at).toLocaleDateString('en-ZA') : '—' }}
                </td>
                <td class="text-caption">{{ f.registration_number }} {{ f.make }}</td>
                <td class="text-capitalize text-caption">{{ f.fuel_type ?? '—' }}</td>
                <td>{{ f.litres }} L</td>
                <td>R {{ Number(f.cost_per_litre).toFixed(2) }}</td>
                <td class="font-weight-medium">R {{ Number(f.total_cost).toLocaleString('en-ZA',{minimumFractionDigits:2}) }}</td>
                <td>{{ f.odometer_reading ? `${Number(f.odometer_reading).toLocaleString()} km` : '—' }}</td>
                <td>{{ f.consumption_per_100km ? `${f.consumption_per_100km} L/100km` : '—' }}</td>
                <td class="text-caption">{{ f.station_name ?? '—' }}</td>
              </tr>
              <tr v-if="!fuelLogs.length">
                <td colspan="9" class="text-center text-medium-emphasis pa-6">{{ t('fleet.no_fuel_records') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- ── Driver Behaviour ──────────────────────────────────────────────── -->
        <VWindowItem value="behaviour">
          <div class="pa-4">

            <!-- No API connected -->
            <VAlert v-if="!hasBehaviourApi" type="info" variant="tonal" class="mb-4">
              <VAlertTitle>{{ t('fleet.telematics_not_connected') }}</VAlertTitle>
              Driver behaviour data requires a telematics integration.
              Contact your fleet administrator to connect a telematics provider via
              <strong>Fleet → Setup → Integrations</strong>.
            </VAlert>

            <!-- API connected but no data -->
            <template v-else-if="hasBehaviourApi && !behaviourRecords.length">
              <VAlert type="warning" variant="tonal" class="mb-4">
                Telematics is connected but no behaviour records were found for your driver profile.
              </VAlert>
            </template>

            <!-- Behaviour records from API -->
            <template v-else>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th v-for="col in Object.keys(behaviourRecords[0] ?? {})" :key="col" class="text-capitalize">
                      {{ col.replace(/_/g,' ') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(rec, idx) in behaviourRecords" :key="idx">
                    <td v-for="col in Object.keys(rec)" :key="col" class="text-caption">
                      {{ rec[col] ?? '—' }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

          </div>
        </VWindowItem>

      </VWindow>
    </VCard>

    <!-- Print-only booking table -->
    <div class="print-only mt-4">
      <table style="width:100%;border-collapse:collapse;font-size:11px">
        <thead>
          <tr style="background:#f0f0f0">
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.reference') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.vehicle') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.purpose') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.destination') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.departure') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.return') }}</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">KM</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:left">{{ t('fleet.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in recentBookings" :key="b.id">
            <td style="border:1px solid #ccc;padding:4px">{{ b.reference }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.vehicle }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.purpose ?? '—' }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.destination ?? '—' }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.departure_at }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.return_at }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.km_travelled ?? '—' }}</td>
            <td style="border:1px solid #ccc;padding:4px">{{ b.workflow_state }}</td>
          </tr>
          <tr v-if="!recentBookings.length">
            <td colspan="8" style="border:1px solid #ccc;padding:8px;text-align:center">{{ t('fleet.no_bookings_yet') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>
</template>

<style>
@media print {
  .no-print { display: none !important; }
  .print-only { display: block !important; }
  body { font-size: 11px; }
}
@media screen {
  .print-only { display: none; }
}
</style>
