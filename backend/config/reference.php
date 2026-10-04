<?php

use App\Models;

/*
|--------------------------------------------------------------------------
| Reference (lookup) data registry
|--------------------------------------------------------------------------
| Generic CRUD at /api/v1/reference/{type}. Every type has code, name,
| sort_order, status; `fields` adds type-specific columns.
|   type: string|bool|select|reference ; options for select ; model for reference
*/

return [
    'vessel-types' => [
        'model' => Models\VesselType::class,
        'label' => 'Vessel types',
        'fields' => [
            'category' => ['type' => 'select', 'label' => 'Category', 'required' => true, 'options' => ['offshore', 'tanker', 'bulk', 'general', 'other']],
        ],
    ],
    'fuel-types' => [
        'model' => Models\FuelType::class,
        'label' => 'Fuel types',
        'fields' => [
            'category' => ['type' => 'select', 'label' => 'Category', 'required' => true, 'options' => ['HFO', 'VLSFO', 'ULSFO', 'LSMGO', 'MGO', 'MDO', 'LNG', 'METHANOL', 'OTHER']],
            'is_eca_compliant' => ['type' => 'bool', 'label' => 'ECA compliant'],
        ],
    ],
    'cargo-types' => [
        'model' => Models\CargoType::class,
        'label' => 'Cargo types',
        'fields' => [
            'unit' => ['type' => 'select', 'label' => 'Unit', 'required' => true, 'options' => ['mt', 'm3', 'bbl', 'units']],
        ],
    ],
    'offshore-activity-types' => [
        'model' => Models\OffshoreActivityType::class,
        'label' => 'Offshore activity types',
        'fields' => [
            'is_billable_default' => ['type' => 'bool', 'label' => 'Billable by default'],
        ],
    ],
    'milestone-types' => [
        'model' => Models\MilestoneType::class,
        'label' => 'Milestone types',
        'fields' => [
            'applies_to' => ['type' => 'select', 'label' => 'Applies to', 'required' => true, 'options' => ['voyage', 'offshore', 'both']],
            'is_laytime_relevant' => ['type' => 'bool', 'label' => 'Laytime relevant'],
        ],
    ],
    'expense-categories' => [
        'model' => Models\ExpenseCategory::class,
        'label' => 'Expense categories',
        'fields' => [
            'group' => ['type' => 'select', 'label' => 'Group', 'required' => true, 'options' => ['bunker', 'port', 'agency', 'canal', 'brokerage', 'commission', 'operational', 'mobilization', 'demobilization', 'supplier', 'tonnage', 'other']],
        ],
    ],
    'revenue-categories' => [
        'model' => Models\RevenueCategory::class,
        'label' => 'Revenue categories',
        'fields' => [
            'group' => ['type' => 'select', 'label' => 'Group', 'required' => true, 'options' => ['freight', 'hire', 'offshore_service', 'demurrage', 'other']],
            'is_commissionable' => ['type' => 'bool', 'label' => 'Commissionable by default'],
        ],
    ],
    'da-cost-categories' => [
        'model' => Models\DaCostCategory::class,
        'label' => 'Port DA cost categories',
        'fields' => [
            'expense_category_id' => ['type' => 'reference', 'label' => 'Expense category', 'reference' => 'expense-categories', 'model' => Models\ExpenseCategory::class],
        ],
    ],
];
