<?php

declare(strict_types=1);

/**
 * Role-based permissions map.
 *
 * SuperAdmin bypasses all checks (via Gate::before).
 * Client is confined to portal routes (via ClientPortalMiddleware).
 * Only tenant-level roles are mapped here.
 */
return [
    'admin' => [
        'view_dashboard',
        'manage_team',
        'manage_settings',
        'manage_subscription',
        'manage_clients',
        'manage_accounts',
        'manage_journal_entries',
        'post_journal_entries',
        'manage_invoices',
        'send_invoices',
        'manage_payments',
        'manage_documents',
        'view_reports',
        'manage_eta',
        'manage_timesheets',
        'approve_timesheets',
        'manage_payroll',
        'manage_employees',
        'manage_landing_page',
        'manage_onboarding',
        'invite_client_portal',
    ],

    'accountant' => [
        'view_dashboard',
        'manage_clients',
        'manage_accounts',
        'manage_journal_entries',
        'manage_invoices',
        'send_invoices',
        'manage_payments',
        'manage_documents',
        'view_reports',
        'manage_eta',
        'manage_timesheets',
    ],

    'auditor' => [
        'view_dashboard',
        'manage_documents',
        'view_reports',
    ],
];
