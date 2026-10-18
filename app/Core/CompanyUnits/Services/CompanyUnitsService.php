<?php

namespace App\Core\CompanyUnits\Services;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Models\CompanyUnit;

class CompanyUnitsService
{
    public function getTree(int $companyId): array
    {
        $roots = CompanyUnit::with($this->recursiveWith())
            ->where('company_id', $companyId)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $this->formatTree($roots->toArray());
    }

    public function getTreeWithMembers(int $companyId): array
    {
        $roots = CompanyUnit::with([
            'childrenRecursive',
            'user.person',
            'assignedUsers.person',
        ])
            ->where('company_id', $companyId)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $this->formatTreeWithMembers($roots->toArray());
    }

    private function recursiveWith(): array
    {
        return [
            'user.person',
            'childrenRecursive' => function ($q) {
                $q->with(array_merge($this->recursiveWith(), ['assignedUsers.person']))
                    ->orderBy('sort_order');
            },
        ];
    }

    // ──────────────────────────────────────────────
    // DECODE profile_pic JSON → data URL
    // ──────────────────────────────────────────────
    private function decodeProfilePic(mixed $raw, ?string $path = null): ?string
    {
        // Already a usable src — a data URL from an older payload, or a URL we produced.
        if (is_string($raw) && (str_starts_with($raw, 'data:') || str_starts_with($raw, 'http'))) {
            return $raw;
        }

        return StoredImage::url($path, $raw);
    }

    private function formatTree(array $nodes): array
    {
        return array_map(fn($node) => [
            'id'         => $node['id'],
            'name'       => $node['name'],
            'type'       => $node['type'],
            'parent_id'  => $node['parent_id'],
            'sort_order' => $node['sort_order'],
            'user_id'    => $node['user_id'] ?? null,
            'user'       => $this->formatUser($node['user'] ?? null),
            'children'   => $this->formatTree($node['children_recursive'] ?? []),
        ], $nodes);
    }

    private function formatTreeWithMembers(array $nodes): array
    {
        return array_map(function (array $node) {
            $person = $node['user']['person'] ?? null;

            return [
                'id'         => $node['id'],
                'name'       => $node['name'],
                'type'       => $node['type'],
                'parent_id'  => $node['parent_id'],
                'sort_order' => $node['sort_order'],
                'user_id'    => $node['user_id'],
                'user'       => $person ? [
                    'id'           => $person['id'],
                    'first_name'   => $person['first_name'],
                    'surname'      => $person['surname'],
                    'email'        => $person['email'],
                    'job_function' => $person['job_function'] ?? null,
                    'profile_pic'  => $this->decodeProfilePic($person['profile_pic'] ?? null),
                    'initial'      => $person['initial'] ?? strtoupper(
                        substr($person['first_name'] ?? '', 0, 1) .
                        substr($person['surname']    ?? '', 0, 1)
                    ),
                ] : null,
                'assigned_users' => $this->formatAssignedUsers($node['assigned_users'] ?? []),
                'children' => $this->formatTreeWithMembers($node['children_recursive'] ?? $node['children'] ?? []),
            ];
        }, $nodes);
    }

    private function formatUser(?array $user): ?array
    {
        if (empty($user)) return null;

        $person = $user['hr_person'] ?? $user['person'] ?? null;

        return [
            'id'          => $user['id'],
            'first_name'  => $person['first_name']  ?? null,
            'surname'     => $person['surname']      ?? null,
            'email'       => $user['email'],
            'job_function'=> $person['job_function'] ?? null,
            'initial'     => $person['initial']      ?? strtoupper(
                substr($person['first_name'] ?? '', 0, 1) .
                substr($person['surname']    ?? '', 0, 1)
            ),
            'profile_pic' => $this->decodeProfilePic($person['profile_pic'] ?? null, $person['profile_pic_path'] ?? null),
        ];
    }

    private function formatAssignedUsers(array $users): array
    {
        return array_map(function ($u) {
            $person = $u['hr_person'] ?? $u['person'] ?? null;

            return [
                'id'          => $u['id'],
                'first_name'  => $person['first_name']  ?? null,
                'surname'     => $person['surname']      ?? null,
                'email'       => $u['email'],
                'job_function'=> $person['job_function'] ?? null,
                'initial'     => $person['initial']      ?? strtoupper(
                    substr($person['first_name'] ?? '', 0, 1) .
                    substr($person['surname']    ?? '', 0, 1)
                ),
                'profile_pic' => $this->decodeProfilePic($person['profile_pic'] ?? null, $person['profile_pic_path'] ?? null),
            ];
        }, $users);
    }

    public function create(array $data): CompanyUnit
    {
        $maxOrder = CompanyUnit::where('parent_id', $data['parent_id'] ?? null)
            ->where('company_id', $data['company_id'])
            ->max('sort_order') ?? 0;

        return CompanyUnit::create([
            'name'       => $data['name'],
            'parent_id'  => $data['parent_id']  ?? null,
            'company_id' => $data['company_id'],
            'type'       => $data['type']        ?? null,
            'user_id'    => $data['user_id']     ?? null,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    public function rename(int $id, string $name, ?string $type = null, ?int $userId = null): CompanyUnit
    {
        $unit = CompanyUnit::findOrFail($id);
        $update = array_filter(
            ['name' => $name, 'type' => $type, 'user_id' => $userId],
            fn($v) => $v !== null
        );
        $unit->update($update);
        return $unit;
    }

    public function move(int $id, ?int $newParentId, int $sortOrder): CompanyUnit
    {
        $unit = CompanyUnit::findOrFail($id);
        $unit->update(['parent_id' => $newParentId, 'sort_order' => $sortOrder]);
        return $unit;
    }

    public function delete(int $id): void
    {
        CompanyUnit::findOrFail($id)->delete();
    }

    public function reorder(array $items): void
    {
        foreach ($items as $idx => $item) {
            CompanyUnit::where('id', $item['id'])->update(['sort_order' => $idx]);
        }
    }
}
