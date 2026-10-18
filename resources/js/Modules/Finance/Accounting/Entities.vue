<script lang="ts" setup>
/**
 * The businesses this tenant keeps books for.
 *
 * Sage calls them company files; here they are entities, and they sit inside one subscription.
 * Adding one gives it its own customers, suppliers, chart of accounts and letterhead — nothing is
 * inherited, deliberately, because a VAT number or bank account carried across from another
 * business would print on real invoices.
 */
import { ref, computed } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import CompanySwitchNotice from '@/Components/Tenancy/CompanySwitchNotice.vue'

const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

interface Entity {
  id: number; uuid: string; name: string; slug: string
  is_default: boolean; status: string; is_current: boolean
  vat_number: string | null; logo: string | null; configured: boolean
}
interface Allowance { included: number; purchased: number; allowance: number; used: number; remaining: number }
interface Package { code: string; name: string; units: number; unit_label: string; price_monthly: number }

const props = defineProps<{
  entities: Entity[]
  currentId: number | null
  allowance: Allowance
  packages: Package[]
  /** True when nothing on this page can be answered — a platform admin above any one tenant. */
  noCompany?: boolean
  /** Why, phrased for the edition and the kind of account reading it. */
  noCompanyReason?: string | null
}>()

const addOpen = ref(false)
const addForm = useForm({ name: '' })

const renaming = ref<Entity | null>(null)
const renameForm = useForm({ name: '' })

const isOwner = computed(() => Boolean((page.props as any).auth?.user?.is_company_owner))

const active = computed(() => props.entities.filter(e => e.status === 'active'))
const archived = computed(() => props.entities.filter(e => e.status !== 'active'))

function add() {
  addForm.post(route('finance.accounting.entities.store'), {
    preserveScroll: true,
    onSuccess: () => { addOpen.value = false; addForm.reset() },
  })
}

function switchTo(entity: Entity) {
  if (entity.is_current) return
  router.post(route('finance.accounting.entities.switch', { entity: entity.id }), {}, { preserveScroll: true })
}

/*
 * "Letterhead" on a business's card has to mean that business's letterhead.
 *
 * It was a plain link to the settings screen, which shows whichever business is being worked in —
 * so opening Mkhaya Move's letterhead from Move's own card edited Mkhaya MK's, under a heading
 * that said Move. Both cards led to one row and the last save won. Switching first is what makes
 * the card's own menu about the card.
 */
function editLetterhead(entity: Entity) {
  router.post(route('finance.accounting.entities.switch', { entity: entity.id }), {
    to: 'finance.accounting.settings',
  })
}

function startRename(entity: Entity) {
  renaming.value = entity
  renameForm.name = entity.name
}

function saveRename() {
  if (!renaming.value) return
  renameForm.patch(route('finance.accounting.entities.update', { entity: renaming.value.id }), {
    preserveScroll: true,
    onSuccess: () => { renaming.value = null },
  })
}

