<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type Card = {
  id: number; card_number_last4: string; masked_number: string; provider: string
  card_type: string
  vehicle: string | null; vehicle_id: number | null
  driver_id: number | null
  status: string; daily_limit: number; monthly_limit: number; current_month_spend: number
  expiry_month: number | null; expiry_year: number | null
}
type Transaction = {
  id: number; card_id: number; amount: number; merchant_name: string | null
  merchant_category: string | null; transaction_date: string
  litres: number | null; cost_per_litre: number | null; odometer_reading: number | null
  is_flagged: boolean; is_reconciled: boolean; notes: string | null
}
type Vehicle = { id: number; registration: string; make: string; model: string }
type Driver = { id: number; name: string }

const props = defineProps<{
  cards: Card[]
  transactions: { data: Transaction[]; meta: any }
  vehicles: Vehicle[]
  drivers: Driver[]
}>()

const selectedCard = ref<Card | null>(null)
const cardDialog   = ref(false)
const txDialog     = ref(false)
const editingCard  = ref<Card | null>(null)

const cardForm = useForm({
  vehicle_id: null as number | null,
  driver_id: null as number | null,
  card_type: 'fuel',
  provider: '',
  card_number_last4: '',
  expiry_month: null as number | null,
  expiry_year: null as number | null,
  daily_limit: 500,
  monthly_limit: 5000,
  status: 'active',
})

function openAddCard() {
  editingCard.value = null
  cardForm.reset()
  cardForm.card_type = 'fuel'
  cardForm.status = 'active'
  cardForm.daily_limit = 500
  cardForm.monthly_limit = 5000
  cardDialog.value = true
}

function openEditCard(c: Card) {
  editingCard.value = c
  cardForm.vehicle_id = c.vehicle_id
  cardForm.driver_id = c.driver_id
  cardForm.card_type = c.card_type || 'fuel'
  cardForm.provider = c.provider
  cardForm.expiry_month = c.expiry_month
  cardForm.expiry_year = c.expiry_year
  cardForm.daily_limit = c.daily_limit
  cardForm.monthly_limit = c.monthly_limit
  cardForm.status = c.status
  cardDialog.value = true
}

function saveCard() {
  if (editingCard.value) {
    cardForm.patch(route('fleet.cards.update', editingCard.value.id), {
      preserveScroll: true,
      onSuccess: () => { cardDialog.value = false; cardForm.reset() },
    })
  } else {
    cardForm.post(route('fleet.cards.store'), {
      preserveScroll: true,
      onSuccess: () => { cardDialog.value = false; cardForm.reset() },
    })
  }
}

const txForm = useForm({
  amount: null as number | null,
  merchant_name: '',
  merchant_category: '',
  transaction_date: '',
  litres: null as number | null,
  cost_per_litre: null as number | null,
  odometer_reading: null as number | null,
  notes: '',
})

function openTx(c: Card) {
  selectedCard.value = c
  txForm.reset()
  txDialog.value = true
}

function saveTx() {
  if (!selectedCard.value) return
  txForm.post(route('fleet.cards.transactions.store', selectedCard.value.id), {
    preserveScroll: true,
    onSuccess: () => { txDialog.value = false; txForm.reset() },
  })
}

function flagTx(tx: Transaction) {
  router.patch(route('fleet.cards.transactions.flag', tx.id), {}, { preserveScroll: true })
}

function reconcileTx(tx: Transaction) {
  router.patch(route('fleet.cards.transactions.reconcile', tx.id), {}, { preserveScroll: true })
}

const deleteDialog = ref(false)
const toDelete = ref<Card | null>(null)

function confirmDelete(c: Card) {
  toDelete.value = c
  deleteDialog.value = true
}

