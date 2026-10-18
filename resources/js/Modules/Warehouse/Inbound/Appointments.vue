<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Appt = { id: string; number: string; dock: string | null; po: string | null; supplier: string | null; slot_start: string; slot_end: string; carrier: string | null; vehicle_reg: string | null; driver_name: string | null; status: string; overdue: boolean; arrived_at: string | null; departed_at: string | null; wait_minutes: number | null }

const props = defineProps<{
  warehouses: Array<{ id: string; title: string }>
  warehouse: string | null
  day: string
  queue: Appt[]
  docks: Array<{ id: number; title: string }>
  orders: Array<{ id: number; title: string; supplier_id: number | null }>
  suppliers: Array<{ id: number; title: string }>
  people: Array<{ id: number; name: string }>
  can: { book: boolean }
}>()

const overdue = computed(() => props.queue.filter(a => a.overdue))
function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.inbound.appointments.index'), { warehouse: props.warehouse, day: props.day, ...patch })
}

const dialog = ref(false)
const form = useForm({ warehouse: props.warehouse, dock_bin_id: null as number | null, purchase_order_id: null as number | null, supplier_id: null as number | null,
  slot_start: `${props.day}T09:00`, slot_end: `${props.day}T10:00`, carrier: '', vehicle_reg: '', driver_name: '', driver_phone: '', assigned_user_id: null as number | null, notes: '' })
function book() {
  form.post(route('warehouse.inbound.appointments.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
function move(a: Appt, to: string) {
  router.post(route('warehouse.inbound.appointments.move', a.id), { to }, { preserveScroll: true })
}
const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
const color = (s: string) => ({ BOOKED: 'primary', ARRIVED: 'warning', UNLOADING: 'info', COMPLETED: 'success', NO_SHOW: 'error', CANCELLED: 'secondary' } as Record<string, string>)[s]
const next: Record<string, string[]> = { BOOKED: ['ARRIVED', 'NO_SHOW', 'CANCELLED'], ARRIVED: ['UNLOADING', 'COMPLETED'], UNLOADING: ['COMPLETED'] }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.appointments.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.appointments.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VSelect :model-value="warehouse" :items="warehouses" item-title="title" item-value="id" density="compact" hide-details style="min-width: 240px" @update:model-value="v => reload({ warehouse: v })" />
        <VTextField :model-value="day" type="date" density="compact" hide-details style="max-width: 170px" @update:model-value="v => reload({ day: v })" />
        <VBtn v-if="can.book" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.appointments.book') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VAlert v-if="overdue.length" type="error" variant="tonal" class="mb-4">{{ t('warehouse_management.appointments.overdue', { n: overdue.length, list: overdue.map(a => a.number).join(', ') }) }}</VAlert>
    <VAlert v-if="!docks.length" type="info" variant="tonal" class="mb-4">{{ t('warehouse_management.appointments.no_docks') }}</VAlert>

    <VCard border>
      <VCardText v-if="!queue.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.appointments.none') }}</VCardText>
      <VTable v-else density="comfortable">
        <thead><tr><th>{{ t('warehouse_management.appointments.slot') }}</th><th>{{ t('warehouse_management.appointments.dock') }}</th><th>{{ t('warehouse_management.products.supplier') }}</th><th>{{ t('warehouse_management.appointments.vehicle') }}</th><th>{{ t('warehouse_management.locations.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="a in queue" :key="a.id" :class="{ 'bg-error-lighten': a.overdue }">
            <td class="text-no-wrap">{{ time(a.slot_start) }}–{{ time(a.slot_end) }}<div class="text-caption text-medium-emphasis">{{ a.number }}<template v-if="a.po"> · {{ a.po }}</template></div></td>
            <td>{{ a.dock ?? '—' }}</td>
            <td>{{ a.supplier }}<div v-if="a.carrier" class="text-caption">{{ a.carrier }}</div></td>
            <td class="text-caption">{{ a.vehicle_reg }}<div v-if="a.driver_name">{{ a.driver_name }}</div><div v-if="a.wait_minutes !== null">{{ t('warehouse_management.appointments.waited', { n: a.wait_minutes }) }}</div></td>
            <td><VChip size="small" label variant="tonal" :color="a.overdue ? 'error' : color(a.status)">{{ a.overdue ? t('warehouse_management.appointments.late') : t(`warehouse_management.appointment_status.${a.status}`) }}</VChip></td>
            <td class="text-end text-no-wrap">
              <VBtn v-for="to in (next[a.status] ?? [])" :key="to" size="small" variant="text" @click="move(a, to)">{{ t(`warehouse_management.appointment_action.${to}`) }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.appointments.book') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="form.dock_bin_id" :items="docks" item-title="title" item-value="id" clearable :label="t('warehouse_management.appointments.dock')" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.purchase_order_id" :items="orders" item-title="title" item-value="id" clearable :label="t('warehouse_management.expected.order')" /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.supplier_id" :items="suppliers" item-title="title" item-value="id" clearable :label="t('warehouse_management.products.supplier')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.slot_start" type="datetime-local" :label="t('warehouse_management.appointments.from')" :error-messages="form.errors.slot_start" /></VCol>
            <VCol cols="6"><VTextField v-model="form.slot_end" type="datetime-local" :label="t('warehouse_management.appointments.to')" :error-messages="form.errors.slot_end" /></VCol>
            <VCol cols="6"><VTextField v-model="form.carrier" :label="t('warehouse_management.appointments.carrier')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.vehicle_reg" :label="t('warehouse_management.appointments.vehicle')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.driver_name" :label="t('warehouse_management.appointments.driver')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.driver_phone" :label="t('warehouse_management.appointments.phone')" /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.assigned_user_id" :items="people" item-title="name" item-value="id" clearable :label="t('warehouse_management.appointments.receiver')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="book">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
