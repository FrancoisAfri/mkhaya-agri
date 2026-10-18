<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'

const route = ziggyRoute

type Asset = { uuid: string; name: string; asset_number: string | null; barcode: string | null }
type Line = {
  id: number
  uuid: string
  status: string
  scanned_code: string | null
  scanned_at: string | null
  notes: string | null
  asset: Asset | null
}

const props = defineProps<{
  audit: {
    uuid: string
    name: string
    status: string
    location: { id: number; name: string } | null
    started_by: string | null
    started_at: string | null
    completed_at: string | null
    expected_count: number
    found_count: number
    missing_count: number
    unexpected_count: number
    notes: string | null
  }
  progress: { expected: number; found: number; percent: number }
  lines: { found: Line[]; missing: Line[]; unexpected: Line[] }
}>()

const isOpen = computed(() => props.audit.status === 'open')

const code = ref('')
const scanInput = ref<any>(null)
const scanning = ref(false)
const lastResult = ref<{ result: string; label: string } | null>(null)

// Live counts, updated from scan responses without a full reload.
const counts = ref({
  expected: props.progress.expected,
  found: props.progress.found,
  missing: props.audit.missing_count,
  unexpected: props.audit.unexpected_count,
})

const percent = computed(() =>
  counts.value.expected > 0
    ? Math.round((counts.value.found / counts.value.expected) * 100)
    : 0,
)

const resultColor: Record<string, string> = {
  found: 'success',
  unexpected: 'warning',
}

async function submitScan() {
  const value = code.value.trim()
  if (!value || scanning.value || !isOpen.value) return

  scanning.value = true
  try {
    const { data } = await axios.post(
      route('assets.audits.scan', props.audit.uuid),
      { code: value },
      { headers: { Accept: 'application/json' } },
    )
    counts.value = data.counts
    const label =
      data.result === 'found'
        ? `Found: ${data.line.asset?.name ?? value}`
        : `Unexpected: ${data.line.asset?.name ?? value}`
    lastResult.value = { result: data.result, label }
  } catch (e) {
    lastResult.value = { result: 'error', label: 'Scan failed — try again' }
  } finally {
    scanning.value = false
    code.value = ''
    await nextTick()
    scanInput.value?.focus?.()
  }
}

function refreshLists() {
  router.reload({ only: ['lines', 'progress', 'audit'] })
}

function complete() {
  if (!confirm('Complete this audit? Un-scanned assets will be marked missing.')) return
  router.post(route('assets.audits.complete', props.audit.uuid), {}, {
    preserveScroll: true,
  })
}

function fmtTime(v: string | null) {
  if (!v) return ''
  return new Date(v).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' })
}

function lineLabel(l: Line) {
  if (l.asset) return l.asset.name
  return l.scanned_code ?? 'Unknown code'
}

