<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'

const route = ziggyRoute
defineProps<{ delegations: any[]; people: Array<{ id: number; name: string }>; categories: string[]; can: { manage: boolean } }>()
const dialog = ref(false)
const today = new Date().toISOString().slice(0, 16)
const form = useForm({ from_user_id: null as number | null, to_user_id: null as number | null, starts_at: today, ends_at: '', categories: [] as string[], reason: '' })
function save() { form.post(route('workflow.delegations.store'), { onSuccess: () => { dialog.value = false; form.reset() } }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Delegations</h4><div class="text-body-2 text-medium-emphasis">While you are away, someone you choose can act on your workflow tasks. It ends on its own, never lets anyone approve their own request, and gives no wider access to the records themselves.</div></div>
      <VBtn color="primary" prepend-icon="tabler-user-share" @click="dialog = true">Delegate my tasks</VBtn>
    </div>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>From</th><th>To</th><th>Period</th><th>Covers</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="d in delegations" :key="d.uuid">
            <td>{{ d.from }}<VChip v-if="d.from_me" size="x-small" class="ms-1" label>you</VChip></td>
            <td>{{ d.to }}<VChip v-if="d.to_me" size="x-small" class="ms-1" label>you</VChip></td>
            <td class="text-body-2 text-no-wrap">{{ when(d.starts_at) }} – {{ when(d.ends_at) }}</td>
            <td class="text-body-2">{{ d.categories.length ? d.categories.join(', ') : 'All workflows' }}<div class="text-caption text-medium-emphasis">{{ d.reason }}</div></td>
            <td><VChip size="small" :color="statusColor[d.status]" label>{{ label(d.status) }}</VChip></td>
            <td><VBtn v-if="d.status === 'active' && (d.from_me || can.manage)" size="small" variant="text" color="error" @click="router.post(route('workflow.delegations.revoke', d.uuid))">End</VBtn></td>
          </tr>
          <tr v-if="!delegations.length"><td colspan="6" class="text-medium-emphasis">No delegations.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VDialog v-model="dialog" max-width="520">
      <VCard title="Delegate tasks">
        <VCardText class="d-flex flex-column gap-2">
          <VAutocomplete v-if="can.manage" v-model="form.from_user_id" :items="people" item-title="name" item-value="id" label="Whose tasks (empty = yours)" clearable />
          <VAutocomplete v-model="form.to_user_id" :items="people" item-title="name" item-value="id" label="Who acts for them" :error-messages="form.errors.to_user_id" />
          <div class="d-flex gap-2"><VTextField v-model="form.starts_at" type="datetime-local" label="From" /><VTextField v-model="form.ends_at" type="datetime-local" label="Until" :error-messages="form.errors.ends_at" /></div>
          <VAutocomplete v-model="form.categories" :items="categories" multiple chips label="Only these categories (empty = all)" />
          <VTextField v-model="form.reason" label="Reason (e.g. annual leave)" :error-messages="form.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.to_user_id || !form.ends_at || form.reason.length < 3" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
