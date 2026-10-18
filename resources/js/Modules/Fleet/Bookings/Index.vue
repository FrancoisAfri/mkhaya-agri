<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import FleetCalendarView from './CalendarView.vue'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type ApprovalStep = {
  id: number; step_number: number; status: string
  comments: string | null; action_at: string | null
}
type Booking = {
  id: number; reference: string
  vehicle: string; vehicle_id: number
  driver: string;  driver_id: number
  requester: string; requester_id: number
  purpose: string | null; destination: string | null
  usage_type: string; is_local_trip: boolean
  project: string | null; passenger_count: number
  departure_at: string; expected_return_at: string | null
  actual_return_at: string | null
  start_odometer: number | null; end_odometer: number | null; km_travelled: number | null
  workflow_state: string
  collection_location: string | null; collected_at: string | null
  supporting_document_url: string | null
  collection_document_url: string | null; collection_image_url: string | null
  vehicle_snapshot: { make: string | null; model: string | null; year: number | null; reg: string | null; odometer: number | null }
  approvals: ApprovalStep[]
  tollgates_count: number; incidents_count: number
  notes: string | null
  inspections: InspectionReport[]
}
type InspectionReport = {
  id: number; inspection_type: string; inspected_at: string; inspector_name: string
  odometer_reading: number | null; fuel_level: string | null; condition_overall: string | null
  lights_ok: boolean; tyres_ok: boolean; brakes_ok: boolean; wipers_ok: boolean
  fluids_ok: boolean; first_aid_ok: boolean; fire_ext_ok: boolean; spare_tyre_ok: boolean; interior_ok: boolean
  notes: string | null
  damage_markers: { area: string; label: string; photo: string | null }[]
}
type Vehicle = { id: number; registration_number: string; make: string; model: string; year: number | null; fuel_type: string | null }
type Driver  = { id: number; name: string; licence_type: string; licence_expiry: string | null; is_eligible: boolean }
type FillingStation = { id: number; name: string; location: string | null; fuel_types: string | null }

const props = defineProps<{
  bookings: Booking[]
  vehicles: Vehicle[]
  drivers:  Driver[]
  approvalConfigs: any[]
  fillingStations: FillingStation[]
}>()

// ── Snackbar / toast ─────────────────────────────────────────────────────
const snack = ref<{ show: boolean; msg: string; color: string }>({ show: false, msg: '', color: 'success' })
function toast(msg: string, color = 'success') { snack.value = { show: true, msg, color } }

const showCalendar = ref(false)

const search = ref('')
const stateFilter = ref('')

const filtered = computed(() => {
  return props.bookings.filter(b => {
    const q = search.value.toLowerCase()
    const matchSearch = !q || [
      b.reference, b.vehicle, b.driver,
      b.destination ?? '', b.purpose ?? '',
      b.vehicle_snapshot?.reg ?? '', b.project ?? '',
    ].some(s => s.toLowerCase().includes(q))
    const matchState = !stateFilter.value || b.workflow_state === stateFilter.value
    return matchSearch && matchState
  })
})

const dialog = ref(false)
const supportingDocFile = ref<File | null>(null)
const formProcessing = ref(false)
const form = useForm({
  vehicle_id:         null as number | null,
  driver_id:          null as number | null,
  destination:        '',
  purpose:            '',
  departure_at:       '',
  expected_return_at: '',
  usage_type:         'official',
  is_local_trip:      false,
  project:            '',
  passenger_count:    1,
  notes:              '',
})

// ── Custom validation errors ─────────────────────────────────────────────
const customErrors = ref<Record<string, string>>({})

function validateForm(): boolean {
  customErrors.value = {}
  const raw = form.data()
  const now = new Date()

  if (!raw.vehicle_id) {
    customErrors.value.vehicle_id = t('fleet_bookings_index.validation.vehicle_required')
    return false
  }
  if (!raw.purpose?.trim()) {
    customErrors.value.purpose = t('fleet_bookings_index.validation.purpose_required')
    return false
  }
  if (!raw.destination?.trim()) {
    customErrors.value.destination = t('fleet_bookings_index.validation.destination_required')
    return false
  }
  if (!raw.departure_at) {
    customErrors.value.departure_at = t('fleet_bookings_index.validation.departure_required')
    return false
  }
  if (!raw.expected_return_at) {
    customErrors.value.expected_return_at = t('fleet_bookings_index.validation.return_required')
    return false
  }

  const depDate = new Date(raw.departure_at)
  const retDate = new Date(raw.expected_return_at)

  if (depDate <= now) {
    customErrors.value.departure_at = t('fleet_bookings_index.validation.departure_future')
    return false
  }
  if (retDate <= depDate) {
    customErrors.value.expected_return_at = t('fleet_bookings_index.validation.return_after_departure')
    return false
  }

  return true
}

function save() {
  // Clear previous errors
  form.clearErrors()
  customErrors.value = {}

  // Frontend validation
  if (!validateForm()) {
    toast(t('fleet_bookings_index.validation.fix_errors'), 'error')
    return
  }

  const fd = new FormData()
  const raw = form.data()

  Object.entries(raw).forEach(([k, v]) => {
    if (v === null || v === undefined) return

    if (k === 'is_local_trip') {
      fd.append(k, v ? '1' : '0')
      return
    }

    if (['vehicle_id', 'driver_id', 'passenger_count'].includes(k)) {
      fd.append(k, String(v))
      return
    }

    fd.append(k, String(v))
  })

  if (supportingDocFile.value) {
    fd.append('supporting_document', supportingDocFile.value)
  }

  formProcessing.value = true
  router.post(route('fleet.bookings.store'), fd as any, {
    forceFormData: true,
    onSuccess: () => {
      dialog.value = false
      form.reset()
      supportingDocFile.value = null
      toast(t('fleet_bookings_index.toasts.booking_created'))
    },
    onError: (errors) => {
      // Merge server errors into customErrors for display
      if (typeof errors === 'object') {
        Object.assign(customErrors.value, errors)
      }
      toast(t('fleet_bookings_index.toasts.action_failed'), 'error')
    },
    onFinish: () => { formProcessing.value = false },
  })
}

// ── Reset form when dialog opens ─────────────────────────────────────────
watch(dialog, (val) => {
  if (val) {
    form.reset()
    form.usage_type = 'official'
    form.passenger_count = 1
    form.is_local_trip = false
    supportingDocFile.value = null
    customErrors.value = {}
    form.clearErrors()
  }
})

type ActionType = 'approve' | 'collect' | 'check_in' | 'cancel'
const actionDialog  = ref(false)
const actionItem    = ref<Booking | null>(null)
const actionType    = ref<ActionType | null>(null)
const actionNote    = ref('')
const actionOdo     = ref<number | null>(null)
const actionLocation = ref('')
const collectDocFile = ref<File | null>(null)
const collectImgFile = ref<File | null>(null)
const actionProcessing = ref(false)

function doAction(b: Booking, type: ActionType) {
  actionItem.value     = b
  actionType.value     = type
  actionNote.value     = ''
  actionOdo.value      = type === 'collect' ? (b.vehicle_snapshot?.odometer ?? null) : null
  actionLocation.value = ''
  collectDocFile.value = null
  collectImgFile.value = null
  actionDialog.value   = true
}

function confirmAction() {
  if (!actionItem.value || !actionType.value) return
  const id = actionItem.value.id
  actionProcessing.value = true

  if (actionType.value === 'collect') {
    const fd = new FormData()
    fd.append('start_odometer', String(actionOdo.value ?? 0))
    if (actionLocation.value) fd.append('collection_location', actionLocation.value)
    if (collectDocFile.value) fd.append('collection_document', collectDocFile.value)
    if (collectImgFile.value) fd.append('collection_image', collectImgFile.value)
    router.post(route('fleet.bookings.collect', id), fd as any, {
      forceFormData: true,
      onSuccess: () => { actionDialog.value = false; toast(t('fleet_bookings_index.toasts.vehicle_collected')) },
      onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
      onFinish:  () => { actionProcessing.value = false },
    })
    return
  }

  const payloadMap: Record<ActionType, Record<string, any>> = {
    approve:  { comments: actionNote.value },
    collect:  {},
    check_in: { end_odometer: actionOdo.value, notes: actionNote.value },
    cancel:   { cancellation_reason: actionNote.value },
  }

  const methodMap: Record<ActionType, 'patch' | 'post'> = {
    approve: 'patch', collect: 'post', check_in: 'patch', cancel: 'patch',
  }

  const routeMap: Record<ActionType, string> = {
    approve:  'fleet.bookings.approve',
    collect:  'fleet.bookings.collect',
    check_in: 'fleet.bookings.check-in',
    cancel:   'fleet.bookings.cancel',
  }

  router[methodMap[actionType.value]](route(routeMap[actionType.value], id), payloadMap[actionType.value], {
    onSuccess: () => { actionDialog.value = false; toast(t('fleet_bookings_index.toasts.action_success')) },
    onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish:  () => { actionProcessing.value = false },
  })
}

