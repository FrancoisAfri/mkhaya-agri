<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Vehicle {
  id: number; uuid: string
  registration_number: string; registration: string; fleet_number: string | null
  make: string | null; model: string | null; year: number | null
  colour: string | null; color: string | null
  vin_number: string | null; vin: string | null
  chassis_number: string | null; engine_number: string | null
  fuel_type: string | null; tank_size: number | null
  vehicle_type: string | null; transmission: string | null
  engine_capacity: number | null
  seating_capacity: number | null; seats: number | null
  odometer_reading: number; odometer_unit: string
  vehicle_cell_number: string | null; tracking_cell_number: string | null
  owner_name: string | null; title_holder_name: string | null
  extras: string | null; fleet_card: string | null
  status: string; approval_status: string | null
  location: string | null; key_safe_name: string | null
  department: string | null; company_unit: string | null; division: string | null
  company_unit_id: number | null; assigned_driver_id: number | null
  driver_name: string | null; current_driver: string | null
  purchase_date: string | null; purchase_date_raw: string | null
  purchase_price: number | null; current_value: number | null
  expected_disposal_date: string | null; expected_disposal_date_raw: string | null
  co2_emissions_g_per_km: number | null
  registration_document_path: string | null
  notes: string | null
  licence_disc_expiry: string | null; roadworthy_expiry: string | null; insurance_expiry: string | null
}
interface Lookup       { id: number; name: string }
interface VehicleModel { id: number; make_id: number; name: string }
interface DocType { id: number; name: string }
interface KeySafe    { id: number; name: string }
interface Driver     { id: number; name: string }
interface Employee   { id: number; name: string }
interface VehicleKey {
  id: number; key_number: string | null; key_type: string; key_status: string
  description: string | null; issued_to_type: string
  employee_name: string | null; safe_name: string | null
  date_issued: string | null; issued_by: string | null; reason_for_loss: string | null
}

const props = defineProps<{
  vehicle: Vehicle
  images: Array<{ id: number; file_path: string | null; file_name: string; is_primary: boolean; mime_type: string }>
  vehicleKeys: VehicleKey[]
  employees: Employee[]
  bookings:    any[]; fuelLogs: any[]; incidents: any[]; fines: any[]
  maintenance: any[]; documents: any[]; insurance: any[]; warranties: any[]
  permits: any[]; tollgates: any[]; costs: { fuel_total: number; maintenance_total: number; toll_total: number; general_costs_total: number; grand_total: number }
  notes:       any[]; odometerLogs: any[]
  reminders: any[]; fireExtinguishers: any[]; generalCosts: any[]
  documentTypes: DocType[]; documentSuppliers: { id: number; name: string }[]
  keySafes: KeySafe[]; drivers: Driver[]
  makes: Lookup[]; vehicleModels: VehicleModel[]
  vehicleTypes: Lookup[]; fuelTypes: Lookup[]; companyUnits: Lookup[]
}>()

const tab = ref('general')
const snack = ref<{ show: boolean; msg: string; color: string }>({ show: false, msg: '', color: 'success' })
function toast(msg: string, color = 'success') { snack.value = { show: true, msg, color } }

const confirmDialog  = ref(false)
const confirmMessage = ref('')
const pendingDelete  = ref<(() => void) | null>(null)

function askConfirm(message: string, action: () => void) {
  confirmMessage.value = message
  pendingDelete.value  = action
  confirmDialog.value  = true
}

function doConfirmedDelete() {
  pendingDelete.value?.()
  confirmDialog.value = false
  pendingDelete.value = null
}

function imgSrc(path: string | null): string {
  if (!path) return ''
  try { const d = JSON.parse(path); return `data:${d.mime};base64,${d.data}` } catch { return path }
}

const statusLabel = (s: string) => {
  const labels: Record<string, string> = {
    available: t('fleet_vehicles_index.statuses.available'),
    booked: t('fleet_vehicles_index.statuses.booked'),
    in_use: t('fleet_vehicles_index.statuses.in_use'),
    maintenance: t('fleet_vehicles_index.statuses.maintenance'),
    retired: t('fleet_vehicles_index.statuses.retired'),
    inactive: t('fleet_vehicles_index.statuses.inactive'),
  }
  return labels[s] ?? s.replace(/_/g, ' ')
}

const statusColor = (s: string) => ({ available: 'success', booked: 'primary', in_use: 'deep-purple', maintenance: 'warning', offroad: 'error', disposed: 'grey' })[s] ?? 'grey'
const stateColor = (s: string) => ({ pending: 'warning', approved: 'success', collected: 'teal', security_out: 'primary', security_in: 'deep-purple', returned: 'grey', cancelled: 'error', rejected: 'error', turned_back: 'orange' })[s] ?? 'grey'
const severityColor = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' })[s] ?? 'grey'
const priorityColor = (p: string) => ({ emergency: 'error', high: 'warning', normal: 'info', low: 'success' })[p] ?? 'grey'
const docDaysColor  = (d: number | null) => !d ? 'error' : d <= 7 ? 'error' : d <= 30 ? 'warning' : 'success'
const keyStatusColor = (s: string) => ({ in_safe: 'success', with_employee: 'primary', lost: 'error', damaged: 'warning' })[s] ?? 'grey'

const bookingViewDialog = ref(false)
const bookingViewItem   = ref<any | null>(null)
function openBookingView(b: any) { bookingViewItem.value = b; bookingViewDialog.value = true }

const noteDialog   = ref(false)
const editingNoteId = ref<number | null>(null)
const noteForm = useForm({ note_type: 'general', body: '', severity: 'info' })

function openAddNote() {
  editingNoteId.value = null
  noteForm.reset()
  noteForm.note_type = 'general'
  noteForm.severity  = 'info'
  noteDialog.value   = true
}

function openEditNote(n: any) {
  editingNoteId.value  = n.id
  noteForm.note_type   = n.note_type
  noteForm.body        = n.body
  noteForm.severity    = n.severity
  noteDialog.value     = true
}

function saveNote() {
  if (editingNoteId.value) {
    noteForm.patch(route('fleet.vehicles.notes.update', editingNoteId.value), {
      preserveScroll: true,
      onSuccess: () => { noteDialog.value = false; noteForm.reset(); toast(t('fleet_vehicles_show.toasts.note_updated')) },
    })
  } else {
    noteForm.post(route('fleet.vehicles.notes.store', props.vehicle.uuid), {
      preserveScroll: true,
      onSuccess: () => { noteDialog.value = false; noteForm.reset(); toast(t('fleet_vehicles_show.toasts.note_added')) },
    })
  }
}

function deleteNote(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.note_removed'), () => {
    router.delete(route('fleet.vehicles.notes.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.note_removed')),
    })
  })
}

const imgDialog  = ref(false)
const imgFiles   = ref<File[]>([])
const uploading  = ref(false)
function uploadImages() {
  if (!imgFiles.value.length) return
  uploading.value = true
  const fd = new FormData()
  imgFiles.value.forEach(f => fd.append('images[]', f))
  router.post(route('fleet.vehicles.images.upload', props.vehicle.id), fd as any, {
    preserveScroll: true,
    onSuccess: () => {
      uploading.value = false
      imgDialog.value = false
      imgFiles.value = []
      toast(t('fleet_vehicles_show.toasts.images_uploaded'))
    },
    onError: () => {
      uploading.value = false
      toast(t('fleet_vehicles_show.toasts.upload_failed'), 'error')
    },
  })
}

const docDialog = ref(false)
const editingDocumentId = ref<number | null>(null)
const docCategory = ref('general')
const docForm = useForm({
  document_type: '', supplier: '', document_number: '',
  issued_date: '', expiry_date: '', issuing_authority: '',
  status: 'valid' as string, notes: '',
  file: null as File | null,
})

function openAddDocument(category = 'general') {
  editingDocumentId.value = null
  docCategory.value = category
  docForm.reset()
  docForm.status = 'valid'
  docDialog.value = true
}

function openEditDocument(d: any) {
  editingDocumentId.value   = d.id
  docCategory.value         = d.document_category ?? 'general'
  docForm.document_type     = d.document_type ?? ''
  docForm.supplier          = d.supplier ?? ''
  docForm.document_number   = d.document_number ?? ''
  docForm.issued_date       = d.issued_date_raw ?? ''
  docForm.expiry_date       = d.expiry_date_raw ?? ''
  docForm.issuing_authority = d.issuing_authority ?? ''
  docForm.status            = d.status ?? 'valid'
  docForm.notes             = d.notes ?? ''
  docForm.file              = null
  docDialog.value = true
}

