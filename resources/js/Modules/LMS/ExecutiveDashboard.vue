<template>
  <VContainer fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_executive_dashboard.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('lms_executive_dashboard.page_description') }}</p>
      </div>
    </div>

    <!-- ── Top KPI Row ──────────────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">mdi-shield-check</VIcon>
            <div class="text-h6 font-weight-bold text-success">{{ trainingCompliance.rate }}%</div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.compliance_rate') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon color="info" size="22" class="mb-1">mdi-brain</VIcon>
            <div class="text-h6 font-weight-bold text-info">{{ fmt(avgUnderstandingScore.overall) }}%</div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.avg_understanding') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon :color="mandatoryOverdue.overdue > 0 ? 'error' : 'success'" size="22" class="mb-1">mdi-calendar-remove</VIcon>
            <div class="text-h6 font-weight-bold" :class="mandatoryOverdue.overdue > 0 ? 'text-error' : 'text-success'">
              {{ mandatoryOverdue.overdue }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.mandatory_overdue') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon :color="certificationRisk.expired > 0 ? 'error' : 'warning'" size="22" class="mb-1">mdi-certificate-outline</VIcon>
            <div class="text-h6 font-weight-bold" :class="certificationRisk.expired > 0 ? 'text-error' : 'text-warning'">
              {{ certificationRisk.expired + certificationRisk.expiring30 }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.cert_risk_30d') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon color="deep-purple" size="22" class="mb-1">mdi-trending-up</VIcon>
            <div class="text-h6 font-weight-bold text-deep-purple">{{ fmt(trainingRoi.avgRoi) }}%</div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.avg_training_roi') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard border rounded="lg">
          <VCardText class="pa-3 text-center">
            <VIcon :color="policyViolations.unresolved > 0 ? 'error' : 'success'" size="22" class="mb-1">mdi-alert-octagon</VIcon>
            <div class="text-h6 font-weight-bold" :class="policyViolations.unresolved > 0 ? 'text-error' : 'text-success'">
              {{ policyViolations.unresolved }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.kpis.policy_violations') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- ── Training Compliance Rate ──────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="success">mdi-shield-check</VIcon> {{ t('lms_executive_dashboard.training_compliance.title') }}</span>
            <VChip :color="trainingCompliance.rate >= 80 ? 'success' : trainingCompliance.rate >= 60 ? 'warning' : 'error'" size="x-small">
              {{ trainingCompliance.rate }}%
            </VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div class="d-flex gap-4 mb-3">
              <div class="text-center">
                <div class="text-h6 text-success">{{ trainingCompliance.completed }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.training_compliance.completed') }}</div>
              </div>
              <div class="text-center">
                <div class="text-h6 text-error">{{ trainingCompliance.overdue }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.training_compliance.overdue') }}</div>
              </div>
              <div class="text-center">
                <div class="text-h6">{{ trainingCompliance.total }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.training_compliance.total') }}</div>
              </div>
            </div>
            <VProgressLinear :model-value="trainingCompliance.rate" color="success" rounded height="8" class="mb-3" />
          </VCardText>
          <VDivider />
          <div style="max-height:220px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="r in trainingCompliance.byRule" :key="r.rule_id" class="py-1">
                <VListItemTitle class="text-caption font-weight-medium">{{ r.rule_name }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VProgressLinear :model-value="r.compliance_rate" :color="rateColor(r.compliance_rate)"
                      rounded height="4" style="max-width:80px" />
                    <span class="text-caption">{{ r.compliance_rate }}%</span>
                    <VChip v-if="r.overdue > 0" color="error" size="x-small" variant="tonal">
                      {{ r.overdue }} {{ t('lms_executive_dashboard.training_compliance.overdue').toLowerCase() }}
                    </VChip>
                  </div>
                </VListItemSubtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ r.completed }}/{{ r.total }}</span>
                </template>
              </VListItem>
              <VListItem v-if="!trainingCompliance.byRule?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.training_compliance.no_rules') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Average Understanding Score ──────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="info">mdi-brain</VIcon> {{ t('lms_executive_dashboard.understanding_scores.title') }}</span>
            <VChip color="info" size="x-small">
              {{ t('lms_executive_dashboard.understanding_scores.avg', { score: fmt(avgUnderstandingScore.overall) }) }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <div style="max-height:280px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in avgUnderstandingScore.perCourse" :key="c.course_id" class="py-2">
                <VListItemTitle class="text-caption font-weight-medium">{{ c.course_title }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VProgressLinear :model-value="c.avg_score" :color="scoreColor(c.avg_score)"
                      rounded height="4" style="max-width:90px" />
                    <span class="text-caption">{{ fmt(c.avg_score) }}%</span>
                    <VChip v-if="c.refresher_count > 0" color="warning" size="x-small" variant="tonal">
                      {{ t('lms_executive_dashboard.understanding_scores.refresher', { count: c.refresher_count }) }}
                    </VChip>
                  </div>
                </VListItemSubtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">
                    {{ t('lms_executive_dashboard.understanding_scores.employees', { count: c.employee_count }) }}
                  </span>
                </template>
              </VListItem>
              <VListItem v-if="!avgUnderstandingScore.perCourse?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.understanding_scores.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Highest Failure Rate Courses ──────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="error">mdi-close-circle-multiple</VIcon>
            {{ t('lms_executive_dashboard.highest_failure_rates.title') }}
          </VCardTitle>
          <VDivider />
          <div style="max-height:260px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in highestFailureRates" :key="c.course_id" class="py-2">
                <template #prepend>
                  <VChip :color="failColor(c.failure_rate)" size="x-small" label class="mr-2">{{ c.failure_rate }}%</VChip>
                </template>
                <VListItemTitle class="text-caption font-weight-medium">{{ c.course_title }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ t('lms_executive_dashboard.highest_failure_rates.attempts', { failed: c.failed_attempts, total: c.total_attempts }) }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!highestFailureRates?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.highest_failure_rates.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Employees Needing Refresher ────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="warning">mdi-refresh-circle</VIcon> {{ t('lms_executive_dashboard.refresher_needed.title') }}</span>
            <VChip color="warning" size="x-small">
              {{ t('lms_executive_dashboard.refresher_needed.employees', { count: refresherNeeded.total }) }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3 pb-1">
            <div class="text-caption text-medium-emphasis mb-2">{{ t('lms_executive_dashboard.refresher_needed.top_courses') }}</div>
          </VCardText>
          <div style="max-height:220px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in refresherNeeded.topCourses" :key="c.course_id" class="py-1">
                <VListItemTitle class="text-caption">{{ c.course_title }}</VListItemTitle>
                <template #append>
                  <VChip color="warning" size="x-small" variant="tonal">{{ c.refresher_count }}</VChip>
                </template>
              </VListItem>
              <VListItem v-if="!refresherNeeded.topCourses?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.refresher_needed.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Skills Readiness ───────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="teal">mdi-gauge</VIcon> {{ t('lms_executive_dashboard.skills_readiness.title') }}</span>
            <VChip :color="skillsReadiness.readiness >= 80 ? 'success' : skillsReadiness.readiness >= 60 ? 'warning' : 'error'" size="x-small">
              {{ skillsReadiness.readiness }}%
            </VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div class="d-flex gap-4 mb-2">
              <div class="text-center">
                <div class="text-h6 text-error">{{ skillsReadiness.open }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.skills_readiness.open_gaps') }}</div>
              </div>
              <div class="text-center">
                <div class="text-h6 text-success">{{ skillsReadiness.closed }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.skills_readiness.closed') }}</div>
              </div>
              <div class="text-center">
                <div class="text-h6">{{ skillsReadiness.total }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.skills_readiness.total') }}</div>
              </div>
            </div>
            <VProgressLinear :model-value="skillsReadiness.readiness"
              :color="skillsReadiness.readiness >= 80 ? 'success' : skillsReadiness.readiness >= 60 ? 'warning' : 'error'"
              rounded height="8" class="mb-2" />
          </VCardText>
          <VDivider />
          <div style="max-height:180px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="s in skillsReadiness.avgGapPerSkill" :key="s.skill_id" class="py-1">
                <VListItemTitle class="text-caption font-weight-medium">{{ s.skill_name }}</VListItemTitle>
                <VListItemSubtitle>
                  <span class="text-caption">
                    {{ t('lms_executive_dashboard.skills_readiness.avg_gap', { gap: s.avg_gap }) }}
                    &nbsp;|&nbsp; {{ t('lms_executive_dashboard.skills_readiness.affected_employees', { count: s.affected_employees }) }}
                  </span>
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Mandatory Training Overdue ────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="error">mdi-calendar-remove</VIcon> {{ t('lms_executive_dashboard.mandatory_overdue.title') }}</span>
            <VChip :color="mandatoryOverdue.rate > 0 ? 'error' : 'success'" size="x-small">{{ mandatoryOverdue.rate }}%</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div class="d-flex gap-4 mb-3">
              <div class="text-center">
                <div class="text-h6 text-error">{{ mandatoryOverdue.overdue }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.mandatory_overdue.overdue') }}</div>
              </div>
              <div class="text-center">
                <div class="text-h6">{{ mandatoryOverdue.mandatory }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.mandatory_overdue.total_mandatory') }}</div>
              </div>
            </div>
            <VProgressLinear :model-value="mandatoryOverdue.rate" color="error" rounded height="8" class="mb-3" />
            <div class="text-caption text-medium-emphasis mb-1">{{ t('lms_executive_dashboard.mandatory_overdue.by_department') }}</div>
          </VCardText>
          <div style="max-height:160px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="d in mandatoryOverdue.byDept" :key="d.dept_id" class="py-1">
                <VListItemTitle class="text-caption">Dept {{ d.dept_id }}</VListItemTitle>
                <template #append>
                  <VChip color="error" size="x-small" variant="tonal">{{ d.overdue_count }}</VChip>
                </template>
              </VListItem>
              <VListItem v-if="!mandatoryOverdue.byDept?.length" class="text-center text-medium-emphasis pa-2">
                {{ t('lms_executive_dashboard.mandatory_overdue.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Certification Risk ─────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="amber-darken-2">mdi-certificate-outline</VIcon>
            {{ t('lms_executive_dashboard.certification_risk.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <VRow dense>
              <VCol cols="3" class="text-center">
                <VChip color="error" size="small" class="mb-1">{{ certificationRisk.expired }}</VChip>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.certification_risk.expired') }}</div>
              </VCol>
              <VCol cols="3" class="text-center">
                <VChip color="deep-orange" size="small" class="mb-1">{{ certificationRisk.expiring30 }}</VChip>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.certification_risk.days_30') }}</div>
              </VCol>
              <VCol cols="3" class="text-center">
                <VChip color="warning" size="small" class="mb-1">{{ certificationRisk.expiring60 }}</VChip>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.certification_risk.days_60') }}</div>
              </VCol>
              <VCol cols="3" class="text-center">
                <VChip color="info" size="small" class="mb-1">{{ certificationRisk.expiring90 }}</VChip>
                <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.certification_risk.days_90') }}</div>
              </VCol>
            </VRow>
          </VCardText>
          <VDivider />
          <div style="max-height:220px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in certificationRisk.atRiskCerts" :key="c.id" class="py-1">
                <template #prepend>
                  <VChip :color="c.days_left <= 0 ? 'error' : c.days_left <= 30 ? 'deep-orange' : c.days_left <= 60 ? 'warning' : 'info'"
                    size="x-small" label class="mr-2">
                    {{ c.days_left <= 0 ? t('lms_executive_dashboard.certification_risk.expired_label') : t('lms_executive_dashboard.certification_risk.days_left', { days: c.days_left }) }}
                  </VChip>
                </template>
                <VListItemTitle class="text-caption font-weight-medium">{{ c.employee_name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ c.course_title }}</VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!certificationRisk.atRiskCerts?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.certification_risk.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Training ROI ───────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="deep-purple">mdi-trending-up</VIcon> {{ t('lms_executive_dashboard.training_roi.title') }}</span>
            <div class="d-flex gap-2">
              <VChip color="deep-purple" size="x-small">
                {{ t('lms_executive_dashboard.training_roi.avg', { score: fmt(trainingRoi.avgRoi) }) }}
              </VChip>
              <VChip color="success" size="x-small">
                {{ t('lms_executive_dashboard.training_roi.best', { score: fmt(trainingRoi.bestRoi) }) }}
              </VChip>
            </div>
          </VCardTitle>
          <VDivider />
          <div style="max-height:260px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="c in trainingRoi.topCourses" :key="c.course_id" class="py-2">
                <template #prepend>
                  <VChip color="deep-purple" size="x-small" label class="mr-2">{{ fmt(c.avg_roi) }}%</VChip>
                </template>
                <VListItemTitle class="text-caption font-weight-medium">{{ c.course_title }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex gap-2 mt-1">
                    <span class="text-caption">{{ t('lms_executive_dashboard.training_roi.completion', { score: fmt(c.avg_completion) }) }}</span>
                    <span class="text-caption">{{ t('lms_executive_dashboard.training_roi.pass', { score: fmt(c.avg_pass) }) }}</span>
                    <span class="text-caption">{{ t('lms_executive_dashboard.training_roi.understanding', { score: fmt(c.avg_understanding) }) }}</span>
                  </div>
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="!trainingRoi.topCourses?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.training_roi.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Department Capability Score ───────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="teal-darken-1">mdi-office-building-cog</VIcon>
            {{ t('lms_executive_dashboard.department_capability.title') }}
          </VCardTitle>
          <VDivider />
          <div style="max-height:280px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="d in departmentCapability.departments" :key="d.dept_id" class="py-2">
                <template #prepend>
                  <VChip :color="scoreColor(d.capability_score)" size="x-small" label class="mr-2 font-weight-bold">
                    {{ fmt(d.capability_score) }}
                  </VChip>
                </template>
                <VListItemTitle class="text-caption font-weight-medium">Dept {{ d.dept_id }}</VListItemTitle>
                <VListItemSubtitle>
                  <div class="d-flex gap-2 mt-1">
                    <span class="text-caption">{{ t('lms_executive_dashboard.department_capability.understanding', { score: fmt(d.understanding_avg) }) }}</span>
                    <span class="text-caption">{{ t('lms_executive_dashboard.department_capability.completion', { score: fmt(d.completion_rate) }) }}</span>
                  </div>
                </VListItemSubtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">
                    {{ t('lms_executive_dashboard.department_capability.assessed', { count: d.assessed_count }) }}
                  </span>
                </template>
              </VListItem>
              <VListItem v-if="!departmentCapability.departments?.length" class="text-center text-medium-emphasis pa-3">
                {{ t('lms_executive_dashboard.department_capability.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Policy Training Violations ────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="error">mdi-alert-octagon</VIcon> {{ t('lms_executive_dashboard.policy_violations.title') }}</span>
            <div class="d-flex gap-2">
              <VChip size="x-small">{{ t('lms_executive_dashboard.policy_violations.total', { count: policyViolations.total }) }}</VChip>
              <VChip :color="policyViolations.unresolved > 0 ? 'error' : 'success'" size="x-small">
                {{ t('lms_executive_dashboard.policy_violations.unresolved', { count: policyViolations.unresolved }) }}
              </VChip>
            </div>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3 pb-1">
            <div class="d-flex flex-wrap gap-2 mb-2">
              <VChip v-for="s in policyViolations.bySeverity" :key="s.severity"
                :color="severityColor(s.severity)" size="x-small" variant="tonal">
                {{ t(`lms_executive_dashboard.policy_violations.severity.${s.severity}`) }}: {{ s.total }}
              </VChip>
            </div>
          </VCardText>
          <VDivider />
          <div style="max-height:220px;overflow-y:auto">
            <VList density="compact">
              <VListItem v-for="v in policyViolations.recent" :key="v.id" class="py-1">
                <template #prepend>
                  <VChip :color="v.severity_color" size="x-small" label class="mr-2">{{ v.severity }}</VChip>
                </template>
                <VListItemTitle class="text-caption font-weight-medium">{{ v.employee_name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ v.label }} — {{ v.course_title }}</VListItemSubtitle>
                <template #append>
                  <VChip :color="v.action_color" size="x-small" variant="tonal">
                    {{ v.action_taken ?? t('lms_executive_dashboard.policy_violations.logged') }}
                  </VChip>
                </template>
              </VListItem>
              <VListItem v-if="!policyViolations.recent?.length" class="text-center text-medium-emphasis pa-3">
                <VIcon color="success">mdi-check-circle</VIcon> {{ t('lms_executive_dashboard.policy_violations.no_data') }}
              </VListItem>
            </VList>
          </div>
        </VCard>
      </VCol>

      <!-- ── Workforce Skill Gaps ───────────────────────────────────────── -->
      <VCol cols="12">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span><VIcon class="mr-1" size="18" color="orange">mdi-chart-bubble</VIcon> {{ t('lms_executive_dashboard.workforce_skill_gaps.title') }}</span>
            <div class="d-flex gap-2">
              <VChip color="error" size="x-small">
                {{ t('lms_executive_dashboard.workforce_skill_gaps.open_gaps', { count: workforceSkillGaps.totalOpen }) }}
              </VChip>
              <VChip color="warning" size="x-small">
                {{ t('lms_executive_dashboard.workforce_skill_gaps.affected_employees', { count: workforceSkillGaps.affectedEmployees }) }}
              </VChip>
            </div>
          </VCardTitle>
          <VDivider />
          <VRow class="pa-3">
            <VCol cols="12" md="7">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('lms_executive_dashboard.workforce_skill_gaps.top_skills') }}</div>
              <VList density="compact">
                <VListItem v-for="g in workforceSkillGaps.topGaps" :key="g.skill_id" class="py-1">
                  <template #prepend>
                    <VChip color="error" size="x-small" label class="mr-2">{{ g.gap_count }}</VChip>
                  </template>
                  <VListItemTitle class="text-caption font-weight-medium">{{ g.skill_name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ t('lms_executive_dashboard.workforce_skill_gaps.avg_gap', { gap: g.avg_gap }) }}
                    &nbsp;|&nbsp; {{ t('lms_executive_dashboard.workforce_skill_gaps.max_gap', { gap: g.max_gap }) }}
                  </VListItemSubtitle>
                </VListItem>
                <VListItem v-if="!workforceSkillGaps.topGaps?.length" class="text-center text-medium-emphasis pa-3">
                  {{ t('lms_executive_dashboard.workforce_skill_gaps.no_data') }}
                </VListItem>
              </VList>
            </VCol>
            <VCol cols="12" md="5">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('lms_executive_dashboard.workforce_skill_gaps.by_department') }}</div>
              <VList density="compact">
                <VListItem v-for="d in workforceSkillGaps.byDept" :key="d.dept_id" class="py-1">
                  <VListItemTitle class="text-caption">Dept {{ d.dept_id }}</VListItemTitle>
                  <template #append>
                    <div class="d-flex gap-1 align-center">
                      <VChip color="error" size="x-small" variant="tonal">
                        {{ t('lms_executive_dashboard.workforce_skill_gaps.gaps', { count: d.gap_count }) }}
                      </VChip>
                      <VChip color="warning" size="x-small" variant="tonal">
                        {{ t('lms_executive_dashboard.workforce_skill_gaps.employees_short', { count: d.affected_employees }) }}
                      </VChip>
                    </div>
                  </template>
                </VListItem>
                <VListItem v-if="!workforceSkillGaps.byDept?.length" class="text-center text-medium-emphasis pa-2">
                  {{ t('lms_executive_dashboard.workforce_skill_gaps.no_data') }}
                </VListItem>
              </VList>
            </VCol>
          </VRow>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── QCTO & Learnership Compliance ────────────────────────────────── -->
    <VRow class="mt-2">
      <VCol cols="12">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
            <VIcon color="deep-purple" size="20">mdi-certificate-outline</VIcon>
            {{ t('lms_executive_dashboard.qcto_compliance.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow dense>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" color="primary" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.active_learnerships }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.active_learnerships') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" color="primary" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.active_enrolments }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.active_enrolments') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" color="success" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.completed_enrolments }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.completed_enrolments') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" :color="qctoCompliance.pending_wsp > 0 ? 'warning' : 'success'" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.pending_wsp }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.pending_wsp') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" color="success" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.submitted_wsp }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.submitted_wsp') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" :color="qctoCompliance.pending_atr > 0 ? 'warning' : 'success'" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.pending_atr }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.pending_atr') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" :color="qctoCompliance.pending_nlrd > 0 ? 'warning' : 'success'" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.pending_nlrd }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.pending_nlrd') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" :color="qctoCompliance.expiring_assessors > 0 ? 'warning' : 'success'" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.expiring_assessors }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.expiring_assessors') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" :color="qctoCompliance.expiring_sdfs > 0 ? 'warning' : 'success'" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.expiring_sdfs }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.expiring_sdfs') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="2">
                <VCard variant="tonal" color="primary" rounded="lg">
                  <VCardText class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ qctoCompliance.registered_setas }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('lms_executive_dashboard.qcto_compliance.registered_setas') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  trainingCompliance:    Record<string, any>
  avgUnderstandingScore: Record<string, any>
  highestFailureRates:   any[]
  refresherNeeded:       Record<string, any>
  skillsReadiness:       Record<string, any>
  mandatoryOverdue:      Record<string, any>
  certificationRisk:     Record<string, any>
  trainingRoi:           Record<string, any>
  departmentCapability:  Record<string, any>
  policyViolations:      Record<string, any>
  workforceSkillGaps:    Record<string, any>
  qctoCompliance: {
    active_learnerships: number
    active_enrolments: number
    completed_enrolments: number
    pending_wsp: number
    submitted_wsp: number
    pending_atr: number
    pending_nlrd: number
    expiring_assessors: number
    expiring_sdfs: number
    registered_setas: number
  }
}>()

function fmt(v: number | null | undefined): string {
  return v != null ? Number(v).toFixed(1) : '—'
}

function rateColor(rate: number): string {
  if (rate >= 80) return 'success'
  if (rate >= 60) return 'warning'
  return 'error'
}

function scoreColor(score: number): string {
  if (score >= 75) return 'success'
  if (score >= 55) return 'info'
  if (score >= 40) return 'warning'
  return 'error'
}

function failColor(rate: number): string {
  if (rate >= 50) return 'error'
  if (rate >= 30) return 'warning'
  return 'info'
}

function severityColor(severity: string): string {
  const map: Record<string, string> = {
    critical: 'error', high: 'deep-orange', medium: 'warning', low: 'info'
  }
  return map[severity] ?? 'grey'
}
</script>