function lineSub(l: Line) {
  if (l.asset) return l.asset.asset_number ?? l.asset.barcode ?? ''
  return 'No matching asset'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon variant="text" @click="router.visit(route('assets.audits.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div class="flex-grow-1">
        <div class="d-flex align-center gap-2">
          <h4 class="text-h4 font-weight-bold mb-0">{{ audit.name }}</h4>
          <VChip
            size="small"
            variant="tonal"
            :color="audit.status === 'completed' ? 'success' : audit.status === 'cancelled' ? 'error' : 'warning'"
          >
            {{ audit.status }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ audit.location?.name ?? 'All locations' }}
          <span v-if="audit.started_by"> · started by {{ audit.started_by }}</span>
        </p>
      </div>
      <VBtn
        v-if="isOpen"
        color="success"
        prepend-icon="tabler-flag-check"
        @click="complete"
      >
        Complete Audit
      </VBtn>
    </div>

    <!-- Progress -->
    <VCard elevation="0" border class="mb-6">
      <VCardText class="pa-6">
        <div class="d-flex justify-space-between mb-2">
          <span class="text-body-1 font-weight-medium">
            {{ counts.found }} of {{ counts.expected }} assets found
          </span>
          <span class="text-body-1 font-weight-bold">{{ percent }}%</span>
        </div>
        <VProgressLinear :model-value="percent" color="success" height="12" rounded />
        <VRow class="mt-4">
          <VCol cols="4" class="text-center">
            <p class="text-h5 font-weight-bold text-success mb-0">{{ counts.found }}</p>
            <p class="text-caption text-medium-emphasis mb-0">Found</p>
          </VCol>
          <VCol cols="4" class="text-center">
            <p class="text-h5 font-weight-bold text-error mb-0">
              {{ audit.status === 'completed' ? counts.missing : counts.expected - counts.found }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">
              {{ audit.status === 'completed' ? 'Missing' : 'Outstanding' }}
            </p>
          </VCol>
          <VCol cols="4" class="text-center">
            <p class="text-h5 font-weight-bold text-warning mb-0">{{ counts.unexpected }}</p>
            <p class="text-caption text-medium-emphasis mb-0">Unexpected</p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Scanner -->
    <VCard v-if="isOpen" elevation="0" border class="mb-6">
      <VCardText class="pa-6">
        <p class="text-body-1 font-weight-medium mb-3">
          <VIcon icon="tabler-scan" class="me-1" /> Scan asset
        </p>
        <VForm @submit.prevent="submitScan">
          <div class="d-flex gap-3">
            <VTextField
              ref="scanInput"
              v-model="code"
              placeholder="Scan or type barcode / asset number, press Enter"
              autofocus
              hide-details
              :loading="scanning"
              @keyup.enter="submitScan"
            />
            <VBtn type="submit" color="primary" :loading="scanning" size="large">
              Scan
            </VBtn>
            <VBtn variant="tonal" size="large" @click="refreshLists">
              <VIcon icon="tabler-refresh" />
            </VBtn>
          </div>
        </VForm>
        <VAlert
          v-if="lastResult"
          :type="lastResult.result === 'error' ? 'error' : lastResult.result === 'found' ? 'success' : 'warning'"
          variant="tonal"
          density="compact"
          class="mt-3"
        >
          {{ lastResult.label }}
        </VAlert>
      </VCardText>
    </VCard>

    <!-- Three lists -->
    <VRow>
      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="d-flex align-center gap-2 text-success">
            <VIcon icon="tabler-circle-check" /> Found ({{ lines.found.length }})
          </VCardTitle>
          <VDivider />
          <VList lines="two" max-height="420" class="overflow-y-auto">
            <VListItem v-for="l in lines.found" :key="l.uuid">
              <VListItemTitle>{{ lineLabel(l) }}</VListItemTitle>
              <VListItemSubtitle>{{ lineSub(l) }} · {{ fmtTime(l.scanned_at) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="lines.found.length === 0">
              <VListItemSubtitle>Nothing scanned yet.</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="d-flex align-center gap-2 text-error">
            <VIcon icon="tabler-alert-triangle" />
            {{ audit.status === 'completed' ? 'Missing' : 'Outstanding' }} ({{ lines.missing.length }})
          </VCardTitle>
          <VDivider />
          <VList lines="two" max-height="420" class="overflow-y-auto">
            <VListItem v-for="l in lines.missing" :key="l.uuid">
              <VListItemTitle>{{ lineLabel(l) }}</VListItemTitle>
              <VListItemSubtitle>{{ lineSub(l) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="lines.missing.length === 0">
              <VListItemSubtitle>All expected assets accounted for.</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="d-flex align-center gap-2 text-warning">
            <VIcon icon="tabler-help-circle" /> Unexpected ({{ lines.unexpected.length }})
          </VCardTitle>
          <VDivider />
          <VList lines="two" max-height="420" class="overflow-y-auto">
            <VListItem v-for="l in lines.unexpected" :key="l.uuid">
              <VListItemTitle>{{ lineLabel(l) }}</VListItemTitle>
              <VListItemSubtitle>{{ lineSub(l) }} · {{ fmtTime(l.scanned_at) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="lines.unexpected.length === 0">
              <VListItemSubtitle>No surprises scanned.</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
