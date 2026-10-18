<template>
  <VApp>
    <VMain>
      <div style="min-height:100vh; background:linear-gradient(145deg,#0d1b2a 0%,#1b2a3b 40%,#1a237e 100%)">
        <!-- Secure Header -->
        <div class="d-flex align-center justify-space-between px-6 py-4">
          <div class="d-flex align-center ga-3">
            <div style="background:rgba(255,255,255,0.1); border-radius:12px; padding:8px">
              <v-icon color="white" size="24">mdi-shield-check</v-icon>
            </div>
            <div>
              <div class="text-white font-weight-bold text-body-1">{{ t('ethics_layout.portal_title') }}</div>
              <div class="text-caption" style="color:rgba(255,255,255,0.6)">{{ t('ethics_layout.portal_subtitle') }}</div>
            </div>
          </div>
          <div class="d-flex align-center ga-4">
            <div class="d-flex align-center ga-1">
              <v-icon size="14" color="success">mdi-lock</v-icon>
              <span class="text-caption" style="color:rgba(255,255,255,0.7)">{{ t('ethics_layout.encrypted') }}</span>
            </div>
            <div class="d-flex align-center ga-1">
              <v-icon size="14" color="success">mdi-shield-account</v-icon>
              <span class="text-caption" style="color:rgba(255,255,255,0.7)">{{ t('ethics_layout.identity_protected') }}</span>
            </div>
          </div>
        </div>

        <!-- Page Content -->
        <slot />

        <!-- Footer -->
        <div class="text-center py-6 px-4">
          <p class="text-caption mb-1" style="color:rgba(255,255,255,0.4)">
            {{ t('ethics_layout.footer_encryption') }}
          </p>
          <p class="text-caption" style="color:rgba(255,255,255,0.3)">
            {{ t('ethics_layout.footer_retaliation') }}
          </p>
        </div>
      </div>
    </VMain>
  </VApp>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