function submitDoc() {
  const fd = new FormData()
  fd.append('vehicle_id',         String(props.vehicle.id))
  fd.append('document_type',      docForm.document_type)
  fd.append('document_category',  docCategory.value)
  if (docForm.supplier)          fd.append('supplier',          docForm.supplier)
  if (docForm.document_number)   fd.append('document_number',   docForm.document_number)
  if (docForm.issued_date)       fd.append('issued_date',       docForm.issued_date)
  if (docForm.expiry_date)       fd.append('expiry_date',       docForm.expiry_date)
  if (docForm.issuing_authority) fd.append('issuing_authority', docForm.issuing_authority)
  fd.append('status', docForm.status)
  if (docForm.notes) fd.append('notes', docForm.notes)
  if (docForm.file)  fd.append('file', docForm.file)

  if (editingDocumentId.value) {
    fd.append('_method', 'PATCH')
    router.post(route('fleet.documents.update', editingDocumentId.value), fd as any, {
      preserveScroll: true,
      onSuccess: () => { docDialog.value = false; docForm.reset(); toast(t('fleet_vehicles_show.toasts.document_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    router.post(route('fleet.documents.store'), fd as any, {
      preserveScroll: true,
      onSuccess: () => { docDialog.value = false; docForm.reset(); toast(t('fleet_vehicles_show.toasts.document_added')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  }
}

const permitDialog = ref(false)
const editingDocId = ref<number | null>(null)

const permitForm = useForm({
  document_type:   '',
  supplier:        '',
  document_number: '',
  issued_date:     '',
  expiry_date:     '',
  status:          'valid' as string,
  file:            null as File | null,
})

function openAddPermit() {
  editingDocId.value = null
  permitForm.reset()
  permitForm.status = 'valid'
  permitDialog.value = true
}

function openEditPermit(d: any) {
  editingDocId.value   = d.id
  permitForm.document_type   = d.document_type ?? ''
  permitForm.supplier        = d.supplier ?? ''
  permitForm.document_number = d.document_number ?? ''
  permitForm.issued_date     = d.issued_date_raw ?? ''
  permitForm.expiry_date     = d.expiry_date_raw ?? ''
  permitForm.status          = d.status ?? 'valid'
  permitForm.file            = null
  permitDialog.value = true
}

function submitPermit() {
  const fd = new FormData()
  fd.append('vehicle_id',        String(props.vehicle.id))
  fd.append('document_type',     permitForm.document_type)
  fd.append('document_category', 'permit_licence')
  if (permitForm.supplier)        fd.append('supplier',        permitForm.supplier)
  if (permitForm.document_number) fd.append('document_number', permitForm.document_number)
  if (permitForm.issued_date)     fd.append('issued_date',     permitForm.issued_date)
  if (permitForm.expiry_date)     fd.append('expiry_date',     permitForm.expiry_date)
  fd.append('status', permitForm.status)
  if (permitForm.file) fd.append('file', permitForm.file)

  if (editingDocId.value) {
    fd.append('_method', 'PATCH')
    router.post(route('fleet.documents.update', editingDocId.value), fd as any, {
      preserveScroll: true,
      onSuccess: () => { permitDialog.value = false; permitForm.reset(); toast(t('fleet_vehicles_show.toasts.document_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    router.post(route('fleet.documents.store'), fd as any, {
      preserveScroll: true,
      onSuccess: () => { permitDialog.value = false; permitForm.reset(); toast(t('fleet_vehicles_show.toasts.permit_added')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  }
}

function deleteDocument(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.document_removed'), () => {
    router.delete(route('fleet.documents.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.document_removed')),
    })
  })
}

const serviceDialog    = ref(false)
const editingServiceId = ref<number | null>(null)
const serviceForm = useForm({
  maintenance_type:         'scheduled' as string,
  category:                 '' as string,
  description:              '',
  scheduled_date:           '',
  completed_date:           '',
  odometer_at_service:      '' as string | number,
  next_service_km:          '' as string | number,
  next_service_date:        '',
  service_provider:         '',
  service_provider_contact: '',
  cost:                     '' as string | number,
  labour_cost:              '' as string | number,
  parts_cost:               '' as string | number,
  invoice_number:           '',
  status:                   'scheduled' as string,
  priority:                 'normal' as string,
  notes:                    '',
})

function openAddService() {
  editingServiceId.value = null
  serviceForm.reset()
  serviceForm.maintenance_type = 'scheduled'
  serviceForm.status           = 'scheduled'
  serviceForm.priority         = 'normal'
  serviceDialog.value = true
}

function openEditService(m: any) {
  editingServiceId.value            = m.id
  serviceForm.maintenance_type      = m.type ?? 'scheduled'
  serviceForm.category              = m.category ?? ''
  serviceForm.description           = m.description ?? ''
  serviceForm.scheduled_date        = m.scheduled_date_raw ?? ''
  serviceForm.completed_date        = m.completed_date_raw ?? ''
  serviceForm.odometer_at_service   = m.odometer_at_service ?? ''
  serviceForm.next_service_km       = m.next_service_km ?? ''
  serviceForm.next_service_date     = m.next_service_date_raw ?? ''
  serviceForm.service_provider      = m.service_provider ?? ''
  serviceForm.service_provider_contact = m.service_provider_contact ?? ''
  serviceForm.cost                  = m.cost ?? ''
  serviceForm.labour_cost           = m.labour_cost ?? ''
  serviceForm.parts_cost            = m.parts_cost ?? ''
  serviceForm.invoice_number        = m.invoice ?? ''
  serviceForm.status                = m.status ?? 'scheduled'
  serviceForm.priority              = m.priority ?? 'normal'
  serviceForm.notes                 = m.notes ?? ''
  serviceDialog.value = true
}

function submitService() {
  if (editingServiceId.value) {
    serviceForm.patch(route('fleet.maintenance.update', editingServiceId.value), {
      preserveScroll: true,
      onSuccess: () => { serviceDialog.value = false; serviceForm.reset(); toast(t('fleet_vehicles_show.toasts.service_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    serviceForm.transform(d => ({ ...d, vehicle_id: props.vehicle.id }))
      .post(route('fleet.maintenance.store'), {
        preserveScroll: true,
        onSuccess: () => { serviceDialog.value = false; serviceForm.reset(); toast(t('fleet_vehicles_show.toasts.service_added')) },
        onError:   () => toast('Please fix the errors.', 'error'),
      })
  }
}

function deleteService(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.service_removed'), () => {
    router.delete(route('fleet.maintenance.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.service_removed')),
    })
  })
}

const insuranceDialog    = ref(false)
const editingInsuranceId = ref<number | null>(null)
const insuranceForm = useForm({
  document_type:      '',
  supplier:           '',
  contact_person:     '',
  contact_number:     '',
  contact_email:      '',
  contact_address:    '',
  value_covered:      '' as string | number,
  payment_frequency:  'annual' as string,
  premium_amount:     '' as string | number,
  document_number:    '',
  issued_date:        '',
  expiry_date:        '',
  status:             'valid' as string,
  notes:              '',
  file:               null as File | null,
})

function openAddInsurance() {
  editingInsuranceId.value = null
  insuranceForm.reset()
  insuranceForm.status            = 'valid'
  insuranceForm.payment_frequency = 'annual'
  insuranceDialog.value = true
}

function openEditInsurance(d: any) {
  editingInsuranceId.value        = d.id
  insuranceForm.document_type     = d.document_type ?? ''
  insuranceForm.supplier          = d.supplier ?? ''
  insuranceForm.contact_person    = d.contact_person ?? ''
  insuranceForm.contact_number    = d.contact_number ?? ''
  insuranceForm.contact_email     = d.contact_email ?? ''
  insuranceForm.contact_address   = d.contact_address ?? ''
  insuranceForm.value_covered     = d.value_covered ?? ''
  insuranceForm.payment_frequency = d.payment_frequency ?? 'annual'
  insuranceForm.premium_amount    = d.premium_amount ?? ''
  insuranceForm.document_number   = d.document_number ?? ''
  insuranceForm.issued_date       = d.issued_date_raw ?? ''
  insuranceForm.expiry_date       = d.expiry_date_raw ?? ''
  insuranceForm.status            = d.status ?? 'valid'
  insuranceForm.notes             = d.notes ?? ''
  insuranceForm.file              = null
  insuranceDialog.value = true
}

function submitInsurance() {
  const fd = new FormData()
  fd.append('vehicle_id',        String(props.vehicle.id))
  fd.append('document_type',     insuranceForm.document_type)
  fd.append('document_category', 'insurance')
  if (insuranceForm.supplier)          fd.append('supplier',          insuranceForm.supplier)
  if (insuranceForm.contact_person)    fd.append('contact_person',    insuranceForm.contact_person)
  if (insuranceForm.contact_number)    fd.append('contact_number',    insuranceForm.contact_number)
  if (insuranceForm.contact_email)     fd.append('contact_email',     insuranceForm.contact_email)
  if (insuranceForm.contact_address)   fd.append('contact_address',   insuranceForm.contact_address)
  if (insuranceForm.value_covered !== '') fd.append('value_covered',  String(insuranceForm.value_covered))
  if (insuranceForm.payment_frequency) fd.append('payment_frequency', insuranceForm.payment_frequency)
  if (insuranceForm.premium_amount !== '') fd.append('premium_amount', String(insuranceForm.premium_amount))
  if (insuranceForm.document_number)   fd.append('document_number',   insuranceForm.document_number)
  if (insuranceForm.issued_date)       fd.append('issued_date',       insuranceForm.issued_date)
  if (insuranceForm.expiry_date)       fd.append('expiry_date',       insuranceForm.expiry_date)
  fd.append('status', insuranceForm.status)
  if (insuranceForm.notes)  fd.append('notes',  insuranceForm.notes)
  if (insuranceForm.file)   fd.append('file',   insuranceForm.file)

  if (editingInsuranceId.value) {
    fd.append('_method', 'PATCH')
    router.post(route('fleet.documents.update', editingInsuranceId.value), fd as any, {
      preserveScroll: true,
      onSuccess: () => { insuranceDialog.value = false; insuranceForm.reset(); toast(t('fleet_vehicles_show.toasts.insurance_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    router.post(route('fleet.documents.store'), fd as any, {
      preserveScroll: true,
      onSuccess: () => { insuranceDialog.value = false; insuranceForm.reset(); toast(t('fleet_vehicles_show.toasts.insurance_added')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  }
}

const statusDialog = ref(false)
const statusForm   = useForm({ status: props.vehicle.status, reason: '' })
function changeStatus() {
  statusForm.patch(route('fleet.vehicles.change-status', props.vehicle.uuid), {
    preserveScroll: true,
    onSuccess: () => { statusDialog.value = false; toast(t('fleet_vehicles_show.toasts.status_updated')) },
  })
}

const keyDialog = ref(false)
const keyForm = useForm({
  key_number: '',
  key_type: 'spare',
  key_status: 'in_safe',
  description: '',
  issued_to_type: 'safe' as 'safe' | 'employee',
  issued_to_employee_id: null as number | null,
  safe_id: null as number | null,
  date_issued: '',
  issued_by_id: null as number | null,
  reason_for_loss: '',
})

function openAddKey() { keyForm.reset(); keyForm.issued_to_type = 'safe'; keyDialog.value = true }

function submitKey() {
  keyForm.post(route('fleet.vehicles.keys.store', props.vehicle.uuid), {
    preserveScroll: true,
    onSuccess: () => { keyDialog.value = false; keyForm.reset(); toast(t('fleet_vehicles_show.toasts.key_added')) },
  })
}

function doDeleteKey(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.key_removed'), () => {
    router.delete(route('fleet.vehicles.keys.delete', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.key_removed')),
    })
  })
}

const reminderDialog = ref(false)
const editingReminderId = ref<number | null>(null)
const reminderForm = useForm({ name: '', description: '', start_date: '', end_date: '' })

function openAddReminder() {
  editingReminderId.value = null
  reminderForm.reset()
  reminderDialog.value = true
}

function openEditReminder(r: any) {
  editingReminderId.value   = r.id
  reminderForm.name         = r.name ?? ''
  reminderForm.description  = r.description ?? ''
  reminderForm.start_date   = r.start_date_raw ?? ''
  reminderForm.end_date     = r.end_date_raw ?? ''
  reminderDialog.value      = true
}

function saveReminder() {
  if (editingReminderId.value) {
    reminderForm.patch(route('fleet.vehicles.reminders.update', editingReminderId.value), {
      preserveScroll: true,
      onSuccess: () => { reminderDialog.value = false; reminderForm.reset(); toast(t('fleet_vehicles_show.toasts.reminder_updated')) },
    })
  } else {
    reminderForm.post(route('fleet.vehicles.reminders.store', props.vehicle.uuid), {
      preserveScroll: true,
      onSuccess: () => { reminderDialog.value = false; reminderForm.reset(); toast(t('fleet_vehicles_show.toasts.reminder_added')) },
    })
  }
}

function deleteReminder(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.reminder_removed'), () => {
    router.delete(route('fleet.vehicles.reminders.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.reminder_removed')),
    })
  })
}

const extDialog = ref(false)
const editingExtId = ref<number | null>(null)
const extForm = useForm({
  barcode: '', item: '', description: '',
  weight_kg: '' as string | number,
  serial_number: '', invoice_number: '', purchase_order_number: '',
  supplier: '', date_purchased: '',
  cost: '' as string | number,
  status: 'active',
  image: null as File | null,
  document: null as File | null,
})

function openAddExt() {
  editingExtId.value = null
  extForm.reset()
  extForm.status = 'active'
  extDialog.value = true
}

function openEditExt(e: any) {
  editingExtId.value           = e.id
  extForm.barcode              = e.barcode ?? ''
  extForm.item                 = e.item ?? ''
  extForm.description          = e.description ?? ''
  extForm.weight_kg            = e.weight_kg ?? ''
  extForm.serial_number        = e.serial_number ?? ''
  extForm.invoice_number       = e.invoice_number ?? ''
  extForm.purchase_order_number = e.purchase_order_number ?? ''
  extForm.supplier             = e.supplier ?? ''
  extForm.date_purchased       = e.date_purchased_raw ?? ''
  extForm.cost                 = e.cost ?? ''
  extForm.status               = e.status ?? 'active'
  extForm.image                = null
  extForm.document             = null
  extDialog.value = true
}

function saveExt() {
  const fd = new FormData()
  const fields: Array<keyof typeof extForm> = [
    'barcode', 'item', 'description', 'weight_kg', 'serial_number',
    'invoice_number', 'purchase_order_number', 'supplier', 'date_purchased', 'cost', 'status',
  ]
  fields.forEach(k => { if ((extForm as any)[k] !== '' && (extForm as any)[k] !== null) fd.append(k, String((extForm as any)[k])) })
  if (extForm.image)    fd.append('image', extForm.image)
  if (extForm.document) fd.append('document', extForm.document)

  if (editingExtId.value) {
    fd.append('_method', 'PATCH')
    router.post(route('fleet.vehicles.fire-extinguishers.update', editingExtId.value), fd as any, {
      preserveScroll: true,
      onSuccess: () => { extDialog.value = false; extForm.reset(); toast(t('fleet_vehicles_show.toasts.extinguisher_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    router.post(route('fleet.vehicles.fire-extinguishers.store', props.vehicle.uuid), fd as any, {
      preserveScroll: true,
      onSuccess: () => { extDialog.value = false; extForm.reset(); toast(t('fleet_vehicles_show.toasts.extinguisher_added')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  }
}

function deleteExt(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.extinguisher_removed'), () => {
    router.delete(route('fleet.vehicles.fire-extinguishers.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.extinguisher_removed')),
    })
  })
}

const generalCostDialog    = ref(false)
const editingGeneralCostId = ref<number | null>(null)
const generalCostForm = useForm({
  date:                  '',
  document_number:       '',
  supplier_name:         '',
  cost_type:             '',
  cost:                  '' as string | number,
  description:           '',
  person_responsible_id: null as number | null,
})

function openAddGeneralCost() {
  editingGeneralCostId.value = null
  generalCostForm.reset()
  generalCostDialog.value = true
}

function openEditGeneralCost(c: any) {
  editingGeneralCostId.value              = c.id
  generalCostForm.date                   = c.date ?? ''
  generalCostForm.document_number        = c.document_number ?? ''
  generalCostForm.supplier_name          = c.supplier_name ?? ''
  generalCostForm.cost_type              = c.cost_type ?? ''
  generalCostForm.cost                   = c.cost ?? ''
  generalCostForm.description            = c.description ?? ''
  generalCostForm.person_responsible_id  = c.person_responsible_id ?? null
  generalCostDialog.value = true
}

function submitGeneralCost() {
  if (editingGeneralCostId.value) {
    generalCostForm.patch(route('fleet.vehicles.general-costs.update', editingGeneralCostId.value), {
      preserveScroll: true,
      onSuccess: () => { generalCostDialog.value = false; generalCostForm.reset(); toast(t('fleet_vehicles_show.toasts.general_cost_updated')) },
      onError:   () => toast('Please fix the errors.', 'error'),
    })
  } else {
    generalCostForm.transform(d => ({ ...d, vehicle_id: props.vehicle.id }))
      .post(route('fleet.vehicles.general-costs.store', props.vehicle.uuid), {
        preserveScroll: true,
        onSuccess: () => { generalCostDialog.value = false; generalCostForm.reset(); toast(t('fleet_vehicles_show.toasts.general_cost_added')) },
        onError:   () => toast('Please fix the errors.', 'error'),
      })
  }
}

function deleteGeneralCost(id: number) {
  askConfirm(t('fleet_vehicles_show.toasts.general_cost_removed'), () => {
    router.delete(route('fleet.vehicles.general-costs.destroy', id), {
      preserveScroll: true,
      onSuccess: () => toast(t('fleet_vehicles_show.toasts.general_cost_removed')),
    })
  })
}

const primaryImg = computed(() => props.images.find(i => i.is_primary) ?? props.images[0] ?? null)
const regDocSrc = computed(() => props.vehicle.registration_document_path ? imgSrc(props.vehicle.registration_document_path) : null)

const editDialog = ref(false)
const editSelectedMakeId = ref<number | null>(null)

const editFilteredModels = computed(() =>
  editSelectedMakeId.value
    ? props.vehicleModels.filter(m => m.make_id === editSelectedMakeId.value)
    : props.vehicleModels
)

const editForm = useForm({
  registration_number: '',  fleet_number: '',
  make: '',                 model: '',
  year: null as number | null,
  colour: '',               vin_number: '',
  chassis_number: '',       engine_number: '',
  fuel_type: '',            vehicle_type: '',
  transmission: 'automatic',
  engine_capacity: null as number | null,
  seating_capacity: 5,      tank_size: null as number | null,
  odometer_reading: 0,
  company_unit_id: null as number | null,
  assigned_driver_id: null as number | null,
  location: '',             key_safe_name: '',
  purchase_date: '',        purchase_price: null as number | null,
  current_value: null as number | null,
  expected_disposal_date: '',
  co2_emissions_g_per_km: null as number | null,
  vehicle_cell_number: '',  tracking_cell_number: '',
  owner_name: '',           title_holder_name: '',
  extras: '',               notes: '',
  status: 'available',
})

function openEdit() {
  const v = props.vehicle
  const foundMake = props.makes.find(m => m.name === v.make)
  editSelectedMakeId.value = foundMake?.id ?? null

  editForm.registration_number  = v.registration_number ?? ''
  editForm.fleet_number         = v.fleet_number ?? ''
  editForm.make                 = v.make ?? ''
  editForm.model                = v.model ?? ''
  editForm.year                 = v.year ?? null
  editForm.colour               = v.colour ?? v.color ?? ''
  editForm.vin_number           = v.vin_number ?? v.vin ?? ''
  editForm.chassis_number       = v.chassis_number ?? ''
  editForm.engine_number        = v.engine_number ?? ''
  editForm.fuel_type            = v.fuel_type ?? ''
  editForm.vehicle_type         = v.vehicle_type ?? ''
  editForm.transmission         = (v as any).transmission ?? 'automatic'
  editForm.engine_capacity      = (v as any).engine_capacity ?? null
  editForm.seating_capacity     = v.seating_capacity ?? v.seats ?? 5
  editForm.tank_size            = v.tank_size ?? null
  editForm.odometer_reading     = v.odometer_reading ?? 0
  editForm.company_unit_id      = v.company_unit_id ?? null
  editForm.assigned_driver_id   = v.assigned_driver_id ?? null
  editForm.location             = v.location ?? ''
  editForm.key_safe_name        = v.key_safe_name ?? ''
  editForm.purchase_date        = v.purchase_date_raw ?? ''
  editForm.purchase_price       = v.purchase_price ?? null
  editForm.current_value        = v.current_value ?? null
  editForm.expected_disposal_date = v.expected_disposal_date_raw ?? ''
  editForm.co2_emissions_g_per_km = v.co2_emissions_g_per_km ?? null
  editForm.vehicle_cell_number  = v.vehicle_cell_number ?? ''
  editForm.tracking_cell_number = v.tracking_cell_number ?? ''
  editForm.owner_name           = v.owner_name ?? ''
  editForm.title_holder_name    = v.title_holder_name ?? ''
  editForm.extras               = v.extras ?? ''
  editForm.notes                = v.notes ?? ''
  editForm.status               = v.status ?? 'available'
  editDialog.value = true
}

function onEditMakeChange(makeName: string) {
  editForm.model = ''
  const found = props.makes.find(m => m.name === makeName)
  editSelectedMakeId.value = found?.id ?? null
}

function submitEdit() {
  editForm.patch(route('fleet.vehicles.update', props.vehicle.uuid), {
    preserveScroll: true,
    onSuccess: () => { editDialog.value = false; toast(t('fleet_vehicles_show.toasts.vehicle_updated')) },
  })
}
</script>

<template>
  <div class="pa-4">
    <VSnackbar v-model="snack.show" :color="snack.color" location="top right" :timeout="3000">{{ snack.msg }}</VSnackbar>

    <VDialog v-model="confirmDialog" max-width="420" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center">
          <VIcon start color="error">tabler-alert-circle</VIcon>
          {{ t('fleet_vehicles_show.confirm_delete.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">{{ confirmMessage }}</VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="confirmDialog=false">{{ t('fleet_vehicles_show.confirm_delete.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="doConfirmedDelete">{{ t('fleet_vehicles_show.confirm_delete.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <div class="d-flex align-center mb-4 flex-wrap ga-2">
      <VBtn icon variant="text" :href="route('fleet.vehicles.index')"><VIcon>tabler-arrow-left</VIcon></VBtn>
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ vehicle.registration_number }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ [vehicle.year, vehicle.make, vehicle.model, vehicle.colour].filter(Boolean).join(' · ') }}
          <span v-if="vehicle.fleet_number"> · Fleet #{{ vehicle.fleet_number }}</span>
        </p>
      </div>
      <VChip :color="statusColor(vehicle.status)" label class="text-capitalize">{{ statusLabel(vehicle.status) }}</VChip>
    </div>

    <div class="d-flex flex-wrap ga-2 mb-4">
      <VBtn size="small" color="primary" variant="flat" prepend-icon="tabler-pencil" @click="openEdit">{{ t('fleet_vehicles_show.edit_vehicle') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-photo-square-rounded" @click="imgDialog=true; tab='images'">{{ t('fleet_vehicles_show.images_btn') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-key" @click="tab='keys'">{{ t('fleet_vehicles_show.key_tracking') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-certificate" @click="tab='permits'">{{ t('fleet_vehicles_show.permit_licences') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-file-text" @click="tab='documents'">{{ t('fleet_vehicles_show.documents') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-note" @click="tab='notes'">{{ t('fleet_vehicles_show.notes_btn') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-bell" @click="tab='reminders'">{{ t('fleet_vehicles_show.reminders') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-fire-extinguisher" @click="tab='fire-extinguishers'">{{ t('fleet_vehicles_show.fire_extinguishers') }}</VBtn>
      <VBtn size="small" variant="tonal" prepend-icon="tabler-barrier-block" @click="tab='tollgates'">{{ t('fleet_vehicles_show.toll_gate') }}</VBtn>
      <VBtn size="small" color="warning" variant="flat" prepend-icon="tabler-arrows-left-right" @click="statusDialog=true">{{ t('fleet_vehicles_show.change_status') }}</VBtn>
    </div>

    <VDivider class="mb-4" />

    <VCard>
      <VTabs v-model="tab" color="primary" show-arrows density="compact">
        <VTab value="general"><VIcon start size="18">tabler-id</VIcon>{{ t('fleet_vehicles_show.tabs.general') }}</VTab>
        <VTab value="bookings"><VIcon start size="18">tabler-calendar-check</VIcon>{{ t('fleet_vehicles_show.tabs.bookings') }}<VChip v-if="bookings.length" size="x-small" class="ml-1">{{ bookings.length }}</VChip></VTab>
        <VTab value="fuel"><VIcon start size="18">tabler-gas-station</VIcon>{{ t('fleet_vehicles_show.tabs.fuel') }}<VChip v-if="fuelLogs.length" size="x-small" class="ml-1">{{ fuelLogs.length }}</VChip></VTab>
        <VTab value="incidents"><VIcon start size="18">tabler-alert-circle</VIcon>{{ t('fleet_vehicles_show.tabs.incidents') }}<VChip v-if="incidents.length" size="x-small" color="error" class="ml-1">{{ incidents.length }}</VChip></VTab>
        <VTab value="fines"><VIcon start size="18">tabler-cash-off</VIcon>{{ t('fleet_vehicles_show.tabs.fines') }}<VChip v-if="fines.length" size="x-small" color="error" class="ml-1">{{ fines.length }}</VChip></VTab>
        <VTab value="service"><VIcon start size="18">tabler-tool</VIcon>{{ t('fleet_vehicles_show.tabs.service') }}</VTab>
        <VTab value="insurance"><VIcon start size="18">tabler-shield-check</VIcon>{{ t('fleet_vehicles_show.tabs.insurance') }}</VTab>
        <VTab value="warranties"><VIcon start size="18">tabler-certificate</VIcon>{{ t('fleet_vehicles_show.tabs.warranties') }}</VTab>
        <VTab value="general_cost"><VIcon start size="18">tabler-currency-dollar</VIcon>{{ t('fleet_vehicles_show.tabs.general_cost') }}<VChip v-if="generalCosts.length" size="x-small" class="ml-1">{{ generalCosts.length }}</VChip></VTab>
        <VTab value="images"><VIcon start size="18">tabler-photo-square-rounded</VIcon>{{ t('fleet_vehicles_show.tabs.images') }}<VChip v-if="images.length" size="x-small" class="ml-1">{{ images.length }}</VChip></VTab>
        <VTab value="keys"><VIcon start size="18">tabler-key</VIcon>{{ t('fleet_vehicles_show.tabs.keys') }}<VChip v-if="vehicleKeys.length" size="x-small" class="ml-1">{{ vehicleKeys.length }}</VChip></VTab>
        <VTab value="permits"><VIcon start size="18">tabler-id-badge-2</VIcon>{{ t('fleet_vehicles_show.tabs.permits') }}</VTab>
        <VTab value="documents"><VIcon start size="18">tabler-files</VIcon>{{ t('fleet_vehicles_show.tabs.documents_tab') }}<VChip v-if="documents.length" size="x-small" class="ml-1">{{ documents.length }}</VChip></VTab>
        <VTab value="tollgates"><VIcon start size="18">tabler-barrier-block</VIcon>{{ t('fleet_vehicles_show.tabs.tollgates') }}</VTab>
        <VTab value="notes"><VIcon start size="18">tabler-note</VIcon>{{ t('fleet_vehicles_show.tabs.notes_tab') }}</VTab>
        <VTab value="reminders"><VIcon start size="18">tabler-bell</VIcon>{{ t('fleet_vehicles_show.tabs.reminders_tab') }}<VChip v-if="reminders.length" size="x-small" class="ml-1">{{ reminders.length }}</VChip></VTab>
        <VTab value="fire-extinguishers"><VIcon start size="18">tabler-fire-extinguisher</VIcon>{{ t('fleet_vehicles_show.tabs.fire_extinguishers_tab') }}<VChip v-if="fireExtinguishers.length" size="x-small" class="ml-1">{{ fireExtinguishers.length }}</VChip></VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <VWindowItem value="general">
          <VRow class="pa-4" dense>
            <VCol cols="12" md="6">
              <VTable density="compact" class="mb-2">
                <tbody>
                  <tr><td class="text-medium-emphasis font-weight-medium" style="width:50%">{{ t('fleet_vehicles_show.general.fleet_number') }}</td><td>{{ vehicle.fleet_number ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.registration') }}</td><td>{{ vehicle.registration_number }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.make') }}</td><td>{{ vehicle.make ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.model') }}</td><td>{{ vehicle.model ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.chassis') }}</td><td>{{ vehicle.chassis_number ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.colour') }}</td><td>{{ vehicle.colour ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.odometer') }}</td><td>{{ (vehicle.odometer_reading ?? 0).toLocaleString() }} km</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.fuel_type') }}</td><td>{{ vehicle.fuel_type ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.tank_size') }}</td><td>{{ vehicle.tank_size ? `${vehicle.tank_size} L` : '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.division') }}</td><td>{{ vehicle.division ?? vehicle.company_unit ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.department') }}</td><td>{{ vehicle.department ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.responsible_person') }}</td><td>{{ vehicle.current_driver ?? vehicle.driver_name ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.extras') }}</td><td class="text-caption">{{ vehicle.extras ?? '—' }}</td></tr>
                </tbody>
              </VTable>
            </VCol>
            <VCol cols="12" md="6">
              <VTable density="compact" class="mb-2">
                <tbody>
                  <tr><td class="text-medium-emphasis font-weight-medium" style="width:50%">{{ t('fleet_vehicles_show.general.year') }}</td><td>{{ vehicle.year ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.engine') }}</td><td>{{ vehicle.engine_number ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.vehicle_type') }}</td><td>{{ vehicle.vehicle_type ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.licence_renewal') }}</td><td><VChip v-if="vehicle.licence_disc_expiry" size="x-small">{{ vehicle.licence_disc_expiry }}</VChip><span v-else>—</span></td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.cof_expiry') }}</td><td><VChip v-if="vehicle.roadworthy_expiry" size="x-small" color="info">{{ vehicle.roadworthy_expiry }}</VChip><span v-else>—</span></td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.purchase_price') }}</td><td>{{ vehicle.purchase_price ? `R ${Number(vehicle.purchase_price).toLocaleString()}` : '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.vehicle_cell') }}</td><td>{{ vehicle.vehicle_cell_number ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.tracking_cell') }}</td><td>{{ vehicle.tracking_cell_number ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.fleet_card') }}</td><td>{{ vehicle.fleet_card ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.owner') }}</td><td>{{ vehicle.owner_name ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.title_holder') }}</td><td>{{ vehicle.title_holder_name ?? '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.registration_paper') }}</td><td><a v-if="regDocSrc" :href="regDocSrc" target="_blank" class="text-primary text-decoration-none"><VIcon size="16">tabler-file-text</VIcon> {{ t('fleet_vehicles_show.general.view_doc') }}</a><span v-else>—</span></td></tr>
                  <tr><td class="text-medium-emphasis font-weight-medium">{{ t('fleet_vehicles_show.general.status') }}</td><td><VChip size="x-small" :color="statusColor(vehicle.status)" label class="text-capitalize">{{ statusLabel(vehicle.status) }}</VChip></td></tr>
                </tbody>
              </VTable>
              <div v-if="primaryImg" class="pa-2"><VImg :src="imgSrc(primaryImg.file_path)" height="160" cover /></div>
            </VCol>
          </VRow>
        </VWindowItem>

        <VWindowItem value="bookings">
          <VTable density="compact" class="ma-3">
            <thead><tr><th>{{ t('fleet_vehicles_show.bookings.reference') }}</th><th>{{ t('fleet_vehicles_show.bookings.driver') }}</th><th>{{ t('fleet_vehicles_show.bookings.project') }}</th><th>{{ t('fleet_vehicles_show.bookings.destination') }}</th><th>{{ t('fleet_vehicles_show.bookings.departure') }}</th><th>{{ t('fleet_vehicles_show.bookings.return') }}</th><th>{{ t('fleet_vehicles_show.bookings.km') }}</th><th>{{ t('fleet_vehicles_show.bookings.status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in bookings" :key="b.id">
                <td class="text-primary font-weight-medium">{{ b.reference }}</td><td>{{ b.driver }}</td><td>{{ b.project ?? '—' }}</td><td>{{ b.destination }}</td>
                <td>{{ b.departure_at }}</td><td>{{ b.return_at ?? '—' }}</td><td>{{ b.km_travelled ?? '—' }}</td>
                <td><VChip size="x-small" :color="stateColor(b.workflow_state)" label>{{ b.workflow_state.replace(/_/g,' ') }}</VChip></td>
                <td><VBtn size="x-small" variant="tonal" color="secondary" icon @click="openBookingView(b)"><VIcon size="14">tabler-eye</VIcon></VBtn></td>
              </tr>
              <tr v-if="!bookings.length"><td colspan="9" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.bookings.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="fuel">
          <VTable density="compact" class="ma-3">
            <thead><tr><th>{{ t('fleet_vehicles_show.fuel.date') }}</th><th>{{ t('fleet_vehicles_show.fuel.type') }}</th><th>{{ t('fleet_vehicles_show.fuel.litres') }}</th><th>{{ t('fleet_vehicles_show.fuel.cost_l') }}</th><th>{{ t('fleet_vehicles_show.fuel.total') }}</th><th>{{ t('fleet_vehicles_show.fuel.odometer') }}</th><th>{{ t('fleet_vehicles_show.fuel.consumption') }}</th><th>{{ t('fleet_vehicles_show.fuel.station') }}</th></tr></thead>
            <tbody>
              <tr v-for="f in fuelLogs" :key="f.id">
                <td>{{ f.filled_at }}</td><td>{{ f.fuel_type }}</td><td>{{ f.litres }}</td>
                <td>{{ f.cost_per_litre ? `R${f.cost_per_litre}` : '—' }}</td><td>{{ f.total_cost ? `R${f.total_cost}` : '—' }}</td>
                <td>{{ f.odometer?.toLocaleString() }}</td><td>{{ f.consumption ?? '—' }}</td><td>{{ f.station ?? '—' }}</td>
              </tr>
              <tr v-if="!fuelLogs.length"><td colspan="8" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.fuel.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="incidents">
          <VTable density="compact" class="ma-3">
            <thead><tr><th>{{ t('fleet_vehicles_show.incidents.booking') }}</th><th>{{ t('fleet_vehicles_show.incidents.type') }}</th><th>{{ t('fleet_vehicles_show.incidents.severity') }}</th><th>{{ t('fleet_vehicles_show.incidents.description') }}</th><th>{{ t('fleet_vehicles_show.incidents.location') }}</th><th>{{ t('fleet_vehicles_show.incidents.case_no') }}</th><th>{{ t('fleet_vehicles_show.incidents.date') }}</th><th>{{ t('fleet_vehicles_show.incidents.reported_by') }}</th></tr></thead>
            <tbody>
              <tr v-for="i in incidents" :key="i.id">
                <td class="text-caption">{{ i.reference }}</td><td>{{ i.incident_type }}</td>
                <td><VChip size="x-small" :color="severityColor(i.severity)" label>{{ i.severity }}</VChip></td>
                <td class="text-body-2">{{ i.description }}</td><td>{{ i.location ?? '—' }}</td>
                <td>{{ i.police_case_number ?? '—' }}</td><td>{{ i.incident_at ? new Date(i.incident_at).toLocaleDateString('en-ZA') : '—' }}</td>
                <td>{{ i.reported_by }}</td>
              </tr>
              <tr v-if="!incidents.length"><td colspan="8" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.incidents.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="fines">
          <VTable density="compact" class="ma-3">
            <thead><tr><th>{{ t('fleet_vehicles_show.fines.offence_date') }}</th><th>{{ t('fleet_vehicles_show.fines.fine_no') }}</th><th>{{ t('fleet_vehicles_show.fines.offence') }}</th><th>{{ t('fleet_vehicles_show.fines.authority') }}</th><th>{{ t('fleet_vehicles_show.fines.amount') }}</th><th>{{ t('fleet_vehicles_show.fines.outstanding') }}</th><th>{{ t('fleet_vehicles_show.fines.points') }}</th><th>{{ t('fleet_vehicles_show.fines.status') }}</th><th>{{ t('fleet_vehicles_show.fines.driver') }}</th><th>{{ t('fleet_vehicles_show.fines.source') }}</th></tr></thead>
            <tbody>
              <tr v-for="f in fines" :key="f.id">
                <td class="text-no-wrap">{{ f.offence_date ? new Date(f.offence_date).toLocaleDateString('en-ZA') : '—' }}</td>
                <td>{{ f.fine_number ?? '—' }}</td>
                <td><div class="text-body-2 font-weight-medium">{{ f.offence_code ?? '' }}</div><div class="text-caption text-medium-emphasis">{{ f.offence_description ?? '—' }}</div></td>
                <td>{{ f.issuing_authority ?? '—' }}</td><td class="text-no-wrap">R{{ f.amount }}</td>
                <td class="text-no-wrap">{{ f.outstanding_amount != null ? `R${f.outstanding_amount}` : '—' }}</td>
                <td><VChip v-if="f.aarto_points" size="x-small" color="warning" label>{{ f.aarto_points }} pts</VChip><span v-else>—</span></td>
                <td><VChip size="x-small" label :color="f.status==='paid'?'success':f.status==='disputed'?'info':f.status==='written_off'?'grey':'error'">{{ f.status }}</VChip></td>
                <td>{{ f.driver_name ?? '—' }}</td><td><VChip size="x-small" label color="secondary">{{ f.provider }}</VChip></td>
              </tr>
              <tr v-if="!fines.length"><td colspan="10" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.fines.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="service">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddService">{{ t('fleet_vehicles_show.service.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.service.type') }}</th><th>{{ t('fleet_vehicles_show.service.category') }}</th><th>{{ t('fleet_vehicles_show.service.description') }}</th><th>{{ t('fleet_vehicles_show.service.scheduled') }}</th><th>{{ t('fleet_vehicles_show.service.completed') }}</th><th>{{ t('fleet_vehicles_show.service.next_service_date') }}</th><th>{{ t('fleet_vehicles_show.service.next_service_km') }}</th><th>{{ t('fleet_vehicles_show.service.status') }}</th><th>{{ t('fleet_vehicles_show.service.priority') }}</th><th>{{ t('fleet_vehicles_show.service.provider') }}</th><th>{{ t('fleet_vehicles_show.service.cost') }}</th><th>{{ t('fleet_vehicles_show.service.invoice') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in maintenance" :key="m.id">
                <td class="text-capitalize">{{ m.type }}</td><td class="text-capitalize">{{ m.category?.replace(/_/g,' ') ?? '—' }}</td>
                <td class="text-caption" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ m.description }}</td>
                <td class="text-no-wrap">{{ m.scheduled_date ?? '—' }}</td><td class="text-no-wrap">{{ m.completed_date ?? '—' }}</td>
                <td class="text-no-wrap"><span v-if="m.next_service_date" class="text-primary font-weight-medium">{{ m.next_service_date }}</span><span v-else>—</span></td>
                <td>{{ m.next_service_km ? `${Number(m.next_service_km).toLocaleString()} km` : '—' }}</td>
                <td><VChip size="x-small" label :color="m.status==='completed'?'success':m.status==='in_progress'?'primary':m.status==='scheduled'?'warning':m.status==='cancelled'?'grey':'grey'">{{ m.status?.replace(/_/g,' ') }}</VChip></td>
                <td><VChip size="x-small" :color="priorityColor(m.priority)" label>{{ m.priority }}</VChip></td>
                <td>{{ m.service_provider ?? '—' }}</td><td class="text-no-wrap">{{ m.cost ? `R${m.cost}` : '—' }}</td><td>{{ m.invoice ?? '—' }}</td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditService(m)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteService(m.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!maintenance.length"><td colspan="13" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.service.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="insurance">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddInsurance">{{ t('fleet_vehicles_show.insurance.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.insurance.type') }}</th><th>{{ t('fleet_vehicles_show.insurance.supplier') }}</th><th>{{ t('fleet_vehicles_show.insurance.contact') }}</th><th>{{ t('fleet_vehicles_show.insurance.policy_no') }}</th><th>{{ t('fleet_vehicles_show.insurance.inception') }}</th><th>{{ t('fleet_vehicles_show.insurance.expiry') }}</th><th>{{ t('fleet_vehicles_show.insurance.days_left') }}</th><th>{{ t('fleet_vehicles_show.insurance.premium') }}</th><th>{{ t('fleet_vehicles_show.insurance.frequency') }}</th><th>{{ t('fleet_vehicles_show.insurance.value_covered') }}</th><th>{{ t('fleet_vehicles_show.insurance.status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in insurance" :key="d.id">
                <td class="font-weight-medium">{{ d.document_type }}</td><td>{{ d.supplier ?? '—' }}</td><td>{{ d.contact_person ?? '—' }}</td>
                <td class="text-caption">{{ d.document_number ?? '—' }}</td><td>{{ d.issued_date ?? '—' }}</td><td>{{ d.expiry_date ?? '—' }}</td>
                <td><VChip v-if="d.days_left !== null" size="x-small" :color="docDaysColor(d.days_left)">{{ d.days_left }}d</VChip><span v-else>—</span></td>
                <td class="text-no-wrap">{{ d.premium_amount ? `R${d.premium_amount}` : '—' }}</td>
                <td class="text-capitalize">{{ d.payment_frequency?.replace(/_/g,' ') ?? '—' }}</td>
                <td class="text-no-wrap">{{ d.value_covered ? `R${Number(d.value_covered).toLocaleString()}` : '—' }}</td>
                <td><VChip size="x-small" :color="d.status==='valid'?'success':d.status==='expired'?'error':'warning'" label>{{ d.status?.replace(/_/g,' ') }}</VChip></td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditInsurance(d)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteDocument(d.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!insurance.length"><td colspan="12" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.insurance.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="warranties">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddDocument('warranty')">{{ t('fleet_vehicles_show.warranties.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.warranties.type') }}</th><th>{{ t('fleet_vehicles_show.warranties.supplier') }}</th><th>{{ t('fleet_vehicles_show.warranties.number') }}</th><th>{{ t('fleet_vehicles_show.warranties.issuer') }}</th><th>{{ t('fleet_vehicles_show.warranties.issue_date') }}</th><th>{{ t('fleet_vehicles_show.warranties.expiry') }}</th><th>{{ t('fleet_vehicles_show.warranties.days_left') }}</th><th>{{ t('fleet_vehicles_show.warranties.status') }}</th><th>{{ t('fleet_vehicles_show.warranties.file') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in warranties" :key="d.id">
                <td>{{ d.document_type }}</td><td>{{ d.supplier ?? '—' }}</td><td>{{ d.document_number ?? '—' }}</td><td>{{ d.issuing_authority ?? '—' }}</td>
                <td>{{ d.issued_date ?? '—' }}</td><td>{{ d.expiry_date ?? '—' }}</td>
                <td><VChip v-if="d.days_left !== null" size="x-small" :color="docDaysColor(d.days_left)">{{ d.days_left }}d</VChip><span v-else>—</span></td>
                <td><VChip size="x-small" :color="d.status==='valid'?'success':'error'" label>{{ d.status }}</VChip></td>
                <td><VIcon v-if="d.has_file" size="16" color="success">tabler-paperclip</VIcon><span v-else class="text-caption text-medium-emphasis">—</span></td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditDocument(d)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteDocument(d.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!warranties.length"><td colspan="10" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.warranties.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="general_cost">
          <div class="pa-4">
            <VRow class="mb-3">
              <VCol cols="12" sm="6" md="2" v-for="c in [
                { label: t('fleet_vehicles_show.general_cost.fuel_total'),         value: costs.fuel_total,          color: 'primary', icon: 'tabler-gas-station' },
                { label: t('fleet_vehicles_show.general_cost.maintenance_total'),   value: costs.maintenance_total,   color: 'warning', icon: 'tabler-tool' },
                { label: t('fleet_vehicles_show.general_cost.toll_total'),          value: costs.toll_total,          color: 'info',    icon: 'tabler-barrier-block' },
                { label: t('fleet_vehicles_show.general_cost.general_total'),       value: costs.general_costs_total, color: 'purple',  icon: 'tabler-receipt' },
                { label: t('fleet_vehicles_show.general_cost.grand_total'),         value: costs.grand_total,         color: 'success', icon: 'tabler-currency-dollar' },
              ]" :key="c.label">
                <VCard variant="tonal" :color="c.color" class="text-center pa-3">
                  <VIcon size="24" class="mb-1">{{ c.icon }}</VIcon>
                  <div class="text-subtitle-1 font-weight-bold">R {{ Number(c.value).toLocaleString() }}</div>
                  <div class="text-caption">{{ c.label }}</div>
                </VCard>
              </VCol>
            </VRow>
            <div class="d-flex justify-end mb-2"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddGeneralCost">{{ t('fleet_vehicles_show.general_cost.add') }}</VBtn></div>
            <VTable density="compact">
              <thead><tr><th>{{ t('fleet_vehicles_show.general_cost.date') }}</th><th>{{ t('fleet_vehicles_show.general_cost.doc_number') }}</th><th>{{ t('fleet_vehicles_show.general_cost.supplier') }}</th><th>{{ t('fleet_vehicles_show.general_cost.cost_type') }}</th><th>{{ t('fleet_vehicles_show.general_cost.cost') }}</th><th>{{ t('fleet_vehicles_show.general_cost.description') }}</th><th>{{ t('fleet_vehicles_show.general_cost.person_responsible') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="c in generalCosts" :key="c.id">
                  <td class="text-no-wrap">{{ c.date ? new Date(c.date).toLocaleDateString('en-ZA') : '—' }}</td>
                  <td class="text-caption">{{ c.document_number ?? '—' }}</td><td>{{ c.supplier_name ?? '—' }}</td>
                  <td class="text-capitalize">{{ c.cost_type?.replace(/_/g,' ') ?? '—' }}</td>
                  <td class="text-no-wrap font-weight-medium">{{ c.cost ? `R${Number(c.cost).toLocaleString()}` : '—' }}</td>
                  <td class="text-caption" style="max-width:200px;white-space:pre-wrap">{{ c.description ?? '—' }}</td>
                  <td>{{ c.person_responsible || '—' }}</td>
                  <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditGeneralCost(c)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteGeneralCost(c.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
                </tr>
                <tr v-if="!generalCosts.length"><td colspan="8" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.general_cost.no_data') }}</td></tr>
              </tbody>
            </VTable>
          </div>
        </VWindowItem>

        <VWindowItem value="images">
          <div class="pa-4">
            <div class="d-flex justify-end mb-3"><VBtn size="small" color="primary" prepend-icon="tabler-upload" @click="imgDialog=true">{{ t('fleet_vehicles_show.images.upload') }}</VBtn></div>
            <div v-if="images.length" class="d-flex flex-wrap ga-3">
              <VCard v-for="img in images" :key="img.id" width="180" variant="outlined">
                <VImg :src="imgSrc(img.file_path)" height="130" cover />
                <VCardText class="pa-2 text-caption">{{ img.file_name }}<VChip v-if="img.is_primary" size="x-small" color="primary" class="ml-1">{{ t('fleet_vehicles_show.images.primary') }}</VChip></VCardText>
              </VCard>
            </div>
            <div v-else class="text-center text-medium-emphasis pa-8"><VIcon size="48" class="mb-2">tabler-photo-off</VIcon><div>{{ t('fleet_vehicles_show.images.no_data') }}</div></div>
          </div>
        </VWindowItem>

        <VWindowItem value="keys">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddKey">{{ t('fleet_vehicles_show.keys.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.keys.key_no') }}</th><th>{{ t('fleet_vehicles_show.keys.type') }}</th><th>{{ t('fleet_vehicles_show.keys.issued_to') }}</th><th>{{ t('fleet_vehicles_show.keys.employee_safe') }}</th><th>{{ t('fleet_vehicles_show.keys.description') }}</th><th>{{ t('fleet_vehicles_show.keys.status') }}</th><th>{{ t('fleet_vehicles_show.keys.date_issued') }}</th><th>{{ t('fleet_vehicles_show.keys.issued_by') }}</th><th>{{ t('fleet_vehicles_show.keys.loss_reason') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="k in vehicleKeys" :key="k.id">
                <td class="font-weight-medium">{{ k.key_number ?? '—' }}</td><td class="text-capitalize">{{ k.key_type }}</td><td class="text-capitalize">{{ k.issued_to_type }}</td>
                <td>{{ k.issued_to_type === 'employee' ? (k.employee_name ?? '—') : (k.safe_name ?? '—') }}</td>
                <td class="text-caption">{{ k.description ?? '—' }}</td>
                <td><VChip size="x-small" :color="keyStatusColor(k.key_status)" label class="text-capitalize">{{ k.key_status.replace(/_/g, ' ') }}</VChip></td>
                <td>{{ k.date_issued ?? '—' }}</td><td>{{ k.issued_by ?? '—' }}</td><td class="text-caption text-error">{{ k.reason_for_loss ?? '—' }}</td>
                <td><VBtn icon size="x-small" variant="text" color="error" @click="doDeleteKey(k.id)"><VIcon>tabler-trash</VIcon></VBtn></td>
              </tr>
              <tr v-if="!vehicleKeys.length"><td colspan="10" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.keys.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="permits">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddPermit">{{ t('fleet_vehicles_show.permits.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.permits.type') }}</th><th>{{ t('fleet_vehicles_show.permits.supplier') }}</th><th>{{ t('fleet_vehicles_show.permits.number') }}</th><th>{{ t('fleet_vehicles_show.permits.issued') }}</th><th>{{ t('fleet_vehicles_show.permits.expiry') }}</th><th>{{ t('fleet_vehicles_show.permits.days_left') }}</th><th>{{ t('fleet_vehicles_show.permits.status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in permits" :key="d.id">
                <td class="font-weight-medium">{{ d.document_type }}</td><td>{{ d.supplier ?? '—' }}</td><td>{{ d.document_number ?? '—' }}</td>
                <td>{{ d.issued_date ?? '—' }}</td><td>{{ d.expiry_date ?? '—' }}</td>
                <td><VChip v-if="d.days_left !== null" size="x-small" :color="docDaysColor(d.days_left)">{{ d.days_left }}d</VChip><span v-else>—</span></td>
                <td><VChip size="x-small" label :color="d.status==='valid'?'success':d.status==='expired'?'error':d.status==='pending_renewal'?'warning':'grey'">{{ d.status?.replace(/_/g, ' ') }}</VChip></td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditPermit(d)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteDocument(d.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!permits.length"><td colspan="8" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.permits.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="documents">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddDocument">{{ t('fleet_vehicles_show.documents_tab.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.documents_tab.type') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.supplier') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.number') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.issuer') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.issued') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.expiry') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.days_left') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.status') }}</th><th>{{ t('fleet_vehicles_show.documents_tab.file') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in documents" :key="d.id">
                <td class="font-weight-medium">{{ d.document_type }}</td><td>{{ d.supplier ?? '—' }}</td><td class="text-caption">{{ d.document_number ?? '—' }}</td><td>{{ d.issuing_authority ?? '—' }}</td>
                <td>{{ d.issued_date ?? '—' }}</td><td>{{ d.expiry_date ?? '—' }}</td>
                <td><VChip v-if="d.days_left !== null" size="x-small" :color="docDaysColor(d.days_left)">{{ d.days_left }}d</VChip><span v-else>—</span></td>
                <td><VChip size="x-small" label :color="d.status==='valid'?'success':d.status==='expired'?'error':d.status==='pending_renewal'?'warning':'grey'">{{ d.status?.replace(/_/g, ' ') }}</VChip></td>
                <td><VIcon v-if="d.has_file" size="16" color="success">tabler-paperclip</VIcon><span v-else class="text-caption text-medium-emphasis">—</span></td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditDocument(d)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteDocument(d.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!documents.length"><td colspan="10" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.documents_tab.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="tollgates">
          <VTable density="compact" class="ma-3">
            <thead><tr><th>{{ t('fleet_vehicles_show.tollgates.booking') }}</th><th>{{ t('fleet_vehicles_show.tollgates.location') }}</th><th>{{ t('fleet_vehicles_show.tollgates.date') }}</th><th>{{ t('fleet_vehicles_show.tollgates.amount') }}</th></tr></thead>
            <tbody>
              <tr v-for="t in tollgates" :key="t.id">
                <td class="text-caption">{{ t.reference }}</td><td>{{ t.toll_location }}</td>
                <td>{{ t.toll_at ? new Date(t.toll_at).toLocaleDateString('en-ZA') : '—' }}</td><td>R{{ t.amount }}</td>
              </tr>
              <tr v-if="!tollgates.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.tollgates.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="notes">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddNote">{{ t('fleet_vehicles_show.notes.add') }}</VBtn></div>
          <div class="mx-3 mb-3">
            <VCard v-for="n in notes" :key="n.id" variant="tonal" :color="n.severity==='critical'?'error':n.severity==='warning'?'warning':'info'" class="pa-3 mb-2">
              <div class="d-flex align-center ga-2 mb-1">
                <VChip size="x-small" label class="text-capitalize">{{ n.note_type }}</VChip>
                <VChip size="x-small" label class="text-capitalize" :color="n.severity==='critical'?'error':n.severity==='warning'?'warning':'info'">{{ n.severity }}</VChip>
                <span class="text-caption text-medium-emphasis ml-auto">{{ n.created_at }}</span>
                <VBtn icon size="x-small" variant="text" color="white" @click="openEditNote(n)"><VIcon size="16">tabler-pencil</VIcon></VBtn>
                <VBtn icon size="x-small" variant="text" color="white" @click="deleteNote(n.id)"><VIcon size="16">tabler-trash</VIcon></VBtn>
              </div>
              <div class="text-body-2">{{ n.body }}</div>
            </VCard>
            <div v-if="!notes.length" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.notes.no_data') }}</div>
          </div>
        </VWindowItem>

        <VWindowItem value="reminders">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddReminder">{{ t('fleet_vehicles_show.reminders_tab.add') }}</VBtn></div>
          <div class="mx-3 mb-3">
            <VCard v-for="r in reminders" :key="r.id" variant="outlined" class="pa-3 mb-2">
              <div class="d-flex align-center ga-2 mb-1">
                <VIcon size="18" color="primary">tabler-bell</VIcon><span class="font-weight-medium">{{ r.name }}</span>
                <span class="text-caption text-medium-emphasis ml-auto">{{ r.created_at }}</span>
                <VBtn icon size="x-small" variant="text" color="primary" @click="openEditReminder(r)"><VIcon size="16">tabler-pencil</VIcon></VBtn>
                <VBtn icon size="x-small" variant="text" color="error" @click="deleteReminder(r.id)"><VIcon size="16">tabler-trash</VIcon></VBtn>
              </div>
              <div v-if="r.description" class="text-body-2 mb-1">{{ r.description }}</div>
              <div class="d-flex ga-3 text-caption text-medium-emphasis">
                <span v-if="r.start_date"><VIcon size="14">tabler-calendar-event</VIcon> {{ t('fleet_vehicles_show.reminders_tab.start') }}: {{ r.start_date }}</span>
                <span v-if="r.end_date"><VIcon size="14">tabler-calendar-due</VIcon> {{ t('fleet_vehicles_show.reminders_tab.end') }}: {{ r.end_date }}</span>
                <span v-if="r.notified_at" class="ml-auto"><VIcon size="14">tabler-bell-check</VIcon> {{ t('fleet_vehicles_show.reminders_tab.notified') }}: {{ r.notified_at }}</span>
              </div>
            </VCard>
            <div v-if="!reminders.length" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.reminders_tab.no_data') }}</div>
          </div>
        </VWindowItem>

        <VWindowItem value="fire-extinguishers">
          <div class="d-flex justify-end pa-3"><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openAddExt">{{ t('fleet_vehicles_show.fire_extinguishers_tab.add') }}</VBtn></div>
          <VTable density="compact" class="ma-3 mt-0">
            <thead><tr><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.barcode') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.item') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.weight') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.serial') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.invoice') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.po') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.supplier') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.date_purchased') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.cost') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.status') }}</th><th>{{ t('fleet_vehicles_show.fire_extinguishers_tab.image') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in fireExtinguishers" :key="e.id">
                <td class="text-caption">{{ e.barcode ?? '—' }}</td><td class="font-weight-medium">{{ e.item ?? '—' }}</td><td>{{ e.weight_kg ?? '—' }}</td>
                <td class="text-caption">{{ e.serial_number ?? '—' }}</td><td class="text-caption">{{ e.invoice_number ?? '—' }}</td><td class="text-caption">{{ e.purchase_order_number ?? '—' }}</td>
                <td>{{ e.supplier ?? '—' }}</td><td>{{ e.date_purchased ?? '—' }}</td><td>{{ e.cost ? `R${e.cost}` : '—' }}</td>
                <td><VChip size="x-small" label :color="e.status==='active'?'success':e.status==='expired'?'error':'warning'">{{ e.status }}</VChip></td>
                <td><VImg v-if="e.image_url" :src="e.image_url" width="40" height="40" cover /><span v-else class="text-caption text-medium-emphasis">—</span></td>
                <td><div class="d-flex gap-1"><VBtn icon size="x-small" variant="text" color="primary" @click="openEditExt(e)"><VIcon>tabler-pencil</VIcon></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="deleteExt(e.id)"><VIcon>tabler-trash</VIcon></VBtn></div></td>
              </tr>
              <tr v-if="!fireExtinguishers.length"><td colspan="12" class="text-center text-medium-emphasis pa-4">{{ t('fleet_vehicles_show.fire_extinguishers_tab.no_data') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <div class="mt-4"><VBtn variant="tonal" prepend-icon="tabler-arrow-left" :href="route('fleet.vehicles.index')">{{ t('fleet_vehicles_show.back') }}</VBtn></div>

    <!-- Note Dialog -->
    <VDialog v-model="noteDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-note</VIcon>{{ editingNoteId ? t('fleet_vehicles_show.dialog_titles.edit_note') : t('fleet_vehicles_show.dialog_titles.add_note') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="noteForm.note_type" :items="['general','incident','alert','maintenance']" :label="t('fleet_vehicles_show.notes.type')" variant="outlined" density="comfortable" :error-messages="noteForm.errors.note_type" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="noteForm.severity" :items="[{title:'Info',value:'info'},{title:'Warning',value:'warning'},{title:'Critical',value:'critical'}]" item-title="title" item-value="value" :label="t('fleet_vehicles_show.notes.severity')" variant="outlined" density="comfortable" :error-messages="noteForm.errors.severity" /></VCol>
            <VCol cols="12"><VTextarea v-model="noteForm.body" :label="t('fleet_vehicles_show.notes.add')" variant="outlined" density="comfortable" rows="4" auto-grow :error-messages="noteForm.errors.body" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="noteDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="noteForm.processing" @click="saveNote">{{ editingNoteId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Image Upload Dialog -->
    <VDialog v-model="imgDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_vehicles_show.dialog_titles.upload_images') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4"><VFileInput v-model="imgFiles" label="Select images" accept="image/*" multiple variant="outlined" density="comfortable" /></VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="imgDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="uploading" @click="uploadImages">{{ t('fleet_vehicles_show.dialogs.upload') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Document Dialog -->
    <VDialog v-model="docDialog" max-width="580" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-file-text</VIcon>{{ editingDocumentId ? t('fleet_vehicles_show.dialog_titles.edit_document') : t('fleet_vehicles_show.dialog_titles.add_document') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VAutocomplete v-model="docForm.document_type" :label="t('fleet_vehicles_show.documents_tab.type') + ' *'" :items="documentTypes" item-title="name" item-value="name" variant="outlined" density="comfortable" :error-messages="(docForm.errors as any).document_type" /></VCol>
            <VCol cols="12"><VTextField v-model="docForm.supplier" :label="t('fleet_vehicles_show.documents_tab.supplier')" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12"><VTextField v-model="docForm.document_number" :label="t('fleet_vehicles_show.documents_tab.number')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="docForm.issuing_authority" :label="t('fleet_vehicles_show.documents_tab.issuer')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6"><VTextField v-model="docForm.issued_date" :label="t('fleet_vehicles_show.documents_tab.issued')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6"><VTextField v-model="docForm.expiry_date" :label="t('fleet_vehicles_show.documents_tab.expiry')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VSelect v-model="docForm.status" :label="t('fleet_vehicles_show.documents_tab.status')" :items="[{title:'Valid',value:'valid'},{title:'Expired',value:'expired'},{title:'Pending Renewal',value:'pending_renewal'},{title:'Revoked',value:'revoked'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VFileInput v-model="(docForm as any).file" label="Attach File (PDF / JPG / PNG)" accept=".pdf,.jpg,.jpeg,.png" variant="outlined" density="comfortable" prepend-icon="tabler-paperclip" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="docDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="docForm.processing" @click="submitDoc">{{ editingDocumentId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Service Dialog -->
    <VDialog v-model="serviceDialog" max-width="760" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-tool</VIcon>{{ editingServiceId ? t('fleet_vehicles_show.dialog_titles.edit_service') : t('fleet_vehicles_show.dialog_titles.add_service') }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height:80vh">
          <VRow dense class="mt-1">
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-1">SERVICE INFO</div></VCol>
            <VCol cols="12" md="4"><VSelect v-model="serviceForm.maintenance_type" :label="t('fleet_vehicles_show.service.type') + ' *'" :items="['scheduled','unscheduled','emergency','inspection']" variant="outlined" density="comfortable" :error-messages="serviceForm.errors.maintenance_type" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="serviceForm.category" :label="t('fleet_vehicles_show.service.category')" :items="[{title:'Oil Change',value:'oil_change'},{title:'Tyres',value:'tyres'},{title:'Brakes',value:'brakes'},{title:'Electrical',value:'electrical'},{title:'Bodywork',value:'bodywork'},{title:'Other',value:'other'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="serviceForm.priority" :label="t('fleet_vehicles_show.service.priority') + ' *'" :items="[{title:'Low',value:'low'},{title:'Normal',value:'normal'},{title:'High',value:'high'},{title:'Emergency',value:'emergency'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" :error-messages="serviceForm.errors.priority" /></VCol>
            <VCol cols="12"><VTextarea v-model="serviceForm.description" :label="t('fleet_vehicles_show.service.description') + ' *'" variant="outlined" density="comfortable" rows="2" auto-grow :error-messages="serviceForm.errors.description" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">DATES & ODOMETER</div></VCol>
            <VCol cols="12" md="4"><VTextField v-model="serviceForm.scheduled_date" :label="t('fleet_vehicles_show.service.scheduled')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="serviceForm.completed_date" :label="t('fleet_vehicles_show.service.completed')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="serviceForm.odometer_at_service" label="Odometer at Service (km)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">NEXT SERVICE <span class="text-primary">(reminder will be auto-created)</span></div></VCol>
            <VCol cols="12" md="6"><VTextField v-model="serviceForm.next_service_date" :label="t('fleet_vehicles_show.service.next_service_date')" type="date" variant="outlined" density="comfortable" hint="A reminder will be sent before this date based on fleet settings" persistent-hint /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="serviceForm.next_service_km" :label="t('fleet_vehicles_show.service.next_service_km')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">PROVIDER & COSTS</div></VCol>
            <VCol cols="12" md="6"><VTextField v-model="serviceForm.service_provider" :label="t('fleet_vehicles_show.service.provider')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="serviceForm.service_provider_contact" label="Provider Contact" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="serviceForm.cost" :label="t('fleet_vehicles_show.service.cost')" type="number" variant="outlined" density="comfortable" prefix="R" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="serviceForm.labour_cost" label="Labour Cost" type="number" variant="outlined" density="comfortable" prefix="R" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="serviceForm.parts_cost" label="Parts Cost" type="number" variant="outlined" density="comfortable" prefix="R" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="serviceForm.invoice_number" :label="t('fleet_vehicles_show.service.invoice')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">STATUS & NOTES</div></VCol>
            <VCol cols="12" md="4"><VSelect v-model="serviceForm.status" :label="t('fleet_vehicles_show.service.status') + ' *'" :items="[{title:'Scheduled',value:'scheduled'},{title:'In Progress',value:'in_progress'},{title:'Completed',value:'completed'},{title:'Cancelled',value:'cancelled'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="8"><VTextarea v-model="serviceForm.notes" label="Notes" variant="outlined" density="comfortable" rows="2" auto-grow /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="serviceDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="serviceForm.processing" @click="submitService">{{ editingServiceId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Change Status Dialog -->
    <VDialog v-model="statusDialog" max-width="400" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_vehicles_show.dialog_titles.change_status') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="statusForm.status" :items="['available','booked','maintenance','offroad','disposed']" label="New status" variant="outlined" density="comfortable" class="mb-3" />
          <VTextarea v-model="statusForm.reason" label="Reason (optional)" variant="outlined" density="comfortable" rows="2" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="statusDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="warning" variant="flat" :loading="statusForm.processing" @click="changeStatus">{{ t('fleet_vehicles_show.dialogs.update') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Permit Dialog -->
    <VDialog v-model="permitDialog" max-width="560" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-certificate</VIcon>{{ editingDocId ? t('fleet_vehicles_show.dialog_titles.edit_permit') : t('fleet_vehicles_show.dialog_titles.add_permit') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VAutocomplete v-model="permitForm.document_type" :label="t('fleet_vehicles_show.permits.type') + ' *'" :items="documentTypes" item-title="name" item-value="name" variant="outlined" density="comfortable" :error-messages="(permitForm.errors as any).document_type" /></VCol>
            <VCol cols="12"><VTextField v-model="permitForm.supplier" :label="t('fleet_vehicles_show.permits.supplier')" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12"><VTextField v-model="permitForm.document_number" :label="t('fleet_vehicles_show.permits.number')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6"><VTextField v-model="permitForm.issued_date" :label="t('fleet_vehicles_show.permits.issued')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6"><VTextField v-model="permitForm.expiry_date" :label="t('fleet_vehicles_show.permits.expiry')" type="date" variant="outlined" density="comfortable" :error-messages="(permitForm.errors as any).expiry_date" /></VCol>
            <VCol cols="12"><VSelect v-model="permitForm.status" :label="t('fleet_vehicles_show.permits.status')" :items="[{title:'Valid',value:'valid'},{title:'Expired',value:'expired'},{title:'Pending Renewal',value:'pending_renewal'},{title:'Revoked',value:'revoked'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VFileInput v-model="(permitForm as any).file" label="Document (PDF / JPG / PNG)" accept=".pdf,.jpg,.jpeg,.png" variant="outlined" density="comfortable" prepend-icon="tabler-paperclip" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="permitDialog = false">{{ t('fleet_vehicles_show.dialogs.close') }}</VBtn><VBtn color="primary" variant="flat" :loading="permitForm.processing" @click="submitPermit">{{ t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Insurance Dialog -->
    <VDialog v-model="insuranceDialog" max-width="720" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-shield-check</VIcon>{{ editingInsuranceId ? t('fleet_vehicles_show.dialog_titles.edit_insurance') : t('fleet_vehicles_show.dialog_titles.add_insurance') }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height:80vh">
          <VRow dense class="mt-1">
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1">POLICY DETAILS</div></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="insuranceForm.document_type" :label="t('fleet_vehicles_show.insurance.type') + ' *'" :items="documentTypes" item-title="name" item-value="name" variant="outlined" density="comfortable" :error-messages="(insuranceForm.errors as any).document_type" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="insuranceForm.document_number" :label="t('fleet_vehicles_show.insurance.policy_no')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="insuranceForm.issued_date" :label="t('fleet_vehicles_show.insurance.inception')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="insuranceForm.expiry_date" :label="t('fleet_vehicles_show.insurance.expiry')" type="date" variant="outlined" density="comfortable" hint="A reminder will be sent before expiry based on fleet settings" persistent-hint /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">INSURER / SUPPLIER</div></VCol>
            <VCol cols="12" md="6"><VTextField v-model="insuranceForm.supplier" :label="t('fleet_vehicles_show.insurance.supplier')" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="insuranceForm.contact_person" :label="t('fleet_vehicles_show.insurance.contact')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="insuranceForm.contact_number" label="Contact Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="insuranceForm.contact_email" label="Contact E-Mail" type="email" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="insuranceForm.status" :label="t('fleet_vehicles_show.insurance.status')" :items="[{title:'Valid',value:'valid'},{title:'Expired',value:'expired'},{title:'Pending Renewal',value:'pending_renewal'},{title:'Revoked',value:'revoked'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextarea v-model="insuranceForm.contact_address" label="Address" variant="outlined" density="comfortable" rows="2" auto-grow /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">COVERAGE & COST</div></VCol>
            <VCol cols="12" md="4"><VTextField v-model="insuranceForm.value_covered" :label="t('fleet_vehicles_show.insurance.value_covered')" type="number" variant="outlined" density="comfortable" prefix="R" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="insuranceForm.payment_frequency" :label="t('fleet_vehicles_show.insurance.frequency')" :items="[{title:'Monthly',value:'monthly'},{title:'Annual',value:'annual'},{title:'Once-Off',value:'once_off'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="insuranceForm.premium_amount" :label="t('fleet_vehicles_show.insurance.premium')" type="number" variant="outlined" density="comfortable" prefix="R" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">NOTES & ATTACHMENT</div></VCol>
            <VCol cols="12"><VTextarea v-model="insuranceForm.notes" label="Description / Notes" variant="outlined" density="comfortable" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VFileInput v-model="(insuranceForm as any).file" label="Attachment (PDF / JPG / PNG)" accept=".pdf,.jpg,.jpeg,.png" variant="outlined" density="comfortable" prepend-icon="tabler-paperclip" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="insuranceDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="insuranceForm.processing" @click="submitInsurance">{{ editingInsuranceId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Key Dialog -->
    <VDialog v-model="keyDialog" max-width="540" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-key</VIcon>{{ t('fleet_vehicles_show.dialog_titles.add_key') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" md="6"><VTextField v-model="keyForm.key_number" :label="t('fleet_vehicles_show.keys.key_no')" variant="outlined" density="comfortable" :error-messages="keyForm.errors.key_number" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="keyForm.key_type" :label="t('fleet_vehicles_show.keys.type')" :items="['master','spare','valet','duplicate']" variant="outlined" density="comfortable" :error-messages="keyForm.errors.key_type" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="keyForm.key_status" :label="t('fleet_vehicles_show.keys.status')" :items="[{title:'In Safe',value:'in_safe'},{title:'With Employee',value:'with_employee'},{title:'Lost',value:'lost'},{title:'Damaged',value:'damaged'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" :error-messages="keyForm.errors.key_status" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="keyForm.date_issued" :label="t('fleet_vehicles_show.keys.date_issued')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextarea v-model="keyForm.description" :label="t('fleet_vehicles_show.keys.description')" rows="2" auto-grow variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-body-2 mb-2 font-weight-medium">{{ t('fleet_vehicles_show.keys.issued_to') }}</div><VRadioGroup v-model="keyForm.issued_to_type" inline><VRadio label="Employee" value="employee" /><VRadio label="Safe" value="safe" /></VRadioGroup></VCol>
            <VCol v-if="keyForm.issued_to_type === 'employee'" cols="12"><VAutocomplete v-model="keyForm.issued_to_employee_id" label="Employee" :items="employees" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable :error-messages="(keyForm.errors as any).issued_to_employee_id" /></VCol>
            <VCol v-else cols="12"><VSelect v-model="keyForm.safe_id" label="Key Safe" :items="keySafes" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12"><VAutocomplete v-model="keyForm.issued_by_id" :label="t('fleet_vehicles_show.keys.issued_by')" :items="employees" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol v-if="keyForm.key_status === 'lost'" cols="12"><VTextField v-model="keyForm.reason_for_loss" :label="t('fleet_vehicles_show.keys.loss_reason')" variant="outlined" density="comfortable" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="keyDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="keyForm.processing" @click="submitKey">{{ t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Reminder Dialog -->
    <VDialog v-model="reminderDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-bell</VIcon>{{ editingReminderId ? t('fleet_vehicles_show.dialog_titles.edit_reminder') : t('fleet_vehicles_show.dialog_titles.add_reminder') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="reminderForm.name" label="Name *" variant="outlined" density="comfortable" :error-messages="reminderForm.errors.name" /></VCol>
            <VCol cols="12"><VTextarea v-model="reminderForm.description" label="Description" variant="outlined" density="comfortable" rows="3" auto-grow /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="reminderForm.start_date" :label="t('fleet_vehicles_show.reminders_tab.start')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="reminderForm.end_date" :label="t('fleet_vehicles_show.reminders_tab.end')" type="date" variant="outlined" density="comfortable" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="reminderDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="reminderForm.processing" @click="saveReminder">{{ editingReminderId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Fire Extinguisher Dialog -->
    <VDialog v-model="extDialog" max-width="680" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-fire-extinguisher</VIcon>{{ editingExtId ? t('fleet_vehicles_show.dialog_titles.edit_extinguisher') : t('fleet_vehicles_show.dialog_titles.add_extinguisher') }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height:80vh">
          <VRow dense class="mt-1">
            <VCol cols="12" md="6"><VTextField v-model="extForm.barcode" :label="t('fleet_vehicles_show.fire_extinguishers_tab.barcode')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.item" :label="t('fleet_vehicles_show.fire_extinguishers_tab.item')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextarea v-model="extForm.description" label="Description" variant="outlined" density="comfortable" rows="2" auto-grow /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.weight_kg" :label="t('fleet_vehicles_show.fire_extinguishers_tab.weight')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.serial_number" :label="t('fleet_vehicles_show.fire_extinguishers_tab.serial')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.invoice_number" :label="t('fleet_vehicles_show.fire_extinguishers_tab.invoice')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.purchase_order_number" :label="t('fleet_vehicles_show.fire_extinguishers_tab.po')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.supplier" :label="t('fleet_vehicles_show.fire_extinguishers_tab.supplier')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.date_purchased" :label="t('fleet_vehicles_show.fire_extinguishers_tab.date_purchased')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="extForm.cost" :label="t('fleet_vehicles_show.fire_extinguishers_tab.cost')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="extForm.status" :label="t('fleet_vehicles_show.fire_extinguishers_tab.status')" :items="[{title:'Active',value:'active'},{title:'Expired',value:'expired'},{title:'Damaged',value:'damaged'},{title:'Retired',value:'retired'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VFileInput v-model="(extForm as any).image" label="Image (JPG/PNG)" accept=".jpg,.jpeg,.png" variant="outlined" density="comfortable" prepend-icon="tabler-photo" /></VCol>
            <VCol cols="12"><VFileInput v-model="(extForm as any).document" label="Document (PDF/JPG/PNG)" accept=".pdf,.jpg,.jpeg,.png" variant="outlined" density="comfortable" prepend-icon="tabler-paperclip" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="extDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" @click="saveExt">{{ editingExtId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- General Cost Dialog -->
    <VDialog v-model="generalCostDialog" max-width="620" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-receipt</VIcon>{{ editingGeneralCostId ? t('fleet_vehicles_show.dialog_titles.edit_general_cost') : t('fleet_vehicles_show.dialog_titles.add_general_cost') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" md="6"><VTextField v-model="generalCostForm.date" :label="t('fleet_vehicles_show.general_cost.date')" type="date" variant="outlined" density="comfortable" :error-messages="generalCostForm.errors.date" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="generalCostForm.document_number" :label="t('fleet_vehicles_show.general_cost.doc_number')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="generalCostForm.supplier_name" :label="t('fleet_vehicles_show.general_cost.supplier')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="generalCostForm.cost_type" :label="t('fleet_vehicles_show.general_cost.cost_type')" :items="[{title:'Oil',value:'oil'},{title:'Routine Check-ups',value:'routine_checkup'},{title:'Tyre Change',value:'tyre_change'},{title:'Parts',value:'parts'},{title:'Other',value:'other'}]" item-title="title" item-value="value" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="generalCostForm.cost" :label="t('fleet_vehicles_show.general_cost.cost')" type="number" variant="outlined" density="comfortable" prefix="R" :error-messages="generalCostForm.errors.cost" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="generalCostForm.person_responsible_id" :label="t('fleet_vehicles_show.general_cost.person_responsible')" :items="employees" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12"><VTextarea v-model="generalCostForm.description" :label="t('fleet_vehicles_show.general_cost.description')" variant="outlined" density="comfortable" rows="3" auto-grow /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="generalCostDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="generalCostForm.processing" @click="submitGeneralCost">{{ editingGeneralCostId ? t('fleet_vehicles_show.dialogs.update') : t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Vehicle Dialog -->
    <VDialog v-model="editDialog" max-width="860" scrollable persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center"><VIcon start>tabler-pencil</VIcon>{{ t('fleet_vehicles_show.dialog_titles.edit_vehicle') }} — {{ vehicle.registration_number }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height:80vh">
          <VRow dense class="mt-1">
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-2">IDENTIFICATION</div></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.registration_number" label="Registration *" variant="outlined" density="comfortable" :error-messages="editForm.errors.registration_number" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.fleet_number" label="Fleet Number" variant="outlined" density="comfortable" :error-messages="editForm.errors.fleet_number" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.vin_number" label="VIN Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.chassis_number" label="Chassis Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.engine_number" label="Engine Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.engine_capacity" label="Engine Capacity (cc)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-3">VEHICLE DETAILS</div></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.make" label="Make" :items="makes" item-title="name" item-value="name" variant="outlined" density="comfortable" :error-messages="editForm.errors.make" @update:model-value="onEditMakeChange" clearable /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.model" label="Model" :items="editFilteredModels" item-title="name" item-value="name" variant="outlined" density="comfortable" :disabled="!editForm.make" clearable /></VCol>
            <VCol cols="12" md="2"><VTextField v-model.number="editForm.year" label="Year" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="2"><VTextField v-model="editForm.colour" label="Colour" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.fuel_type" label="Fuel Type" :items="fuelTypes" item-title="name" item-value="name" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.vehicle_type" label="Vehicle Type" :items="vehicleTypes" item-title="name" item-value="name" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.transmission" label="Transmission" :items="['automatic','manual']" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.seating_capacity" label="Seats" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.tank_size" label="Fuel Tank (litres)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.odometer_reading" label="Odometer (km)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.co2_emissions_g_per_km" label="CO₂ (g/km)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-3">ASSIGNMENT</div></VCol>
            <VCol cols="12" md="6"><VSelect v-model="editForm.company_unit_id" label="Department / Company Unit" :items="companyUnits" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="editForm.assigned_driver_id" label="Assigned Driver" :items="drivers" item-title="name" item-value="id" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="editForm.location" label="Location" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="editForm.key_safe_name" label="Key Safe" :items="keySafes" item-title="name" item-value="name" variant="outlined" density="comfortable" clearable /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-3">FINANCIAL</div></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.purchase_date" label="Purchase Date" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.purchase_price" label="Purchase Price (R)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="editForm.current_value" label="Current Value (R)" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="editForm.expected_disposal_date" label="Expected Disposal Date" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis font-weight-bold mb-1 mt-3">OWNERSHIP & TRACKING</div></VCol>
            <VCol cols="12" md="4"><VTextField v-model="editForm.owner_name" label="Owner Name" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="editForm.title_holder_name" label="Title Holder Name" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="editForm.status" label="Status" variant="outlined" density="comfortable" :items="['available','booked','maintenance','offroad','disposed']" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="editForm.vehicle_cell_number" label="Vehicle Cell Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="editForm.tracking_cell_number" label="Tracking Cell Number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="editForm.extras" label="Extras / Accessories" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextarea v-model="editForm.notes" label="Notes" rows="2" auto-grow variant="outlined" density="comfortable" /></VCol>
          </VRow>
          <VAlert v-if="Object.keys(editForm.errors).length" type="error" variant="tonal" density="compact" class="mt-3">Please fix the errors highlighted above.</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="editDialog=false">{{ t('fleet_vehicles_show.dialogs.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="editForm.processing" @click="submitEdit">{{ t('fleet_vehicles_show.dialogs.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Booking View Dialog -->
    <VDialog v-model="bookingViewDialog" max-width="640" scrollable>
      <VCard v-if="bookingViewItem">
        <VCardTitle class="d-flex align-center pa-4"><VIcon class="mr-2">tabler-calendar-check</VIcon>{{ bookingViewItem.reference }}<VSpacer /><VChip :color="stateColor(bookingViewItem.workflow_state)" size="small" label class="text-capitalize">{{ bookingViewItem.workflow_state.replace(/_/g, ' ') }}</VChip></VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" md="6"><div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_vehicles_show.bookings.driver') }}</div><div class="text-body-2">{{ bookingViewItem.driver || '—' }}</div></VCol>
            <VCol cols="12" md="6"><div class="text-caption text-medium-emphasis mb-1">Requester</div><div class="text-body-2">{{ bookingViewItem.requester || '—' }}</div></VCol>
            <VCol cols="12" md="6"><div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_vehicles_show.bookings.destination') }}</div><div class="text-body-2">{{ bookingViewItem.destination || '—' }}</div></VCol>
            <VCol v-if="bookingViewItem.project" cols="12" md="6"><div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_vehicles_show.bookings.project') }}</div><div class="text-body-2">{{ bookingViewItem.project }}</div></VCol>
            <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_vehicles_show.bookings.departure') }}</div><div class="text-body-2">{{ bookingViewItem.departure_at }}</div></VCol>
            <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis mb-1">{{ t('fleet_vehicles_show.bookings.return') }}</div><div class="text-body-2">{{ bookingViewItem.return_at || '—' }}</div></VCol>
            <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis mb-1">Distance</div><div class="text-body-2">{{ bookingViewItem.km_travelled ? `${bookingViewItem.km_travelled} km` : '—' }}</div></VCol>
            <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis mb-1">Trip Type</div><div class="text-body-2 text-capitalize">{{ bookingViewItem.booking_type?.replace(/_/g, ' ') || '—' }}</div></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3 justify-end"><VBtn variant="tonal" @click="bookingViewDialog = false">{{ t('fleet_vehicles_show.dialogs.close') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
