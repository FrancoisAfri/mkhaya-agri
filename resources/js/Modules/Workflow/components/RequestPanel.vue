<script setup lang="ts">
// Everything about a request a permitted person may see: its values, the business record, the
// path it took, every step and decision, comments and documents.
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from '../useWorkflow'
import PathGraph from './PathGraph.vue'

const route = ziggyRoute
// An absent boolean prop is false in Vue, so the request page (which passes nothing) needs the default.
const props = withDefaults(defineProps<{ request: any; showActions?: boolean }>(), { showActions: true })
const tab = ref('details')
const r = props.request

const comment = useForm({ body: '' })
function addComment() { comment.post(route('workflow.instances.comment', r.instance.uuid), { preserveScroll: true, onSuccess: () => comment.reset() }) }

const answer = useForm({ answer: '' })
function respond() { answer.post(route('workflow.instances.respond', r.instance.uuid), { preserveScroll: true, onSuccess: () => answer.reset() }) }

const file = useForm({ file: null as File | null, title: '' })
function attach() { file.post(route('workflow.instances.attach', r.instance.uuid), { forceFormData: true, preserveScroll: true, onSuccess: () => file.reset() }) }

const cancelDialog = ref(false)
const cancel = useForm({ reason: '' })
function doCancel() { cancel.post(route('workflow.instances.cancel', r.instance.uuid), { onSuccess: () => { cancelDialog.value = false } }) }
</script>

