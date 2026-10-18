<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Deadline = {
  uuid: string; title: string; kind: string; tax_type: string | null; entity: string | null; due_date: string; days_left: number
  status: string; source: string; owner_id: number | null; reviewer_id: number | null; escalation_id: number | null
  owner: string | null; reviewer: string | null; period: { uuid: string; label: string; status: string } | null
  resolution_note: string | null; completed_at: string | null; reminders_sent: string[]
}

const props = defineProps<{
  deadlines: Deadline[]
  filters: { status?: string; tax_type?: string; owner?: string; from?: string; to?: string }
  people: Array<{ id: number; name: string }>
  taxTypes: Array<{ id: number; code: string; name: string }>
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
  can: { manage: boolean }
}>()

const f = reactive({ status: props.filters.status ?? 'outstanding', tax_type: props.filters.tax_type ?? null, owner: props.filters.owner ? Number(props.filters.owner) : null })
function apply() {
  router.get(route('tax.calendar.index'), { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), ...(props.business ? { business: props.business } : {}) }, { preserveState: true, preserveScroll: true })
}

// The list grouped by month — the shape a compliance officer plans in.
const byMonth = computed(() => {
  const groups: Record<string, Deadline[]> = {}
  for (const d of props.deadlines) {
    const key = new Date(`${d.due_date}T00:00:00`).toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' })
    ;(groups[key] ??= []).push(d)
  }
  return groups
})

function dueLabel(d: Deadline) {
  if (['done', 'waived'].includes(d.status)) return label(d.status)
  if (d.days_left < 0) return `${-d.days_left} day(s) overdue`
  if (d.days_left === 0) return 'Due today'
  return `in ${d.days_left} day(s)`
}

