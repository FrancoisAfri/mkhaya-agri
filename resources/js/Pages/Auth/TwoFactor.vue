<script lang="ts" setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import './login.css'

const props = defineProps<{
  phoneMasked: string | null
}>()

// ── Timer ──────────────────────────────────────────────────────────────────
const timerDisplay = ref('10:00')
let   timerSeconds = 600
let   timerInterval: ReturnType<typeof setInterval> | null = null

function startTimer() {
  timerSeconds = 600
  timerDisplay.value = '10:00'
  if (timerInterval) clearInterval(timerInterval)
  timerInterval = setInterval(() => {
    timerSeconds--
    const m = Math.floor(timerSeconds / 60).toString().padStart(2, '0')
    const s = (timerSeconds % 60).toString().padStart(2, '0')
    timerDisplay.value = `${m}:${s}`
    if (timerSeconds <= 0) {
      clearInterval(timerInterval!)
      otpError.value = 'OTP expired. Please request a new one.'
    }
  }, 1000)
}

onMounted(startTimer)
onBeforeUnmount(() => { if (timerInterval) clearInterval(timerInterval) })

// ── State ──────────────────────────────────────────────────────────────────
const otpError   = ref('')
const otpSuccess = ref('')
const resending  = ref(false)

// ── Form ───────────────────────────────────────────────────────────────────
const form = useForm({ otp: '' })

const digits    = ref(['', '', '', '', '', ''])
const inputRefs = ref<HTMLInputElement[]>([])

function onDigitInput(index: number, e: Event) {
  const input = e.target as HTMLInputElement
  const val   = input.value.replace(/\D/g, '').slice(-1)
  digits.value[index] = val
  form.otp = digits.value.join('')
  if (val && index < 5) inputRefs.value[index + 1]?.focus()
}

function onDigitKeydown(index: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !digits.value[index] && index > 0) inputRefs.value[index - 1]?.focus()
  if (e.key === 'ArrowLeft'  && index > 0) inputRefs.value[index - 1]?.focus()
  if (e.key === 'ArrowRight' && index < 5) inputRefs.value[index + 1]?.focus()
}

function onDigitPaste(e: ClipboardEvent) {
  e.preventDefault()
  const pasted = (e.clipboardData?.getData('text') ?? '').replace(/\D/g, '').slice(0, 6)
  pasted.split('').forEach((ch, i) => { digits.value[i] = ch })
  form.otp = digits.value.join('')
  inputRefs.value[Math.min(pasted.length, 5)]?.focus()
}

function submitOtp() {
  otpError.value = ''
  if (form.otp.length < 6) { otpError.value = 'Please enter all 6 digits.'; return }
  form.post('/2fa', {
    onError: (errors) => {
      otpError.value = errors.otp ?? 'Verification failed.'
      digits.value = ['', '', '', '', '', '']
      form.otp = ''
      inputRefs.value[0]?.focus()
    },
  })
}

// ── Resend ─────────────────────────────────────────────────────────────────
const page = usePage()

function resendOtp() {
  otpError.value   = ''
  otpSuccess.value = ''
  resending.value  = true
  router.post('/2fa/resend', {}, {
    preserveScroll: true,
    preserveState:  true,
    onFinish: () => { resending.value = false },
  })
}

watch(() => page.props.twoFactorResult as any, (result) => {
  if (!result) return
  if (result.success) { otpSuccess.value = result.message; startTimer() }
  else { otpError.value = result.message }
})

function cancelLogin() { router.get('/login') }
</script>

<script lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue'
export default { layout: AuthLayout }
</script>