function patch(entity: Entity, payload: Record<string, unknown>) {
  router.patch(route('finance.accounting.entities.update', { entity: entity.id }), payload, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4 d-flex flex-wrap align-center ga-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.entities_title') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.entities_sub') }}</div>
      </div>
      <VSpacer />
      <!--
        Hidden rather than disabled when there is no tenant. A disabled button says "not now";
        the real answer is "not here", and the notice below says so and offers the way out.
      -->
      <VBtn
        v-if="!noCompany"
        color="primary"
        :disabled="allowance.remaining < 1"
        prepend-icon="mdi-plus"
        @click="addOpen = true"
      >
        {{ t('acc.entity_add') }}
      </VBtn>
    </div>

    <!--
      A platform administrator sits above any one tenant, so there are no books here to add a
      business to. Showing "0 of 1 used" over an empty list states a fact about nothing; this
      offers the switch instead.
    -->
    <CompanySwitchNotice
      v-if="noCompany"
      :show="true"
      :title="t('acc.entity_no_company_title')"
      :body="noCompanyReason || t('acc.entity_no_company_body')"
    />

    <template v-else>

    <!-- What the plan covers. Shown before the list, because it is the reason the button above
         may be disabled, and a disabled button with no explanation reads as a fault. -->
    <VAlert
      :type="allowance.remaining > 0 ? 'info' : 'warning'"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      <div class="d-flex flex-wrap align-center ga-2">
        <span>{{ t('acc.entity_allowance', { used: allowance.used, allowance: allowance.allowance }) }}</span>
        <template v-if="allowance.remaining < 1 && packages.length">
          <VDivider vertical class="mx-2" />
          <span class="text-body-2">{{ t('acc.entity_buy_more') }}</span>
          <!-- Only the account owner can change the subscription, so only they are offered the
               link. Sending anybody else there ends in a 403 on a page they cannot act on. -->
          <VBtn
            v-if="isOwner"
            size="small" variant="tonal" color="primary"
            :href="route('tenancy.subscription.index')"
          >
            {{ t('acc.entity_manage_subscription') }}
          </VBtn>
          <span v-else class="text-body-2 text-medium-emphasis">{{ t('acc.entity_ask_owner') }}</span>
        </template>
      </div>
    </VAlert>

    <VRow>
      <VCol v-for="entity in active" :key="entity.id" cols="12" md="6" lg="4">
        <VCard
          :variant="entity.is_current ? 'outlined' : 'elevated'"
          :color="entity.is_current ? 'primary' : undefined"
          class="h-100"
        >
          <VCardItem>
            <template #prepend>
              <VAvatar size="44" rounded="lg" :image="entity.logo || undefined" color="grey-lighten-3">
                <VIcon v-if="!entity.logo" size="22">mdi-domain</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-subtitle-1">{{ entity.name }}</VCardTitle>
            <VCardSubtitle>
              <!-- A missing VAT number is not a missing letterhead; a business can be set up and
                   not be registered for VAT. The warning belongs on having no letterhead at all. -->
              <span v-if="entity.vat_number">{{ t('acc.vat_number') }}: {{ entity.vat_number }}</span>
              <span v-else-if="entity.configured">{{ entity.name }}</span>
              <span v-else class="text-warning">{{ t('acc.entity_not_set_up') }}</span>
            </VCardSubtitle>
            <template #append>
              <VChip v-if="entity.is_default" size="x-small" variant="tonal">{{ t('acc.entity_default') }}</VChip>
            </template>
          </VCardItem>

          <VCardActions>
            <VBtn
              v-if="!entity.is_current"
              variant="tonal" size="small" prepend-icon="mdi-swap-horizontal"
              @click="switchTo(entity)"
            >
              {{ t('acc.entity_work_in') }}
            </VBtn>
            <VChip v-else size="small" variant="flat" prepend-icon="mdi-check-circle">
              {{ t('acc.entity_current') }}
            </VChip>

            <VSpacer />

            <VMenu>
              <template #activator="{ props: menuProps }">
                <VBtn v-bind="menuProps" icon variant="text" size="small" :aria-label="t('acc.actions')">
                  <VIcon size="20">mdi-dots-vertical</VIcon>
                </VBtn>
              </template>
              <VList density="compact">
                <VListItem prepend-icon="mdi-card-account-details-outline" @click="editLetterhead(entity)">
                  <VListItemTitle>{{ t('acc.entity_letterhead') }}</VListItemTitle>
                </VListItem>
                <VListItem prepend-icon="mdi-pencil-outline" @click="startRename(entity)">
                  <VListItemTitle>{{ t('acc.entity_rename') }}</VListItemTitle>
                </VListItem>
                <VListItem
                  v-if="!entity.is_default"
                  prepend-icon="mdi-star-outline"
                  @click="patch(entity, { is_default: true })"
                >
                  <VListItemTitle>{{ t('acc.entity_make_default') }}</VListItemTitle>
                </VListItem>
                <VDivider />
                <VListItem
                  v-if="active.length > 1"
                  prepend-icon="mdi-archive-outline"
                  @click="patch(entity, { status: 'archived' })"
                >
                  <VListItemTitle>{{ t('acc.entity_archive') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <template v-if="archived.length">
      <h3 class="text-subtitle-1 mt-6 mb-2">{{ t('acc.entity_archived') }}</h3>
      <div class="text-caption text-medium-emphasis mb-3">{{ t('acc.entity_archived_note') }}</div>
      <VList density="compact" class="rounded border">
        <VListItem v-for="entity in archived" :key="entity.id">
          <template #prepend><VIcon size="18">mdi-archive-outline</VIcon></template>
          <VListItemTitle>{{ entity.name }}</VListItemTitle>
          <template #append>
            <VBtn size="small" variant="text" @click="patch(entity, { status: 'active' })">
              {{ t('acc.entity_restore') }}
            </VBtn>
          </template>
        </VListItem>
      </VList>
    </template>

    </template>

    <!-- Add -->
    <VDialog v-model="addOpen" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.entity_add') }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model="addForm.name"
            :label="t('acc.entity_name')"
            :error-messages="addForm.errors.name"
            variant="outlined"
            density="comfortable"
            autofocus
            @keyup.enter="add"
          />
          <div class="text-caption text-medium-emphasis">{{ t('acc.entity_add_note') }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addOpen = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="addForm.processing" @click="add">{{ t('acc.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Rename -->
    <VDialog :model-value="renaming !== null" max-width="480" @update:model-value="renaming = null">
      <VCard>
        <VCardTitle>{{ t('acc.entity_rename') }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model="renameForm.name"
            :label="t('acc.entity_name')"
            :error-messages="renameForm.errors.name"
            variant="outlined"
            density="comfortable"
            autofocus
            @keyup.enter="saveRename"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="renaming = null">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="renameForm.processing" @click="saveRename">{{ t('acc.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
