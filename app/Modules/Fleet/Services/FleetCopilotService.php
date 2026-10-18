<?php

namespace App\Modules\Fleet\Services;

use App\Core\AI\Models\AiChatMessage;
use App\Core\AI\Models\AiChatSession;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FleetCopilotService
{
    private const MODULE = 'fleet';
    private const MAX_HISTORY = 12;
    private const TIMEOUT = 30;

    // ── Tool definitions (FR-4.4.3) ────────────────────────────────────────────

    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'query_vehicles',
                'description' => 'Query the fleet vehicle register. Returns vehicles with status, odometer, driver assignments.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['available', 'in_use', 'maintenance', 'booked', 'out_of_service'], 'description' => 'Filter by status'],
                        'search' => ['type' => 'string', 'description' => 'Search by registration, make, model'],
                    ],
                ],
            ],
            [
                'name'        => 'query_bookings',
                'description' => 'Query fleet bookings with workflow state, driver, vehicle, dates.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'state'  => ['type' => 'string', 'enum' => ['pending', 'approved', 'in_use', 'returned', 'cancelled']],
                        'limit'  => ['type' => 'integer', 'default' => 10],
                    ],
                ],
            ],
            [
                'name'        => 'query_documents',
                'description' => 'Query fleet vehicle documents (licences, permits, roadworthy). Useful for expiry checks.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'expiring_within_days' => ['type' => 'integer', 'description' => 'Only return documents expiring within N days'],
                    ],
                ],
            ],
            [
                'name'        => 'query_costs',
                'description' => 'Summarise fleet fuel and maintenance costs for a period.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'period_days' => ['type' => 'integer', 'default' => 30, 'description' => 'Number of days to look back'],
                    ],
                ],
            ],
            [
                'name'        => 'get_expiring_items',
                'description' => 'Get all items expiring soon: documents, driver licences, insurance.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'within_days' => ['type' => 'integer', 'default' => 30],
                    ],
                ],
            ],
            // Action tools (FR-4.4.6) — require user confirmation in UI
            [
                'name'        => 'approve_booking',
                'description' => 'Approve a pending fleet booking. Always ask user to confirm before calling this.',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['booking_id'],
                    'properties' => [
                        'booking_id' => ['type' => 'integer'],
                        'comments'   => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'create_booking',
                'description' => 'Create a new fleet booking. Always ask user to confirm before calling this.',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['vehicle_id', 'driver_id', 'purpose', 'departure_at', 'expected_return_at'],
                    'properties' => [
                        'vehicle_id'         => ['type' => 'integer'],
                        'driver_id'          => ['type' => 'integer'],
                        'purpose'            => ['type' => 'string'],
                        'departure_at'       => ['type' => 'string', 'description' => 'ISO 8601 datetime'],
                        'expected_return_at' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'mark_key_lost',
                'description' => 'Mark a vehicle key as lost and set vehicle status to out_of_service.',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['vehicle_id'],
                    'properties' => [
                        'vehicle_id' => ['type' => 'integer'],
                        'reason'     => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    // ── Main chat entry point ──────────────────────────────────────────────────

    public function chat(string $message, ?string $sessionId, string $provider = 'local'): array
    {
        $user = Auth::user();

        $this->rateLimit($user->id);

        $sessionId = $sessionId ?? Str::uuid()->toString();

        AiChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['user_id' => $user->id, 'title' => Str::limit($message, 60)]
        );

        AiChatMessage::create([
            'user_id'    => $user->id,
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $message,
            'metadata'   => ['module' => self::MODULE, 'provider' => $provider],
        ]);

        $history     = $this->buildHistory($sessionId);
        $systemPrompt = $this->buildSystemPrompt($user);

        $responseText = match ($provider) {
            // Free-first chain by default; explicit provider names still force one.
            'chain', 'local' => $this->callChain($systemPrompt, $history, $message),
            'claude'   => $this->callClaude($systemPrompt, $history, $message, $user),
            'openai'   => $this->callOpenAI($systemPrompt, $history, $message),
            'gemini'   => $this->callGemini($systemPrompt, $history, $message),
            'deepseek' => $this->callDeepSeek($systemPrompt, $history, $message),
            default    => $this->callChain($systemPrompt, $history, $message),
        };

        $assistant = AiChatMessage::create([
            'user_id'    => $user->id,
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $responseText,
            'metadata'   => ['module' => self::MODULE, 'provider' => $provider],
        ]);

        return [
            'session_id' => $sessionId,
            'message'    => [
                'id'        => $assistant->id,
                'role'      => 'assistant',
                'content'   => $assistant->content,
                'timestamp' => $assistant->created_at->toISOString(),
            ],
        ];
    }

    // ── Claude with tool-calling loop (FR-4.4.2, FR-4.4.3) ───────────────────

    private function callClaude(string $systemPrompt, array $history, string $message, User $user): string
    {
        $apiKey  = config('ai.providers.claude.api_key');
        $model   = config('ai.providers.claude.model', 'claude-3-5-sonnet-20241022');

        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $maxIter = 5; // prevent infinite tool loops

        for ($i = 0; $i < $maxIter; $i++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->timeout(self::TIMEOUT)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 2048,
                    'system'     => $systemPrompt,
                    'tools'      => $this->toolDefinitions(),
                    'messages'   => $messages,
                ])->throw()->json();

                $stopReason = $response['stop_reason'] ?? 'end_turn';
                $content    = $response['content'] ?? [];

                // Collect text blocks
                $textBlocks = collect($content)->where('type', 'text')->pluck('text')->implode("\n");

                // Check for tool_use blocks
                $toolUseBlocks = collect($content)->where('type', 'tool_use');

                if ($toolUseBlocks->isEmpty() || $stopReason === 'end_turn') {
                    return $textBlocks ?: 'I could not generate a response.';
                }

                // Append assistant turn with tool use
                $messages[] = ['role' => 'assistant', 'content' => $content];

                // Execute tools and collect results
                $toolResults = [];
                foreach ($toolUseBlocks as $block) {
                    $result = $this->executeTool($block['name'], $block['input'] ?? [], $user);
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'     => json_encode($result),
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];

            } catch (\Throwable $e) {
                Log::error('Fleet copilot (Claude) failed', ['error' => $e->getMessage()]);
                return '⚠️ AI service unavailable. Please try again.';
            }
        }

        return 'I was unable to complete the request after multiple attempts.';
    }

    // ── OpenAI with function calling ───────────────────────────────────────────

    private function callOpenAI(string $systemPrompt, array $history, string $message): string
    {
        $apiKey = config('ai.providers.openai.api_key');
        $model  = config('ai.providers.openai.model', 'gpt-4o');

        // Convert Anthropic tool format to OpenAI format
        $tools = array_map(fn($t) => [
            'type'     => 'function',
            'function' => [
                'name'        => $t['name'],
                'description' => $t['description'],
                'parameters'  => $t['input_schema'],
            ],
        ], $this->toolDefinitions());

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $maxIter = 5;

        for ($i = 0; $i < $maxIter; $i++) {
            try {
                $response = Http::withToken($apiKey)->timeout(self::TIMEOUT)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model'    => $model,
                        'messages' => $messages,
                        'tools'    => $tools,
                    ])->throw()->json();

                $choice    = $response['choices'][0] ?? [];
                $msg       = $choice['message'] ?? [];
                $toolCalls = $msg['tool_calls'] ?? [];

                if (empty($toolCalls) || ($choice['finish_reason'] ?? '') === 'stop') {
                    return $msg['content'] ?? 'No response.';
                }

                $messages[] = $msg;

                foreach ($toolCalls as $call) {
                    $args   = json_decode($call['function']['arguments'] ?? '{}', true);
                    $result = $this->executeTool($call['function']['name'], $args, Auth::user());

                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'],
                        'content'      => json_encode($result),
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('Fleet copilot (OpenAI) failed', ['error' => $e->getMessage()]);
                return '⚠️ AI service unavailable. Please try again.';
            }
        }

        return 'Unable to complete the request.';
    }

    // ── Gemini (context injection — no native tool-calling loop here) ──────────

    private function callGemini(string $systemPrompt, array $history, string $message): string
    {
        $apiKey = config('ai.providers.gemini.api_key');
        $model  = config('ai.providers.gemini.model', 'gemini-1.5-pro');
        $base   = config('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $contextJson = json_encode($this->buildFleetContext(Auth::user()), JSON_PRETTY_PRINT);
        $fullMessage = "FLEET DATA CONTEXT:\n{$contextJson}\n\nUSER QUESTION:\n{$message}";

        $contents = array_merge(
            array_map(fn($m) => ['role' => $m['role'] === 'assistant' ? 'model' : $m['role'], 'parts' => [['text' => $m['content']]]], $history),
            [['role' => 'user', 'parts' => [['text' => $fullMessage]]]]
        );

        try {
            $response = Http::withQueryParameters(['key' => $apiKey])
                ->timeout(self::TIMEOUT)
                ->post("{$base}/models/{$model}:generateContent", [
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents'           => $contents,
                ])->throw()->json();

            return $response['candidates'][0]['content']['parts'][0]['text'] ?? 'No response.';
        } catch (\Throwable $e) {
            Log::error('Fleet copilot (Gemini) failed', ['error' => $e->getMessage()]);
            return '⚠️ AI service unavailable. Please try again.';
        }
    }

    // ── DeepSeek (OpenAI-compatible) ───────────────────────────────────────────

    // ── Local tier (Groq / OpenAI-compatible) — the default, cheap workhorse ──
    /** Free-first provider chain — the default brain for the fleet copilot. */
    private function callChain(string $systemPrompt, array $history, string $message): string
    {
        $contextJson = json_encode($this->buildFleetContext(Auth::user()), JSON_PRETTY_PRINT);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt . "\n\nFLEET CONTEXT:\n" . $contextJson]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $res = app(\App\Core\AI\Services\AIChatProviderChain::class)->complete($messages);

        return $res['text'] ?? '⚠️ AI service unavailable. Please try again.';
    }

    private function callLocal(string $systemPrompt, array $history, string $message): string
    {
        // Prefer the local (Groq) tier; fall back to OpenAI's key/endpoint if
        // the local tier has no credentials, so the copilot always has a brain.
        $local   = config('ai.providers.local');
        $useLocal = ! empty($local['api_key']);
        $apiKey  = $useLocal ? $local['api_key'] : config('ai.providers.openai.api_key');
        /*
         * The configured model, with no private fallback.
         *
         * This used to fall back to `llama-3.3-70b-versatile` when the config had no model.
         * Groq decommissioned that name, so the fallback stopped being a safety net and became
         * a guaranteed 404 — and one that reads as "the AI is down" rather than "this service
         * is asking for a model that no longer exists". `config/ai.php` already carries the
         * default; a second copy here is a second thing to update and the one nobody
         * remembers. `LocalAgent`, which is the driver everything else goes through, has always
         * simply trusted the config.
         */
        $model   = $useLocal ? ($local['model'] ?? '') : config('ai.providers.openai.model', 'gpt-4o');
        $baseUrl = rtrim($useLocal ? ($local['base_url'] ?? 'https://api.groq.com/openai/v1') : 'https://api.openai.com/v1', '/');

        $contextJson = json_encode($this->buildFleetContext(Auth::user()), JSON_PRETTY_PRINT);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt . "\n\nFLEET CONTEXT:\n" . $contextJson]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        try {
            $response = Http::withToken($apiKey)->timeout(self::TIMEOUT)
                ->post("{$baseUrl}/chat/completions", [
                    'model'    => $model,
                    'messages' => $messages,
                ])->throw()->json();

            return $response['choices'][0]['message']['content'] ?? 'No response.';
        } catch (\Throwable $e) {
            Log::error('Fleet copilot (local) failed', ['error' => $e->getMessage()]);
            return '⚠️ AI service unavailable. Please try again.';
        }
    }

    private function callDeepSeek(string $systemPrompt, array $history, string $message): string
    {
        $apiKey  = config('ai.providers.deepseek.api_key');
        $model   = config('ai.providers.deepseek.model', 'deepseek-chat');
        $baseUrl = config('ai.providers.deepseek.base_url', 'https://api.deepseek.com/v1');

        $contextJson = json_encode($this->buildFleetContext(Auth::user()), JSON_PRETTY_PRINT);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt . "\n\nFLEET CONTEXT:\n" . $contextJson]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        try {
            $response = Http::withToken($apiKey)->timeout(self::TIMEOUT)
                ->post("{$baseUrl}/chat/completions", [
                    'model'    => $model,
                    'messages' => $messages,
                ])->throw()->json();

            return $response['choices'][0]['message']['content'] ?? 'No response.';
        } catch (\Throwable $e) {
            Log::error('Fleet copilot (DeepSeek) failed', ['error' => $e->getMessage()]);
            return '⚠️ AI service unavailable. Please try again.';
        }
    }

    // ── Tool execution (FR-4.4.3) ──────────────────────────────────────────────

    public function executeTool(string $name, array $input, ?User $user = null): array
    {
        $user ??= Auth::user();

        return match ($name) {
            'query_vehicles'   => $this->toolQueryVehicles($input, $user),
            'query_bookings'   => $this->toolQueryBookings($input, $user),
            'query_documents'  => $this->toolQueryDocuments($input, $user),
            'query_costs'      => $this->toolQueryCosts($input, $user),
            'get_expiring_items' => $this->toolGetExpiringItems($input, $user),
            'approve_booking'  => $this->toolApproveBooking($input, $user),
            'create_booking'   => ['status' => 'pending_confirmation', 'params' => $input, 'action' => 'create_booking'],
            'mark_key_lost'    => $this->toolMarkKeyLost($input, $user),
            default            => ['error' => "Unknown tool: {$name}"],
        };
    }

    // ── Tool implementations ───────────────────────────────────────────────────

    private function toolQueryVehicles(array $input, User $user): array
    {
        $query = DB::table('fleet_vehicles')->whereNull('deleted_at');

        if (!empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (!empty($input['search'])) {
            $s = '%' . $input['search'] . '%';
            $query->where(fn($q) => $q->where('registration_number', 'ilike', $s)
                ->orWhere('make', 'ilike', $s)
                ->orWhere('model', 'ilike', $s));
        }

        return $query->select('id', 'registration_number', 'make', 'model', 'year', 'status', 'fuel_type', 'odometer_reading')
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function toolQueryBookings(array $input, User $user): array
    {
        $query = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->whereNull('b.deleted_at');

        if (!empty($input['state'])) {
            $query->where('b.workflow_state', $input['state']);
        }

        $limit = min(20, (int) ($input['limit'] ?? 10));

        return $query->select('b.id', 'b.reference', 'b.workflow_state', 'b.purpose',
                'b.departure_at', 'b.expected_return_at',
                'v.registration_number', 'v.make', 'v.model',
                'd.user_id as driver_user_id')
            ->orderByDesc('b.created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function toolQueryDocuments(array $input, User $user): array
    {
        $query = DB::table('fleet_documents')
            ->whereNull('deleted_at')
            ->whereNotNull('expiry_date');

        $days = (int) ($input['expiring_within_days'] ?? 30);
        $query->where('expiry_date', '<=', now()->addDays($days));

        return $query->select('id', 'vehicle_id', 'document_type', 'document_number', 'expiry_date', 'status')
            ->orderBy('expiry_date')
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function toolQueryCosts(array $input, User $user): array
    {
        $days  = (int) ($input['period_days'] ?? 30);
        $since = now()->subDays($days);

        $fuel = DB::table('fleet_fuel_logs')
            ->where('filled_at', '>=', $since)
            ->selectRaw('COUNT(*) as fills, SUM(litres) as total_litres, SUM(amount) as total_cost')
            ->first();

        $maintenance = DB::table('fleet_maintenance')
            ->where('status', 'completed')
            ->where('completed_date', '>=', $since)
            ->selectRaw('COUNT(*) as jobs, SUM(actual_cost) as total_cost')
            ->first();

        $tollgates = DB::table('fleet_booking_tollgates')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as count, SUM(amount) as total')
            ->first();

        return [
            'period_days'  => $days,
            'fuel'         => (array) $fuel,
            'maintenance'  => (array) $maintenance,
            'tollgates'    => (array) $tollgates,
            'total_spend'  => (float) ($fuel->total_cost ?? 0)
                + (float) ($maintenance->total_cost ?? 0)
                + (float) ($tollgates->total ?? 0),
        ];
    }

    private function toolGetExpiringItems(array $input, User $user): array
    {
        $days  = (int) ($input['within_days'] ?? 30);
        $until = now()->addDays($days);

        $docs = DB::table('fleet_documents')
            ->whereNull('deleted_at')
            ->where('expiry_date', '<=', $until)
            ->where('expiry_date', '>=', now())
            ->orderBy('expiry_date')
            ->get(['document_type', 'document_number', 'vehicle_id', 'expiry_date'])
            ->toArray();

        $licences = DB::table('fleet_drivers')
            ->whereNull('deleted_at')
            ->where('licence_expiry', '<=', $until->toDateString())
            ->where('licence_expiry', '>=', now()->toDateString())
            ->orderBy('licence_expiry')
            ->get(['id', 'user_id', 'licence_number', 'licence_expiry'])
            ->toArray();

        return [
            'documents'       => $docs,
            'driver_licences' => $licences,
        ];
    }

    private function toolApproveBooking(array $input, User $user): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        if (!$bookingId) return ['error' => 'booking_id required'];

        $booking = DB::table('fleet_bookings')->where('id', $bookingId)->first();
        if (!$booking) return ['error' => 'Booking not found'];

        if (!in_array($booking->workflow_state, ['pending', 'security_check'])) {
            return ['error' => "Booking is in state '{$booking->workflow_state}' and cannot be approved."];
        }

        DB::table('fleet_bookings')->where('id', $bookingId)->update([
            'workflow_state' => 'approved',
            'approved_by'    => $user->id,
            'approved_at'    => now(),
            'updated_at'     => now(),
        ]);

        return ['success' => true, 'booking_id' => $bookingId, 'new_state' => 'approved'];
    }

    private function toolMarkKeyLost(array $input, User $user): array
    {
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        if (!$vehicleId) return ['error' => 'vehicle_id required'];

        DB::table('fleet_vehicles')->where('id', $vehicleId)->update([
            'status'        => 'out_of_service',
            'status_reason' => 'Key reported lost: ' . ($input['reason'] ?? 'No reason given'),
            'updated_at'    => now(),
        ]);

        return ['success' => true, 'vehicle_id' => $vehicleId, 'new_status' => 'out_of_service'];
    }

    // ── Context & system prompt ────────────────────────────────────────────────

    private function buildFleetContext(User $user): array
    {
        return [
            'vehicles_summary' => DB::table('fleet_vehicles')->whereNull('deleted_at')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')->pluck('count', 'status')->toArray(),

            'active_bookings' => DB::table('fleet_bookings')
                ->whereIn('workflow_state', ['pending', 'approved', 'in_use'])
                ->whereNull('deleted_at')->count(),

            'maintenance_overdue' => DB::table('fleet_maintenance')
                ->where('status', 'scheduled')
                ->where('scheduled_date', '<', now()->toDateString())
                ->count(),

            'high_risk_vehicles' => DB::table('fleet_vehicle_predictions')
                ->where('risk_score', '>=', 70)
                ->where('predicted_at', '>=', now()->subDays(1))
                ->count(),

            'unreviewed_anomalies' => DB::table('fleet_fuel_anomalies')
                ->where('is_reviewed', false)->count(),

            'expiring_docs_30d' => DB::table('fleet_documents')
                ->whereNull('deleted_at')
                ->where('expiry_date', '<=', now()->addDays(30))
                ->where('expiry_date', '>=', now())
                ->count(),
        ];
    }

    private function buildSystemPrompt(User $user): string
    {
        $context = json_encode($this->buildFleetContext($user), JSON_PRETTY_PRINT);
        $role    = $user->roles?->first()?->name ?? 'employee';

        return <<<PROMPT
You are Mkhaya Fleet AI, an intelligent assistant for fleet management operations.

USER: {$user->email} (role: {$role})

FLEET OVERVIEW:
{$context}

CAPABILITIES:
- You have access to tools to query vehicles, bookings, documents, costs, and expiring items.
- For action tools (approve_booking, create_booking, mark_key_lost): ALWAYS describe what you are about to do and ask for confirmation BEFORE calling the tool. Phrase it as: "I'm about to [action]. Do you confirm?"
- Only perform actions the user has explicitly confirmed.
- Respect role-based access: non-managers cannot approve bookings.

GUIDELINES:
- Be concise and factual. Never fabricate data — use your tools to fetch real data.
- When answering cost/stats questions, always call the appropriate tool first.
- Format monetary values as R {amount}.
- If unsure, say so rather than guessing.
PROMPT;
    }

    // ── Session helpers ────────────────────────────────────────────────────────

    public function getSessions(): array
    {
        return AiChatSession::where('user_id', Auth::id())
            ->whereIn('session_id', function ($q) {
                $q->select('session_id')->from('ai_chat_messages')
                  ->where('metadata->module', self::MODULE);
            })
            ->latest()->limit(20)
            ->get()
            ->map(fn($s) => [
                'session_id' => $s->session_id,
                'title'      => $s->title,
                'created_at' => $s->created_at->toISOString(),
            ])
            ->toArray();
    }

    public function getHistory(string $sessionId): array
    {
        return AiChatMessage::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => [
                'id'        => $m->id,
                'role'      => $m->role,
                'content'   => $m->content,
                'timestamp' => $m->created_at->toISOString(),
            ])
            ->toArray();
    }

    public function deleteSession(string $sessionId): void
    {
        AiChatSession::where('session_id', $sessionId)->where('user_id', Auth::id())->delete();
        AiChatMessage::where('session_id', $sessionId)->where('user_id', Auth::id())->delete();
    }

    public function availableProviders(): array
    {
        $all = ['local', 'claude', 'openai', 'gemini', 'deepseek'];
        return collect($all)
            ->filter(fn($p) => filled(config("ai.providers.{$p}.api_key")))
            ->map(fn($p) => [
                'value' => $p,
                'label' => $p === 'local' ? 'Groq (fast)' : ucfirst($p),
                'model' => config("ai.providers.{$p}.model"),
            ])
            ->values()
            ->toArray();
    }

    private function buildHistory(string $sessionId): array
    {
        return AiChatMessage::where('session_id', $sessionId)
            ->latest()->limit(self::MAX_HISTORY)->get()->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }

    private function rateLimit(int $userId): void
    {
        $key = "fleet-ai-{$userId}";
        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages(['message' => 'Too many requests. Please slow down.']);
        }
        RateLimiter::hit($key, 60);
    }
}
