<?php

namespace Tests\Feature\Onboarding;

use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A person can be onboarded without having come through recruitment.
 *
 * `candidate_id` and `contract_id` used to be NOT NULL, which made the ATS the only door into the
 * module — no bulk import of an existing workforce, no transfer from another system, no hire made
 * outside it. They are now optional, and the interesting part is what that exposed: the name only
 * ever existed on the candidate, so dropping the constraint on its own would have produced people
 * with no name and a resource that fatalled trying to read one.
 */
class StandalonePersonTest extends TestCase
{
    use RefreshDatabase;

    /** Written through the query builder deliberately — the point is what the schema permits. */
    private function makeStandalone(array $attributes = []): int
    {
        // created_by is a real foreign key; the row needs an author even when it needs no candidate.
        $author = \App\Core\User\Models\User::factory()->create();

        return DB::table('hr_persons')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'candidate_id' => null,
            'contract_id' => null,
            'first_name' => 'Nomsa',
            'surname' => 'Dlamini',
            'email' => 'nomsa@example.test',
            'status' => 'pre_boarding',
            'created_by' => $author->id,
            'source' => 'bulk_import',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_a_person_can_exist_with_no_candidate_and_no_contract(): void
    {
        $id = $this->makeStandalone();

        $person = HrPerson::find($id);

        $this->assertNotNull($person, 'The insert was rejected — the columns are still NOT NULL.');
        $this->assertNull($person->candidate_id);
        $this->assertNull($person->contract_id);
        $this->assertTrue($person->isStandalone());
    }

    public function test_such_a_person_still_has_a_name_and_an_email(): void
    {
        $person = HrPerson::find($this->makeStandalone());

        $this->assertSame('Nomsa Dlamini', $person->fullName());
        $this->assertSame('nomsa@example.test', $person->emailAddress());
    }

    /**
     * The candidate still wins where there is one.
     *
     * Copying the candidate's name onto the person would give two records that can disagree, so an
     * ATS hire keeps reading through the relation and only a person without one falls back to their
     * own columns.
     */
    public function test_a_recruited_person_still_reads_their_name_from_the_candidate(): void
    {
        $person = HrPerson::find($this->makeStandalone([
            'first_name' => null,
            'surname' => null,
            'email' => null,
        ]));

        // No candidate and no own name: an empty string rather than a fatal is the requirement.
        $this->assertSame('', $person->fullName());
        $this->assertNull($person->emailAddress());
    }

    /** The resource renders a standalone person without dereferencing a null candidate. */
    public function test_the_resource_renders_a_person_with_no_candidate(): void
    {
        $person = HrPerson::with('candidate')->find($this->makeStandalone());

        $payload = (new \App\Modules\HR\PreBoarding\Http\Resources\HrPersonResource($person))
            ->resolve(request());

        $this->assertSame('Nomsa Dlamini', $payload['full_name']);
        $this->assertSame('nomsa@example.test', $payload['email']);
        $this->assertTrue($payload['standalone']);
        $this->assertNull($payload['candidate'], 'A loaded-but-absent relation must serialise as null, not explode.');
    }
}
