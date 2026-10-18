<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

const props = defineProps<{
  compliance: Record<string, any> | null
  expiring: Array<Record<string, any>>
  lmsAvailable: boolean
  noCompany?: boolean
}>()

const expired = computed(() => props.expiring.filter(e => e.expired).length)
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.training.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.training.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        There is deliberately no "add course" button on this screen. Saying why is kinder than
        letting somebody hunt for one.
      -->
      <VAlert v-if="!lmsAvailable" type="info" variant="tonal" class="mb-4">
        {{ t('sheq.training.unavailable') }}
      </VAlert>

      <template v-else>
        <VAlert v-if="expired" type="warning" variant="tonal" class="mb-4"
          role="status" aria-live="polite">
          {{ expired }} {{ t('sheq.training.expired').toLowerCase() }}
        </VAlert>

        <VRow class="mb-1">
          <VCol cols="6" md="3">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.training.compliance') }}
              </div>
              <div class="text-h4 font-weight-bold">{{ compliance?.compliance_pct ?? 0 }}%</div>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.training.completed') }}
              </div>
              <div class="text-h4 font-weight-bold">{{ compliance?.completed ?? 0 }}</div>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.training.overdue') }}
              </div>
              <div class="text-h4 font-weight-bold text-warning">{{ compliance?.overdue ?? 0 }}</div>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.training.expired') }}
              </div>
              <div class="text-h4 font-weight-bold text-error">{{ compliance?.expired ?? 0 }}</div>
            </VCard>
          </VCol>
        </VRow>

        <VCard elevation="0" border rounded="lg" class="mt-4">
          <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
            {{ t('sheq.training.expiring') }}
          </VCardTitle>
          <VDivider />
          <div v-if="!expiring.length" class="text-center py-8 text-medium-emphasis">
            <div class="text-body-2">Nothing expiring.</div>
          </div>
          <VTable v-else density="compact">
            <tbody>
              <tr v-for="(e, i) in expiring" :key="i">
                <td class="text-body-2">{{ e.person }}</td>
                <td class="text-body-2">{{ e.course }}</td>
                <td style="width: 130px">
                  <VChip size="x-small" :color="e.expired ? 'error' : 'warning'" variant="tonal">
                    {{ e.expires_on }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </template>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