<template>
  <div class="authentication-bg">
    <div id="tsparticles" class="absolute inset-0"></div>

    <div class="container-fluid">
      <div class="row">

        <!-- ── Left: 2FA form ─────────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-left">
          <div class="w-100" style="max-width: 420px">

            <!-- Logo -->
            <div class="text-center mb-4">
              <a href="https://mkhayamk.co.za/" style="text-decoration:none;color:inherit">
                Powered by
                <img src="/images/logo_default.png" alt="Logo" style="height:150px;display:block;margin:0 auto" />
              </a>
            </div>

            <!-- Shield + title -->
            <div class="text-center mb-4">
              <div style="
                display:inline-flex;align-items:center;justify-content:center;
                width:70px;height:70px;border-radius:50%;
                background:linear-gradient(135deg,#ffd700 0%,#e6c200 100%);
                margin-bottom:14px;
                box-shadow:0 8px 24px rgba(255,215,0,.4)
              ">
                <i class="bi bi-shield-lock-fill" style="font-size:30px;color:#5e2b8c"></i>
              </div>
              <h3 class="login-title mb-2">Two-Factor Verification</h3>
              <p style="color:#fff;font-size:0.9rem;margin-bottom:0">
                A 6-digit code was sent to
                <strong style="color:#ffd700">
                  {{ phoneMasked ?? 'your email address' }}
                </strong>
              </p>
            </div>

            <!-- Alerts -->
            <div v-if="otpError" class="alert alert-danger py-2 small mb-3">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ otpError }}
            </div>
            <div v-if="otpSuccess" class="alert alert-success py-2 small mb-3">
              <i class="bi bi-check-circle-fill me-2"></i>{{ otpSuccess }}
            </div>

            <!-- OTP digit boxes -->
            <form @submit.prevent="submitOtp">
              <label style="color:#ffd700;font-size:0.85rem;font-weight:600;display:block;text-align:center;margin-bottom:10px">
                Enter your 6-digit OTP
              </label>

              <div class="d-flex justify-content-center mb-2" style="gap:8px">
                <input
                  v-for="(_, i) in 6"
                  :key="i"
                  :ref="el => inputRefs[i] = (el as HTMLInputElement)"
                  type="text"
                  inputmode="numeric"
                  maxlength="1"
                  :value="digits[i]"
                  class="form-control text-center fw-bold"
                  style="
                    width:50px;height:54px;font-size:22px;
                    border:2px solid rgba(255,255,255,0.3);
                    border-radius:10px;
                    background:rgba(255,255,255,0.92);
                    color:#5e2b8c;
                    transition:border-color .15s,box-shadow .15s
                  "
                  :style="digits[i] ? 'border-color:#ffd700;box-shadow:0 0 0 3px rgba(255,215,0,.25)' : ''"
                  @input="onDigitInput(i, $event)"
                  @keydown="onDigitKeydown(i, $event)"
                  @paste="onDigitPaste"
                  @focus="($event.target as HTMLInputElement).select()"
                  :disabled="form.processing"
                />
              </div>

              <!-- Timer -->
              <div class="text-center mb-4">
                <span class="otp-timer">
                  Code expires in:&nbsp;
                  <strong :style="timerSeconds <= 60 ? 'color:#ff6b6b' : 'color:#ffd700'">
                    {{ timerDisplay }}
                  </strong>
                </span>
              </div>

              <!-- Verify button -->
              <button
                type="submit"
                class="btn btn-primary btn-block w-100 mb-3 fs-5"
                :disabled="form.processing || form.otp.length < 6"
              >
                <span v-if="form.processing">
                  <span class="spinner-border spinner-border-sm me-2"></span>Verifying…
                </span>
                <span v-else>
                  <i class="bi bi-shield-check me-2"></i>Verify &amp; Sign In
                </span>
              </button>

              <!-- Resend + Cancel -->
              <div class="d-flex justify-content-between align-items-center mt-1">
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0"
                  style="color:#ffd700;font-size:13px;text-decoration:none"
                  @click="resendOtp"
                  :disabled="resending || form.processing"
                >
                  <span v-if="resending">
                    <span class="spinner-border spinner-border-sm me-1" style="width:12px;height:12px"></span>Sending…
                  </span>
                  <span v-else><i class="bi bi-arrow-clockwise me-1"></i>Resend OTP</span>
                </button>

                <button
                  type="button"
                  class="btn btn-link btn-sm p-0"
                  style="color:rgba(255,255,255,0.7);font-size:13px;text-decoration:none"
                  @click="cancelLogin"
                >
                  <i class="bi bi-arrow-left me-1"></i>Back to Login
                </button>
              </div>

            </form>

            <!-- Security note -->
            <div class="mt-4 p-3 rounded" style="background:rgba(255,215,0,0.08);border:1px solid rgba(255,215,0,0.25)">
              <p class="small mb-0" style="color:#ffd700">
                <i class="bi bi-info-circle me-1"></i>
                Never share your OTP with anyone. Mkhaya Hub will never ask for it.
              </p>
            </div>

          </div>
        </div>

        <!-- ── Right: Carousel ────────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-right">
          <div class="top-content text-center mb-4">
            <h4 class="text-white">The Future Does Not Wait, Neither Should Your Business</h4>
            <p class="text-white">
              At Mkhaya, we believe technology should do more than support your business.
              It should accelerate it.
            </p>
          </div>
          <div class="bottom-carousel">
            <div id="twoFaCarousel" class="carousel slide w-100" data-bs-ride="carousel">
              <div class="carousel-inner rounded">
                <div class="carousel-item active">
                  <a href="https://mkhayamk.co.za/" target="_blank">
                    <img src="/images/IT Support.jpeg" class="d-block w-100" alt="IT Support" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/website-design-services/" target="_blank">
                    <img src="/images/Website.jpeg" class="d-block w-100" alt="Website" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/software-engineering/" target="_blank">
                    <img src="/images/Custom Solutions.jpeg" class="d-block w-100" alt="Custom Solutions" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/mkhaya-move/" target="_blank">
                    <img src="/images/Mkhaya Move.jpeg" class="d-block w-100" alt="Mkhaya Move" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
              </div>
              <button class="carousel-control-prev" type="button" data-bs-target="#twoFaCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#twoFaCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>