<template>
  <div>
    <VAlert v-if="r.instance.error" type="warning" variant="tonal" density="compact" class="mb-3">{{ r.instance.error }}</VAlert>
    <VAlert v-if="r.can.respond" type="info" variant="tonal" class="mb-3" title="An approver needs more information">
      <div class="text-body-2 mb-2">Read the latest comment, then answer here. The approver's deadline is paused until you do.</div>
      <VTextarea v-model="answer.answer" label="Your answer" rows="2" auto-grow :error-messages="answer.errors.answer" />
      <VBtn color="primary" :loading="answer.processing" :disabled="answer.answer.trim().length < 2" @click="respond">Send answer</VBtn>
    </VAlert>

    <VTabs v-model="tab" density="compact" class="mb-3">
      <VTab value="details">Details</VTab>
      <VTab value="path">Path</VTab>
      <VTab value="history">History <VChip size="x-small" class="ms-1">{{ r.timeline.length }}</VChip></VTab>
      <VTab value="discussion">Comments &amp; documents <VChip size="x-small" class="ms-1">{{ r.comments.length + r.attachments.length }}</VChip></VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="details">
        <VRow>
          <VCol cols="12" md="7">
            <VCard border>
              <VCardItem><VCardTitle class="text-body-1">Request</VCardTitle></VCardItem>
              <VTable density="compact">
                <tbody>
                  <tr v-for="v in r.values" :key="v.key">
                    <td class="text-medium-emphasis" style="inline-size: 40%">{{ v.label }}</td>
                    <td>
                      <template v-if="v.type === 'line_items' && Array.isArray(v.raw)">
                        <VTable density="compact">
                          <thead><tr><th v-for="c in v.columns ?? []" :key="c.key">{{ c.label }}</th></tr></thead>
                          <tbody><tr v-for="(row, i) in v.raw" :key="i"><td v-for="c in v.columns ?? []" :key="c.key">{{ row[c.key] }}</td></tr></tbody>
                        </VTable>
                      </template>
                      <span v-else>{{ v.value || '—' }}</span>
                    </td>
                  </tr>
                  <tr v-if="!r.values.length"><td class="text-medium-emphasis">No form values.</td></tr>
                </tbody>
              </VTable>
            </VCard>
          </VCol>
          <VCol cols="12" md="5">
            <VCard v-if="r.record" border class="mb-3">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ r.record.module }}</VCardTitle>
                <VCardSubtitle>{{ r.record.reference }}</VCardSubtitle>
                <template #append><VBtn v-if="r.record.url" :href="r.record.url" size="small" variant="tonal" append-icon="tabler-external-link">Open</VBtn></template>
              </VCardItem>
              <VTable density="compact"><tbody><tr v-for="f in r.record.fields" :key="f.label"><td class="text-medium-emphasis">{{ f.label }}</td><td>{{ f.value }}</td></tr></tbody></VTable>
              <VCardText v-if="!r.record.url" class="text-caption text-medium-emphasis pt-2">You can see what this workflow needs; opening the record itself follows its module's own access rules.</VCardText>
            </VCard>
            <VCard border>
              <VCardItem><VCardTitle class="text-body-1">Steps</VCardTitle></VCardItem>
              <VList density="compact">
                <VListItem v-for="t in r.tasks" :key="t.uuid" :href="route('workflow.tasks.show', t.uuid)">
                  <VListItemTitle class="text-body-2">{{ t.name }}</VListItemTitle>
                  <VListItemSubtitle>
                    {{ t.status === 'open' ? (t.queue ? 'In a queue' : `With ${t.assignee}`) : `${label(t.decision ?? t.status)}${t.decided_by ? ' by ' + t.decided_by : ''}${t.on_behalf_of ? ' for ' + t.on_behalf_of : ''}` }}
                  </VListItemSubtitle>
                  <template #append><VChip size="x-small" :color="statusColor[t.decision ?? t.status]" label>{{ label(t.decision ?? t.status) }}</VChip></template>
                </VListItem>
                <VListItem v-if="!r.tasks.length"><VListItemSubtitle>No people have been involved yet.</VListItemSubtitle></VListItem>
              </VList>
            </VCard>
            <div v-if="showActions && r.can.cancel" class="mt-3 text-end">
              <VBtn color="error" variant="tonal" prepend-icon="tabler-x" @click="cancelDialog = true">Cancel request</VBtn>
            </div>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem value="path">
        <PathGraph v-if="tab === 'path'" :graph="r.graph" :height="380" /><!-- mounted when visible, so it can fit the drawing to its width -->
        <div class="text-caption text-medium-emphasis mt-1">Filled steps are done; a pulsing step is where the request waits; solid lines are the route it took.</div>
      </VWindowItem>

      <VWindowItem value="history">
        <VTimeline density="compact" side="end" truncate-line="both">
          <VTimelineItem v-for="(e, i) in r.timeline" :key="i" size="x-small" :dot-color="statusColor[e.outcome ?? e.status] ?? statusColor[e.status] ?? 'grey'">
            <div class="d-flex justify-space-between flex-wrap gap-2">
              <div><strong class="text-body-2">{{ e.node }}</strong> <span class="text-caption text-medium-emphasis">{{ e.type_label }}{{ e.activation > 1 ? ` · pass ${e.activation}` : '' }}</span></div>
              <span class="text-caption text-medium-emphasis">{{ when(e.started_at) }}</span>
            </div>
            <div class="text-body-2">{{ label(e.status) }}{{ e.outcome && e.outcome !== 'completed' ? ` — ${label(e.outcome)}` : '' }}</div>
            <div v-if="e.detail" class="text-caption text-medium-emphasis">{{ e.detail }}</div>
          </VTimelineItem>
        </VTimeline>
      </VWindowItem>

      <VWindowItem value="discussion">
        <VRow>
          <VCol cols="12" md="7">
            <VCard border>
              <VCardItem><VCardTitle class="text-body-1">Comments</VCardTitle></VCardItem>
              <VList density="compact" lines="three">
                <VListItem v-for="c in r.comments" :key="c.id">
                  <VListItemSubtitle class="text-caption">{{ c.by }} · {{ when(c.at) }}</VListItemSubtitle>
                  <div class="text-body-2" style="white-space: pre-wrap">{{ c.body }}</div>
                </VListItem>
                <VListItem v-if="!r.comments.length"><VListItemSubtitle>No comments yet.</VListItemSubtitle></VListItem>
              </VList>
              <VCardText v-if="r.can.comment">
                <VTextarea v-model="comment.body" label="Add a comment" rows="2" auto-grow :error-messages="comment.errors.body" />
                <VBtn size="small" color="primary" :disabled="!comment.body.trim()" :loading="comment.processing" @click="addComment">Post</VBtn>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" md="5">
            <VCard border>
              <VCardItem><VCardTitle class="text-body-1">Documents</VCardTitle><VCardSubtitle>Filed in Document Management</VCardSubtitle></VCardItem>
              <VList density="compact">
                <VListItem v-for="a in r.attachments" :key="a.id" :href="a.url" target="_blank" prepend-icon="tabler-file">
                  <VListItemTitle class="text-body-2">{{ a.title }}</VListItemTitle><VListItemSubtitle>{{ a.added }}</VListItemSubtitle>
                </VListItem>
                <VListItem v-if="!r.attachments.length"><VListItemSubtitle>No documents.</VListItemSubtitle></VListItem>
              </VList>
              <VCardText v-if="r.can.attach">
                <VTextField v-model="file.title" label="Title" density="compact" :error-messages="file.errors.title" />
                <VFileInput v-model="file.file" label="File" density="compact" prepend-icon="tabler-paperclip" :error-messages="file.errors.file" />
                <VBtn size="small" color="primary" :disabled="!file.file || !file.title" :loading="file.processing" @click="attach">Upload</VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="cancelDialog" max-width="480">
      <VCard title="Cancel this request">
        <VCardText>
          <p class="text-body-2 mb-3">Every open step stops, and the people waiting on it are told. This cannot be undone.</p>
          <VTextarea v-model="cancel.reason" label="Why it is being cancelled" rows="2" auto-grow :error-messages="cancel.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="cancelDialog = false">Keep it</VBtn>
          <VBtn color="error" :loading="cancel.processing" :disabled="cancel.reason.trim().length < 5" @click="doCancel">Cancel request</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
