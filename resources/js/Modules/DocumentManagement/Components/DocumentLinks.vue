<script setup lang="ts">
/**
 * What a document is about.
 *
 * Shown grouped by relation — "Supplier: Acme Trading" — rather than as a flat list of things,
 * because the relation is what someone is scanning for when they open a contract and want the
 * project it belongs to.
 *
 * The labels come from the server and were captured when the link was made, so a row still reads
 * properly after the record it points at has been deleted. That is deliberate: a document usually
 * outlives the thing it was about.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Link {
  uuid: string
  relation: string
  relation_label: string
  label: string | null
  linkable_id: number
}

const props = defineProps<{
  /** Keyed by relation, which is how the server groups them. */
  links: Record<string, Link[]>
  relations: { value: string; label: string }[]
  documentUuid: string
  canEdit: boolean
}>()

const groups = computed(() =>
  Object.entries(props.links)
    .map(([relation, entries]) => ({
      relation,
      label: entries[0]?.relation_label ?? relation,
      entries,
    }))
    .sort((a, b) => a.label.localeCompare(b.label)),
)

const total = computed(() => Object.values(props.links).reduce((n, list) => n + list.length, 0))

// ── Adding ──────────────────────────────────────────────────────────────────

interface Candidate { id: number; label: string; subtitle: string | null }

const adding = ref(false)
const relation = ref<string | null>(null)
const chosen = ref<Candidate | null>(null)
const busy = ref(false)

const term = ref('')
const results = ref<Candidate[]>([])
const searching = ref(false)

/**
 * Searching the chosen module for a record.
 *
 * Debounced, and every response is checked against the request that is still current — a slow
 * answer to an abandoned query arriving after a fast one would otherwise replace the results the
 * person is looking at with results for something they stopped typing.
 */
let sequence = 0
let timer: ReturnType<typeof setTimeout> | undefined

function search() {
  if (!relation.value) return

  const mine = ++sequence
  searching.value = true

  window.clearTimeout(timer)
  timer = setTimeout(async () => {
    try {
      const url = route('document-management.relations.search')
      const response = await fetch(`${url}?relation=${encodeURIComponent(relation.value!)}&q=${encodeURIComponent(term.value)}`, {
        headers: { Accept: 'application/json' },
      })

      if (!response.ok) throw new Error(String(response.status))
      const body = await response.json()

      // Stale answer to a query nobody is waiting for any more.
      if (mine !== sequence) return
      results.value = body.results ?? []
    } catch {
      if (mine === sequence) results.value = []
    } finally {
      if (mine === sequence) searching.value = false
    }
  }, 220)
}

/** Changing the kind of record invalidates whatever was on screen for the previous one. */
function relationChanged() {
  chosen.value = null
  results.value = []
  term.value = ''
  search()
}

function add() {
  if (!relation.value || !chosen.value) return

  busy.value = true
  router.put(route('document-management.documents.update', props.documentUuid), {
    links: [
      ...Object.values(props.links).flat().map(l => ({ relation: l.relation, id: l.linkable_id })),
      { relation: relation.value, id: chosen.value.id },
    ],
  }, {
    preserveScroll: true,
    onFinish: () => {
      busy.value = false
      adding.value = false
      relation.value = null
      chosen.value = null
      term.value = ''
      results.value = []
    },
  })
}

/** Removing a link never removes the document — it exists in its own right. */
function remove(target: Link) {
  router.put(route('document-management.documents.update', props.documentUuid), {
    links: Object.values(props.links).flat()
      .filter(l => l.uuid !== target.uuid)
      .map(l => ({ relation: l.relation, id: l.linkable_id })),
  }, { preserveScroll: true })
}
</script>

<template>
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-link-variant" />
      <span class="text-body-2 font-weight-medium">{{ d('related_records') }}</span>
      <VChip v-if="total" size="x-small" variant="tonal">{{ total }}</VChip>
      <VSpacer />
      <VBtn
        v-if="canEdit" size="x-small" variant="tonal" icon="mdi-plus"
        :aria-label="d('link_add')" @click="adding = true"
      />
    </VCardText>

    <VDivider />

    <VCardText v-if="!total" class="text-body-2 text-medium-emphasis">
      {{ d('related_none') }}
    </VCardText>

    <VList v-else density="compact">
      <template v-for="group in groups" :key="group.relation">
        <VListSubheader class="text-caption">{{ group.label }}</VListSubheader>
        <VListItem v-for="link in group.entries" :key="link.uuid">
          <VListItemTitle class="text-body-2">
            {{ link.label ?? d('link_unnamed', { id: link.linkable_id }) }}
          </VListItemTitle>
          <template v-if="canEdit" #append>
            <VBtn
              size="x-small" variant="text" icon="mdi-close"
              :aria-label="d('link_remove')" @click="remove(link)"
            />
          </template>
        </VListItem>
      </template>
    </VList>
  </VCard>

  <VDialog v-model="adding" max-width="460">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('link_add') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VSelect
          v-model="relation" :items="relations" item-title="label" item-value="value"
          :label="d('link_relation')" density="comfortable" autofocus
          @update:model-value="relationChanged"
        />

        <!-- Searchable rather than an id field: nobody knows a supplier by its primary key, and
             the reference number in the subtitle is what separates two companies with the
             same name. -->
        <VAutocomplete
          v-model="chosen" :items="results" :loading="searching"
          :disabled="!relation" :label="d('link_search')"
          :no-data-text="term ? d('link_no_results') : d('link_start_typing')"
          item-title="label" return-object density="comfortable"
          :menu-props="{ maxHeight: 320 }"
          @update:search="(value: string) => { term = value; search() }"
        >
          <template #item="{ props: item, item: entry }">
            <VListItem v-bind="item" :title="entry.raw.label" :subtitle="entry.raw.subtitle ?? undefined" />
          </template>
        </VAutocomplete>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="adding = false">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="busy" :disabled="!relation || !chosen" @click="add">
          {{ d('link_add') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
