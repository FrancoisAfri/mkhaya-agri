<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{
  definition: any; versions: any[]; running: any[]
  people: Array<{ id: number; name: string }>; roles: string[]; calendars: Array<{ id: number; name: string }>; departments: Array<{ id: number; name: string }>
  categories: string[]
  can: { design: boolean; publish: boolean; manage: boolean; ai: boolean; me: number }
  requiresReview: boolean
}>()
const d = props.definition
const review = computed(() => props.versions.find(v => v.status === 'in_review'))
const working = computed(() => props.versions.find(v => ['draft', 'rejected'].includes(v.status)))

const props_ = useForm({ name: d.name, description: d.description, category: d.category, tags: d.tags ?? [], process_owner_id: d.process_owner_id, departments: d.departments ?? [],
  trigger_type: d.trigger_type, trigger_config: { ...(d.trigger_config ?? {}) }, start_roles: d.start_roles ?? [], default_priority: d.default_priority,
  calendar_id: d.calendar_id, is_confidential: d.is_confidential, restricted_user_ids: d.restricted_user_ids ?? [], retention_days: d.retention_days, reason: '' })
const propsDialog = ref(false)
function saveProps() { props_.patch(route('workflow.definitions.update', d.uuid), { onSuccess: () => { propsDialog.value = false; props_.reason = '' } }) }

const reviewForm = useForm({ approve: true, comment: '' })
const statusForm = useForm({ action: '', reason: '' })
const statusDialog = ref(false)
function openStatus(a: string) { statusForm.action = a; statusForm.reason = ''; statusDialog.value = true }
const rollbackForm = useForm({ reason: '' })
const rollbackTo = ref<any>(null)
const migrateForm = useForm({ instances: [] as string[], reason: '' })
const migrateDialog = ref(false)

