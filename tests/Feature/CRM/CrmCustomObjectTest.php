<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmObjectType;
use App\Modules\CRM\Services\CrmObjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrmCustomObjectTest extends TestCase
{
    use DatabaseTransactions;

    private function projectType(): CrmObjectType
    {
        return CrmObjectType::create([
            'uuid' => Str::uuid(), 'name' => 'Project', 'slug' => 'project-' . Str::random(5),
            'icon' => 'box', 'record_label' => 'title',
            'fields' => [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                ['name' => 'budget', 'label' => 'Budget', 'type' => 'number', 'required' => false],
                ['name' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['Planning', 'Active'], 'required' => false],
            ],
            'is_active' => true,
        ]);
    }

    public function test_valid_record_is_cleaned_and_coerced(): void
    {
        $type = $this->projectType();
        $svc  = app(CrmObjectService::class);

        $data = $svc->validateData($type, ['title' => 'Apollo', 'budget' => '50000', 'stage' => 'Active', 'junk' => 'drop me']);

        $this->assertSame('Apollo', $data['title']);
        $this->assertSame(50000, $data['budget']);      // coerced to number
        $this->assertArrayNotHasKey('junk', $data);     // undeclared field stripped
    }

    public function test_missing_required_field_fails(): void
    {
        $type = $this->projectType();

        $this->expectException(ValidationException::class);
        app(CrmObjectService::class)->validateData($type, ['budget' => 10]); // title required
    }

    public function test_select_value_must_be_in_options(): void
    {
        $type = $this->projectType();

        $this->expectException(ValidationException::class);
        app(CrmObjectService::class)->validateData($type, ['title' => 'X', 'stage' => 'Nonexistent']);
    }

    public function test_record_label_resolves(): void
    {
        $type = $this->projectType();
        $svc  = app(CrmObjectService::class);

        $this->assertSame('Apollo', $svc->recordLabel($type, ['title' => 'Apollo'], 1));
        $this->assertSame('#7', $svc->recordLabel($type, [], 7)); // no label data -> id
    }
}
