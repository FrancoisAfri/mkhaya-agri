<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Rule = { id: string | null; category: string | null; reportable: boolean; obligations: string[] }
type Obligation = { id: string; label: string; due_in: string | null; responsible_role: string | null; evidence_required: boolean }
type Metric = { id: string; label: string; numerator: string; denominator: string; multiplier: number }
type Pack = {
  version_id: number; pack_id: string; name: string; jurisdiction: string; version: string
  effective_from: string; effective_to: string | null; signed: boolean; checksum: string
  rules: Rule[]; obligations: Obligation[]; metrics: Metric[]
}

/**
 * REG-FIX-4 / REG-FIX-5, as the screen sees it.
 *
 * `blocking` gaps cannot be acknowledged — a checksum mismatch is not a judgement call.
 * `acknowledgeable` ones can, and doing so records who accepted that an unsigned or unreviewed
 * rulebook is running as the live authority.
 */
type Assurance = {
  version_id: number
  gaps: { code: string; label: string; detail: string; blocking: boolean }[]
  blocking: string[]
  acknowledgeable: string[]
  clean: boolean
  may_activate_accepted: boolean
  requires_acknowledgement: boolean
  legal_review: { reviewed_at: string; reviewer_name: string; reviewer_capacity: string
    reference: string | null; valid_until: string | null } | null
  release_metadata: Record<string, string> | null
}

type Site = { id: number; name: string; site_type: string | null }
type Activation = {
  pack_version_id: number
  site_id: number | null
  site_name: string | null
  status: string
  activated_at: string | null
}

const props = defineProps<{
  packs: Pack[]
  assurance: Record<number, Assurance>
  activeVersionIds: number[]
  canManage: boolean
  noCompany?: boolean
  /** Every live activation, company-wide and per site. */
  activations?: Activation[]
  /** The sites this rulebook may be put on. */
  sites?: Site[]
}>()

const allSites = computed<Site[]>(() => props.sites ?? [])
const allActivations = computed<Activation[]>(() => props.activations ?? [])

/** Where this pack version is in force: company-wide, or the named sites. */
function scopesFor(versionId: number) {
  const rows = allActivations.value.filter(a => a.pack_version_id === versionId)
  return {
    companyWide: rows.some(a => a.site_id === null),
    sites: rows.filter(a => a.site_id !== null),
  }
}

/**
 * The pack a site is governed by right now, if any.
 *
 * A site-specific activation beats the company default, which is the same precedence the engine
 * applies when it classifies an incident.
 */
function governingPack(siteId: number): Pack | null {
  const specific = allActivations.value.find(a => a.site_id === siteId)
  if (specific) return props.packs.find(p => p.version_id === specific.pack_version_id) ?? null
  const wide = allActivations.value.find(a => a.site_id === null)
  return wide ? props.packs.find(p => p.version_id === wide.pack_version_id) ?? null : null
}

/** Whether a pack claims to cover this site's type. Empty means the pack does not say. */
function coversSite(pack: Pack, site: Site): boolean {
  const types = pack.applies_to_site_types ?? []
  if (!types.length || !site.site_type) return true
  return types.includes(site.site_type)
}

const expanded = ref<string[]>([])

/**
 * Until a pack is activated, incidents cannot be classified and reporting is refused — an incident
 * judged by no rulebook has no statutory deadlines. So activation is a first-class action here, not
 * a setting buried elsewhere.
 */
const showActivate = ref(false)
const activating = ref<Pack | null>(null)
const scope = ref<'company' | 'sites'>('company')
const chosenSites = ref<number[]>([])
const asPreview = ref(false)
const busy = ref(false)

function openActivate(pack: Pack) {
  activating.value = pack
  scope.value = 'company'
  chosenSites.value = []
  asPreview.value = false
  showActivate.value = true
}

/**
 * Activation is per scope: company-wide, or one call per chosen site.
 *
 * The engine has always accepted a site; the screen never sent one, so every activation replaced
 * the company-wide one and the two packs looked mutually exclusive. A group with a mine and a
 * factory can now be covered correctly for both.
 */
