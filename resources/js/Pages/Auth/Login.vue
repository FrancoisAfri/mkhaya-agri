<script lang="ts" setup>
import { ref } from "vue";
import { onMounted } from "vue";
import { useForm,router,usePage  } from "@inertiajs/vue3";
import "./login.css";
import { route } from "ziggy-js";
import { watch } from "vue";


interface LoginProps {
    redirect?: string;
    boardModuleActive?: boolean;
}

const props = withDefaults(defineProps<LoginProps>(), {
    boardModuleActive: false,
});

const loginType = ref("password");
const showPassword = ref(false);

// OTP state
const otpSent = ref(false);
const otpLoading = ref(false);
const otpError = ref("");
const otpSuccess = ref("");
const timerDisplay = ref("05:00");
let timerInterval: ReturnType<typeof setInterval> | null = null;

function switchLoginType(type: string) {
    loginType.value = type;
}

// Password form
const form = useForm({
    email: "",
    password: "",
    remember: false,
});

function submitLogin() {
    form.post(route("auth.login"), {
        onSuccess: (page: any) => {
            const props = page.props as LoginProps;
            const redirect = props.redirect ?? "/";
            // window.location.href = redirect;
            //  router.visit(redirect);
        },
    });
}

function togglePassword() {
    showPassword.value = !showPassword.value;
}

// OTP form
const otpForm = useForm({
    email: "",
    otp: "",
    remember_otp: false,
});

function startTimer() {
    let seconds = 300; // 5 minutes
    timerDisplay.value = "05:00";
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(() => {
        seconds--;
        const m = Math.floor(seconds / 60)
            .toString()
            .padStart(2, "0");
        const s = (seconds % 60).toString().padStart(2, "0");
        timerDisplay.value = `${m}:${s}`;
        if (seconds <= 0) {
            clearInterval(timerInterval!);
            otpSent.value = false;
            otpError.value = "OTP expired. Please request a new one.";
        }
    }, 1000);
}

async function sendOTP() {
    otpError.value = "";
    otpSuccess.value = "";

    if (!otpForm.email) {
        otpError.value = "Please enter your email address.";
        return;
    }

    otpLoading.value = true;

    router.post(route("send.otp"),
        { email: otpForm.email },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: any) => {
                const data = page.props.otpResult;
                if (data?.success) {
                    otpSent.value = true;
                    otpSuccess.value = "OTP sent successfully to your email!";
                    startTimer();
                } else {
                    otpError.value = data?.message ?? "Failed to send OTP.";
                }
                otpLoading.value = false;
            },
            onError: (errors: any) => {
                otpError.value = errors?.email ?? errors?.message ?? "Failed to send OTP.";
                otpLoading.value = false;
            },
            onFinish: () => {
                otpLoading.value = false;
            }
        }
    );
}

function submitOtp() {
    otpForm.post(route("auth.otp.login"), {
        onSuccess: (page: any) => {
            const props = page.props as LoginProps;
            const redirect = props.redirect ?? "/";
            // window.location.href = redirect;
            //  router.visit(redirect);
        },
        onError: () => {
            otpError.value =
                otpForm.errors.otp ??
                otpForm.errors.email ??
                "Verification failed.";
        },
    });
}
onMounted(() => {
  // @ts-ignore
  if (typeof tsParticles !== 'undefined') {
    // @ts-ignore
    tsParticles.load("tsparticles", {
      background: { color: { value: "transparent" } },
      particles: {
        number: { value: 80 },
        color: { value: "#ffffff" },
        links: { enable: true, color: "#ffffff" },
        move: { enable: true, speed: 2 },
      },
    })
  }
})

const page = usePage();

// Watcher pour récupérer la réponse après le post
watch(() => page.props.otpResult as any, (result) => {
    if (!result) return;
    if (result.success) {
        otpSent.value = true;
        otpSuccess.value = result.message;
        startTimer();
    } else {
        otpError.value = result.message;
    }
    otpLoading.value = false;
});
</script>
<script lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue'

export default {
  layout: AuthLayout
}
</script>

