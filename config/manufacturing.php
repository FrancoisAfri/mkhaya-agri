<?php

/*
|--------------------------------------------------------------------------
| Manufacturing & Production -- commercial packaging (MFG-025)
|--------------------------------------------------------------------------
|
| A company's package is sold through the existing subscription system as an add-on module
| (manufacturing_essentials / _professional / _enterprise in billing_modules). The highest one a
| company holds applies; a plan that includes every module counts as Enterprise. A company holding
| none gets `default_package` -- Enterprise, so turning packaging on never takes features away from
| an existing customer until an operator decides otherwise.
|
| `features` are the module's areas (the first path segment after /manufacturing); `max_factories`
| caps how many active factories a company may run (null: no cap). Billing stays in the
| subscription and finance systems; nothing here charges anybody.
|
*/

return [
    'default_package' => env('MANUFACTURING_DEFAULT_PACKAGE', 'ENTERPRISE'),

    'packages' => [
        'ESSENTIALS' => [
            'label' => 'Essentials',
            'features' => ['setup', 'engineering', 'production', 'floor', 'quality', 'reports'],
            'max_factories' => 1,
        ],
        'PROFESSIONAL' => [
            'label' => 'Professional',
            'features' => ['setup', 'engineering', 'production', 'floor', 'quality', 'reports', 'planning', 'costing', 'maintenance',
                'workforce', 'fulfilment', 'loss', 'notifications', 'accounting'],
            'max_factories' => 3,
        ],
        'ENTERPRISE' => [
            'label' => 'Enterprise',
            'features' => ['*'],
            'max_factories' => null,
        ],
    ],

    // While a company has an overdue invoice, manufacturing is read-only: everything can be seen
    // and exported, nothing changed. Suspension itself is the platform's (ResolveCompany).
    'read_only_when_overdue' => env('MANUFACTURING_READ_ONLY_WHEN_OVERDUE', true),

    // MFG-019: let a language model word the assistant's computed answers.
    'assistant_narration' => env('MANUFACTURING_ASSISTANT_NARRATION', true),
];
