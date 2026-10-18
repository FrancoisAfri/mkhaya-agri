<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { date, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  committee: any
  members: any[]
  officers: { id: number; name: string }[]
  roles: string[]
  reviews: any[]
  can: { manage: boolean }
}>()

const adding = ref(false)

const memberForm = useForm({
  user_id: null as number | null,
  is_external: false,
  external_name: '',
  external_email: '',
  external_organisation: '',
  member_role: 'MEMBER',
  is_voting: true,
  appointed_at: '',
  start_date: '',
  end_date: '',
})

const base = computed(() => `/procurement-governance/committees/${props.committee.uuid}`)

const votingCount = computed(() =>
  props.members.filter(m => m.is_voting && m.status === 'ACTIVE').length)

const hasExternal = computed(() => props.members.some(m => m.is_external && m.status === 'ACTIVE'))

/** The constitution problems the server would refuse on, shown before anyone presses activate. */
const readiness = computed(() => {
  const problems: string[] = []

  if (!props.committee.chairperson_id) problems.push(t('procurement_governance.committees.needs_chair'))
  if (votingCount.value < props.committee.quorum) {
    problems.push(t('procurement_governance.committees.needs_quorum', {
      have: votingCount.value, need: props.committee.quorum,
    }))
  }
  if (props.committee.requires_external_member && !hasExternal.value) {
    problems.push(t('procurement_governance.committees.needs_external'))
  }

  return problems
})

function addMember() {
  memberForm.post(`${base.value}/members`, {
    preserveScroll: true,
    onSuccess: () => { adding.value = false; memberForm.reset() },
  })
}

function endMembership(memberId: number) {
  router.post(`${base.value}/members/${memberId}/end`, { status: 'ENDED' }, { preserveScroll: true })
}

function activate() {
  router.post(`${base.value}/activate`, {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <span class="text-caption font-weight-bold text-medium-emphasis">{{ committee.reference }}</span>
          <StatusChip :status="committee.status" />
        </div>
        <h1 class="text-h5 font-weight-bold">{{ committee.name }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          <template v-if="committee.committee_type">{{ committee.committee_type }} · </template>
          {{ t('procurement_governance.committees.quorum') }} {{ committee.quorum }}
          <template v-if="committee.end_date">
            · {{ t('procurement_governance.committees.term_ends') }} {{ date(committee.end_date) }}
          </template>
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="mdi-account-plus-outline" @click="adding = true">
          {{ t('procurement_governance.committees.add_member') }}
        </VBtn>
        <VBtn
          v-if="can.manage && committee.status === 'DRAFT'"
          color="primary" prepend-icon="mdi-check-circle-outline"
          :disabled="!!readiness.length" @click="activate"
        >
          {{ t('procurement_governance.committees.activate') }}
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="readiness.length && committee.status === 'DRAFT'"
      type="warning" variant="tonal" class="mb-4" icon="mdi-alert-outline"
    >
      <div class="text-body-2 font-weight-medium mb-1">{{ t('procurement_governance.committees.not_constituted') }}</div>
      <ul class="text-body-2 ps-4 mb-0">
        <li v-for="(problem, i) in readiness" :key="i">{{ problem }}</li>
      </ul>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.committees.members') }}
          </VCardTitle>

          <div v-if="!members.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-account-group-outline" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.committees.no_members') }}</div>
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.committees.member') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.role') }}</th>
                  <th scope="col">{{ t('procurement_governance.committees.voting') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.committees.appointed') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.common.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="member in members" :key="member.id">
                  <td>
                    <div class="text-body-2">{{ member.display_name }}</div>
                    <div v-if="member.external_organisation" class="text-caption text-medium-emphasis">
                      {{ member.external_organisation }}
                    </div>
                  </td>
                  <td class="text-body-2">
                    {{ humanise(member.member_role) }}
                    <VChip v-if="member.is_external" size="x-small" variant="tonal" class="ms-1" label>
                      {{ t('procurement_governance.tenders.external') }}
                    </VChip>
                  </td>
                  <td>
                    <VIcon
                      :icon="member.is_voting ? 'mdi-check' : 'mdi-minus'"
                      :color="member.is_voting ? 'success' : 'grey'" size="18"
                      :aria-label="member.is_voting
                        ? t('procurement_governance.committees.voting')
                        : t('procurement_governance.committees.non_voting')"
                    />
                  </td>
                  <td class="d-none d-md-table-cell text-body-2">{{ date(member.appointed_at) }}</td>
                  <td><StatusChip :status="member.status" /></td>
                  <td class="text-end">
                    <VBtn
                      v-if="can.manage && member.status === 'ACTIVE'"
                      size="x-small" variant="text" @click="endMembership(member.id)"
                    >
                      {{ t('procurement_governance.committees.end_term') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.committees.tenders') }}
          </VCardTitle>

          <div v-if="!reviews.length" class="text-center py-8 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.committees.no_tenders') }}</div>
          </div>

          <VList v-else density="compact">
            <VListItem
              v-for="review in reviews" :key="review.uuid"
              :href="`/procurement-governance/tenders/${review.uuid}`"
              class="procgov-link-item"
            >
              <VListItemTitle class="text-body-2">{{ review.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ review.reference }}
                <template v-if="review.evaluation_deadline"> · {{ date(review.evaluation_deadline) }}</template>
              </VListItemSubtitle>
              <template #append><StatusChip :status="review.status" size="x-small" /></template>
            </VListItem>
          </VList>
        </VCard>

        <VCard v-if="committee.terms_of_reference" elevation="0" border rounded="lg" class="mt-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.committees.terms') }}
          </VCardTitle>
          <VCardText class="text-body-2" style="white-space: pre-wrap">
            {{ committee.terms_of_reference }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="adding" max-width="560">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.committees.add_member') }}</VCardTitle>
        <VCardText>
          <VSwitch
            v-model="memberForm.is_external"
            :label="t('procurement_governance.committees.external_member')"
            :hint="t('procurement_governance.committees.external_hint')" persistent-hint
            density="compact" class="mb-2"
          />

          <VSelect
            v-if="!memberForm.is_external"
            v-model="memberForm.user_id"
            :items="officers.map(o => ({ title: o.name, value: o.id }))"
            :label="t('procurement_governance.committees.member')"
            :error-messages="memberForm.errors.user_id"
            variant="outlined" density="comfortable" class="mb-3"
          />

          <template v-else>
            <VTextField
              v-model="memberForm.external_name" :label="t('procurement_governance.committees.external_name')"
              variant="outlined" density="comfortable" class="mb-3"
            />
            <VTextField
              v-model="memberForm.external_organisation"
              :label="t('procurement_governance.committees.external_organisation')"
              variant="outlined" density="comfortable" class="mb-3"
            />
            <VTextField
              v-model="memberForm.external_email" type="email"
              :label="t('procurement_governance.committees.external_email')"
              variant="outlined" density="comfortable" class="mb-3"
            />
          </template>

          <VRow dense>
            <VCol cols="12" md="7">
              <VSelect
                v-model="memberForm.member_role"
                :items="roles.map(r => ({ title: humanise(r), value: r }))"
                :label="t('procurement_governance.tenders.role')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="5" class="d-flex align-center">
              <VCheckbox
                v-model="memberForm.is_voting"
                :label="t('procurement_governance.committees.voting')"
                density="compact" hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="adding = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="memberForm.processing" @click="addMember">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-link-item { cursor: pointer; }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
}
</style>
