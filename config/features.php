<?php

declare(strict_types=1);

/**
 * Feature catalog for plan-based gating.
 *
 * Each key is a feature slug used by Plan::hasFeature() and the `feature:` middleware.
 * 'category' distinguishes module-level features (gate whole route groups, e.g. Clients, Invoicing)
 * from addon features (fine-grained toggles like e_invoice, api_access).
 *
 * Route-level usage:
 *   Route::middleware('feature:clients')->group(...);
 *
 * Plan-level storage: Plan.features JSON column — { "clients": true, "e_invoice": false, ... }
 */
return [

    'catalog' => [
        // ─── Core modules ─────────────────────────────────────
        'clients' => [
            'name_en' => 'Clients',
            'name_ar' => 'العملاء',
            'category' => 'module',
            'group' => 'core',
        ],
        'documents' => [
            'name_en' => 'Documents',
            'name_ar' => 'المستندات',
            'category' => 'module',
            'group' => 'core',
        ],
        'invoicing' => [
            'name_en' => 'Invoicing',
            'name_ar' => 'الفواتير',
            'category' => 'module',
            'group' => 'core',
        ],
        'accounting' => [
            'name_en' => 'Accounting (COA / Journal)',
            'name_ar' => 'المحاسبة (دليل الحسابات / القيود)',
            'category' => 'module',
            'group' => 'core',
        ],
        'reports' => [
            'name_en' => 'Reports',
            'name_ar' => 'التقارير',
            'category' => 'module',
            'group' => 'core',
        ],

        // ─── Finance modules ──────────────────────────────────
        'banking' => [
            'name_en' => 'Banking & Reconciliation',
            'name_ar' => 'البنوك والتسويات',
            'category' => 'module',
            'group' => 'finance',
        ],
        'expenses' => [
            'name_en' => 'Expenses',
            'name_ar' => 'المصروفات',
            'category' => 'module',
            'group' => 'finance',
        ],
        'bills_vendors' => [
            'name_en' => 'Bills & Vendors',
            'name_ar' => 'الفواتير والموردون',
            'category' => 'module',
            'group' => 'finance',
        ],
        'collections' => [
            'name_en' => 'Collections (AR)',
            'name_ar' => 'التحصيل',
            'category' => 'module',
            'group' => 'finance',
        ],
        'fixed_assets' => [
            'name_en' => 'Fixed Assets',
            'name_ar' => 'الأصول الثابتة',
            'category' => 'module',
            'group' => 'finance',
        ],
        'tax' => [
            'name_en' => 'Tax Management',
            'name_ar' => 'إدارة الضرائب',
            'category' => 'module',
            'group' => 'finance',
        ],
        'cost_centers' => [
            'name_en' => 'Cost Centers',
            'name_ar' => 'مراكز التكلفة',
            'category' => 'module',
            'group' => 'finance',
        ],
        'budgeting' => [
            'name_en' => 'Budgeting',
            'name_ar' => 'الموازنات',
            'category' => 'module',
            'group' => 'finance',
        ],

        // ─── Operations ───────────────────────────────────────
        'inventory' => [
            'name_en' => 'Inventory',
            'name_ar' => 'المخزون',
            'category' => 'module',
            'group' => 'operations',
        ],
        'payroll' => [
            'name_en' => 'Payroll',
            'name_ar' => 'الرواتب',
            'category' => 'module',
            'group' => 'operations',
        ],
        'timesheets' => [
            'name_en' => 'Timesheets',
            'name_ar' => 'سجلات الوقت',
            'category' => 'module',
            'group' => 'operations',
        ],
        'ecommerce' => [
            'name_en' => 'E-Commerce Integration',
            'name_ar' => 'تكامل التجارة الإلكترونية',
            'category' => 'module',
            'group' => 'operations',
        ],

        // ─── Add-ons ──────────────────────────────────────────
        'e_invoice' => [
            'name_en' => 'ETA E-Invoice',
            'name_ar' => 'الفاتورة الإلكترونية (ETA)',
            'category' => 'addon',
            'group' => 'integrations',
        ],
        'api_access' => [
            'name_en' => 'Public API Access',
            'name_ar' => 'الوصول إلى الـ API',
            'category' => 'addon',
            'group' => 'integrations',
        ],
        'custom_reports' => [
            'name_en' => 'Custom Reports',
            'name_ar' => 'تقارير مخصصة',
            'category' => 'addon',
            'group' => 'integrations',
        ],
        'client_portal' => [
            'name_en' => 'Client Portal',
            'name_ar' => 'بوابة العملاء',
            'category' => 'addon',
            'group' => 'integrations',
        ],
        'priority_support' => [
            'name_en' => 'Priority Support',
            'name_ar' => 'الدعم المميز',
            'category' => 'addon',
            'group' => 'support',
        ],
        'audit_log' => [
            'name_en' => 'Audit Log',
            'name_ar' => 'سجل المراجعة',
            'category' => 'addon',
            'group' => 'compliance',
        ],
    ],

    /**
     * Default feature bundles per plan slug.
     * Used by PlanSeeder to populate the plans.features JSON column.
     */
    'plan_bundles' => [
        'free_trial' => [
            'clients', 'documents',
        ],
        'starter' => [
            'clients', 'documents', 'invoicing', 'accounting', 'reports',
            'expenses', 'custom_reports',
        ],
        'professional' => [
            'clients', 'documents', 'invoicing', 'accounting', 'reports',
            'banking', 'expenses', 'bills_vendors', 'collections',
            'fixed_assets', 'tax', 'cost_centers', 'budgeting',
            'inventory', 'payroll', 'timesheets',
            'e_invoice', 'api_access', 'custom_reports', 'client_portal',
        ],
        'enterprise' => [
            'clients', 'documents', 'invoicing', 'accounting', 'reports',
            'banking', 'expenses', 'bills_vendors', 'collections',
            'fixed_assets', 'tax', 'cost_centers', 'budgeting',
            'inventory', 'payroll', 'timesheets', 'ecommerce',
            'e_invoice', 'api_access', 'custom_reports', 'client_portal',
            'priority_support', 'audit_log',
        ],
    ],
];