const resetDialog  = ref(false)
const resetBooking = ref<Booking | null>(null)
const resetProcessing = ref(false)

function openResetDialog(b: Booking) {
  resetBooking.value = b
  resetDialog.value  = true
}

function confirmReset() {
  if (!resetBooking.value) return
  resetProcessing.value = true
  router.post(route('fleet.bookings.reset-approval-steps', resetBooking.value.id), {}, {
    onSuccess: () => { resetDialog.value = false; toast(t('fleet_bookings_index.toasts.steps_reset')) },
    onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish:  () => { resetProcessing.value = false },
  })
}

const tollDialog   = ref(false)
const tollBooking  = ref<Booking | null>(null)
const tollReceiptFile = ref<File | null>(null)
const tollProcessing  = ref(false)
const tollForm = useForm({
  amount:        '' as number | string,
  toll_location: '',
  toll_at:       new Date().toISOString().slice(0, 16),
})

function openTollDialog(b: Booking) { tollBooking.value = b; tollDialog.value = true }

function saveTollgate() {
  if (!tollBooking.value) return
  const fd = new FormData()
  fd.append('amount', String(tollForm.amount))
  fd.append('toll_at', tollForm.toll_at)
  if (tollForm.toll_location) fd.append('toll_location', tollForm.toll_location)
  if (tollReceiptFile.value) fd.append('receipt', tollReceiptFile.value)

  tollProcessing.value = true
  router.post(route('fleet.bookings.tollgates.store', tollBooking.value.id), fd as any, {
    forceFormData: true,
    onSuccess: () => { tollDialog.value = false; tollForm.reset(); tollReceiptFile.value = null; toast(t('fleet_bookings_index.toasts.tollgate_added')) },
    onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish:  () => { tollProcessing.value = false },
  })
}

const incidentDialog  = ref(false)
const incidentBooking = ref<Booking | null>(null)
const evidenceFiles   = ref<File[]>([])
const incidentProcessing = ref(false)
const incidentForm = useForm({
  incident_type:      'accident',
  severity:           'medium',
  description:        '',
  incident_at:        new Date().toISOString().slice(0, 16),
  location:           '',
  police_case_number: '',
})

function openIncidentDialog(b: Booking) { incidentBooking.value = b; incidentDialog.value = true }

function saveIncident() {
  if (!incidentBooking.value) return
  const fd = new FormData()
  Object.entries(incidentForm.data()).forEach(([k, v]) => { if (v) fd.append(k, String(v)) })
  evidenceFiles.value.forEach(f => fd.append('evidence[]', f))

  incidentProcessing.value = true
  router.post(route('fleet.bookings.incidents.store', incidentBooking.value.id), fd as any, {
    forceFormData: true,
    onSuccess: () => {
      incidentDialog.value = false
      incidentForm.reset()
      evidenceFiles.value = []
      toast(t('fleet_bookings_index.toasts.incident_reported'))
    },
    onError:  () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish: () => { incidentProcessing.value = false },
  })
}

const returnDialog  = ref(false)
const returnBooking = ref<Booking | null>(null)
const returnProcessing = ref(false)

const retFuel = ref({ fuel_type: 'petrol', litres: null as number|null, cost_per_litre: null as number|null, total_cost: null as number|null, station_name: '', full_tank: false, filled_at: '' })

watch(() => [retFuel.value.litres, retFuel.value.cost_per_litre], ([l, c]) => {
  if (l && c) retFuel.value.total_cost = Math.round((l as number) * (c as number) * 100) / 100
})
const retFine = ref({ fine_number: '', offence_description: '', offence_date: '', amount: null as number|null, location: '', issuing_authority: '', due_date: '', notes: '' })
const retInc  = ref({ incident_type: 'other', severity: 'medium', description: '', incident_at: '', location: '', police_case_number: '' })
const retGc   = ref({ cost_type: '', cost: null as number|null, description: '', date: '', supplier_name: '' })
const retToll = ref({ amount: null as number|null, toll_location: '', toll_at: '' })

const fuelReceiptFile   = ref<File | null>(null)
const fineNoticeFile    = ref<File | null>(null)
const incEvidenceFiles  = ref<File[]>([])
const gcReceiptFile     = ref<File | null>(null)
const retTollReceiptFile = ref<File | null>(null)

function openReturnDialog(b: Booking) {
  returnBooking.value  = b
  const now = new Date().toISOString().slice(0, 16)
  const today = new Date().toISOString().slice(0, 10)
  retFuel.value = { fuel_type: 'petrol', litres: null, cost_per_litre: null, total_cost: null, station_name: '', full_tank: false, filled_at: now }
  retFine.value = { fine_number: '', offence_description: '', offence_date: now, amount: null, location: '', issuing_authority: '', due_date: '', notes: '' }
  retInc.value  = { incident_type: 'other', severity: 'medium', description: '', incident_at: now, location: '', police_case_number: '' }
  retGc.value   = { cost_type: '', cost: null, description: '', date: today, supplier_name: '' }
  retToll.value = { amount: null, toll_location: '', toll_at: now }
  fuelReceiptFile.value  = null; fineNoticeFile.value   = null
  incEvidenceFiles.value = []; gcReceiptFile.value = null; retTollReceiptFile.value = null
  returnDialog.value   = true
}

function submitReturn() {
  if (!returnBooking.value) return
  const fd = new FormData()

  if (retFuel.value.litres) {
    fd.append('fuel_log[fuel_type]',        retFuel.value.fuel_type)
    fd.append('fuel_log[litres]',           String(retFuel.value.litres))
    if (retFuel.value.cost_per_litre)   fd.append('fuel_log[cost_per_litre]',   String(retFuel.value.cost_per_litre))
    if (retFuel.value.total_cost)       fd.append('fuel_log[total_cost]',       String(retFuel.value.total_cost))
    if (returnBooking.value?.end_odometer) fd.append('fuel_log[odometer_reading]', String(returnBooking.value.end_odometer))
    if (retFuel.value.station_name)       fd.append('fuel_log[station_name]',     retFuel.value.station_name)
    fd.append('fuel_log[full_tank]',  retFuel.value.full_tank ? '1' : '0')
    fd.append('fuel_log[filled_at]',  retFuel.value.filled_at || new Date().toISOString())
    if (fuelReceiptFile.value)          fd.append('fuel_log[receipt]',           fuelReceiptFile.value)
  }

  if (retFine.value.amount) {
    fd.append('fine[amount]', String(retFine.value.amount))
    if (retFine.value.fine_number)         fd.append('fine[fine_number]',         retFine.value.fine_number)
    if (retFine.value.offence_description) fd.append('fine[offence_description]', retFine.value.offence_description)
    if (retFine.value.offence_date)        fd.append('fine[offence_date]',        retFine.value.offence_date)
    if (retFine.value.location)            fd.append('fine[location]',            retFine.value.location)
    if (retFine.value.issuing_authority)   fd.append('fine[issuing_authority]',   retFine.value.issuing_authority)
    if (retFine.value.due_date)            fd.append('fine[due_date]',            retFine.value.due_date)
    if (retFine.value.notes)               fd.append('fine[notes]',               retFine.value.notes)
    if (fineNoticeFile.value)              fd.append('fine[notice]',              fineNoticeFile.value)
  }

  if (retInc.value.description) {
    fd.append('incident[incident_type]', retInc.value.incident_type)
    fd.append('incident[severity]',      retInc.value.severity)
    fd.append('incident[description]',   retInc.value.description)
    if (retInc.value.incident_at)        fd.append('incident[incident_at]',        retInc.value.incident_at)
    if (retInc.value.location)           fd.append('incident[location]',           retInc.value.location)
    if (retInc.value.police_case_number) fd.append('incident[police_case_number]', retInc.value.police_case_number)
    incEvidenceFiles.value.forEach((f, i) => fd.append(`incident[evidence][${i}]`, f))
  }

  if (retGc.value.cost) {
    fd.append('general_cost[cost]', String(retGc.value.cost))
    if (retGc.value.cost_type)    fd.append('general_cost[cost_type]',    retGc.value.cost_type)
    if (retGc.value.description)  fd.append('general_cost[description]',  retGc.value.description)
    if (retGc.value.date)         fd.append('general_cost[date]',         retGc.value.date)
    if (retGc.value.supplier_name) fd.append('general_cost[supplier_name]', retGc.value.supplier_name)
    if (gcReceiptFile.value)      fd.append('general_cost[receipt]',      gcReceiptFile.value)
  }

  if (retToll.value.amount) {
    fd.append('tollgate[amount]', String(retToll.value.amount))
    if (retToll.value.toll_location) fd.append('tollgate[toll_location]', retToll.value.toll_location)
    if (retToll.value.toll_at)       fd.append('tollgate[toll_at]',       retToll.value.toll_at)
    if (retTollReceiptFile.value)    fd.append('tollgate[receipt]',       retTollReceiptFile.value)
  }

  returnProcessing.value = true
  router.post(route('fleet.bookings.return-vehicle', returnBooking.value.id), fd as any, {
    forceFormData: true,
    onSuccess: () => { returnDialog.value = false; viewDialog.value = false; toast(t('fleet_bookings_index.toasts.vehicle_returned')) },
    onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish:  () => { returnProcessing.value = false },
  })
}