<template>
    <div class="authentication-bg">
        <div id="tsparticles" class="absolute inset-0"></div>

        <div class="container-fluid">
            <div class="row">
                <!-- Left: Login Form -->
                <div class="col-12 col-md-6 login-left">
                    <div class="w-100" style="max-width: 400px">
                        <div class="text-center mb-4">
                            <a
                                href="https://mkhayamk.co.za/"
                                style="text-decoration: none; color: inherit"
                            >
                                Powered by
                                <img
                                    src="/images/logo_default.png"
                                    alt="Powered By Logo"
                                    height="50"
                                    style="
                                        height: 150px;
                                        justify-content: center;
                                        align-items: center;
                                    "
                                />
                            </a>
                        </div>
                        <h3 class="mb-3 login-title">Sign in</h3>

                        <!-- Login Type Tabs -->
                        <div class="login-tabs">
                            <div
                                class="login-tab"
                                :class="{ active: loginType === 'password' }"
                                @click="switchLoginType('password')"
                            >
                                <i class="bi bi-key-fill me-2"></i>Password
                            </div>
                            <div
                                class="login-tab"
                                :class="{ active: loginType === 'otp' }"
                                @click="switchLoginType('otp')"
                            >
                                <i class="bi bi-shield-lock-fill me-2"></i>OTP
                            </div>
                        </div>

                        <!-- Password Form -->
                        <form
                            v-show="loginType === 'password'"
                            @submit.prevent="submitLogin"
                        >
                            <div class="form-group mb-3">
                                <label for="username">E-mail</label>
                                <input
                                    id="username"
                                    type="email"
                                    class="form-control"
                                    v-model="form.email"
                                />
                                <div
                                    v-if="form.errors.email"
                                    class="text-danger small"
                                >
                                    {{ form.errors.email }}
                                </div>
                            </div>
                            <div class="form-group mb-3">
                                <label for="password">Password</label>
                                <div class="input-group">
                                    <input
                                        id="password"
                                        :type="
                                            showPassword ? 'text' : 'password'
                                        "
                                        class="form-control"
                                        v-model="form.password"
                                    />
                                    <div class="input-group-text">
                                        <button
                                            type="button"
                                            @click="togglePassword()"
                                            style="
                                                background: none;
                                                border: none;
                                                cursor: pointer;
                                            "
                                        >
                                            <i
                                                :class="
                                                    showPassword
                                                        ? 'bi bi-eye-slash-fill'
                                                        : 'bi bi-eye-fill'
                                                "
                                            ></i>
                                        </button>
                                    </div>
                                </div>
                                <div
                                    v-if="form.errors.password"
                                    class="text-danger small"
                                >
                                    {{ form.errors.password }}
                                </div>
                            </div>
                            <div
                                class="form-group form-check mb-3 d-flex justify-content-between align-items-center"
                            >
                                <div>
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="form.remember"
                                    />
                                    <label class="form-check-label"
                                        >Remember me</label
                                    >
                                </div>
                                <a href="/forgot-password" class="forgot-link">Forgot Password?</a>
                            </div>
                            <button
                                type="submit"
                                class="btn btn-primary btn-block mb-3 w-100 text-white fs-4"
                                :disabled="form.processing"
                            >
                                <span v-if="form.processing">
                                    <span
                                        class="spinner-border spinner-border-sm me-2"
                                    ></span
                                    >Signing in...
                                </span>
                                <span v-else>Sign in</span>
                            </button>
                        </form>

                        <!-- OTP Form -->
                        <form
                            v-show="loginType === 'otp'"
                            @submit.prevent="submitOtp"
                        >
                            <div class="form-group mb-3">
                                <label for="otp_email">E-mail</label>
                                <input
                                    id="otp_email"
                                    type="email"
                                    class="form-control"
                                    v-model="otpForm.email"
                                    :disabled="otpSent"
                                />
                                <div
                                    v-if="otpForm.errors.email"
                                    class="text-danger small"
                                >
                                    {{ otpForm.errors.email }}
                                </div>
                            </div>

                            <!-- Error / Success messages -->
                            <div
                                v-if="otpError"
                                class="alert alert-danger py-2 small"
                            >
                                {{ otpError }}
                            </div>
                            <div
                                v-if="otpSuccess"
                                class="alert alert-success py-2 small"
                            >
                                {{ otpSuccess }}
                            </div>

                            <!-- OTP Input — visible après envoi -->
                            <div v-if="otpSent" class="form-group mb-3">
                                <label for="otp">Enter OTP</label>
                                <input
                                    id="otp"
                                    type="text"
                                    class="form-control"
                                    v-model="otpForm.otp"
                                    maxlength="6"
                                    placeholder="Enter 6-digit OTP"
                                />
                                <div class="otp-timer mt-1 small text-muted">
                                    OTP expires in:
                                    <strong>{{ timerDisplay }}</strong>
                                </div>
                                <div
                                    v-if="otpForm.errors.otp"
                                    class="text-danger small"
                                >
                                    {{ otpForm.errors.otp }}
                                </div>
                            </div>

                            <div
                                class="form-group form-check mb-3"
                                v-if="otpSent"
                            >
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    v-model="otpForm.remember_otp"
                                />
                                <label class="form-check-label"
                                    >Remember me</label
                                >
                            </div>

                            <div class="d-grid gap-2">
                                <!-- Send OTP button — visible tant que OTP pas encore envoyé -->
                                <button
                                    v-if="!otpSent"
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="sendOTP"
                                    :disabled="otpLoading"
                                >
                                    <span v-if="otpLoading">
                                        <span
                                            class="spinner-border spinner-border-sm me-2"
                                        ></span
                                        >Sending...
                                    </span>
                                    <span v-else>Send OTP</span>
                                </button>

                                <!-- Verify button — visible après envoi OTP -->
                                <button
                                    v-if="otpSent"
                                    type="submit"
                                    class="btn btn-primary"
                                    :disabled="otpForm.processing"
                                >
                                    <span v-if="otpForm.processing">
                                        <span
                                            class="spinner-border spinner-border-sm me-2"
                                        ></span
                                        >Verifying...
                                    </span>
                                    <span v-else>Verify & Sign in</span>
                                </button>

                                <!-- Resend OTP -->
                                <button
                                    v-if="otpSent"
                                    type="button"
                                    class="btn btn-link btn-sm text-muted"
                                    @click="sendOTP"
                                    :disabled="otpLoading"
                                >
                                    Resend OTP
                                </button>
                            </div>
                        </form>

                        <!-- ── Azure AD SSO (board members only) ────────────── -->
                        <div v-if="props.boardModuleActive" class="mt-4">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <hr class="flex-grow-1" style="border-color:rgba(255,255,255,0.2)">
                                <span style="color:rgba(255,255,255,0.5);font-size:0.8rem;white-space:nowrap">or sign in with</span>
                                <hr class="flex-grow-1" style="border-color:rgba(255,255,255,0.2)">
                            </div>
                            <a
                                :href="route('auth.azure.redirect')"
                                class="btn w-100 d-flex align-items-center justify-content-center gap-2"
                                style="
                                    background:#fff;color:#333;border:1px solid rgba(255,255,255,0.3);
                                    border-radius:8px;padding:10px 16px;font-size:0.9rem;font-weight:500;
                                    text-decoration:none;transition:background .15s
                                "
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 21 21">
                                    <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
                                    <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
                                    <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
                                    <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
                                </svg>
                                Sign in with Microsoft
                            </a>
                            <p class="text-center mt-2" style="color:rgba(255,255,255,0.45);font-size:0.75rem">
                                For board members using Azure Active Directory
                            </p>
                        </div>
                        <!-- ─────────────────────────────────────────────────── -->

                    </div>
                </div>

                <!-- Right: Text + Carousel -->
                <div class="col-12 col-md-6 login-right">
                    <div class="top-content text-center mb-4">
                        <h4 class="text-white">
                            The Future Does Not wait, Neither Should Your
                            Business
                        </h4>
                        <h7 class="text-white">
                            At Mkhaya, we believe technology should do more than
                            support your business. It should accelerate it.
                            Because transformation isn't about adopting more
                            tools. It's about creating smarter ways to solve
                            business solutions.
                        </h7>
                    </div>
                    <div class="bottom-carousel">
                        <div
                            id="loginCarousel"
                            class="carousel slide w-100"
                            data-bs-ride="carousel"
                        >
                            <div class="carousel-inner rounded">
                                <div class="carousel-item active">
                                    <a
                                        href="https://mkhayamk.co.za/"
                                        target="_blank"
                                    >
                                        <img
                                            src="/images/IT Support.jpeg"
                                            class="d-block w-100"
                                            alt="IT Support"
                                        />
                                        <div class="carousel-overlay">
                                            <span>Visit Website</span>
                                        </div>
                                    </a>
                                </div>
                                <div class="carousel-item">
                                    <a
                                        href="https://mkhayamk.co.za/website-design-services/"
                                        target="_blank"
                                    >
                                        <img
                                            src="/images/Website.jpeg"
                                            class="d-block w-100"
                                            alt="Website"
                                        />
                                        <div class="carousel-overlay">
                                            <span>Visit Website</span>
                                        </div>
                                    </a>
                                </div>
                                <div class="carousel-item">
                                    <a
                                        href="https://mkhayamk.co.za/software-engineering/"
                                        target="_blank"
                                    >
                                        <img
                                            src="/images/Custom Solutions.jpeg"
                                            class="d-block w-100"
                                            alt="Custom Solutions"
                                        />
                                        <div class="carousel-overlay">
                                            <span>Visit Website</span>
                                        </div>
                                    </a>
                                </div>
                                <div class="carousel-item">
                                    <a
                                        href="https://mkhayamk.co.za/mkhaya-move/"
                                        target="_blank"
                                    >
                                        <img
                                            src="/images/Mkhaya Move.jpeg"
                                            class="d-block w-100"
                                            alt="Mkhaya Move"
                                        />
                                        <div class="carousel-overlay">
                                            <span>Visit Website</span>
                                        </div>
                                    </a>
                                </div>
                                <div class="carousel-item">
                                    <a
                                        href="https://mkhayamk.co.za/optimate/"
                                        target="_blank"
                                    >
                                        <img
                                            src="/images/Optimate.jpeg"
                                            class="d-block w-100"
                                            alt="Optimate"
                                        />
                                        <div class="carousel-overlay">
                                            <span>Visit Website</span>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            <button
                                class="carousel-control-prev"
                                type="button"
                                data-bs-target="#loginCarousel"
                                data-bs-slide="prev"
                            >
                                <span
                                    class="carousel-control-prev-icon"
                                    aria-hidden="true"
                                ></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button
                                class="carousel-control-next"
                                type="button"
                                data-bs-target="#loginCarousel"
                                data-bs-slide="next"
                            >
                                <span
                                    class="carousel-control-next-icon"
                                    aria-hidden="true"
                                ></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
