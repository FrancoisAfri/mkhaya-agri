<?php

return [

    // Global default brain — the free-first 'chain' for every module. It walks ~19
    // free / free-tier providers (config 'chat.chain') and only reaches paid OpenAI
    // when they're all exhausted, so AI cost across the whole system trends to ~zero.
    // Anything not pinned in 'overrides'/'task_overrides' below uses this.
    'provider' => env('AI_PROVIDER', 'chain'),

    'providers' => [

        'claude' => [
            'api_key'    => env('ANTHROPIC_API_KEY'),
            'model'      => env('CLAUDE_MODEL', 'claude-haiku-4-5'),
            'max_tokens' => 4096,
        ],

        'openai' => [
            'api_key'    => env('OPENAI_API_KEY'),
            'model'      => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => 4096,
        ],

        'gemini' => [
            'api_key'    => env('GEMINI_API_KEY'),
            'model'      => env('GEMINI_MODEL', 'gemini-3.6-flash'),
            'max_tokens' => 4096,
            'base_url'   => 'https://generativelanguage.googleapis.com/v1beta',
        ],

        'deepseek' => [
            'api_key'    => env('DEEPSEEK_API_KEY'),
            'model'      => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => 4096,
            'base_url'   => 'https://api.deepseek.com/v1',
        ],

        // "Local" tier — the cheap workhorse for routine, high-volume work.
        // Points at any OpenAI-compatible endpoint: a hosted open-model
        // provider (Groq / Together / OpenRouter / DeepSeek) or on-prem Ollama.
        // Examples — model names go stale, and a 404 from a provider reads as an outage
        // rather than a decommissioned name. `php artisan ai:diagnose` lists what each
        // configured key can actually reach.
        // Examples:
        //   Groq       base_url=https://api.groq.com/openai/v1        model=openai/gpt-oss-20b
        //   Together   base_url=https://api.together.xyz/v1           model=meta-llama/Llama-3.3-70B-Instruct-Turbo
        //   OpenRouter base_url=https://openrouter.ai/api/v1          model=meta-llama/llama-3.3-70b-instruct
        //   Ollama     base_url=http://127.0.0.1:11434/v1  (no key)   model=qwen2.5:7b-instruct
        'local' => [
            'api_key'       => env('LOCAL_AI_API_KEY'),
            'model'         => env('LOCAL_AI_MODEL', 'openai/gpt-oss-20b'),
            'base_url'      => env('LOCAL_AI_BASE_URL', 'https://api.groq.com/openai/v1'),
            'max_tokens'    => (int) env('LOCAL_AI_MAX_TOKENS', 4096),
            'supports_json' => env('LOCAL_AI_JSON', true),
            // If the local tier fails (rate-limit / outage), retry on this
            // provider so no feature breaks. Set null to disable the safety net.
            'fallback'      => env('LOCAL_AI_FALLBACK', 'openai'),
        ],

        // Conferences self-built client — routine meeting/agenda/minutes work.
        // Point it at Groq by default; falls back to OpenAI when local has no key.
        'conferences' => [
            'api_key'  => env('CONFERENCE_AI_API_KEY', env('LOCAL_AI_API_KEY')),
            'base_url' => env('CONFERENCE_AI_BASE_URL', env('LOCAL_AI_BASE_URL', 'https://api.groq.com/openai/v1')),
            'model'    => env('CONFERENCE_AI_MODEL', env('LOCAL_AI_MODEL', 'openai/gpt-oss-20b')),
        ],

        // CRM has its own self-built client (CrmAiService) — point it at the
        // same Groq tier by default so lead scoring, email drafting and survey
        // analysis run cheap too. Falls back to OpenAI when local has no key.
        // Groq also serves Whisper (whisper-large-v3) at /audio/transcriptions.
        'crm' => [
            'api_key'          => env('CRM_AI_API_KEY', env('LOCAL_AI_API_KEY')),
            'base_url'         => env('CRM_AI_BASE_URL', env('LOCAL_AI_BASE_URL', 'https://api.groq.com/openai/v1')),
            'model'            => env('CRM_AI_MODEL', env('LOCAL_AI_MODEL', 'openai/gpt-oss-20b')),
            'transcribe_model' => env('CRM_AI_TRANSCRIBE_MODEL', 'whisper-large-v3'),
        ],

        // Haiku — faster and cheaper; used for inline helpdesk suggestions
        'claude_haiku' => [
            'api_key'    => env('ANTHROPIC_API_KEY'),
            'model'      => env('HELPDESK_AI_MODEL', 'claude-haiku-4-5'),
            'max_tokens' => 1024,
        ],

        'mock' => [
            'fixture_path' => base_path('tests/Fixtures/AI'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Per-module provider defaults
    |--------------------------------------------------------------------------
    | The default brain for every AI call in a module. Routine, high-volume
    | modules can default to the cheap 'local' tier; a specific task can still
    | escalate to a stronger provider via 'task_overrides' below.
    */
    'overrides' => [
        // Everything now inherits the global free-first 'chain' by default. The
        // high-stakes legal modules still walk the chain (free models first) but its
        // last link is paid OpenAI, so they degrade to the strong model only when
        // every free provider is unavailable. Set the env vars to a specific provider
        // (e.g. 'openai') to force the strong model up-front for a module if needed.
        'policies'              => env('AI_POLICY_PROVIDER', 'chain'),
        'contracts'             => env('AI_CONTRACTS_PROVIDER', 'chain'),
        'disciplinary'          => env('AI_DISCIPLINARY_PROVIDER', 'chain'),
        'disciplinary_evidence' => env('AI_DISCIPLINARY_PROVIDER', 'chain'),
        'helpdesk'              => env('AI_HELPDESK_PROVIDER', 'chain'),
        // 'payroll' => 'deepseek',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-task routing (hybrid AI)
    |--------------------------------------------------------------------------
    | Route individual tasks to a specific provider regardless of the module
    | default. Key = "<module>.<task>"; the task is passed at the call site as
    | $options['task']. Use this to keep routine work on 'local' while sending
    | the genuinely hard, high-stakes reasoning to OpenAI.
    |
    | Anything not listed falls back to the module default in 'overrides'.
    */
    'task_overrides' => [
        // Everything routes through the free-first chain by default. The routine and
        // complex tiers both point at 'chain'; because the chain ends in paid OpenAI,
        // hard tasks still get the strong model when the free tiers can't serve them.
        // Set AI_TIER_COMPLEX=openai to force the strong model up-front for hard tasks.
        'tender_pursuit.summarize'    => env('AI_TIER_ROUTINE', 'chain'),
        'tender_pursuit.ocr_cleanup'  => env('AI_TIER_ROUTINE', 'chain'),
        'tender_pursuit.map_fields'   => env('AI_TIER_ROUTINE', 'chain'),
        'tender_pursuit.qualify'      => env('AI_TIER_COMPLEX', 'chain'),
        'tender_pursuit.evaluate'     => env('AI_TIER_COMPLEX', 'chain'),
        'tender_pursuit.proposal'     => env('AI_TIER_COMPLEX', 'chain'),
        'helpdesk.legal_reasoning'    => env('AI_TIER_COMPLEX', 'chain'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mkhaya assistant provider chain (cost control)
    |--------------------------------------------------------------------------
    | The in-app "Mkhaya" chat assistant answers most questions straight from the
    | system (the how-to knowledge base + live ERP data) with ZERO AI tokens. When
    | a question genuinely needs a language model, it walks this chain IN ORDER and
    | uses the FIRST provider that (a) has an API key configured and (b) returns a
    | valid answer. Every provider before 'openai' is a free / free-tier endpoint,
    | so the paid OpenAI is only ever reached once every free provider is missing a
    | key, rate-limited, or failing. Reorder or trim via AI_CHAT_CHAIN (CSV).
    */
    'chat' => [
        /*
         * Areas that try Claude FIRST (then fall through the rest of the chain as normal).
         *
         * Keys are the module names callers already pass: BaseModuleAI::$module for the driver
         * system, plus 'sheq', 'lms' and 'crm' for the three self-built clients that call the
         * chain directly. Anything not listed is untouched. A module only benefits if
         * ANTHROPIC_API_KEY is set — without it the claude link is skipped and nothing changes.
         * Override with AI_CLAUDE_FIRST (CSV); empty string turns Claude-first off everywhere.
         */
        'claude_first' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'AI_CLAUDE_FIRST',
            'sheq,helpdesk,crm,manufacturing,tender_pursuit,lms,content_marketing,ai_campaign_manager'
        ))))),

        'chain' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'AI_CHAT_CHAIN',
            'groq,cerebras,sambanova,nvidia,together,openrouter,deepinfra,novita,hyperbolic,scaleway,huggingface,gemini,mistral,deepseek,cohere,fireworks,cloudflare,ollama,openai'
        ))))),

        'temperature' => (float) env('AI_CHAT_TEMPERATURE', 0.3),
        'timeout'     => (int) env('AI_CHAT_TIMEOUT', 20),

        // driver: 'openai' = OpenAI-compatible /chat/completions ; 'gemini' = Google native.
        'providers' => [
            'groq' => [
                'driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1',
                'key' => env('GROQ_API_KEY'),
                'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
            ],
            'openrouter' => [
                'driver' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1',
                'key' => env('OPENROUTER_API_KEY'),
                'model' => env('OPENROUTER_MODEL', 'nvidia/nemotron-3.5-lightning:free'),
            ],
            'cerebras' => [
                'driver' => 'openai', 'base_url' => 'https://api.cerebras.ai/v1',
                'key' => env('CEREBRAS_API_KEY'),
                'model' => env('CEREBRAS_MODEL', 'gpt-oss-120b'),
            ],
            'gemini' => [
                'driver' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'key' => env('GEMINI_API_KEY'),
                'model' => env('GEMINI_FLASH_MODEL', 'gemini-3.6-flash'),
            ],
            'mistral' => [
                'driver' => 'openai', 'base_url' => 'https://api.mistral.ai/v1',
                'key' => env('MISTRAL_API_KEY'),
                'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            ],
            'together' => [
                'driver' => 'openai', 'base_url' => 'https://api.together.xyz/v1',
                'key' => env('TOGETHER_API_KEY'),
                'model' => env('TOGETHER_MODEL', 'meta-llama/Llama-3.3-70B-Instruct-Turbo-Free'),
            ],
            'deepseek' => [
                'driver' => 'openai', 'base_url' => 'https://api.deepseek.com/v1',
                'key' => env('DEEPSEEK_API_KEY'),
                'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            ],
            'github' => [
                // GitHub is retiring this service: the old host stopped resolving (a DNS timeout
                // on every single request, for a provider that can no longer answer) and the
                // current one returns 410 "github_models_retirement_brownout".
                //
                // `retired` rather than deletion, and honoured in code rather than only removed
                // from the default chain, because installations pin AI_CHAT_CHAIN in .env — a
                // default nobody reads would leave every existing install still calling it.
                'retired' => true,
                'driver' => 'openai', 'base_url' => env('GITHUB_MODELS_BASE_URL', 'https://models.github.ai/inference'),
                'key' => env('GITHUB_MODELS_TOKEN'),
                'model' => env('GITHUB_MODELS_MODEL', 'gpt-4o-mini'),
            ],
            'cohere' => [
                'driver' => 'openai', 'base_url' => 'https://api.cohere.ai/compatibility/v1',
                'key' => env('COHERE_API_KEY'),
                'model' => env('COHERE_MODEL', 'command-r-08-2024'),
            ],
            'fireworks' => [
                'driver' => 'openai', 'base_url' => 'https://api.fireworks.ai/inference/v1',
                'key' => env('FIREWORKS_API_KEY'),
                'model' => env('FIREWORKS_MODEL', 'accounts/fireworks/models/llama-v3p3-70b-instruct'),
            ],
            'sambanova' => [
                'driver' => 'openai', 'base_url' => 'https://api.sambanova.ai/v1',
                'key' => env('SAMBANOVA_API_KEY'),
                'model' => env('SAMBANOVA_MODEL', 'Meta-Llama-3.3-70B-Instruct'),
            ],
            'nvidia' => [
                'driver' => 'openai', 'base_url' => 'https://integrate.api.nvidia.com/v1',
                'key' => env('NVIDIA_API_KEY'),
                'model' => env('NVIDIA_MODEL', 'meta/llama-3.3-70b-instruct'),
            ],
            'deepinfra' => [
                'driver' => 'openai', 'base_url' => 'https://api.deepinfra.com/v1/openai',
                'key' => env('DEEPINFRA_API_KEY'),
                'model' => env('DEEPINFRA_MODEL', 'meta-llama/Llama-3.3-70B-Instruct'),
            ],
            'novita' => [
                'driver' => 'openai', 'base_url' => 'https://api.novita.ai/v3/openai',
                'key' => env('NOVITA_API_KEY'),
                'model' => env('NOVITA_MODEL', 'meta-llama/llama-3.3-70b-instruct'),
            ],
            'hyperbolic' => [
                'driver' => 'openai', 'base_url' => 'https://api.hyperbolic.xyz/v1',
                'key' => env('HYPERBOLIC_API_KEY'),
                'model' => env('HYPERBOLIC_MODEL', 'meta-llama/Llama-3.3-70B-Instruct'),
            ],
            'scaleway' => [
                'driver' => 'openai', 'base_url' => 'https://api.scaleway.ai/v1',
                'key' => env('SCALEWAY_API_KEY'),
                'model' => env('SCALEWAY_MODEL', 'llama-3.3-70b-instruct'),
            ],
            'huggingface' => [
                'driver' => 'openai', 'base_url' => 'https://router.huggingface.co/v1',
                'key' => env('HUGGINGFACE_API_KEY'),
                'model' => env('HUGGINGFACE_MODEL', 'meta-llama/Llama-3.3-70B-Instruct'),
            ],
            'cloudflare' => [
                'driver' => 'openai',
                'base_url' => 'https://api.cloudflare.com/client/v4/accounts/' . env('CLOUDFLARE_ACCOUNT_ID', '') . '/ai/v1',
                'key' => env('CLOUDFLARE_API_KEY'),
                'model' => env('CLOUDFLARE_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
            ],
            // Self-hosted Ollama — free & keyless. Only tried when a base URL is set.
            'ollama' => [
                'driver' => 'openai', 'base_url' => env('OLLAMA_BASE_URL', ''),
                'key' => env('OLLAMA_API_KEY', ''), 'keyless' => true,
                'model' => env('OLLAMA_MODEL', 'llama3.3'),
            ],
            // Anthropic native Messages API. Deliberately NOT in the default AI_CHAT_CHAIN: it is
            // reached only through 'claude_first' above (or by listing it in AI_CHAT_CHAIN).
            'anthropic' => [
                'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1',
                'key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_CHAT_MODEL', 'claude-haiku-4-5'),
            ],
            // Paid — LAST resort only.
            'openai' => [
                'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1',
                'key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            ],
        ],
    ],

    'audit' => [
        'enabled'        => env('AI_AUDIT_ENABLED', true),
        'log_prompts'    => env('AI_LOG_PROMPTS', true),
        'log_responses'  => env('AI_LOG_RESPONSES', true),
    ],

    'retry' => [
        'attempts' => 3,
        'sleep_ms' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-company AI token quota (SaaS)
    |--------------------------------------------------------------------------
    | Each company gets a monthly AI-token allowance from its plan (or a
    | per-company override). AI is expensive, so usage is metered and can be
    | capped. `default_quota` applies when a plan has no allowance set. Enforcement
    | mode ('strict' | 'grace') is stored in app_settings and toggled by a platform
    | admin; `grace_percent` is how far past 100% a 'grace' company may go once.
    */
    'quota' => [
        'enabled'            => env('AI_QUOTA_ENABLED', true),
        'default_quota'      => (int) env('AI_QUOTA_DEFAULT', 200000),
        'rand_per_1k_tokens' => (float) env('AI_QUOTA_RAND_PER_1K', 0.25),
        'warn_threshold'     => (float) env('AI_QUOTA_WARN_AT', 0.8), // banner at 80% used
        'grace_percent'      => (int) env('AI_QUOTA_GRACE_PERCENT', 10),
        // Instant-buy pricing: Rand per pack, and tokens per pack.
        'pack_tokens'        => (int) env('AI_QUOTA_PACK_TOKENS', 100000),
        'pack_price'         => (float) env('AI_QUOTA_PACK_PRICE', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI image generation (free-first, keyless by default)
    |--------------------------------------------------------------------------
    | The AI Image Generator (Content Marketing #12). Default provider is
    | Pollinations, which is free and needs no API key — the URL takes the prompt
    | in the path plus width/height query params and returns a JPEG. Swap the
    | endpoint (or point at a keyed provider) via env without code changes.
    */
    /*
     * Takes the tells out of machine-written copy: the vocabulary, the em dashes, the rule of
     * three, the closing paragraph about an evolving landscape. Applied to anything a customer
     * reads — articles, ad copy, subject lines, outreach — and never to the JSON analysis calls,
     * where the rules are about prose and cleaning would corrupt the payload.
     */
    'humanizer' => [
        'enabled' => env('AI_HUMANIZER', true),

        /*
         * Which modules' prose gets rewritten. Copy, not records.
         *
         * A helpdesk reply and a SHEQ incident note both come out of the same base class, and only
         * one of them is trying to read well. Rewriting the vocabulary of a record is a different
         * act from polishing an advert, so a module opts in rather than inheriting it.
         */
        'modules' => [
            'content_marketing',
            'ai_campaign_manager',
            'crm',
            'marketplace',
        ],
    ],

    'image' => [
        'provider' => env('AI_IMAGE_PROVIDER', 'pollinations'),
        'url'      => env('AI_IMAGE_URL', 'https://image.pollinations.ai/prompt/'),
        'token'    => env('AI_IMAGE_TOKEN'), // optional bearer for keyed providers
        'model'    => env('AI_IMAGE_MODEL', 'flux'),
        'timeout'  => (int) env('AI_IMAGE_TIMEOUT', 60),

        /*
         * Tried in order until one returns an image, the same way the text chain works. Images had
         * a single hardcoded endpoint, so a provider outage produced "the image service is busy"
         * and nothing else — no fallback, and no per-tenant key.
         */
        'providers' => array_values(array_filter([
            /*
             * Cloudflare Workers AI first, on the same account as the text chain.
             *
             * The keyless provider below ignores the requested size entirely: ask it for
             * 1216×640 or 2432×1280 and it returns 1058×557 either way, at around 40KB — under a
             * tenth of a bit per pixel. Displayed at the width an article hero actually occupies
             * that is upscaled and visibly soft, which is the "blurry images" complaint. Flux on
             * Workers AI returns 1024×1024 at ~450KB for the same prompt: fixed square, so it is
             * cropped to the preset's aspect, and still far more detail than the alternative.
             */
            env('CLOUDFLARE_ACCOUNT_ID') && env('CLOUDFLARE_API_KEY') ? [
                'name'   => 'cloudflare',
                'driver' => 'cloudflare',
                'url'    => 'https://api.cloudflare.com/client/v4/accounts/' . env('CLOUDFLARE_ACCOUNT_ID', '') . '/ai/run/',
                'token'  => env('CLOUDFLARE_API_KEY'),
                'model'  => env('CLOUDFLARE_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
            ] : null,
            [
                'name'  => env('AI_IMAGE_PROVIDER', 'pollinations'),
                'url'   => env('AI_IMAGE_URL', 'https://image.pollinations.ai/prompt/'),
                'token' => env('AI_IMAGE_TOKEN'),
                'model' => env('AI_IMAGE_MODEL', 'flux'),
            ],
            env('AI_IMAGE_URL_2') ? [
                'name'  => env('AI_IMAGE_PROVIDER_2', 'secondary'),
                'url'   => env('AI_IMAGE_URL_2'),
                'token' => env('AI_IMAGE_TOKEN_2'),
                'model' => env('AI_IMAGE_MODEL_2'),
            ] : null,
        ])),

        /*
         * The standard a generated image has to meet before it is attached to an article.
         * Free models are inconsistent, so each attempt is measured and a failing one re-rolled.
         * Raise min_score to be stricter; every attempt costs one more call to the provider.
         * See ImageQualityService for what each measure means.
         */
        'quality' => [
            'attempts'      => (int) env('AI_IMAGE_ATTEMPTS', 3),
            'min_score'     => (int) env('AI_IMAGE_MIN_QUALITY', 60),
            'sharpness'     => (float) env('AI_IMAGE_MIN_SHARPNESS', 120),
            'detail'        => (float) env('AI_IMAGE_MIN_DETAIL', 18),
            'colourfulness' => (float) env('AI_IMAGE_MIN_COLOUR', 12),
            // Raised from 0.06, which passed a 1058×557 hero weighing 40KB — 0.069 bytes per
            // pixel — with a perfect score. Real photographic detail does not compress that far.
            'density'       => (float) env('AI_IMAGE_MIN_DENSITY', 0.15),
            'dimension_tolerance' => 0.15,
            // A hero narrower than this gets upscaled by the theme, which is what "blurry" looks
            // like from the reader's side.
            'min_width'     => (int) env('AI_IMAGE_MIN_WIDTH', 1000),
        ],
    ],

];
