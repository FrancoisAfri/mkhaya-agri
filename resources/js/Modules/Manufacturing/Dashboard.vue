<script setup lang="ts">
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Readiness = {
  factories: number; work_centres: number; work_centres_rated: number; units: number
  products: number; manufactured_products: number; boms_draft: number; boms_approved: number
}

const props = defineProps<{
  readiness: Readiness
  products_without_bom: Array<{ id: string; code: string | null; name: string | null; kind: string }>
  open_shutdowns: Array<{
    id: string; reason: string; kind: string; factory: string | null; line: string | null
    whole_factory: boolean; starts_at: string | null; ends_at: string | null
  }>
  can: { configure: boolean; view_boms: boolean }
}>()

/*
 * Readiness, not throughput. Until orders exist there is nothing to chart, and what a plant
 * actually needs to know is what stands between it and its first production order. Each step says
 * whether it is done, not merely how many rows exist -- a factory with unrated work centres has
 * work centres, and cannot be planned against.
 */
const steps = computed(() => {
  const r = props.readiness
  return [
    {
      key: 'factories', done: r.factories > 0, icon: 'tabler-building-factory-2',
      count: r.factories, to: route('manufacturing.setup.factories'), setup: true,
    },
    {
      key: 'work_centres', done: r.work_centres > 0 && r.work_centres_rated === r.work_centres,
      partial: r.work_centres > 0 && r.work_centres_rated < r.work_centres, icon: 'tabler-tools',
      count: r.work_centres, extra: `${r.work_centres_rated}/${r.work_centres}`,
      to: route('manufacturing.setup.work-centres'), setup: true,
    },
    {
      key: 'units', done: r.units > 0, icon: 'tabler-ruler-measure',
      count: r.units, to: route('manufacturing.setup.units'), setup: true,
    },
    {
      key: 'products', done: r.manufactured_products > 0, icon: 'tabler-package',
      count: r.manufactured_products, to: route('manufacturing.engineering.products.index'), setup: false,
    },
    {
      key: 'boms', done: r.boms_approved > 0, icon: 'tabler-sitemap',
      count: r.boms_approved, to: route('manufacturing.engineering.boms.index'), setup: false,
    },
  ].filter(s => !s.setup || props.can.configure)
})

const allDone = computed(() => steps.value.every(s => s.done))

function when(iso: string | null) {
  return iso ? new Date(iso).toLocaleString() : '—'
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.dashboard.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.dashboard.subtitle') }}</p>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.dashboard.readiness_title') }}</VCardTitle>
            <VCardSubtitle>
              {{ allDone ? t('manufacturing.dashboard.readiness_done') : t('manufacturing.dashboard.readiness_todo') }}
            </VCardSubtitle>
          </VCardItem>
          <VList lines="two">
            <VListItem v-for="s in steps" :key="s.key" :to="s.to" :prepend-icon="s.icon">
              <VListItemTitle>{{ t(`manufacturing.dashboard.step_${s.key}`) }}</VListItemTitle>
              <VListItemSubtitle>
                {{ t(`manufacturing.dashboard.step_${s.key}_hint`) }}
                <template v-if="s.extra"> · {{ t('manufacturing.dashboard.rated', { n: s.extra }) }}</template>
              </VListItemSubtitle>
              <template #append>
                <VChip size="small" label :color="s.done ? 'success' : s.partial ? 'warning' : 'default'" variant="tonal">
                  {{ s.done ? t('manufacturing.dashboard.done') : s.partial ? t('manufacturing.dashboard.partial') : t('manufacturing.dashboard.todo') }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.dashboard.bom_status') }}</VCardTitle>
          </VCardItem>
          <VCardText class="d-flex gap-6">
            <div>
              <div class="text-h4">{{ readiness.boms_approved }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('manufacturing.status.APPROVED') }}</div>
            </div>
            <div>
              <div class="text-h4">{{ readiness.boms_draft }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('manufacturing.status.DRAFT') }}</div>
            </div>
            <div>
              <div class="text-h4">{{ readiness.products }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('manufacturing.dashboard.products') }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.dashboard.shutdowns_title') }}</VCardTitle>
          </VCardItem>
          <VCardText v-if="!open_shutdowns.length" class="text-medium-emphasis">
            {{ t('manufacturing.dashboard.no_shutdowns') }}
          </VCardText>
          <VList v-else density="compact">
            <VListItem v-for="s in open_shutdowns" :key="s.id">
              <VListItemTitle>{{ s.reason }}</VListItemTitle>
              <VListItemSubtitle>
                {{ s.factory }} ·
                {{ s.whole_factory ? t('manufacturing.dashboard.whole_factory') : s.line }}
                · {{ when(s.starts_at) }} → {{ when(s.ends_at) }}
              </VListItemSubtitle>
              <template #append>
                <VChip size="x-small" label variant="tonal">{{ t(`manufacturing.shutdown_kind.${s.kind}`) }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- The products an order cannot be raised for yet. The most useful single thing this page
         can say during configuration. -->
    <VCard v-if="products_without_bom.length" border class="mt-4">
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.dashboard.no_bom_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.dashboard.no_bom_hint') }}</VCardSubtitle>
      </VCardItem>
      <VList density="compact">
        <VListItem v-for="p in products_without_bom" :key="p.id"
                   :to="route('manufacturing.engineering.products.show', p.id)">
          <VListItemTitle>{{ p.code }} — {{ p.name }}</VListItemTitle>
          <template #append>
            <VChip size="x-small" label variant="tonal">{{ t(`manufacturing.kind.${p.kind}`) }}</VChip>
          </template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