const acknowledgement = ref('')

/** The assurance state of the pack being activated, if the server sent one. */
const activatingAssurance = computed<Assurance | null>(() =>
  activating.value ? (props.assurance?.[activating.value.version_id] ?? null) : null)

function confirmActivate() {
  const pack = activating.value
  if (!pack || busy.value) return

  const status = asPreview.value ? 'PREVIEW' : 'ACCEPTED'
  const targets: (number | null)[] = scope.value === 'company' ? [null] : [...chosenSites.value]
  if (!targets.length) return

  busy.value = true
  let remaining = targets.length

  targets.forEach(siteId => {
    router.post('/sheq/regulatory-packs/activate',
      {
        pack_version_id: pack.version_id, status,
        ...(siteId === null ? {} : { site_id: siteId }),
        // Only sent for an ACCEPTED activation: a PREVIEW is a sandbox and is not gated, because
        // the point of previewing an unreviewed pack is to find out what it would do.
        ...(status === 'ACCEPTED' && acknowledgement.value.trim() !== ''
          ? { assurance_acknowledgement: acknowledgement.value.trim() } : {}),
      },
      {
        preserveScroll: true,
        onFinish: () => {
          remaining -= 1
          if (remaining === 0) { busy.value = false; showActivate.value = false }
        },
      })
  })
}

/** Sites the pack does not claim to cover, among those chosen — a warning, never a block. */
const mismatchedSites = computed(() => {
  const pack = activating.value
  if (!pack || scope.value !== 'sites') return []
  return allSites.value.filter(s => chosenSites.value.includes(s.id) && !coversSite(pack, s))
})