const compare = ref<any>(null)
const cmp = ref<{ a: string | null; b: string | null }>({ a: null, b: null })
async function doCompare() {
  const r = await fetch(route('workflow.definitions.compare', d.uuid) + `?a=${cmp.value.a}&b=${cmp.value.b}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
  compare.value = await r.json()
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.definitions.index'))">Workflows</VBtn>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ d.key }} · {{ d.category ?? 'Uncategorised' }} · owner {{ d.owner ?? '—' }}</div>
        <h4 class="text-h4">{{ d.name }} <VChip :color="statusColor[d.status]" label class="ms-2">{{ label(d.status) }}</VChip></h4>
        <div class="text-body-2 text-medium-emphasis">{{ d.description }}</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn v-if="can.design || can.publish" color="primary" prepend-icon="tabler-hierarchy-2" @click="router.get(route('workflow.definitions.designer', d.uuid))">{{ working ? 'Continue the draft' : 'Open designer' }}</VBtn>
        <VBtn v-if="can.design" variant="tonal" prepend-icon="tabler-adjustments" @click="propsDialog = true">Properties</VBtn>
        <VBtn v-if="can.design" variant="tonal" prepend-icon="tabler-download" :href="route('workflow.definitions.export', d.uuid)">Export</VBtn>
        <VBtn v-if="(can.manage || can.publish) && d.status === 'published'" variant="tonal" color="warning" @click="openStatus('suspend')">Suspend</VBtn>
        <VBtn v-if="(can.manage || can.publish) && d.status === 'suspended'" variant="tonal" color="success" @click="openStatus('resume')">Resume</VBtn>
        <VBtn v-if="(can.manage || can.publish) && d.status !== 'retired'" variant="tonal" color="error" @click="openStatus('retire')">Retire</VBtn>
      </div>
    </div>

    <VAlert v-if="review" type="warning" variant="tonal" class="mb-4" :title="`Version ${review.version} is waiting for review`">
      <div class="text-body-2">Submitted by {{ review.submitted_by }} {{ when(review.submitted_at) }}: “{{ review.change_summary }}”</div>
      <template v-if="can.publish && ![review.submitted_by].includes(null)">
        <VTextarea v-model="reviewForm.comment" label="Review comment" rows="2" auto-grow class="mt-2" :error-messages="reviewForm.errors.comment" />
        <div class="d-flex gap-2">
          <VBtn color="success" :loading="reviewForm.processing" :disabled="reviewForm.comment.length < 3" @click="reviewForm.approve = true; reviewForm.post(route('workflow.definitions.review', d.uuid))">Approve and publish</VBtn>
          <VBtn color="error" variant="tonal" :disabled="reviewForm.comment.length < 3" @click="reviewForm.approve = false; reviewForm.post(route('workflow.definitions.review', d.uuid))">Send back</VBtn>
          <VBtn variant="text" @click="router.get(route('workflow.definitions.designer', d.uuid))">Look at it</VBtn>
        </div>
      </template>
      <VBtn v-if="can.design" size="small" variant="text" class="mt-2" @click="router.post(route('workflow.definitions.withdraw', d.uuid))">Withdraw to edit</VBtn>
    </VAlert>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Versions</VCardTitle>
            <template #append>
              <div class="d-flex gap-2 align-center">
                <VSelect v-model="cmp.a" :items="versions.map(v => ({ title: `v${v.version}`, value: v.uuid }))" density="compact" hide-details style="inline-size: 90px" />
                <VSelect v-model="cmp.b" :items="versions.map(v => ({ title: `v${v.version}`, value: v.uuid }))" density="compact" hide-details style="inline-size: 90px" />
                <VBtn size="small" variant="tonal" :disabled="!cmp.a || !cmp.b" @click="doCompare">Compare</VBtn>
              </div>
            </template>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Version</th><th>Status</th><th>What changed</th><th>Published</th><th>Running</th><th /></tr></thead>
            <tbody>
              <tr v-for="v in versions" :key="v.uuid">
                <td>{{ v.version }}<div class="text-caption text-medium-emphasis">{{ v.origin }}</div></td>
                <td><VChip size="small" :color="statusColor[v.status]" label>{{ label(v.status) }}</VChip><div v-if="v.problems" class="text-caption text-error">{{ v.problems }} to fix</div></td>
                <td class="text-body-2">{{ v.change_summary ?? '—' }}<div v-if="v.review_comment" class="text-caption text-medium-emphasis">Review: {{ v.review_comment }} ({{ v.reviewed_by }})</div></td>
                <td class="text-body-2 text-no-wrap">{{ v.published_at ? when(v.published_at, false) : '—' }}</td>
                <td>{{ v.running }}</td>
                <td><VBtn v-if="can.publish && ['superseded', 'retired'].includes(v.status)" size="x-small" variant="text" @click="rollbackTo = v">Roll back to this</VBtn></td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="compare" class="text-body-2">
            <strong>v{{ compare.a }} → v{{ compare.b }}:</strong>
            {{ compare.nodes_added.length ? `added ${compare.nodes_added.join(', ')}; ` : '' }}{{ compare.nodes_removed.length ? `removed ${compare.nodes_removed.join(', ')}; ` : '' }}
            <span v-for="c in compare.nodes_changed" :key="c.name">changed “{{ c.name }}” ({{ c.changes.join(', ') }}); </span>
            {{ compare.edges_added }} connection(s) added, {{ compare.edges_removed }} removed, {{ compare.edges_changed }} changed.
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Requests running ({{ running.length }})</VCardTitle><VCardSubtitle>Each stays on the version it started with.</VCardSubtitle>
            <template #append><VBtn v-if="can.manage && running.some(r => r.version !== versions.find(v => v.status === 'published')?.version)" size="small" variant="tonal" @click="migrateDialog = true">Migrate…</VBtn></template>
          </VCardItem>
          <VList density="compact">
            <VListItem v-for="r in running.slice(0, 15)" :key="r.uuid" :href="route('workflow.instances.show', r.uuid)">
              <VListItemTitle class="text-body-2">{{ r.reference }} — {{ r.title }}</VListItemTitle><VListItemSubtitle>version {{ r.version }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!running.length"><VListItemSubtitle>None.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="propsDialog" max-width="720" scrollable>
      <VCard title="Workflow properties">
        <VCardText class="d-flex flex-column gap-2">
          <VTextField v-model="props_.name" label="Name" :error-messages="props_.errors.name" />
          <VTextarea v-model="props_.description" label="Purpose" rows="2" auto-grow />
          <div class="d-flex gap-2"><VSelect v-model="props_.category" :items="categories" label="Category" clearable :error-messages="props_.errors.category" style="flex: 1" /><VCombobox v-model="props_.tags" label="Tags" multiple chips /></div>
          <div class="d-flex gap-2">
            <VAutocomplete v-model="props_.process_owner_id" :items="people" item-title="name" item-value="id" label="Process owner" />
            <VSelect v-model="props_.default_priority" :items="['low', 'normal', 'high']" label="Default priority" />
          </div>
          <VAutocomplete v-model="props_.departments" :items="departments" item-title="name" item-value="id" label="Applies to departments" multiple chips />
          <VSelect v-model="props_.trigger_type" :items="[{ title: 'Started by a person', value: 'manual' }, { title: 'Started by a module event', value: 'record_event' }, { title: 'On a schedule', value: 'scheduled' }]" label="How it starts" />
          <div v-if="props_.trigger_type === 'scheduled'" class="d-flex gap-2">
            <VSelect v-model="props_.trigger_config.frequency" :items="['daily', 'weekly', 'monthly']" label="Every" />
            <VTextField v-model="props_.trigger_config.time" label="At (HH:MM)" />
            <VTextField v-if="props_.trigger_config.frequency === 'weekly'" v-model.number="props_.trigger_config.weekday" type="number" label="Weekday (1 = Mon)" />
            <VTextField v-if="props_.trigger_config.frequency === 'monthly'" v-model.number="props_.trigger_config.day" type="number" label="Day of month" />
          </div>
          <VAutocomplete v-model="props_.start_roles" :items="roles" label="Who may start it (empty = anyone with workflow access)" multiple chips />
          <VSelect v-model="props_.calendar_id" :items="calendars" item-title="name" item-value="id" label="Business calendar (for SLAs)" clearable />
          <VSwitch v-model="props_.is_confidential" label="Confidential (HR, disciplinary, whistleblowing)" color="warning" inset hide-details />
          <VAutocomplete v-if="props_.is_confidential" v-model="props_.restricted_user_ids" :items="people" item-title="name" item-value="id" label="Who may see it besides its participants" multiple chips />
          <VTextField v-model.number="props_.retention_days" type="number" label="Keep finished requests for (days, empty = indefinitely)" />
          <VTextField v-model="props_.reason" label="Reason for the change" :error-messages="props_.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="propsDialog = false">Cancel</VBtn><VBtn color="primary" :loading="props_.processing" :disabled="props_.reason.length < 3" @click="saveProps">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="statusDialog" max-width="460">
      <VCard :title="`${label(statusForm.action)} this workflow`">
        <VCardText>
          <p class="text-body-2 mb-2">{{ statusForm.action === 'suspend' ? 'No new requests can start; running ones continue.' : statusForm.action === 'retire' ? 'It can no longer be started or edited; running requests finish on their version.' : 'New requests can start again.' }}</p>
          <VTextarea v-model="statusForm.reason" label="Reason" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="statusDialog = false">Cancel</VBtn>
          <VBtn color="primary" :disabled="statusForm.reason.length < 5" :loading="statusForm.processing" @click="statusForm.post(route('workflow.definitions.status', d.uuid), { onSuccess: () => (statusDialog = false) })">{{ label(statusForm.action) }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!rollbackTo" max-width="460" @update:model-value="rollbackTo = null">
      <VCard :title="`Roll back to version ${rollbackTo?.version}`">
        <VCardText><p class="text-body-2 mb-2">A copy of that version becomes the newest version{{ requiresReview ? ', after review' : '' }}. Running requests are not affected.</p>
          <VTextarea v-model="rollbackForm.reason" label="Reason" rows="2" auto-grow /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="rollbackTo = null">Cancel</VBtn>
          <VBtn color="primary" :disabled="rollbackForm.reason.length < 5" @click="rollbackForm.post(route('workflow.definitions.rollback', [d.uuid, rollbackTo.uuid]), { onSuccess: () => (rollbackTo = null) })">Roll back</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="migrateDialog" max-width="560">
      <VCard title="Move running requests to the published version">
        <VCardText>
          <p class="text-body-2 mb-2">Only requests waiting at steps the published version also has can move. Their decisions, data and history are kept.</p>
          <VAutocomplete v-model="migrateForm.instances" :items="running.filter(r => r.version !== versions.find(v => v.status === 'published')?.version).map(r => ({ title: `${r.reference} (v${r.version})`, value: r.uuid }))" multiple chips label="Requests" />
          <VTextarea v-model="migrateForm.reason" label="Reason" rows="2" auto-grow :error-messages="migrateForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="migrateDialog = false">Cancel</VBtn>
          <VBtn color="primary" :disabled="!migrateForm.instances.length || migrateForm.reason.length < 10" @click="migrateForm.post(route('workflow.definitions.migrate', d.uuid), { onSuccess: () => (migrateDialog = false) })">Migrate</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