const viewDialog    = ref(false)
const viewBooking   = ref<Booking | null>(null)
const inspectCompare = ref(false)

const preInspection  = computed(() => viewBooking.value?.inspections?.find(i => i.inspection_type === 'pre_trip')  ?? null)
const postInspection = computed(() => viewBooking.value?.inspections?.find(i => i.inspection_type === 'post_trip') ?? null)

const conditionRank = (c: string | null) => ({ excellent: 4, good: 3, fair: 2, poor: 1 })[c ?? ''] ?? 0
const fuelRank      = (f: string | null) => ({ full: 5, three_quarter: 4, half: 3, quarter: 2, empty: 1 })[f ?? ''] ?? 0

function inspDiff(field: keyof InspectionReport): boolean {
  if (!preInspection.value || !postInspection.value) return false
  return preInspection.value[field] !== postInspection.value[field]
}
function equipDegraded(field: keyof InspectionReport): boolean {
  if (!preInspection.value || !postInspection.value) return false
  return !!preInspection.value[field] && !postInspection.value[field]
}

function openView(b: Booking) {
  viewBooking.value    = b
  inspectCompare.value = false
  viewDialog.value     = true
}

function printInspectionReport() {
  const b = viewBooking.value
  if (!b || !b.inspections?.length) return

  const condColor = (c: string | null) =>
    c === 'excellent' ? '#1b5e20' : c === 'good' ? '#1565c0' : c === 'fair' ? '#e65100' : '#b71c1c'

  const equipRows = (insp: InspectionReport) => [
    [t('fleet_bookings_index.view.lights'), insp.lights_ok], [t('fleet_bookings_index.view.tyres'), insp.tyres_ok], [t('fleet_bookings_index.view.brakes'), insp.brakes_ok],
    [t('fleet_bookings_index.view.wipers'), insp.wipers_ok], [t('fleet_bookings_index.view.fluids'), insp.fluids_ok], [t('fleet_bookings_index.view.first_aid'), insp.first_aid_ok],
    [t('fleet_bookings_index.view.fire_ext'), insp.fire_ext_ok], [t('fleet_bookings_index.view.spare_tyre'), insp.spare_tyre_ok], [t('fleet_bookings_index.view.interior'), insp.interior_ok],
  ].map(([label, ok]) => `<tr><td>${label}</td><td style="color:${ok ? '#2e7d32' : '#c62828'};font-weight:bold">${ok ? '✓ OK' : '✗ Failed'}</td></tr>`).join('')

  const damageSection = (insp: InspectionReport) => {
    if (!insp.damage_markers?.length) return `<p style="color:#555">${t('fleet_bookings_index.view.none')}</p>`
    return insp.damage_markers.map(d => `
      <div style="margin-bottom:12px">
        <strong>${d.label}</strong>
        ${d.photo ? `<br><img src="${d.photo}" style="max-width:220px;max-height:160px;margin-top:6px;border:1px solid #ccc;border-radius:4px" />` : `<br><em style="color:#888">${t('fleet_bookings_index.view.no_photo')}</em>`}
      </div>`).join('')
  }

  const inspBlock = (insp: InspectionReport, title: string, color: string) => `
    <div style="page-break-inside:avoid;margin-bottom:28px">
      <h2 style="background:${color};color:#fff;padding:8px 14px;border-radius:4px;margin:0 0 12px">${title} — ${insp.inspected_at}</h2>
      <p style="margin:0 0 8px"><strong>${t('fleet_bookings_index.view.inspector')}:</strong> ${insp.inspector_name}</p>
      <table style="width:100%;border-collapse:collapse;margin-bottom:14px">
        <tr><td style="width:50%"><strong>${t('fleet_bookings_index.view.odometer')}:</strong> ${insp.odometer_reading?.toLocaleString() ?? '—'} km</td>
            <td><strong>${t('fleet_bookings_index.view.fuel_level')}:</strong> ${insp.fuel_level?.replace(/_/g,' ') ?? '—'}</td></tr>
        <tr><td><strong>${t('fleet_bookings_index.view.overall_condition')}:</strong> <span style="color:${condColor(insp.condition_overall)};font-weight:bold;text-transform:capitalize">${insp.condition_overall ?? '—'}</span></td>
            <td></td></tr>
      </table>
      <h4 style="margin:0 0 6px;border-bottom:1px solid #ddd;padding-bottom:4px">${t('fleet_bookings_index.view.equipment_checks')}</h4>
      <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13px">
        <thead><tr style="background:#f5f5f5"><th style="text-align:left;padding:4px 8px">Item</th><th style="text-align:left;padding:4px 8px">Status</th></tr></thead>
        <tbody>${equipRows(insp)}</tbody>
      </table>
      <h4 style="margin:0 0 6px;border-bottom:1px solid #ddd;padding-bottom:4px">${t('fleet_bookings_index.view.damage_reported')}</h4>
      ${damageSection(insp)}
      ${insp.notes ? `<p style="margin:8px 0 0"><strong>${t('fleet_bookings_index.view.notes_label')}:</strong> ${insp.notes}</p>` : ''}
    </div>`

  const pre  = b.inspections.find(i => i.inspection_type === 'pre_trip')
  const post = b.inspections.find(i => i.inspection_type === 'post_trip')

  const html = `<!DOCTYPE html><html><head><meta charset="utf-8">
    <title>${t('fleet_bookings_index.view.inspection_reports')} — ${b.reference}</title>
    <style>body{font-family:Arial,sans-serif;font-size:14px;padding:24px;color:#222}
    table td,table th{padding:4px 8px;border:1px solid #ddd} h1{margin:0 0 4px} p.meta{color:#555;margin:0 0 20px}</style>
  </head><body>
    <h1>${t('fleet_bookings_index.view.inspection_reports')} — ${b.reference}</h1>
    <p class="meta">${t('fleet_bookings_index.view.vehicle')}: ${b.vehicle} &nbsp;|&nbsp; ${t('fleet_bookings_index.view.driver')}: ${b.driver} &nbsp;|&nbsp; Requester: ${b.requester}</p>
    <hr style="margin-bottom:20px">
    ${pre  ? inspBlock(pre,  t('fleet_bookings_index.view.check_out'),  '#1565c0') : ''}
    ${post ? inspBlock(post, t('fleet_bookings_index.view.check_in'),  '#2e7d32') : ''}
  </body></html>`

  const w = window.open('', '_blank')
  if (!w) return
  w.document.write(html)
  w.document.close()
  w.focus()
  setTimeout(() => w.print(), 400)
}

function openPhoto(url: string | null) {
  if (url) window.open(url, '_blank')
}

const selected = ref<number[]>([])
const bulkApproveProcessing = ref(false)

function bulkApprove() {
  if (!selected.value.length) return
  bulkApproveProcessing.value = true
  router.post(route('fleet.bookings.bulk-approve'), {
    booking_ids: selected.value,
  }, {
    onSuccess: () => { selected.value = []; toast(t('fleet_bookings_index.toasts.bulk_approved')) },
    onError:   () => toast(t('fleet_bookings_index.toasts.action_failed'), 'error'),
    onFinish:  () => { bulkApproveProcessing.value = false },
  })
}

const stateColor = (s: string) => ({
  pending: 'warning', approved: 'success',
  collected: 'teal', security_out: 'primary',
  security_in: 'deep-purple', returned: 'default',
  rejected: 'error', cancelled: 'error', turned_back: 'orange',
})[s] ?? 'default'

const approvalStepColor = (s: string) => ({
  pending: 'warning', approved: 'success', rejected: 'error', skipped: 'secondary',
})[s] ?? 'default'

const actionLabel = (act: ActionType | null) => ({
  approve: 'Approve', collect: t('fleet_bookings_index.collect'), check_in: t('fleet_bookings_index.check_in'), cancel: t('fleet_bookings_index.cancel'),
})[act ?? ''] ?? ''