/** ISO-8601 durations are precise but unreadable; "P7D" means nothing to a safety officer. */
function humanDuration(iso: string | null): string {
  if (!iso) return '—'
  const match = /^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/.exec(iso)
  if (!match) return iso
  const [, d, h, m] = match
  const parts: string[] = []
  if (d) parts.push(t('sheq.duration.days', { count: Number(d) }))
  if (h) parts.push(t('sheq.duration.hours', { count: Number(h) }))
  if (m) parts.push(t('sheq.duration.minutes', { count: Number(m) }))
  return parts.join(' ') || iso
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.packs.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.packs.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VCard v-if="!packs.length" elevation="0" border rounded="lg">
      <VCardText class="text-center py-10 text-medium-emphasis">
        {{ t('sheq.packs.empty') }}
      </VCardText>
    </VCard>

    <!--
      Which rulebook governs which site. The question an inspector asks first, and the one the
      screen could not answer while every activation was company-wide.
    -->
    <VCard v-if="allSites.length" elevation="0" border rounded="lg" class="mb-4">
      <VCardText>
        <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.packs.site_coverage') }}</div>
        <VTable density="compact">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.packs.site') }}</th>
              <th scope="col">{{ t('sheq.packs.site_type') }}</th>
              <th scope="col">{{ t('sheq.packs.governed_by') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="site in allSites" :key="site.id">
              <td>{{ site.name }}</td>
              <td class="text-medium-emphasis">{{ site.site_type ?? '—' }}</td>
              <td>
                <VChip v-if="governingPack(site.id)" size="x-small" variant="tonal" color="success">
                  {{ governingPack(site.id)!.name }}
                </VChip>
                <VChip v-else size="x-small" variant="tonal" color="error">
                  {{ t('sheq.packs.not_governed') }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <VExpansionPanels v-model="expanded" multiple variant="accordion">
      <VExpansionPanel v-for="pack in packs" :key="pack.pack_id + pack.version" :value="pack.pack_id + pack.version">
        <VExpansionPanelTitle>
          <div class="d-flex align-center flex-wrap gap-2 w-100">
            <VIcon size="20" class="me-1">mdi-book-open-page-variant</VIcon>
            <span class="font-weight-semibold">{{ pack.name }}</span>
            <VChip size="x-small" variant="tonal" color="primary">{{ pack.version }}</VChip>
            <VChip size="x-small" variant="tonal">{{ pack.jurisdiction }}</VChip>
            <VChip size="x-small" variant="tonal" :color="pack.signed ? 'success' : 'warning'">
              {{ pack.signed ? t('sheq.packs.signed_yes') : t('sheq.packs.signed_no') }}
            </VChip>
            <!-- Where this rulebook is in force, not merely that it is on somewhere. -->
            <VChip v-if="scopesFor(pack.version_id).companyWide" size="x-small"
                   variant="flat" color="success" prepend-icon="mdi-check-circle">
              {{ t('sheq.packs.active_company_wide') }}
            </VChip>
            <VChip v-for="a in scopesFor(pack.version_id).sites" :key="a.site_id ?? 0"
                   size="x-small" variant="flat" color="success" prepend-icon="mdi-map-marker-check">
              {{ a.site_name }}
            </VChip>
            <VSpacer />
            <VBtn v-if="canManage"
                  size="x-small" variant="tonal" color="primary" class="me-2"
                  @click.stop="openActivate(pack)">
              {{ scopesFor(pack.version_id).companyWide || scopesFor(pack.version_id).sites.length
                 ? t('sheq.packs.change_scope') : t('sheq.packs.activate') }}
            </VBtn>
          </div>
        </VExpansionPanelTitle>

        <VExpansionPanelText>
          <div class="text-caption text-medium-emphasis mb-4">
            {{ t('sheq.packs.effective_from') }} {{ pack.effective_from }}
            · {{ t('sheq.packs.checksum') }} <code>{{ pack.checksum }}…</code>
          </div>

          <!-- Classification rules, in evaluation order. Order is semantic: first match wins. -->
          <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.packs.classification_rules') }}</div>
          <VTable density="compact" class="mb-6">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">{{ t('sheq.packs.rule') }}</th>
                <th scope="col">{{ t('sheq.packs.category') }}</th>
                <th scope="col">{{ t('sheq.packs.reportable') }}</th>
                <th scope="col">{{ t('sheq.packs.triggers') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(rule, i) in pack.rules" :key="rule.id ?? i">
                <td class="text-medium-emphasis">{{ i + 1 }}</td>
                <td><code>{{ rule.id }}</code></td>
                <td class="font-weight-medium">{{ rule.category }}</td>
                <td>
                  <VChip :color="rule.reportable ? 'error' : 'grey'" size="x-small" variant="tonal">
                    {{ rule.reportable ? t('sheq.packs.reportable_yes') : t('sheq.packs.reportable_no') }}
                  </VChip>
                </td>
                <td>
                  <span v-if="!rule.obligations.length" class="text-medium-emphasis">—</span>
                  <VChip v-for="o in rule.obligations" :key="o" size="x-small" variant="tonal" class="me-1 mb-1">{{ o }}</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>

          <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.packs.obligations') }}</div>
          <VTable density="compact" class="mb-6">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.packs.obligation') }}</th>
                <th scope="col">{{ t('sheq.packs.deadline') }}</th>
                <th scope="col">{{ t('sheq.packs.responsible') }}</th>
                <th scope="col">{{ t('sheq.packs.evidence') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in pack.obligations" :key="o.id">
                <td>
                  <div class="font-weight-medium">{{ o.label }}</div>
                  <code class="text-caption text-medium-emphasis">{{ o.id }}</code>
                </td>
                <td>{{ humanDuration(o.due_in) }}</td>
                <td>{{ o.responsible_role ?? '—' }}</td>
                <td>
                  <VIcon v-if="o.evidence_required" size="18" color="success">mdi-check-circle-outline</VIcon>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
              </tr>
            </tbody>
          </VTable>

          <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.packs.metrics') }}</div>
          <VTable density="compact">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.packs.metric') }}</th>
                <th scope="col">{{ t('sheq.packs.formula') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in pack.metrics" :key="m.id">
                <td>
                  <span class="font-weight-medium">{{ m.id }}</span>
                  <span class="text-caption text-medium-emphasis ms-2">{{ m.label }}</span>
                </td>
                <td><code>({{ m.numerator }} × {{ m.multiplier.toLocaleString() }}) ÷ {{ m.denominator }}</code></td>
              </tr>
            </tbody>
          </VTable>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- Choose what this rulebook governs: the whole company, or named sites. -->
    <VDialog v-model="showActivate" max-width="720">
      <VCard v-if="activating">
        <VCardTitle>{{ t('sheq.packs.activate_title', { pack: activating.name }) }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRadioGroup v-model="scope" class="mb-2" hide-details>
            <VRadio value="company" :label="t('sheq.packs.scope_company')" />
            <VRadio value="sites" :label="t('sheq.packs.scope_sites')" :disabled="!allSites.length" />
          </VRadioGroup>

          <p v-if="!allSites.length" class="text-caption text-medium-emphasis mb-3">
            {{ t('sheq.packs.no_sites_hint') }}
          </p>

          <VAutocomplete
            v-if="scope === 'sites'"
            v-model="chosenSites"
            :items="allSites"
            item-title="name"
            item-value="id"
            :label="t('sheq.packs.choose_sites')"
            multiple chips closable-chips
            variant="outlined"
            density="comfortable"
            class="mb-2"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.site_type ?? '—'" />
            </template>
          </VAutocomplete>

          <!-- A warning, never a block: the pack may still be the right call. -->
          <VAlert v-if="mismatchedSites.length" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('sheq.packs.site_type_mismatch', {
              sites: mismatchedSites.map(s => s.name).join(', '),
              types: (activating.applies_to_site_types ?? []).join(', '),
            }) }}
          </VAlert>

          <VCheckbox v-model="asPreview" :label="t('sheq.packs.as_preview')" hide-details density="compact" />
          <p class="text-caption text-medium-emphasis mt-1 mb-0">
            {{ t('sheq.packs.preview_hint') }}
          </p>

          <VAlert type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('sheq.packs.supersede_hint') }}
          </VAlert>

          <!--
            REG-FIX-4 / REG-FIX-5. Deliberately in English and not translated: this text is a
            statement about the product's own statutory assurance, and a mistranslation of it
            would be a mistranslated legal disclaimer.
          -->
          <template v-if="activatingAssurance && !activatingAssurance.clean">
            <VAlert
              v-if="activatingAssurance.blocking.length"
              type="error"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              <div class="font-weight-bold mb-1">This pack cannot be activated</div>
              <div v-for="gap in activatingAssurance.gaps.filter(g => g.blocking)" :key="gap.code">
                <strong>{{ gap.label }}.</strong> {{ gap.detail }}
              </div>
            </VAlert>

            <template v-else-if="!asPreview">
              <VAlert type="warning" variant="tonal" density="compact" class="mt-3">
                <div class="font-weight-bold mb-1">
                  This rulebook is not fully assured
                </div>
                <div
                  v-for="gap in activatingAssurance.gaps.filter(g => !g.blocking)"
                  :key="gap.code"
                  class="mb-1"
                >
                  <strong>{{ gap.label }}.</strong> {{ gap.detail }}
                </div>
              </VAlert>

              <VTextarea
                v-model="acknowledgement"
                class="mt-3"
                variant="outlined"
                rows="2"
                auto-grow
                label="Why it is acceptable to run this as the live authority"
                placeholder="We authored this pack against our own legal advice. Accepted by the SHEQ manager."
                hint="Kept against the activation with the gaps as they stand today, so the record still reads correctly if the pack is reviewed later."
                persistent-hint
              />
            </template>
          </template>

          <VAlert
            v-else-if="activatingAssurance && activatingAssurance.legal_review"
            type="success"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            Legally reviewed by {{ activatingAssurance.legal_review.reviewer_name }}
            ({{ activatingAssurance.legal_review.reviewer_capacity }})<template
              v-if="activatingAssurance.legal_review.valid_until"
            >, certified to {{ activatingAssurance.legal_review.valid_until }}</template>.
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showActivate = false">{{ t('sheq.packs.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="busy"
            :disabled="(scope === 'sites' && !chosenSites.length)
              || (!asPreview && !!activatingAssurance && !activatingAssurance.may_activate_accepted)"
            @click="confirmActivate"
          >
            {{ t('sheq.packs.activate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
