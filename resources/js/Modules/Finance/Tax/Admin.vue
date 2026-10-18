<script setup lang="ts">
import { computed, reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label } from './useTax'

const route = ziggyRoute

const props = defineProps<{
  settings: Array<{ key: string; label: string; value: unknown; default: unknown }>
  permissions: string[]
  users: Array<{ id: number; name: string; permissions: string[]; roles: string[] }>
  taxTypes: Array<{ code: string; name: string; frequencies: string[]; filing_due_rule: Record<string, unknown> | null; payment_due_rule: Record<string, unknown> | null; has_return: boolean; has_payment: boolean }>
  forms: Array<{ code: string; version: number; name: string; effective_from: string; effective_to: string | null; source_reference: string | null }>
  events: { counts: Record<string, number>; failed: Array<{ id: number; name: string; attempts: number; last_error: string | null; created_at: string }> }
}>()

const values = reactive(Object.fromEntries(props.settings.map(s => [s.key, Array.isArray(s.value) ? (s.value as number[]).join(', ') : s.value])))
const form = useForm({ reason: '' })
function save() {
  const payload: Record<string, unknown> = { reason: form.reason }
  for (const s of props.settings) {
    payload[s.key] = s.key === 'reminder_days' ? String(values[s.key]).split(',').map(v => Number(v.trim())).filter(Boolean) : values[s.key]
  }
  form.transform(() => payload).post(route('tax.admin.update'), { preserveScroll: true, onSuccess: () => form.reset() })
}

// The three steps of anything filed — someone holding more than one of them is not wrong (the system
// still refuses self-approval record by record) but is worth knowing about.
const steps = ['tax.prepare', 'tax.review', 'tax.approve']
const combined = computed(() => props.users.filter(u => steps.filter(s => u.permissions.includes(s)).length > 1))
const rule = (r: Record<string, unknown> | null) => !r ? '—' : Object.entries(r).map(([k, v]) => `${k}: ${v}`).join(', ')
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Tax administration</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">This company's tax settings, who may do what, and the configuration that changes only through a reviewed release.</p>

    <VRow>
      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Settings</VCardTitle><VCardSubtitle>Every change is kept on the audit trail with its reason.</VCardSubtitle></VCardItem>
          <VCardText>
            <VTextField v-for="s in settings" :key="s.key" v-model="values[s.key]" :label="s.label" density="compact" class="mb-2"
                        :hint="`Default: ${Array.isArray(s.default) ? (s.default as number[]).join(', ') : s.default}`" persistent-hint />
            <VTextarea v-model="form.reason" label="Reason for the change" rows="2" auto-grow class="mt-2" :error-messages="form.errors.reason" />
          </VCardText>
          <VCardActions><VSpacer /><VBtn color="primary" :loading="form.processing" :disabled="form.reason.trim().length < 5" @click="save">Save</VBtn></VCardActions>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Event delivery</VCardTitle><VCardSubtitle>Tax events handed to other parts of the system, with retries.</VCardSubtitle></VCardItem>
          <VCardText class="d-flex gap-2">
            <VChip v-for="s in ['pending', 'delivered', 'failed']" :key="s" :color="s === 'failed' && events.counts[s] ? 'error' : undefined" label>{{ label(s) }} · {{ events.counts[s] ?? 0 }}</VChip>
          </VCardText>
          <VList v-if="events.failed.length" density="compact">
            <VListItem v-for="e in events.failed" :key="e.id">
              <VListItemTitle class="text-body-2">{{ e.name }} <span class="text-caption text-disabled">#{{ e.id }} · {{ e.attempts }} attempts</span></VListItemTitle>
              <VListItemSubtitle>{{ e.last_error }}</VListItemSubtitle>
              <template #append><VBtn size="x-small" variant="text" @click="router.post(route('tax.admin.events.retry', e.id), {}, { preserveScroll: true })">Retry</VBtn></template>
            </VListItem>
          </VList>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Return forms</VCardTitle><VCardSubtitle>Field mappings, versioned. A SARS form change is a new version.</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <tbody><tr v-for="f in forms" :key="`${f.code}-${f.version}`"><td>{{ f.code }} v{{ f.version }}</td><td class="text-caption">{{ f.effective_from }} → {{ f.effective_to ?? 'open' }}<div class="text-disabled">{{ f.source_reference }}</div></td></tr></tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Who holds tax permissions</VCardTitle><VCardSubtitle>Grant them through roles in Roles & permissions.</VCardSubtitle></VCardItem>
          <VAlert v-if="combined.length" type="info" variant="tonal" density="compact" class="mx-4">
            {{ combined.length }} person(s) can take more than one of prepare, review and approve. The system still refuses the same person two steps on one record.
          </VAlert>
          <VTable density="compact">
            <thead><tr><th>Person</th><th>Roles</th><th>Permissions</th></tr></thead>
            <tbody>
              <tr v-for="u in users" :key="u.id">
                <td>{{ u.name }}</td>
                <td class="text-caption">{{ u.roles.join(', ') || '—' }}</td>
                <td><VChip v-for="p in u.permissions" :key="p" size="x-small" class="me-1 mb-1" :color="steps.includes(p) ? 'primary' : undefined" label>{{ p.replace('tax.', '') }}</VChip></td>
              </tr>
              <tr v-if="!users.length"><td colspan="3" class="text-center text-medium-emphasis py-4">Nobody holds a tax permission yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Tax types and due-date rules</VCardTitle><VCardSubtitle>Published SARS rules as data; a single deadline is moved on the calendar, with a reason.</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>Tax</th><th>Frequencies</th><th>Return due</th><th>Payment due</th></tr></thead>
            <tbody>
              <tr v-for="t in taxTypes" :key="t.code">
                <td>{{ t.code }}<div class="text-caption text-disabled">{{ t.name }}</div></td>
                <td class="text-caption">{{ t.frequencies.map(f => label(f)).join(', ') }}</td>
                <td class="text-caption">{{ t.has_return ? rule(t.filing_due_rule) : 'no return' }}</td>
                <td class="text-caption">{{ t.has_payment ? rule(t.payment_due_rule) : 'no payment' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