type Mode = 'create' | 'reschedule' | 'assign' | 'complete'
const dialog = ref(false)
const mode = ref<Mode>('create')
const target = ref<Deadline | null>(null)
const form = useForm({
  title: '', due_date: '', tax_type_id: null as number | null, entity_id: null as number | null,
  owner_id: null as number | null, reviewer_id: null as number | null, escalation_id: null as number | null,
  reason: '', verified: false, note: '',
})
function openDialog(m: Mode, d: Deadline | null = null) {
  mode.value = m
  target.value = d
  form.reset()
  if (d) Object.assign(form, { due_date: d.due_date, owner_id: d.owner_id, reviewer_id: d.reviewer_id, escalation_id: d.escalation_id })
  dialog.value = true
}
function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (mode.value === 'create') form.transform(d => ({ title: d.title, due_date: d.due_date, tax_type_id: d.tax_type_id, entity_id: d.entity_id, owner_id: d.owner_id, reviewer_id: d.reviewer_id, escalation_id: d.escalation_id })).post(route('tax.calendar.store'), done)
  else if (mode.value === 'reschedule') form.transform(d => ({ due_date: d.due_date, reason: d.reason, verified: d.verified })).post(route('tax.calendar.reschedule', target.value!.uuid), done)
  else if (mode.value === 'assign') form.transform(d => ({ owner_id: d.owner_id, reviewer_id: d.reviewer_id, escalation_id: d.escalation_id })).post(route('tax.calendar.assign', target.value!.uuid), done)
  else form.transform(d => ({ note: d.note })).post(route('tax.calendar.complete', target.value!.uuid), done)
}
const titles: Record<Mode, string> = { create: 'Add a deadline', reschedule: 'Move deadline', assign: 'Assign', complete: 'Mark done' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Compliance calendar</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Filing and payment deadlines. Owners are reminded at 14, 7, 3 and 1 day(s); an overdue item is escalated once.</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="openDialog('create')">Add deadline</VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VBtnToggle v-model="f.status" density="compact" variant="outlined" divided mandatory @update:model-value="apply">
          <VBtn value="outstanding">Outstanding</VBtn><VBtn value="overdue">Overdue</VBtn><VBtn value="done">Done</VBtn>
        </VBtnToggle>
        <VSelect v-model="f.tax_type" :items="taxTypes" item-title="code" item-value="code" label="Tax" clearable density="compact" style="max-inline-size: 150px" @update:model-value="apply" />
        <VAutocomplete v-model="f.owner" :items="people" item-title="name" item-value="id" label="Owner" clearable density="compact" style="max-inline-size: 240px" @update:model-value="apply" />
      </VCardText>
    </VCard>

    <div v-if="!deadlines.length" class="text-center text-medium-emphasis py-8">Nothing here. Deadlines are created with each tax period, or added by hand.</div>

    <div v-for="(items, month) in byMonth" :key="month" class="mb-4">
      <div class="text-overline mb-1">{{ month }}</div>
      <VCard border>
        <VList density="comfortable" lines="two">
          <VListItem v-for="d in items" :key="d.uuid">
            <template #prepend>
              <VAvatar size="36" :color="d.status === 'done' ? 'success' : d.days_left < 0 ? 'error' : d.days_left <= 3 ? 'warning' : 'grey'" variant="tonal">
                <span class="text-caption font-weight-bold">{{ d.due_date.slice(8, 10) }}</span>
              </VAvatar>
            </template>
            <VListItemTitle>
              {{ d.title }}
              <VChip size="x-small" class="ms-1" label>{{ d.kind }}</VChip>
              <VChip v-if="d.source !== 'rule'" size="x-small" class="ms-1" label color="info">{{ d.source }}</VChip>
            </VListItemTitle>
            <VListItemSubtitle>
              {{ d.entity ?? '—' }} · owner {{ d.owner ?? 'unassigned' }} · reviewer {{ d.reviewer ?? '—' }}
              <span v-if="d.period"> · <a href="#" @click.prevent="router.get(route('tax.periods.show', d.period.uuid))">period {{ label(d.period.status).toLowerCase() }}</a></span>
              <span v-if="d.resolution_note"> · {{ d.resolution_note }}</span>
            </VListItemSubtitle>
            <template #append>
              <div class="d-flex align-center gap-2">
                <VChip size="small" :color="d.status === 'done' ? 'success' : statusColor[d.status]" label>{{ dueLabel(d) }}</VChip>
                <VMenu v-if="d.status !== 'done'">
                  <template #activator="{ props: m }"><VBtn v-bind="m" icon="tabler-dots-vertical" size="small" variant="text" /></template>
                  <VList density="compact">
                    <VListItem title="Mark done" @click="openDialog('complete', d)" />
                    <VListItem v-if="can.manage" title="Move date" @click="openDialog('reschedule', d)" />
                    <VListItem v-if="can.manage" title="Assign" @click="openDialog('assign', d)" />
                  </VList>
                </VMenu>
              </div>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </div>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="titles[mode] + (target ? ` · ${target.title}` : '')">
        <VCardText>
          <template v-if="mode === 'create'">
            <VTextField v-model="form.title" label="What is due" :error-messages="form.errors.title" />
            <VTextField v-model="form.due_date" type="date" label="Due date" :error-messages="form.errors.due_date" />
            <VSelect v-model="form.tax_type_id" :items="taxTypes" item-title="code" item-value="id" label="Tax (optional)" clearable />
            <VSelect v-if="businesses.length > 1" v-model="form.entity_id" :items="businesses" item-title="name" item-value="id" label="Business (optional)" clearable />
          </template>
          <template v-if="mode === 'reschedule'">
            <VTextField v-model="form.due_date" type="date" label="New due date" :error-messages="form.errors.due_date" />
            <VTextarea v-model="form.reason" label="Why it moved (e.g. SARS notice, public holiday)" rows="2" auto-grow :error-messages="form.errors.reason" />
            <VCheckbox v-model="form.verified" label="This date is verified from an official SARS source" density="compact" />
          </template>
          <template v-if="mode === 'create' || mode === 'assign'">
            <VAutocomplete v-model="form.owner_id" :items="people" item-title="name" item-value="id" label="Owner" clearable />
            <VAutocomplete v-model="form.reviewer_id" :items="people" item-title="name" item-value="id" label="Reviewer" clearable />
            <VAutocomplete v-model="form.escalation_id" :items="people" item-title="name" item-value="id" label="Escalation contact" clearable />
          </template>
          <template v-if="mode === 'complete'">
            <VTextarea v-model="form.note" label="What was done (e.g. return filed, eFiling ref …)" rows="2" auto-grow :error-messages="form.errors.note" />
          </template>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