const headers = computed(() => [
  { title: '',                     key: 'select',             sortable: false, width: '40px' },
  { title: t('fleet_bookings_index.reference'),   key: 'reference',          width: '120px' },
  { title: t('fleet_bookings_index.vehicle'),     key: 'vehicle' },
  { title: t('fleet_bookings_index.driver'),      key: 'driver' },
  { title: t('fleet_bookings_index.purpose'),     key: 'purpose' },
  { title: t('fleet_bookings_index.departure'),   key: 'departure_at',       width: '140px' },
  { title: t('fleet_bookings_index.return'),      key: 'expected_return_at', width: '140px' },
  { title: t('fleet_bookings_index.status'),      key: 'workflow_state',     width: '130px' },
  { title: t('fleet_bookings_index.approval'),    key: 'approvals',          sortable: false, width: '130px' },
  { title: '',                     key: 'actions',            sortable: false, width: '260px' },
])
</script>

<template>
  <div class="pa-4">
    <!-- Snackbar / Toast -->
    <VSnackbar v-model="snack.show" :color="snack.color" location="top right" :timeout="3000">{{ snack.msg }}</VSnackbar>

    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_bookings_index.bookings_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_bookings_index.bookings_subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn v-if="selected.length" color="success" variant="tonal" class="mr-2" prepend-icon="ri-check-double-line" :loading="bulkApproveProcessing" @click="bulkApprove">
        {{ t('fleet_bookings_index.approve_selected') }} ({{ selected.length }})
      </VBtn>
      <VBtn
        :color="showCalendar ? 'primary' : 'default'"
        :variant="showCalendar ? 'flat' : 'tonal'"
        prepend-icon="ri-calendar-line"
        class="mr-2"
        @click="showCalendar = !showCalendar"
      >
        {{ t('fleet_bookings_index.calendar') }}
      </VBtn>
      <VBtn color="primary" prepend-icon="ri-add-line" @click="dialog = true">{{ t('fleet_bookings_index.new_booking') }}</VBtn>
    </div>

    <!-- Calendar -->
    <div v-if="showCalendar" class="mb-4">
      <FleetCalendarView />
    </div>

    <!-- Filters -->
    <VCard class="mb-4 pa-3">
      <VRow dense>
        <VCol cols="12" md="6">
          <VTextField v-model="search" :label="t('fleet.reference')" prepend-inner-icon="ri-search-line"
            clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="12" md="4">
          <VSelect v-model="stateFilter" :label="t('fleet_bookings_index.status')" clearable density="compact" variant="outlined" hide-details
            :items="[
              { title: 'Pending',                    value: 'pending' },
              { title: 'Approved',                   value: 'approved' },
              { title: 'Collected',                  value: 'collected' },
              { title: 'Security Out — In Use',      value: 'security_out' },
              { title: 'Security Checked — Awaiting Return', value: 'security_in' },
              { title: 'Returned',                   value: 'returned' },
              { title: 'Rejected',                   value: 'rejected' },
              { title: 'Cancelled',                  value: 'cancelled' },
              { title: 'Turned Back',                value: 'turned_back' },
            ]" />
        </VCol>
      </VRow>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTable :headers="headers" :items="filtered" density="comfortable" hover>
        <template #item.select="{ item }">
          <VCheckbox
            v-if="item.workflow_state === 'pending'"
            v-model="selected"
            :value="item.id"
            hide-details
            density="compact"
          />
        </template>

        <template #item.workflow_state="{ item }">
          <VChip :color="stateColor(item.workflow_state)" size="x-small" label class="text-capitalize">
            {{ item.workflow_state.replace(/_/g, ' ') }}
          </VChip>
          <VChip v-if="item.is_local_trip" color="teal" size="x-small" label class="ml-1">{{ t('fleet_bookings_index.local_trip') }}</VChip>
        </template>

        <template #item.approvals="{ item }">
          <div v-if="item.approvals.length" class="d-flex flex-wrap gap-1">
            <VTooltip v-for="step in item.approvals" :key="step.id"
              :text="`Step ${step.step_number}: ${step.status}${step.comments ? ' — ' + step.comments : ''}`">
              <template #activator="{ props: tp }">
                <VChip v-bind="tp" :color="approvalStepColor(step.status)" size="x-small" label>
                  S{{ step.step_number }}
                </VChip>
              </template>
            </VTooltip>
          </div>
          <span v-else class="text-disabled text-caption">—</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 flex-wrap">
            <VBtn size="x-small" color="secondary" variant="tonal" icon :title="t('fleet_bookings_index.view_details')"
              @click="openView(item)">
              <VIcon icon="tabler-eye" size="16" />
            </VBtn>

            <VBtn v-if="item.workflow_state === 'pending'" size="x-small" color="warning" variant="tonal"
              title="Re-evaluate approval steps based on current booking settings"
              @click="openResetDialog(item)">{{ t('fleet_bookings_index.reset_steps') }}</VBtn>

            <VBtn v-if="item.workflow_state === 'approved'" size="x-small" color="primary" variant="tonal"
              @click="doAction(item, 'collect')">{{ t('fleet_bookings_index.collect') }}</VBtn>

            <VBtn v-if="item.workflow_state === 'security_out'" size="x-small" color="deep-purple" variant="tonal"
              @click="doAction(item, 'check_in')">{{ t('fleet_bookings_index.check_in') }}</VBtn>

            <VBtn v-if="item.workflow_state === 'security_in'" size="x-small" color="success" variant="tonal"
              prepend-icon="tabler-car-suv"
              @click="openReturnDialog(item)">{{ t('fleet_bookings_index.return_vehicle') }}</VBtn>

            <VBtn v-if="item.workflow_state === 'security_out'" size="x-small" color="orange" variant="tonal" icon
              :title="t('fleet_bookings_index.log_tollgate')" @click="openTollDialog(item)">
              <VIcon icon="ri-road-map-line" size="14" />
            </VBtn>

            <VBtn v-if="item.workflow_state === 'security_out'" size="x-small" color="red" variant="tonal" icon
              :title="t('fleet_bookings_index.report_incident')" @click="openIncidentDialog(item)">
              <VIcon icon="ri-alarm-warning-line" size="14" />
            </VBtn>

            <VBtn v-if="!['security_out','security_in','returned','cancelled','rejected','turned_back'].includes(item.workflow_state)"
              size="x-small" color="error" variant="text" icon @click="doAction(item, 'cancel')">
              <VIcon icon="ri-close-line" />
            </VBtn>
          </div>
        </template>

        <template #expanded-row="{ item }">
          <td :colspan="headers.length" class="pa-3 bg-surface-variant">
            <VRow dense>
              <VCol cols="12" md="3">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.vehicle_snapshot') }}</div>
                <div class="text-body-2">{{ item.vehicle_snapshot.year }} {{ item.vehicle_snapshot.make }} {{ item.vehicle_snapshot.model }} ({{ item.vehicle_snapshot.reg }})</div>
              </VCol>
              <VCol cols="6" md="2">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.usage') }}</div>
                <div class="text-body-2 text-capitalize">{{ item.usage_type?.replace(/_/g, ' ') }}</div>
              </VCol>
              <VCol v-if="item.project" cols="6" md="2">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.project') }}</div>
                <div class="text-body-2">{{ item.project }}</div>
              </VCol>
              <VCol cols="6" md="2">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.passengers') }}</div>
                <div class="text-body-2">{{ item.passenger_count }}</div>
              </VCol>
              <VCol v-if="item.km_travelled" cols="6" md="2">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.distance') }}</div>
                <div class="text-body-2">{{ item.km_travelled }} km</div>
              </VCol>
              <VCol v-if="item.collection_location" cols="12" md="3">
                <div class="text-caption text-disabled">{{ t('fleet_bookings_index.expanded.collection_location') }}</div>
                <div class="text-body-2">{{ item.collection_location }}</div>
              </VCol>
              <VCol v-if="item.supporting_document_url" cols="6" md="2">
                <VBtn :href="item.supporting_document_url" target="_blank" size="x-small" variant="tonal" prepend-icon="ri-file-line">
                  {{ t('fleet_bookings_index.support_doc') }}
                </VBtn>
              </VCol>
              <VCol v-if="item.tollgates_count" cols="6" md="2">
                <VChip size="x-small" color="orange" label>{{ t('fleet_bookings_index.expanded.tollgates_count', { count: item.tollgates_count }) }}</VChip>
              </VCol>
              <VCol v-if="item.incidents_count" cols="6" md="2">
                <VChip size="x-small" color="red" label>{{ t('fleet_bookings_index.expanded.incidents_count', { count: item.incidents_count }) }}</VChip>
              </VCol>
            </VRow>
          </td>
        </template>
      </VDataTable>
    </VCard>

    <!-- New Booking Dialog -->
    <VDialog v-model="dialog" max-width="720" scrollable persistent>
      <VCard :title="t('fleet_bookings_index.new_booking_request')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="form.vehicle_id" :label="t('fleet_bookings_index.vehicle') + ' *'"
                :items="vehicles"
                :item-title="(v: Vehicle) => `${v.registration_number} — ${v.make} ${v.model}`"
                item-value="id"
                :error-messages="customErrors.vehicle_id || form.errors.vehicle_id" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.driver_id" :label="t('fleet_bookings_index.driver') + ' *'"
                :items="drivers.filter(d => d.is_eligible)"
                item-title="name" item-value="id"
                :error-messages="customErrors.driver_id || form.errors.driver_id">
                <template #item="{ item, props: ip }">
                  <VListItem v-bind="ip">
                    <template #subtitle>
                      {{ item.raw.licence_type }} · exp {{ item.raw.licence_expiry ?? 'N/A' }}
                    </template>
                  </VListItem>
                </template>
              </VSelect>
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.purpose" :label="t('fleet_bookings_index.purpose') + ' *'" :error-messages="customErrors.purpose || form.errors.purpose" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.destination" :label="t('fleet_bookings_index.validation.destination_label')" :error-messages="customErrors.destination || form.errors.destination" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.usage_type" label="Usage Type *"
                :items="[
                  { title: 'Official',  value: 'official' },
                  { title: 'Local',     value: 'local' },
                  { title: 'Personal',  value: 'personal' },
                  { title: 'Business',  value: 'business' },
                ]"
                :error-messages="form.errors.usage_type" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.departure_at" :label="t('fleet_bookings_index.departure') + ' *'" type="datetime-local"
                :min="new Date().toISOString().slice(0, 16)"
                :error-messages="customErrors.departure_at || form.errors.departure_at" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.expected_return_at" :label="t('fleet_bookings_index.return') + ' *'" type="datetime-local"
                :min="form.departure_at"
                :error-messages="customErrors.expected_return_at || form.errors.expected_return_at" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.project" label="Project / Cost Centre" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="form.passenger_count" label="Passengers" type="number" min="1" max="50" />
            </VCol>
            <VCol cols="6" md="3" class="d-flex align-center">
              <VCheckbox v-model="form.is_local_trip" :label="t('fleet_bookings_index.local_trip')" hide-details />
            </VCol>
            <VCol cols="12">
              <div class="text-caption mb-1">{{ t('fleet_bookings_index.support_doc') }} (PDF/Image, max 5MB)</div>
              <VFileInput
                v-model="supportingDocFile"
                accept=".pdf,.jpg,.jpeg,.png"
                label="Attach document"
                prepend-icon=""
                prepend-inner-icon="ri-attachment-line"
                density="compact"
                variant="outlined"
                hide-details
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" label="Notes" rows="2" auto-grow />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :disabled="formProcessing" @click="dialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="formProcessing" @click="save">{{ t('fleet_bookings_index.submit_booking') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Action Confirmation Dialog -->
    <VDialog v-model="actionDialog" max-width="480" persistent>
      <VCard :title="actionLabel(actionType)">
        <VCardText>
          <p class="mb-4">Booking: <strong>{{ actionItem?.reference }}</strong></p>

          <template v-if="actionType === 'collect'">
            <VTextField v-model.number="actionOdo" :label="t('fleet_bookings_index.dialog.collect.odometer')" type="number" class="mb-3" />
            <VTextField v-model="actionLocation" :label="t('fleet_bookings_index.dialog.collect.location')" class="mb-3" />
            <VFileInput v-model="collectDocFile" :label="t('fleet_bookings_index.dialog.collect.document')" accept=".pdf,.jpg,.jpeg,.png"
              prepend-icon="" prepend-inner-icon="ri-file-line" density="compact" variant="outlined" class="mb-2" />
            <VFileInput v-model="collectImgFile" :label="t('fleet_bookings_index.dialog.collect.photo')" accept=".jpg,.jpeg,.png"
              prepend-icon="" prepend-inner-icon="ri-camera-line" density="compact" variant="outlined" />
          </template>

          <template v-else-if="actionType === 'check_in'">
            <VTextField v-model.number="actionOdo" :label="t('fleet_bookings_index.dialog.check_in.odometer')" type="number" class="mb-3" />
            <VTextarea v-model="actionNote" :label="t('fleet_bookings_index.dialog.check_in.notes')" rows="2" />
          </template>

          <template v-else-if="actionType === 'cancel'">
            <VTextarea v-model="actionNote" :label="t('fleet_bookings_index.dialog.cancel.reason')" rows="2" />
          </template>

          <template v-else-if="actionType === 'approve'">
            <VTextarea v-model="actionNote" :label="t('fleet_bookings_index.dialog.approve.comments')" rows="2" />
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :disabled="actionProcessing" @click="actionDialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn :color="actionType === 'cancel' ? 'error' : 'primary'" :loading="actionProcessing" @click="confirmAction">{{ t('fleet_bookings_index.dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Tollgate Dialog -->
    <VDialog v-model="tollDialog" max-width="440" persistent>
      <VCard :title="t('fleet_bookings_index.log_tollgate')">
        <VCardText>
          <p class="mb-4">Booking: <strong>{{ tollBooking?.reference }}</strong></p>
          <VTextField v-model.number="tollForm.amount" label="Amount (R) *" type="number" step="0.01" class="mb-3" />
          <VTextField v-model="tollForm.toll_location" label="Toll Plaza Name" class="mb-3" />
          <VTextField v-model="tollForm.toll_at" label="Date/Time *" type="datetime-local" class="mb-3" />
          <VFileInput v-model="tollReceiptFile" label="Receipt" accept=".pdf,.jpg,.jpeg,.png"
            prepend-icon="" prepend-inner-icon="ri-receipt-line" density="compact" variant="outlined" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :disabled="tollProcessing" @click="tollDialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn color="orange" :loading="tollProcessing" @click="saveTollgate">{{ t('fleet_bookings_index.dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Incident Dialog -->
    <VDialog v-model="incidentDialog" max-width="560" scrollable persistent>
      <VCard :title="t('fleet_bookings_index.report_incident')">
        <VCardText>
          <p class="mb-4">Booking: <strong>{{ incidentBooking?.reference }}</strong></p>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="incidentForm.incident_type" label="Type *"
                :items="[
                  { title: 'Accident',   value: 'accident' },
                  { title: 'Breakdown',  value: 'breakdown' },
                  { title: 'Theft',      value: 'theft' },
                  { title: 'Damage',     value: 'damage' },
                  { title: 'Other',      value: 'other' },
                ]" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="incidentForm.severity" label="Severity *"
                :items="[
                  { title: 'Low',      value: 'low' },
                  { title: 'Medium',   value: 'medium' },
                  { title: 'High',     value: 'high' },
                  { title: 'Critical', value: 'critical' },
                ]" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="incidentForm.description" label="Description *" rows="3" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="incidentForm.incident_at" label="Date/Time *" type="datetime-local" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="incidentForm.location" label="Location" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="incidentForm.police_case_number" label="Police Case Number" />
            </VCol>
            <VCol cols="12">
              <VFileInput v-model="evidenceFiles" label="Evidence (photos/PDFs)" multiple
                accept=".pdf,.jpg,.jpeg,.png" prepend-icon=""
                prepend-inner-icon="ri-image-line" density="compact" variant="outlined" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :disabled="incidentProcessing" @click="incidentDialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn color="red" :loading="incidentProcessing" @click="saveIncident">Submit Report</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- View Booking Detail Dialog -->
    <VDialog v-model="viewDialog" max-width="760" scrollable>
      <VCard v-if="viewBooking">
        <VCardTitle class="d-flex align-center pa-4">
          <VIcon icon="ri-calendar-check-line" class="mr-2" />
          {{ t('fleet_bookings_index.view.title') }}: {{ viewBooking.reference }}
          <VSpacer />
          <VChip :color="stateColor(viewBooking.workflow_state)" size="small" label class="text-capitalize">
            {{ viewBooking.workflow_state.replace(/_/g, ' ') }}
          </VChip>
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.vehicle') }}</div>
              <div class="text-body-1 font-weight-medium">{{ viewBooking.vehicle }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ viewBooking.vehicle_snapshot?.year }} {{ viewBooking.vehicle_snapshot?.make }} {{ viewBooking.vehicle_snapshot?.model }}
                <template v-if="viewBooking.vehicle_snapshot?.reg"> · {{ viewBooking.vehicle_snapshot.reg }}</template>
              </div>
            </VCol>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.driver_requester') }}</div>
              <div class="text-body-2"><strong>{{ t('fleet_bookings_index.view.driver') }}:</strong> {{ viewBooking.driver || '—' }}</div>
              <div class="text-body-2"><strong>Requester:</strong> {{ viewBooking.requester || '—' }}</div>
            </VCol>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.purpose') }}</div>
              <div class="text-body-2">{{ viewBooking.purpose || '—' }}</div>
            </VCol>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.destination') }}</div>
              <div class="text-body-2">{{ viewBooking.destination || '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.departure') }}</div>
              <div class="text-body-2">{{ viewBooking.departure_at }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.expected_return') }}</div>
              <div class="text-body-2">{{ viewBooking.expected_return_at || '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.actual_return') }}</div>
              <div class="text-body-2">{{ viewBooking.actual_return_at || '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.usage_type') }}</div>
              <div class="text-body-2 text-capitalize">{{ viewBooking.usage_type?.replace(/_/g, ' ') || '—' }}</div>
            </VCol>
            <VCol v-if="viewBooking.project" cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.project') }}</div>
              <div class="text-body-2">{{ viewBooking.project }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.passengers') }}</div>
              <div class="text-body-2">{{ viewBooking.passenger_count ?? '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.local_trip') }}</div>
              <VChip :color="viewBooking.is_local_trip ? 'teal' : 'default'" size="x-small" label>
                {{ viewBooking.is_local_trip ? 'Yes' : 'No' }}
              </VChip>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.start_odometer') }}</div>
              <div class="text-body-2">{{ viewBooking.start_odometer?.toLocaleString() || '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.end_odometer') }}</div>
              <div class="text-body-2">{{ viewBooking.end_odometer?.toLocaleString() || '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.distance') }}</div>
              <div class="text-body-2">{{ viewBooking.km_travelled ? `${viewBooking.km_travelled} km` : '—' }}</div>
            </VCol>
            <VCol v-if="viewBooking.collection_location" cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.collection_location') }}</div>
              <div class="text-body-2">{{ viewBooking.collection_location }}</div>
            </VCol>
            <VCol v-if="viewBooking.notes" cols="12">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_bookings_index.view.notes') }}</div>
              <div class="text-body-2 white-space-pre-line">{{ viewBooking.notes }}</div>
            </VCol>
            <VCol v-if="viewBooking.supporting_document_url || viewBooking.collection_document_url || viewBooking.collection_image_url" cols="12">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('fleet_bookings_index.view.documents') }}</div>
              <div class="d-flex gap-2 flex-wrap">
                <VBtn v-if="viewBooking.supporting_document_url" :href="viewBooking.supporting_document_url" target="_blank"
                  size="x-small" variant="tonal" prepend-icon="ri-file-line">{{ t('fleet_bookings_index.view.supporting_doc') }}</VBtn>
                <VBtn v-if="viewBooking.collection_document_url" :href="viewBooking.collection_document_url" target="_blank"
                  size="x-small" variant="tonal" prepend-icon="ri-file-line">{{ t('fleet_bookings_index.view.collection_doc') }}</VBtn>
                <VBtn v-if="viewBooking.collection_image_url" :href="viewBooking.collection_image_url" target="_blank"
                  size="x-small" variant="tonal" prepend-icon="ri-image-line">{{ t('fleet_bookings_index.view.collection_photo') }}</VBtn>
              </div>
            </VCol>
            <VCol v-if="viewBooking.approvals.length" cols="12">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('fleet_bookings_index.view.approval_steps') }}</div>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('fleet_bookings_index.view.step') }}</th><th>{{ t('fleet_bookings_index.view.status') }}</th><th>{{ t('fleet_bookings_index.view.comments') }}</th><th>{{ t('fleet_bookings_index.view.date') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="step in viewBooking.approvals" :key="step.id">
                    <td class="text-caption">S{{ step.step_number }}</td>
                    <td>
                      <VChip :color="approvalStepColor(step.status)" size="x-small" label class="text-capitalize">
                        {{ step.status }}
                      </VChip>
                    </td>
                    <td class="text-caption">{{ step.comments || '—' }}</td>
                    <td class="text-caption">{{ step.action_at ? new Date(step.action_at).toLocaleDateString('en-ZA') : '—' }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCol>
            <VCol v-if="viewBooking.tollgates_count || viewBooking.incidents_count" cols="12">
              <div class="d-flex gap-2">
                <VChip v-if="viewBooking.tollgates_count" size="small" color="orange" label>
                  {{ t('fleet_bookings_index.expanded.tollgates_count', { count: viewBooking.tollgates_count }) }}
                </VChip>
                <VChip v-if="viewBooking.incidents_count" size="small" color="red" label>
                  {{ t('fleet_bookings_index.expanded.incidents_count', { count: viewBooking.incidents_count }) }}
                </VChip>
              </div>
            </VCol>

            <!-- Inspection Reports -->
            <VCol v-if="viewBooking.inspections?.length" cols="12">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 font-weight-bold text-uppercase text-medium-emphasis mb-3 d-flex align-center gap-2 flex-wrap">
                <VIcon size="16">tabler-clipboard-check</VIcon>
                {{ t('fleet_bookings_index.view.inspection_reports') }}
                <VSpacer />
                <VBtnToggle v-if="preInspection && postInspection" v-model="inspectCompare"
                  density="compact" variant="outlined" mandatory class="mr-2">
                  <VBtn :value="false" size="x-small" prepend-icon="tabler-layout-list">{{ t('fleet_bookings_index.view.individual') }}</VBtn>
                  <VBtn :value="true"  size="x-small" prepend-icon="tabler-layout-distribute-vertical">{{ t('fleet_bookings_index.view.compare') }}</VBtn>
                </VBtnToggle>
                <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-printer"
                  @click="printInspectionReport">
                  {{ t('fleet_bookings_index.view.download_print') }}
                </VBtn>
              </div>

              <!-- Individual accordion view -->
              <VExpansionPanels v-if="!inspectCompare" variant="accordion">
                <VExpansionPanel
                  v-for="insp in viewBooking.inspections" :key="insp.id"
                  :bg-color="insp.inspection_type === 'pre_trip' ? 'blue-lighten-5' : 'green-lighten-5'">
                  <VExpansionPanelTitle>
                    <VIcon size="18" class="mr-2" :color="insp.inspection_type === 'pre_trip' ? 'blue' : 'green'">
                      {{ insp.inspection_type === 'pre_trip' ? 'tabler-car-suv' : 'tabler-car' }}
                    </VIcon>
                    <div>
                      <div class="font-weight-medium">
                        {{ insp.inspection_type === 'pre_trip' ? t('fleet_bookings_index.view.check_out') : t('fleet_bookings_index.view.check_in') }}
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        {{ insp.inspected_at }} &nbsp;·&nbsp; {{ t('fleet_bookings_index.view.inspector') }}: {{ insp.inspector_name }}
                      </div>
                    </div>
                    <VSpacer />
                    <VChip size="x-small" :color="conditionRank(insp.condition_overall) >= 3 ? 'success' : conditionRank(insp.condition_overall) === 2 ? 'warning' : 'error'" class="text-capitalize mr-2">
                      {{ insp.condition_overall }}
                    </VChip>
                  </VExpansionPanelTitle>
                  <VExpansionPanelText>
                    <VRow dense class="mt-1">
                      <VCol cols="6" sm="4">
                        <div class="text-caption text-medium-emphasis">{{ t('fleet_bookings_index.view.odometer') }}</div>
                        <div class="text-body-2">{{ insp.odometer_reading?.toLocaleString() ?? '—' }} km</div>
                      </VCol>
                      <VCol cols="6" sm="4">
                        <div class="text-caption text-medium-emphasis">{{ t('fleet_bookings_index.view.fuel_level') }}</div>
                        <div class="text-body-2 text-capitalize">{{ insp.fuel_level?.replace(/_/g, ' ') ?? '—' }}</div>
                      </VCol>
                    </VRow>
                    <div class="text-caption font-weight-bold mt-3 mb-1 text-medium-emphasis">{{ t('fleet_bookings_index.view.equipment_checks') }}</div>
                    <div class="d-flex flex-wrap gap-2">
                      <VChip v-for="(val, key) in { [t('fleet_bookings_index.view.lights')]: insp.lights_ok, [t('fleet_bookings_index.view.tyres')]: insp.tyres_ok, [t('fleet_bookings_index.view.brakes')]: insp.brakes_ok, [t('fleet_bookings_index.view.wipers')]: insp.wipers_ok, [t('fleet_bookings_index.view.fluids')]: insp.fluids_ok, [t('fleet_bookings_index.view.first_aid')]: insp.first_aid_ok, [t('fleet_bookings_index.view.fire_ext')]: insp.fire_ext_ok, [t('fleet_bookings_index.view.spare_tyre')]: insp.spare_tyre_ok, [t('fleet_bookings_index.view.interior')]: insp.interior_ok }"
                        :key="key" size="x-small" :color="val ? 'success' : 'error'" label>
                        <VIcon start size="12">{{ val ? 'tabler-check' : 'tabler-x' }}</VIcon>{{ key }}
                      </VChip>
                    </div>
                    <template v-if="insp.damage_markers?.length">
                      <div class="text-caption font-weight-bold mt-3 mb-2 text-error">{{ t('fleet_bookings_index.view.damage_reported') }}</div>
                      <div class="d-flex flex-wrap gap-3">
                        <div v-for="d in insp.damage_markers" :key="d.area" class="text-center" style="max-width:140px">
                          <VChip size="x-small" color="error" label class="mb-1">{{ d.label }}</VChip>
                          <div v-if="d.photo">
                            <img :src="d.photo" style="width:130px;height:90px;object-fit:cover;border-radius:4px;border:1px solid #e53935;cursor:pointer"
                              @click="openPhoto(d.photo)" :title="`${d.label} — click to enlarge`" />
                          </div>
                          <div v-else class="text-caption text-medium-emphasis">{{ t('fleet_bookings_index.view.no_photo') }}</div>
                        </div>
                      </div>
                    </template>
                    <div v-if="insp.notes" class="text-body-2 text-medium-emphasis mt-3"><strong>{{ t('fleet_bookings_index.view.notes_label') }}:</strong> {{ insp.notes }}</div>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>

              <!-- Side-by-side comparison view -->
              <VTable v-else density="compact" class="rounded border">
                <thead>
                  <tr>
                    <th style="width:34%">{{ t('fleet_bookings_index.view.field') }}</th>
                    <th style="width:33%;background:#E3F2FD" class="text-center">
                      <VIcon size="14" color="blue" class="mr-1">tabler-car-suv</VIcon>
                      {{ t('fleet_bookings_index.view.check_out') }} · {{ preInspection?.inspected_at }}<br>
                      <span class="text-caption font-weight-regular">{{ preInspection?.inspector_name }}</span>
                    </th>
                    <th style="width:33%;background:#E8F5E9" class="text-center">
                      <VIcon size="14" color="green" class="mr-1">tabler-car</VIcon>
                      {{ t('fleet_bookings_index.view.check_in') }} · {{ postInspection?.inspected_at }}<br>
                      <span class="text-caption font-weight-regular">{{ postInspection?.inspector_name }}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="text-caption font-weight-medium">{{ t('fleet_bookings_index.view.odometer') }}</td>
                    <td class="text-center text-body-2">{{ preInspection?.odometer_reading?.toLocaleString() ?? '—' }} km</td>
                    <td class="text-center text-body-2">
                      {{ postInspection?.odometer_reading?.toLocaleString() ?? '—' }} km
                      <span v-if="preInspection?.odometer_reading && postInspection?.odometer_reading" class="text-caption text-medium-emphasis ml-1">
                        (+{{ (postInspection.odometer_reading - preInspection.odometer_reading).toLocaleString() }} km)
                      </span>
                    </td>
                  </tr>
                  <tr :class="inspDiff('fuel_level') ? 'bg-amber-lighten-5' : ''">
                    <td class="text-caption font-weight-medium">
                      {{ t('fleet_bookings_index.view.fuel_level') }}
                      <VIcon v-if="inspDiff('fuel_level')" size="14" color="warning" class="ml-1">tabler-alert-triangle</VIcon>
                    </td>
                    <td class="text-center text-body-2 text-capitalize">{{ preInspection?.fuel_level?.replace(/_/g,' ') ?? '—' }}</td>
                    <td class="text-center text-body-2 text-capitalize" :class="fuelRank(postInspection?.fuel_level ?? null) < fuelRank(preInspection?.fuel_level ?? null) ? 'text-error font-weight-bold' : ''">
                      {{ postInspection?.fuel_level?.replace(/_/g,' ') ?? '—' }}
                    </td>
                  </tr>
                  <tr :class="inspDiff('condition_overall') ? 'bg-amber-lighten-5' : ''">
                    <td class="text-caption font-weight-medium">
                      {{ t('fleet_bookings_index.view.overall_condition') }}
                      <VIcon v-if="inspDiff('condition_overall')" size="14" color="warning" class="ml-1">tabler-alert-triangle</VIcon>
                    </td>
                    <td class="text-center">
                      <VChip size="x-small" :color="conditionRank(preInspection?.condition_overall ?? null) >= 3 ? 'success' : 'warning'" class="text-capitalize">{{ preInspection?.condition_overall ?? '—' }}</VChip>
                    </td>
                    <td class="text-center">
                      <VChip size="x-small" :color="conditionRank(postInspection?.condition_overall ?? null) >= 3 ? 'success' : conditionRank(postInspection?.condition_overall ?? null) === 2 ? 'warning' : 'error'" class="text-capitalize">{{ postInspection?.condition_overall ?? '—' }}</VChip>
                    </td>
                  </tr>
                  <tr class="bg-grey-lighten-4">
                    <td colspan="3" class="text-caption font-weight-bold text-medium-emphasis py-1 px-3">{{ t('fleet_bookings_index.view.equipment_checks') }}</td>
                  </tr>
                  <tr v-for="eq in [
                    { key: 'lights_ok',     label: t('fleet_bookings_index.view.lights') },
                    { key: 'tyres_ok',      label: t('fleet_bookings_index.view.tyres') },
                    { key: 'brakes_ok',     label: t('fleet_bookings_index.view.brakes') },
                    { key: 'wipers_ok',     label: t('fleet_bookings_index.view.wipers') },
                    { key: 'fluids_ok',     label: t('fleet_bookings_index.view.fluids') },
                    { key: 'first_aid_ok',  label: t('fleet_bookings_index.view.first_aid') },
                    { key: 'fire_ext_ok',   label: t('fleet_bookings_index.view.fire_ext') },
                    { key: 'spare_tyre_ok', label: t('fleet_bookings_index.view.spare_tyre') },
                    { key: 'interior_ok',   label: t('fleet_bookings_index.view.interior') },
                  ]" :key="eq.key"
                    :class="equipDegraded(eq.key as keyof InspectionReport) ? 'bg-red-lighten-5' : ''">
                    <td class="text-caption font-weight-medium">
                      {{ eq.label }}
                      <VIcon v-if="equipDegraded(eq.key as keyof InspectionReport)" size="14" color="error" class="ml-1">tabler-alert-circle</VIcon>
                    </td>
                    <td class="text-center">
                      <VIcon :color="(preInspection as any)?.[eq.key] ? 'success' : 'error'" size="18">
                        {{ (preInspection as any)?.[eq.key] ? 'tabler-circle-check' : 'tabler-circle-x' }}
                      </VIcon>
                    </td>
                    <td class="text-center">
                      <VIcon :color="(postInspection as any)?.[eq.key] ? 'success' : 'error'" size="18">
                        {{ (postInspection as any)?.[eq.key] ? 'tabler-circle-check' : 'tabler-circle-x' }}
                      </VIcon>
                    </td>
                  </tr>
                  <tr class="bg-grey-lighten-4">
                    <td colspan="3" class="text-caption font-weight-bold text-medium-emphasis py-1 px-3">{{ t('fleet_bookings_index.view.damage_reported') }}</td>
                  </tr>
                  <tr>
                    <td class="text-caption font-weight-medium" style="vertical-align:top;padding-top:8px">{{ t('fleet_bookings_index.view.damage_reported') }}</td>
                    <td style="vertical-align:top;padding:6px">
                      <span v-if="!preInspection?.damage_markers?.length" class="text-success text-caption">{{ t('fleet_bookings_index.view.none') }}</span>
                      <div v-for="d in preInspection?.damage_markers" :key="d.area" class="mb-2">
                        <VChip size="x-small" color="error" label class="mb-1">{{ d.label }}</VChip>
                        <div v-if="d.photo">
                          <img :src="d.photo" style="width:110px;height:75px;object-fit:cover;border-radius:4px;border:1px solid #e53935;cursor:pointer;display:block"
                            @click="openPhoto(d.photo)" />
                        </div>
                        <div v-else class="text-caption text-medium-emphasis">{{ t('fleet_bookings_index.view.no_photo') }}</div>
                      </div>
                    </td>
                    <td style="vertical-align:top;padding:6px">
                      <span v-if="!postInspection?.damage_markers?.length" class="text-success text-caption">{{ t('fleet_bookings_index.view.none') }}</span>
                      <div v-for="d in postInspection?.damage_markers" :key="d.area" class="mb-2">
                        <VChip size="x-small"
                          :color="preInspection?.damage_markers?.some(p => p.area === d.area) ? 'error' : 'deep-orange'"
                          label class="mb-1">
                          {{ d.label }}
                          <VIcon v-if="!preInspection?.damage_markers?.some(p => p.area === d.area)" end size="10">{{ t('fleet_bookings_index.view.new_damage') }}</VIcon>
                        </VChip>
                        <div v-if="d.photo">
                          <img :src="d.photo" style="width:110px;height:75px;object-fit:cover;border-radius:4px;border:1px solid #e53935;cursor:pointer;display:block"
                            @click="openPhoto(d.photo)" />
                        </div>
                        <div v-else class="text-caption text-medium-emphasis">{{ t('fleet_bookings_index.view.no_photo') }}</div>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="preInspection?.notes || postInspection?.notes">
                    <td class="text-caption font-weight-medium">{{ t('fleet_bookings_index.view.notes_label') }}</td>
                    <td class="text-caption text-medium-emphasis">{{ preInspection?.notes ?? '—' }}</td>
                    <td class="text-caption text-medium-emphasis">{{ postInspection?.notes ?? '—' }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3 justify-end">
          <VSpacer />
          <VBtn variant="tonal" @click="viewDialog = false">{{ t('fleet_bookings_index.dialog.close') }}</VBtn>
          <VBtn v-if="viewBooking && viewBooking.workflow_state === 'approved'" color="primary"
            @click="() => { viewDialog = false; doAction(viewBooking!, 'collect') }">
            {{ t('fleet_bookings_index.view.collect_vehicle') }}
          </VBtn>
          <VBtn v-if="viewBooking && viewBooking.workflow_state === 'security_in'" color="success"
            prepend-icon="tabler-car-suv"
            @click="openReturnDialog(viewBooking!)">
            {{ t('fleet_bookings_index.view.return_vehicle_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reset Approval Steps Dialog -->
    <VDialog v-model="resetDialog" max-width="420" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2">
          <VIcon color="warning">tabler-refresh</VIcon>
          {{ t('fleet_bookings_index.view.reset_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="mb-2">
            {{ t('fleet_bookings_index.view.reset_message', { reference: resetBooking?.reference }) }}
          </p>
          <p class="text-medium-emphasis text-body-2">
            Steps that have already been approved will not be changed.
            Approvers for the corrected active step will be notified.
          </p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3 justify-end ga-2">
          <VBtn variant="tonal" :disabled="resetProcessing" @click="resetDialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn color="warning" variant="flat" :loading="resetProcessing" @click="confirmReset">{{ t('fleet_bookings_index.view.reset_confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Return Vehicle Dialog -->
    <VDialog v-model="returnDialog" max-width="700" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2">
          <VIcon color="success">tabler-car-suv</VIcon>
          {{ t('fleet_bookings_index.return_vehicle') }} — {{ returnBooking?.reference }}
        </VCardTitle>
        <VDivider />

        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" class="mb-4" density="compact">
            Confirm the return. Expand any section to record additional items from this trip.
          </VAlert>

          <VExpansionPanels multiple variant="accordion">

            <!-- Fuel Log -->
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon class="mr-2" color="amber-darken-2">tabler-gas-station</VIcon>
                <strong>{{ t('fleet_bookings_index.add_fuel_log') }}</strong>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VSelect v-model="retFuel.fuel_type" label="Fuel Type *"
                      :items="['petrol','diesel','hybrid','electric','lpg']" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model.number="retFuel.litres" label="Litres *"
                      type="number" min="0" step="0.001" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model.number="retFuel.cost_per_litre" label="Cost per Litre (R)"
                      type="number" min="0" step="0.01" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model.number="retFuel.total_cost" label="Total Cost (R) — auto-calculated"
                      type="number" min="0" step="0.01" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VAutocomplete
                      v-model="retFuel.station_name"
                      label="Filling Station"
                      :items="props.fillingStations.map(s => ({ title: s.location ? `${s.name} — ${s.location}` : s.name, value: s.name }))"
                      density="compact"
                      clearable
                      auto-select-first
                      no-data-text="No stations in setup — type manually"
                    />
                  </VCol>
                  <VCol cols="6">
                    <VTextField
                      :model-value="returnBooking?.end_odometer?.toLocaleString() ?? '—'"
                      label="Odometer at check-in (km)"
                      density="compact"
                      readonly
                      hint="Recorded by security at check-in"
                      persistent-hint
                    />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retFuel.filled_at" label="Filled At"
                      type="datetime-local" density="compact" />
                  </VCol>
                  <VCol cols="6" class="d-flex align-center">
                    <VCheckbox v-model="retFuel.full_tank" label="Full tank" density="compact" hide-details />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      label="Fuel Receipt (PDF / image)"
                      prepend-icon="tabler-paperclip"
                      accept=".pdf,.jpg,.jpeg,.png"
                      density="compact"
                      show-size
                      @update:model-value="fuelReceiptFile = $event as File | null"
                    />
                  </VCol>
                </VRow>
              </VExpansionPanelText>
            </VExpansionPanel>

            <!-- Fine -->
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon class="mr-2" color="red-darken-2">tabler-alert-octagon</VIcon>
                <strong>{{ t('fleet_bookings_index.add_fine') }}</strong>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VTextField v-model="retFine.fine_number" label="Fine / Notice Number" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model.number="retFine.amount" label="Amount (R) *"
                      type="number" min="0" step="0.01" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retFine.offence_date" label="Offence Date"
                      type="datetime-local" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retFine.due_date" label="Due Date"
                      type="date" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VTextField v-model="retFine.offence_description" label="Offence Description" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retFine.location" label="Location" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retFine.issuing_authority" label="Issuing Authority"
                      density="compact" placeholder="e.g. JMPD, SAPS, RTIA" />
                  </VCol>
                  <VCol cols="12">
                    <VTextField v-model="retFine.notes" label="Notes" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      label="Infringement Notice (PDF / image)"
                      prepend-icon="tabler-paperclip"
                      accept=".pdf,.jpg,.jpeg,.png"
                      density="compact"
                      show-size
                      @update:model-value="fineNoticeFile = $event as File | null"
                    />
                  </VCol>
                </VRow>
              </VExpansionPanelText>
            </VExpansionPanel>

            <!-- Incident -->
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon class="mr-2" color="orange-darken-2">tabler-ambulance</VIcon>
                <strong>{{ t('fleet_bookings_index.add_incident') }}</strong>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VSelect v-model="retInc.incident_type" label="Incident Type *"
                      :items="[{title:'Accident',value:'accident'},{title:'Breakdown',value:'breakdown'},{title:'Theft',value:'theft'},{title:'Damage',value:'damage'},{title:'Other',value:'other'}]"
                      density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VSelect v-model="retInc.severity" label="Severity *"
                      :items="[{title:'Low',value:'low'},{title:'Medium',value:'medium'},{title:'High',value:'high'},{title:'Critical',value:'critical'}]"
                      density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VTextarea v-model="retInc.description" label="Description *" rows="2" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retInc.incident_at" label="Incident Date & Time"
                      type="datetime-local" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retInc.location" label="Location" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retInc.police_case_number" label="Police Case Number" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      label="Evidence Photos / Documents (multiple)"
                      prepend-icon="tabler-camera"
                      accept=".pdf,.jpg,.jpeg,.png"
                      density="compact"
                      show-size
                      multiple
                      @update:model-value="incEvidenceFiles = ($event as File[]) ?? []"
                    />
                  </VCol>
                </VRow>
              </VExpansionPanelText>
            </VExpansionPanel>

            <!-- General Cost -->
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon class="mr-2" color="blue-darken-2">tabler-cash</VIcon>
                <strong>{{ t('fleet_bookings_index.add_general_cost') }}</strong>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VTextField v-model="retGc.cost_type" label="Cost Type"
                      density="compact" placeholder="e.g. Parking, Repair, Accommodation" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model.number="retGc.cost" label="Amount (R) *"
                      type="number" min="0" step="0.01" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retGc.supplier_name" label="Supplier / Payee" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retGc.date" label="Date" type="date" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VTextField v-model="retGc.description" label="Description" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      label="Receipt / Invoice (PDF / image)"
                      prepend-icon="tabler-paperclip"
                      accept=".pdf,.jpg,.jpeg,.png"
                      density="compact"
                      show-size
                      @update:model-value="gcReceiptFile = $event as File | null"
                    />
                  </VCol>
                </VRow>
              </VExpansionPanelText>
            </VExpansionPanel>

            <!-- Tollgate -->
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon class="mr-2" color="teal-darken-2">tabler-barrier-block</VIcon>
                <strong>{{ t('fleet_bookings_index.add_toll_gate') }}</strong>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VTextField v-model.number="retToll.amount" label="Amount (R) *"
                      type="number" min="0" step="0.01" density="compact" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="retToll.toll_at" label="Date & Time"
                      type="datetime-local" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VTextField v-model="retToll.toll_location" label="Toll Plaza / Location" density="compact" />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      label="Toll Receipt (PDF / image)"
                      prepend-icon="tabler-paperclip"
                      accept=".pdf,.jpg,.jpeg,.png"
                      density="compact"
                      show-size
                      @update:model-value="retTollReceiptFile = $event as File | null"
                    />
                  </VCol>
                </VRow>
              </VExpansionPanelText>
            </VExpansionPanel>

          </VExpansionPanels>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-3 justify-end ga-2">
          <VBtn variant="tonal" :disabled="returnProcessing" @click="returnDialog = false">{{ t('fleet_bookings_index.cancel') }}</VBtn>
          <VBtn color="success" variant="flat" prepend-icon="tabler-circle-check" :loading="returnProcessing" @click="submitReturn">
            {{ t('fleet_bookings_index.confirm_return') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
