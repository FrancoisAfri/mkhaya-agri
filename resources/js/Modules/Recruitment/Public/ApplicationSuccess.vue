<script setup lang="ts">
// ─── No layout — public standalone page ───────────────────────────────────────
defineOptions({ layout: null })

// ─── Props ────────────────────────────────────────────────────────────────────
defineProps<{
  jobBoardUrl?: string
}>()
</script>

<template>
  <VApp>
    <div
      style="background: #f8fafc; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 16px;"
    >
      <!-- Company Header -->
      <div class="d-flex align-center gap-3 mb-8">
        <img
          src="/images/logo_default.png"
          alt="Mkhaya Agri"
          style="height: 40px; object-fit: contain;"
          @error="($event.target as HTMLImageElement).style.display = 'none'"
        />
        <span style="font-size: 1.25rem; font-weight: 800; color: #0f172a; letter-spacing: -0.3px;">
          MKHAYA AGRI
        </span>
      </div>

      <!-- Success Card -->
      <VCard
        elevation="0"
        border
        class="text-center pa-10"
        style="max-width: 520px; width: 100%; border-radius: 24px;"
      >
        <!-- Icon -->
        <div
          class="d-flex align-center justify-center rounded-circle mx-auto mb-5"
          style="width: 96px; height: 96px; background: rgba(var(--v-theme-success), 0.12);"
        >
          <VIcon icon="tabler-circle-check" color="success" size="56" />
        </div>

        <!-- Title -->
        <h3 class="font-weight-bold mb-3" style="font-size: 1.75rem; color: #0f172a;">
          Application Submitted!
        </h3>

        <!-- Message -->
        <p class="text-body-1 text-medium-emphasis mb-6" style="line-height: 1.7;">
          Thank you for applying. We will review your application and be in touch.
        </p>

        <VDivider class="mb-6" />

        <!-- What happens next -->
        <div class="text-start mb-6">
          <p class="text-body-2 font-weight-semibold mb-3">What happens next?</p>
          <div class="d-flex flex-column gap-3">
            <div class="d-flex align-start gap-3">
              <div
                class="d-flex align-center justify-center rounded-circle flex-shrink-0"
                style="width: 28px; height: 28px; background: rgba(var(--v-theme-primary), 0.1);"
              >
                <VIcon icon="tabler-search" color="primary" size="14" />
              </div>
              <p class="text-body-2 text-medium-emphasis mb-0">
                Our team will review your CV and application details.
              </p>
            </div>
            <div class="d-flex align-start gap-3">
              <div
                class="d-flex align-center justify-center rounded-circle flex-shrink-0"
                style="width: 28px; height: 28px; background: rgba(var(--v-theme-primary), 0.1);"
              >
                <VIcon icon="tabler-phone" color="primary" size="14" />
              </div>
              <p class="text-body-2 text-medium-emphasis mb-0">
                If shortlisted, we will contact you via email or phone.
              </p>
            </div>
            <div class="d-flex align-start gap-3">
              <div
                class="d-flex align-center justify-center rounded-circle flex-shrink-0"
                style="width: 28px; height: 28px; background: rgba(var(--v-theme-primary), 0.1);"
              >
                <VIcon icon="tabler-clock" color="primary" size="14" />
              </div>
              <p class="text-body-2 text-medium-emphasis mb-0">
                We aim to respond to all applicants within 14 business days.
              </p>
            </div>
          </div>
        </div>

        <!-- Return link -->
        <VBtn
          v-if="jobBoardUrl"
          :href="jobBoardUrl"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-arrow-left"
        >
          Return to Job Board
        </VBtn>
        <VBtn
          v-else
          color="primary"
          variant="tonal"
          prepend-icon="tabler-home"
          href="/"
        >
          Return to Home
        </VBtn>
      </VCard>

      <!-- Footer note -->
      <p class="text-caption text-medium-emphasis text-center mt-6 mb-0">
        Powered by Mkhaya Agri — Talent Acquisition Platform
      </p>
    </div>
  </VApp>
</template>