function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.cards.destroy', toDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false; toDelete.value = null },
  })
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_cards_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_cards_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-bank-card-line" @click="openAddCard">
        {{ t('fleet_cards_index.add_card') }}
      </VBtn>
    </div>

    <!-- Card Grid -->
    <VRow class="mb-6">
      <VCol v-for="c in cards" :key="c.id" cols="12" sm="6" md="4" lg="3">
        <VCard variant="outlined" :class="selectedCard?.id === c.id ? 'border-primary' : ''">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <VChip
                :color="c.status === 'active' ? 'success' : c.status === 'blocked' ? 'error' : 'default'"
                size="x-small"
                label
                class="text-capitalize"
              >
                {{ c.status }}
              </VChip>
              <span class="text-caption text-medium-emphasis">{{ c.provider }}</span>
            </div>
            <div class="text-h6 font-weight-bold mb-1">{{ c.masked_number }}</div>
            <div class="text-caption text-medium-emphasis mb-3">
              <span v-if="c.expiry_month">
                {{ String(c.expiry_month).padStart(2, '0') }}/{{ c.expiry_year }}
              </span>
              <span v-else>{{ t('fleet_cards_index.no_expiry') }}</span>
            </div>
            <div class="text-caption">{{ c.vehicle ?? t('fleet_cards_index.no_vehicle') }}</div>
            <VProgressLinear
              :model-value="(c.current_month_spend / c.monthly_limit) * 100"
              height="6"
              rounded
              :color="c.current_month_spend >= c.monthly_limit ? 'error' : c.current_month_spend / c.monthly_limit > 0.8 ? 'warning' : 'success'"
              class="mt-2 mb-1"
            />
            <div class="d-flex justify-space-between text-caption">
              <span>{{ t('fleet_cards_index.spent') }}: R{{ c.current_month_spend?.toLocaleString() }}</span>
              <span>{{ t('fleet_cards_index.limit') }}: R{{ c.monthly_limit?.toLocaleString() }}</span>
            </div>
          </VCardText>
          <VCardActions class="pt-0">
            <VBtn size="x-small" variant="tonal" color="primary" @click="openTx(c)">
              {{ t('fleet_cards_index.log_transaction') }}
            </VBtn>
            <VSpacer />
            <VBtn icon size="x-small" variant="text" @click="openEditCard(c)">
              <VIcon icon="ri-pencil-line" />
            </VBtn>
            <VBtn icon size="x-small" variant="text" color="error" @click="confirmDelete(c)">
              <VIcon icon="ri-delete-bin-line" />
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!cards.length" cols="12">
        <VCard class="text-center pa-8">
          <VIcon icon="ri-bank-card-line" size="48" color="medium-emphasis" />
          <div class="text-medium-emphasis mt-2">{{ t('fleet_cards_index.no_cards') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent Transactions -->
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon icon="ri-exchange-dollar-line" class="mr-2" />
        {{ t('fleet_cards_index.recent_transactions') }}
      </VCardTitle>
      <VDataTableServer
        :headers="[
          { title: t('fleet_cards_index.table.date'),           key: 'transaction_date' },
          { title: t('fleet_cards_index.table.merchant'),       key: 'merchant_name' },
          { title: t('fleet_cards_index.table.category'),       key: 'merchant_category' },
          { title: t('fleet_cards_index.table.amount'),         key: 'amount' },
          { title: t('fleet_cards_index.table.litres'),         key: 'litres' },
          { title: t('fleet_cards_index.table.cost_per_litre'), key: 'cost_per_litre' },
          { title: t('fleet_cards_index.table.odometer'),       key: 'odometer_reading' },
          { title: t('fleet_cards_index.table.flagged'),        key: 'is_flagged' },
          { title: t('fleet_cards_index.table.reconciled'),     key: 'is_reconciled' },
          { title: '', key: 'actions', sortable: false, width: '100px' },
        ]"
        :items="transactions.data"
        :items-length="transactions.meta?.total ?? 0"
        density="compact"
        hover
      >
        <template #item.amount="{ item }">R {{ item.amount?.toLocaleString() }}</template>
        <template #item.litres="{ item }">{{ item.litres ?? '—' }}</template>
        <template #item.cost_per_litre="{ item }">{{ item.cost_per_litre ?? '—' }}</template>
        <template #item.odometer_reading="{ item }">{{ item.odometer_reading ?? '—' }}</template>
        <template #item.merchant_name="{ item }">{{ item.merchant_name ?? '—' }}</template>
        <template #item.merchant_category="{ item }">{{ item.merchant_category ?? '—' }}</template>
        <template #item.is_flagged="{ item }">
          <VIcon :icon="item.is_flagged ? 'ri-flag-line' : 'ri-checkbox-blank-circle-line'" :color="item.is_flagged ? 'error' : 'default'" size="18" />
        </template>
        <template #item.is_reconciled="{ item }">
          <VIcon :icon="item.is_reconciled ? 'ri-checkbox-circle-line' : 'ri-close-circle-line'" :color="item.is_reconciled ? 'success' : 'default'" size="18" />
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn v-if="!item.is_flagged" icon size="x-small" variant="text" color="warning" @click="flagTx(item)">
              <VIcon icon="ri-flag-line" />
              <VTooltip activator="parent">{{ t('fleet_cards_index.flag') }}</VTooltip>
            </VBtn>
            <VBtn v-if="!item.is_reconciled" icon size="x-small" variant="text" color="success" @click="reconcileTx(item)">
              <VIcon icon="ri-check-double-line" />
              <VTooltip activator="parent">{{ t('fleet_cards_index.reconcile') }}</VTooltip>
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Add/Edit Card Dialog -->
    <VDialog v-model="cardDialog" max-width="520" persistent>
      <VCard :title="editingCard ? t('fleet_cards_index.edit_card') : t('fleet_cards_index.add_card_title')">
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="cardForm.vehicle_id"
                :label="t('fleet_cards_index.assign_vehicle')"
                :items="vehicles"
                item-title="registration"
                item-value="id"
                :item-subtitle="(v: Vehicle) => `${v.make} ${v.model}`"
                clearable
                :error-messages="cardForm.errors.vehicle_id"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="cardForm.driver_id"
                :label="t('fleet_cards_index.assign_driver')"
                :items="drivers"
                item-title="name"
                item-value="id"
                clearable
                :error-messages="cardForm.errors.driver_id"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="cardForm.card_type"
                :label="t('fleet_cards_index.card_type_label') + ' *'"
                :items="[
                  { title: t('fleet_cards_index.types.fuel'), value: 'fuel' },
                  { title: t('fleet_cards_index.types.toll'), value: 'toll' },
                  { title: t('fleet_cards_index.types.multi'), value: 'multi' },
                ]"
                item-title="title"
                item-value="value"
                :error-messages="cardForm.errors.card_type"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="cardForm.provider"
                :label="t('fleet_cards_index.provider')"
                :error-messages="cardForm.errors.provider"
              />
            </VCol>
            <VCol cols="12" md="6" v-if="!editingCard">
              <VTextField
                v-model="cardForm.card_number_last4"
                :label="t('fleet_cards_index.last4')"
                maxlength="4"
                :error-messages="cardForm.errors.card_number_last4"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="cardForm.expiry_month"
                :label="t('fleet_cards_index.exp_month')"
                type="number"
                min="1"
                max="12"
                :error-messages="cardForm.errors.expiry_month"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="cardForm.expiry_year"
                :label="t('fleet_cards_index.exp_year')"
                type="number"
                :error-messages="cardForm.errors.expiry_year"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="cardForm.daily_limit"
                :label="t('fleet_cards_index.daily_limit')"
                type="number"
                :error-messages="cardForm.errors.daily_limit"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="cardForm.monthly_limit"
                :label="t('fleet_cards_index.monthly_limit')"
                type="number"
                :error-messages="cardForm.errors.monthly_limit"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="cardForm.status"
                :label="t('fleet_cards_index.status')"
                :items="['active', 'blocked', 'cancelled']"
                :error-messages="cardForm.errors.status"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="cardDialog = false">{{ t('fleet_cards_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="cardForm.processing" @click="saveCard">
            {{ t('fleet_cards_index.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Log Transaction Dialog -->
    <VDialog v-model="txDialog" max-width="520" persistent>
      <VCard :title="t('fleet_cards_index.log_transaction_title')">
        <VCardText>
          <p class="text-caption text-medium-emphasis mb-3">
            {{ t('fleet_cards_index.card') }}: {{ selectedCard?.masked_number }}
          </p>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model="txForm.transaction_date"
                :label="t('fleet_cards_index.table.date') + ' *'"
                type="datetime-local"
                :error-messages="txForm.errors.transaction_date"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="txForm.amount"
                :label="t('fleet_cards_index.table.amount') + ' (R) *'"
                type="number"
                :error-messages="txForm.errors.amount"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="txForm.merchant_name"
                :label="t('fleet_cards_index.table.merchant')"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="txForm.merchant_category"
                :label="t('fleet_cards_index.table.category')"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model.number="txForm.litres"
                :label="t('fleet_cards_index.table.litres')"
                type="number"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model.number="txForm.cost_per_litre"
                :label="t('fleet_cards_index.table.cost_per_litre')"
                type="number"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model.number="txForm.odometer_reading"
                :label="t('fleet_cards_index.table.odometer')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="txForm.notes"
                :label="t('fleet_cards_index.notes')"
                rows="2"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="txDialog = false">{{ t('fleet_cards_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="txForm.processing" @click="saveTx">
            {{ t('fleet_cards_index.log_transaction') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm Dialog -->
    <VDialog v-model="deleteDialog" max-width="400" persistent>
      <VCard :title="t('fleet_cards_index.delete_card')">
        <VCardText>
          {{ t('fleet_cards_index.delete_message', { last4: toDelete?.card_number_last4 }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('fleet_cards_index.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('fleet_cards_index.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
