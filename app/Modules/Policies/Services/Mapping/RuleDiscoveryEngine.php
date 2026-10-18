<?php

namespace App\Modules\Policies\Services\Mapping;

use App\Modules\Policies\Models\{EventRuleMap, PolicyRule};
use App\Modules\Policies\DTOs\PolicyEventDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * RuleDiscoveryEngine
 * 
 * High-performance rule discovery for policy events.
 * Features:
 * - Caching for repeated queries
 * - Pattern matching with wildcard support
 * - Payload filtering
 * - Batch operations
 * - Cache invalidation on rule changes
 */
class RuleDiscoveryEngine
{
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'policy:rules:';

    /**
     * Discover all applicable rules for an event
     * Uses cache to avoid repeated database queries
     * 
     * @param PolicyEventDTO $event
     * @return Collection of applicable PolicyRule objects
     */
    public function discoverRulesForEvent(PolicyEventDTO $event): Collection
    {
        $cacheKey = $this->getCacheKey($event->module, $event->action);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($event) {
            return $this->queryRulesForEvent($event);
        });
    }

    /**
     * Direct database query for rules (no caching)
     * Useful when you need fresh data immediately
     * 
     * @param PolicyEventDTO $event
     * @return Collection
     */
    public function queryRulesForEvent(PolicyEventDTO $event): Collection
    {
        return EventRuleMap::with([
            'rule.policy',
            'rule.conditions',
            'rule.actions',
            'rule.clause',
        ])
            ->where('module', $event->module)
            ->where('active', true)
            ->get()
            ->filter(function (EventRuleMap $map) use ($event) {
                return $this->matchesActionPattern($map->action_pattern, $event->action)
                    && $this->matchesPayloadFilter($map->payload_filter, $event->payload);
            })
            ->map(fn(EventRuleMap $map) => $map->rule)
            ->filter(fn(?PolicyRule $rule) => $rule && $rule->active && $rule->policy->status === 'active')
            ->unique('id')
            ->values();
    }

    /**
     * Discover rules for multiple events
     * More efficient than calling discoverRulesForEvent multiple times
     * 
     * @param array|Collection $events
     * @return array Key: event_key, Value: Collection of rules
     */
    public function discoverRulesForMultipleEvents($events): array
    {
        $results = [];

        // Check cache first
        $toBatch = [];
        foreach ($events as $event) {
            $cacheKey = $this->getCacheKey($event->module, $event->action);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$cacheKey] = $cached;
            } else {
                $toBatch[] = $event;
            }
        }

        // Batch query remaining
        if (!empty($toBatch)) {
            $discovered = $this->batchQueryRules($toBatch);
            foreach ($discovered as $key => $rules) {
                $results[$key] = $rules;
                Cache::put($key, $rules, self::CACHE_TTL);
            }
        }

        return $results;
    }

    /**
     * Batch query multiple events in fewer database queries
     * 
     * @param array $events
     * @return array
     */
    private function batchQueryRules(array $events): array
    {
        $results = [];
        $allMappings = EventRuleMap::with([
            'rule.policy',
            'rule.conditions',
            'rule.actions',
        ])
            ->where('active', true)
            ->get();

        foreach ($events as $event) {
            $key = $this->getCacheKey($event->module, $event->action);
            $rules = $allMappings
                ->filter(function (EventRuleMap $map) use ($event) {
                    return $map->module === $event->module
                        && $this->matchesActionPattern($map->action_pattern, $event->action)
                        && $this->matchesPayloadFilter($map->payload_filter, $event->payload);
                })
                ->map(fn(EventRuleMap $map) => $map->rule)
                ->filter(fn(?PolicyRule $rule) => $rule && $rule->active && $rule->policy->status === 'active')
                ->unique('id')
                ->values();

            $results[$key] = $rules;
        }

        return $results;
    }

    /**
     * Get rules for a module with wildcard support
     * Example: getRulesForModuleAction('Leave', 'leave_request.*')
     * 
     * @param string $module
     * @param string $actionPattern
     * @return Collection
     */
    public function getRulesForModuleAction(string $module, string $actionPattern): Collection
    {
        return EventRuleMap::with('rule.policy', 'rule.conditions')
            ->where('module', $module)
            ->where('action_pattern', $actionPattern)
            ->where('active', true)
            ->get()
            ->map(fn(EventRuleMap $map) => $map->rule)
            ->filter(fn(?PolicyRule $rule) => $rule && $rule->active && $rule->policy->status === 'active')
            ->unique('id')
            ->values();
    }

    /**
     * Get all unique action patterns for a module
     * Useful for admin interfaces showing available actions
     * 
     * @param string $module
     * @return array
     */
    public function getActionPatternsForModule(string $module): array
    {
        return EventRuleMap::where('module', $module)
            ->where('active', true)
            ->distinct('action_pattern')
            ->pluck('action_pattern')
            ->toArray();
    }

    /**
     * Get all modules with event mappings
     * 
     * @return array
     */
    public function getModulesWithMappings(): array
    {
        return EventRuleMap::where('active', true)
            ->distinct('module')
            ->pluck('module')
            ->toArray();
    }

    /**
     * Find rules that would be triggered by a set of payload values
     * 
     * @param string $module
     * @param string $action
     * @param array $payload
     * @return Collection
     */
    public function findRulesWithPayloadMatch(
        string $module,
        string $action,
        array $payload
    ): Collection {
        return EventRuleMap::with('rule.policy')
            ->where('module', $module)
            ->where('active', true)
            ->get()
            ->filter(function (EventRuleMap $map) use ($action, $payload) {
                return $this->matchesActionPattern($map->action_pattern, $action)
                    && $this->matchesPayloadFilter($map->payload_filter, $payload);
            })
            ->map(fn(EventRuleMap $map) => $map->rule)
            ->filter(fn(?PolicyRule $rule) => $rule && $rule->active && $rule->policy->status === 'active')
            ->unique('id')
            ->values();
    }

    /**
     * Clear cache for a specific event
     * Call this when rules change
     * 
     * @param string $module
     * @param string $action
     */
    public function clearCacheForEvent(string $module, string $action): void
    {
        Cache::forget($this->getCacheKey($module, $action));
    }

    /**
     * Clear cache for a module
     * 
     * @param string $module
     */
    public function clearCacheForModule(string $module): void
    {
        $patterns = $this->getActionPatternsForModule($module);
        foreach ($patterns as $pattern) {
            $this->clearCacheForEvent($module, $pattern);
        }
    }

    /**
     * Clear all policy rule caches
     * Do this when rules are created/updated/deleted
     */
    public function clearAllCaches(): void
    {
        Cache::flush(); // Or use tags if you want more granular control
    }

    /**
     * Get cache statistics for debugging
     * 
     * @return array
     */
    public function getCacheStats(): array
    {
        $allKeys = Cache::getMultiple(
            EventRuleMap::where('active', true)->distinct('module')->pluck('module')->toArray()
        );

        return [
            'cached_modules' => count($allKeys),
            'total_cache_entries' => collect($allKeys)->flatten()->count(),
            'cache_ttl' => self::CACHE_TTL,
            'cache_prefix' => self::CACHE_PREFIX,
        ];
    }

    /**
     * Match action pattern (supports wildcards)
     * Examples:
     *   'leave.*' matches 'leave_request.submitted'
     *   'leave_request.submitted' matches exactly
     * 
     * @param string $pattern
     * @param string $action
     * @return bool
     */
    private function matchesActionPattern(string $pattern, string $action): bool
    {
        return fnmatch($pattern, $action);
    }

    /**
     * Check if event payload matches filter requirements
     * 
     * @param array|null $filter
     * @param array $payload
     * @return bool
     */
    private function matchesPayloadFilter(?array $filter, array $payload): bool
    {
        if (empty($filter)) {
            return true;
        }

        foreach ($filter as $key => $expectedValue) {
            if (data_get($payload, $key) !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate cache key
     * 
     * @param string $module
     * @param string $action
     * @return string
     */
    private function getCacheKey(string $module, string $action): string
    {
        return self::CACHE_PREFIX . md5("$module:$action");
    }
}
