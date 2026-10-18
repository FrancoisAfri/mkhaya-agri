<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * The "no active company" notice, for any module whose records belong to a tenant.
 *
 * It exists as one shared component rather than a copy per module because the copies drifted:
 * a fix landed in one module's notice and not the other's, and the wording that was corrected in
 * one kept misleading users in the other.
 *
 * What it fixes: the old text told a stuck administrator to use «View as company» from the user
 * menu. That control is not in the user menu — it is a button on Platform → Companies — so the
 * one instruction given to somebody already stuck pointed at a place that does not exist. This
 * offers the switch inline instead of describing it, which also covers the case the prose could
 * never handle: an installation with several companies, where nothing can be auto-resolved and the
 * person genuinely has to choose.
 *
 * `switchableCompanies` is null for anyone who cannot switch (an ordinary user with no company),
 * so they get the explanation and no controls they are unable to use.
 */
const props = defineProps<{
  /** Whether there is no company context — the caller already knows. */
  show: boolean
  /** Module-specific sentence explaining what belongs to a company here. */
  body: string
  /** Heading. Defaults to the shared "No active company". */
  title?: string
}>()

const { t } = useI18n()
const page = usePage()

const companies = computed<Array<{ id: number; name: string }> | null>(
  () => (page.props as any)?.switchableCompanies ?? null,
)

/**
 * "View as company" is a multi-tenant control, so it stays out of a single-tenant install.
 *
 * On self-hosted there is one customer and the install resolves its own company; being asked to
 * impersonate a tenant reads as nonsense on a box that has none. Where it genuinely cannot
 * resolve — several active companies — the body text says what to change, which is a decision
 * about the installation rather than a switch to make on this screen.
 */
const selfHosted = computed(() => (page.props as any)?.edition === 'self_hosted')

const canSwitch = computed(() => ! selfHosted.value && (companies.value?.length ?? 0) > 0)

const selected = ref<number | null>(null)
const switching = ref(false)

const heading = computed(() => props.title ?? t('tenancy.company_switch.title'))

function enter() {
  if (selected.value === null || switching.value) return

  switching.value = true

  // A full visit, not an XHR follow: switching tenant changes navigation, entitlements and every
  // scoped query, so the whole page has to be rebuilt.
  router.post(`/platform/companies/${selected.value}/impersonate`, {}, {
    onFinish: () => { switching.value = false },
  })
}
</script>

<template>
  <VAlert
    v-if="show"
    type="info"
    variant="tonal"
    class="mb-4"
    icon="mdi-office-building-outline"
  >
    <div class="text-body-2 font-weight-medium mb-1">
      {{ heading }}
    </div>

    <div class="text-body-2">
      {{ body }}
    </div>

    <!--
      The switcher only appears for someone who can actually act on it. Everyone else has already
      been told why the screen is empty, and offering a disabled control would only suggest they
      are missing a permission.
    -->
    <div
      v-if="canSwitch"
      class="d-flex flex-wrap align-center ga-3 mt-4"
    >
      <VSelect
        v-model="selected"
        :items="companies ?? []"
        item-title="name"
        item-value="id"
        :label="t('tenancy.company_switch.label')"
        density="comfortable"
        variant="outlined"
        hide-details
        class="company-switch__select"
      />

      <VBtn
        color="primary"
        :disabled="selected === null"
        :loading="switching"
        class="company-switch__go"
        @click="enter"
      >
        {{ t('tenancy.company_switch.action') }}
      </VBtn>
    </div>

    <div
      v-else-if="companies !== null"
      class="text-body-2 mt-3 font-italic"
    >
      {{ t('tenancy.company_switch.none') }}
    </div>
  </VAlert>
</template>

<style scoped>
/* Wide enough that long company names are readable, but still collapses on a phone. */
.company-switch__select {
  min-inline-size: 16rem;
  max-inline-size: 24rem;
}

/* Vuetify's default height already clears the 44px touch target; this keeps it there when a
   theme tightens button density. */
.company-switch__go {
  min-block-size: 44px;
}
</style>
