<?php

namespace Database\Factories\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMember>
 */
class ProjectMemberFactory extends Factory
{
    protected $model = ProjectMember::class;

    public function definition(): array
    {
        return [
            'project_id'   => fn () => ProjectFactory::new()->create()->id,
            // Null by default, because that is the interesting case: a member is a person on the
            // job, not an account. `user_id` is what a portal invitation fills in.
            'user_id'      => null,
            'role_key'     => 'team_member',
            'display_name' => fake()->name(),
            'email'        => fake()->unique()->safeEmail(),
            'is_active'    => true,
        ];
    }

    /** External, and therefore subject to cost redaction everywhere. */
    public function contractor(): static
    {
        return $this->state(fn () => ['role_key' => 'contractor']);
    }

    public function client(): static
    {
        return $this->state(fn () => ['role_key' => 'client']);
    }

    public function projectManager(): static
    {
        return $this->state(fn () => ['role_key' => 'project_manager']);
    }

    /** Somebody with no login at all — a subcontractor's foreman and a phone number. */
    public function withoutLogin(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
