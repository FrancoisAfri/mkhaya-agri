<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * What Project Management can connect to — and, more usefully, what each connection actually does
 * and where in the module it is used.
 *
 * The page used to list capability keys with "Available" beside them, which answered the wrong
 * question: a reader learned that `finance.budgets` was available and nothing about whether
 * anything here reads a Finance budget. Three states now, not two, and every row says where it is
 * consumed with a link to go and look.
 */
const { t, te } = useI18n()
const route = ziggyRoute

type Row = {
  key: string
  area: string
  /** live = PM consumes it · ready = module present, PM's side not built · off = not available */
  state: 'live' | 'ready' | 'off'
  /** Route names of the PM screens that use it. */
  uses: string[]
  /** The provider interface it upgrades, when it goes through one. */
  seam: string | null
}

const props = defineProps<{
  capabilities: Record<string, boolean>
  catalogue: Row[]
  counts: { live: number; ready: number; total: number }
}>()

/** Group by the owning module so the page reads as "what can PM connect to", not a flat key list. */
const groups = computed(() => {
  const out: Record<string, Row[]> = {}
  for (const row of props.catalogue) (out[row.area] ??= []).push(row)
  return out
})

const stateColor: Record<Row['state'], string> = { live: 'success', ready: 'warning', off: 'grey' }
const stateIcon: Record<Row['state'], string> = {
  live: 'tabler-plug-connected',
  ready: 'tabler-plug',
  off: 'tabler-plug-connected-x',
}

/**
 * A capability key is an identifier — it appears in the API manifest and in support conversations,
 * so it stays as it is. What it *means* is what a reader needs, and that is translated.
 */
function label(key: string) {
  const path = `project_management.capabilities.${key.replace('.', '_')}`

  return te(path) ? t(path) : key
}

function areaLabel(area: string) {
  const path = `project_management.capability_areas.${area}`

  return te(path) ? t(path) : area
}

/** The screen a capability feeds, named the way it appears in the sidebar. */
function surfaceLabel(name: string) {
  const path = `project_management.surfaces.${name.replace('project-management.', '').replace(/\./g, '_')}`

  return te(path) ? t(path) : name
}

/**
 * Per-project routes need a project to link to, and this page does not have one. Those are named
 * rather than linked — a link that 404s is worse than a label.
 */
function isLinkable(name: string) {
  return !name.includes('{') && [
    'project-management.projects.index',
    'project-management.setup.clients',
    'project-management.insights.reports',
  ].includes(name)
}

function open(name: string) {
  router.get(route(name))
}
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">{{ t('project_management.integrations.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('project_management.integrations.subtitle') }}</p>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('project_management.integrations.standalone_notice') }}
    </VAlert>

    <!-- The summary that matters: how many are doing something, not how many are licensed. -->
    <VCard border class="mb-4">
      <VCardText class="d-flex align-center flex-wrap gap-6">
        <div class="d-flex align-center gap-3">
          <VAvatar color="success" variant="tonal" rounded size="42">
            <VIcon icon="tabler-plug-connected" />
          </VAvatar>
          <div>
            <div class="text-h6">{{ counts.live }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ t('project_management.integrations.count_live') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-3">
          <VAvatar color="warning" variant="tonal" rounded size="42">
            <VIcon icon="tabler-plug" />
          </VAvatar>
          <div>
            <div class="text-h6">{{ counts.ready }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ t('project_management.integrations.count_ready') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-3">
          <VAvatar color="grey" variant="tonal" rounded size="42">
            <VIcon icon="tabler-plug-connected-x" />
          </VAvatar>
          <div>
            <div class="text-h6">{{ counts.total - counts.live - counts.ready }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ t('project_management.integrations.count_off') }}
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- States spelled out, because "ready" is the one people would otherwise misread. -->
    <VAlert v-if="counts.ready" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ t('project_management.integrations.ready_explains') }}
    </VAlert>

    <VRow>
      <VCol v-for="(items, area) in groups" :key="area" cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ areaLabel(area) }}</VCardTitle>
          </VCardItem>
          <VDivider />

          <VList density="comfortable">
            <template v-for="(item, i) in items" :key="item.key">
              <VDivider v-if="i" />
              <VListItem>
                <template #prepend>
                  <VIcon :icon="stateIcon[item.state]" :color="stateColor[item.state]" class="me-3" />
                </template>

                <VListItemTitle>{{ label(item.key) }}</VListItemTitle>

                <VListItemSubtitle class="text-caption">
                  <!-- The identifier stays visible: it is what support conversations use. -->
                  <span class="text-disabled">{{ item.key }}</span>
                  <template v-if="item.seam"> · {{ item.seam }}</template>
                </VListItemSubtitle>

                <!-- Where in Project Management this is consumed. -->
                <div v-if="item.uses.length" class="mt-1 d-flex flex-wrap align-center gap-1">
                  <span class="text-caption text-medium-emphasis">
                    {{ t('project_management.integrations.used_on') }}
                  </span>
                  <template v-for="name in item.uses" :key="name">
                    <VChip
                      v-if="isLinkable(name)" size="x-small" label link
                      @click="open(name)"
                    >
                      {{ surfaceLabel(name) }}
                    </VChip>
                    <VChip v-else size="x-small" label variant="text">
                      {{ surfaceLabel(name) }}
                    </VChip>
                  </template>
                </div>

                <template #append>
                  <VChip size="small" :color="stateColor[item.state]" label>
                    {{ t(`project_management.integrations.state_${item.state}`) }}
                  </VChip>
                </template>
              </VListItem>
            </template>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
