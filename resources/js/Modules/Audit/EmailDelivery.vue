<script setup lang="ts">
/**
 * What this system tried to email, and what came of it.
 *
 * The question that produced this page was "did that invitation go out?". Until now the only
 * evidence was the recipient saying it never arrived — by which point nobody could tell whether it
 * had been sent and lost downstream, refused by the mail server, or never attempted at all. Three
 * different problems, three different fixes, and no way to tell them apart.
 */
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Row = {
  id: number; to: string; subject: string | null; mailable: string | null
  status: string; error: string | null; mailer: string | null; at: string | null
}

const props = defineProps<{
  rows: { data: Row[]; links: any[]; total: number }
  counts: { sent: number; failed: number; pending: number }
  kinds: { value: string; label: string }[]
  filters: Record<string, any>
  statuses: string[]
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? null)
const mailable = ref(props.filters.mailable ?? null)
const days = ref(props.filters.days ?? 30)

function apply() {
  router.get(route('settings.email-delivery'), {
    search: search.value || undefined,
    status: status.value || undefined,
    mailable: mailable.value || undefined,
    days: days.value,
  }, { preserveState: true, replace: true })
}

// Typing filters as you go, without a request per keystroke.
let timer: any = null
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(apply, 350)
})

const tone: Record<string, string> = { sent: 'success', failed: 'error', pending: 'warning' }

/** The row that is open, because a transport error is a paragraph and not a cell. */
const showing = ref<Row | null>(null)
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4 mb-1">Email delivery</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Every message this system attempted, and what the mail server said about it.
    </p>

    <!--
      Three numbers, because they are three different problems. "Not confirmed" is the one worth
      explaining: the attempt was recorded and never came back, which is what a mail server refusing
      connections looks like from in here.
    -->
    <VRow class="mb-2">
      <VCol cols="12" sm="4">
        <VCard border><VCardText>
          <div class="text-h5 font-weight-bold text-success">{{ counts.sent }}</div>
          <div class="text-caption text-medium-emphasis">Accepted by the mail server</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard border><VCardText>
          <div class="text-h5 font-weight-bold text-error">{{ counts.failed }}</div>
          <div class="text-caption text-medium-emphasis">Failed, with a reason</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard border><VCardText>
          <div class="text-h5 font-weight-bold text-warning">{{ counts.pending }}</div>
          <div class="text-caption text-medium-emphasis">Attempted, never confirmed</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap ga-3">
        <VTextField
          v-model="search" placeholder="Search address or subject"
          prepend-inner-icon="tabler-search" density="compact" variant="outlined"
          hide-details clearable style="min-width:260px"
        />
        <VSelect
          v-model="status" :items="statuses.map(s => ({ title: s, value: s }))"
          label="Status" density="compact" variant="outlined" hide-details clearable
          style="max-width:180px" @update:model-value="apply"
        />
        <VSelect
          v-model="mailable" :items="kinds.map(k => ({ title: k.label, value: k.value }))"
          label="Kind" density="compact" variant="outlined" hide-details clearable
          style="max-width:240px" @update:model-value="apply"
        />
        <VSelect
          v-model="days"
          :items="[{ title: 'Last 7 days', value: 7 }, { title: 'Last 30 days', value: 30 }, { title: 'Last 90 days', value: 90 }]"
          label="Window" density="compact" variant="outlined" hide-details
          style="max-width:180px" @update:model-value="apply"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>When</th><th>To</th><th>Subject</th><th>Kind</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id">
            <td class="text-caption text-no-wrap">{{ r.at }}</td>
            <td>{{ r.to }}</td>
            <td class="text-truncate" style="max-width:280px" :title="r.subject ?? ''">{{ r.subject ?? '—' }}</td>
            <td><VChip v-if="r.mailable" size="x-small" label>{{ r.mailable }}</VChip><span v-else>—</span></td>
            <td>
              <VChip size="x-small" :color="tone[r.status] ?? 'default'" variant="tonal">{{ r.status }}</VChip>
            </td>
            <td class="text-right">
              <!-- The reason lives behind a click: a transport error is a paragraph, and a table
                   that tries to show one becomes unreadable for the rows that succeeded. -->
              <VBtn
                v-if="r.error" size="x-small" variant="text" icon="tabler-alert-circle" color="error"
                title="Why it failed" @click="showing = r"
              />
            </td>
          </tr>
          <tr v-if="!rows.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              No email has been sent in this window.
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="showing !== null" max-width="640" @update:model-value="showing = null">
      <VCard v-if="showing">
        <VCardItem>
          <VCardTitle>Why it failed</VCardTitle>
          <VCardSubtitle>{{ showing.to }} · {{ showing.at }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <pre class="text-caption" style="white-space:pre-wrap; margin:0">{{ showing.error }}</pre>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showing = null">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
