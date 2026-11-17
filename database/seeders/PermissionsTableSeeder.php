<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsTableSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            // Dashboard Permissions
            'dashboard',
            'dashboard-top-stats',
            'dashboard-sales-analytics-chart',
            'dashboard-sales-weekly-analytics',
            'dashboard-weekly-analytics',
            'dashboard-monthly-analytics',
            'dashboard-yearly-analytics',
            'dashboard-daily-analytics',
            'dashboard-users',
            'dashboard-users-stats',
            'dashboard-agents-stats',
            'dashboard-agents-chart',

            // Applicant Permissions
            'applicant-index',
            'applicant-download-resume',
            'applicant-upload-resume',
            'applicant-add-note',
            'applicant-view-note',
            'applicant-upload-crm-resume',
            'applicant-view-history',
            'applicant-view-notes-history',
            'applicant-filters',
            'applicant-create',
            'applicant-edit',
            'applicant-edit-name',
            'applicant-edit-email',
            'applicant-edit-phone',
            'applicant-edit-landline',
            'applicant-edit-postcode',
            'applicant-delete',
            'applicant-view',
            'applicant-export',
            'applicant-export-all',
            'applicant-export-emails',
            'applicant-import',
            'applicant-show-blocked-data',
            'applicant-show-synopsis-forms',
            'applicant-view-phone-number',

            // Office Permissions
            'office-index',
            'office-filters',
            'office-view-note',
            'office-add-note',
            'office-view-notes-history',
            'office-view-manager-details',
            'office-create',
            'office-add-more-contact-btn',
            'office-edit',
            'office-edit-postcode',
            'office-name',
            'office-delete',
            'office-view',
            'office-import',
            'office-export',
            'office-export-all',
            'office-export-emails',
            'office-blacklist',

            // Unit Permissions
            'unit-index',
            'unit-filters',
            'unit-view-note',
            'unit-add-note',
            'unit-view-notes-history',
            'unit-view-manager-details',
            'unit-create',
            'unit-add-more-contact-btn',
            'unit-edit',
            'unit-edit-postcode',
            'unit-edit-head-office',
            'unit-name',
            'unit-delete',
            'unit-view',
            'unit-import',
            'unit-export',
            'unit-export-all',
            'unit-export-emails',
            'unit-change-status',
            'unit-blacklist',

            // Sale Permissions
            'sale-index',
            'sale-view-note',
            'sale-add-note',
            'sale-change-status',
            'sale-mark-on-hold',
            'sale-mark-as-open-close',
            'sale-view-history',
            'sale-view-documents',
            'sale-view-notes-history',
            'sale-view-manager-details',
            'sale-filters',
            'sale-create',
            'sale-edit',
            'sale-edit-postcode',
            'sale-edit-head-office',
            'sale-edit-job-category',
            'sale-edit-job-type',
            'sale-edit-job-description',
            'sale-edit-job-title',
            'sale-delete',
            'sale-view',
            'sale-blacklist',

            'sale-export',
            'sale-export-all',
            'sale-export-emails',

            'sale-import',

            // Sale Sub Modules Direct Sales Permissions
            'sale-direct-index',
            'sale-direct-view',
            'sale-direct-view-note',
            'sale-direct-add-note',
            'sale-direct-view-notes-history',
            'sale-direct-view-manager-details',
            'sale-direct-view-history',
            'sale-direct-view-documents',
            'sale-direct-mark-on-hold',
            'sale-direct-change-status',
            'sale-direct-filters',

            // Sale Sub Modules Open Sales Permissions
            'sale-open-index',
            'sale-open-view',
            'sale-open-view-note',
            'sale-open-add-note',
            'sale-open-view-notes-history',
            'sale-open-view-manager-details',
            'sale-open-view-history',
            'sale-open-view-documents',
            'sale-open-mark-on-hold',
            'sale-open-change-status',
            'sale-open-filters',

            // Sale Sub Modules Closed Sales Permissions
            'sale-closed-index',
            'sale-closed-view',
            'sale-closed-view-note',
            'sale-closed-add-note',
            'sale-closed-view-notes-history',
            'sale-closed-view-manager-details',
            'sale-closed-view-history',
            'sale-closed-view-documents',
            'sale-closed-change-status',
            'sale-closed-filters',

            // Sale Sub Modules Rejected Sales Permissions
            'sale-rejected-index',
            'sale-rejected-view',
            'sale-rejected-view-note',
            'sale-rejected-view-notes-history',
            'sale-rejected-view-manager-details',
            'sale-rejected-view-history',
            'sale-rejected-view-documents',
            'sale-rejected-change-status',
            'sale-rejected-filters',

            // Sale Sub Modules On Hold Sales Permissions
            'sale-hold-index',
            'sale-hold-view',
            'sale-hold-view-note',
            'sale-hold-view-notes-history',
            'sale-hold-view-manager-details',
            'sale-hold-view-history',
            'sale-hold-view-documents',
            'sale-hold-change-status',
            'sale-hold-filters',

            // Sale Sub Modules Pending On Hold Sales Permissions
            'sale-pending-hold-index',
            'sale-pending-hold-view',
            'sale-pending-hold-view-note',
            'sale-pending-hold-view-notes-history',
            'sale-pending-hold-view-manager-details',
            'sale-pending-hold-view-documents',
            'sale-pending-hold-mark-approved',
            'sale-pending-hold-mark-dis-approved',
            'sale-pending-hold-filters',

            // Resources Permissions
            // Resource Sub Modules Direct Resources Permissions
            'resource-direct-index',
            'resource-direct-filters',
            'resource-direct-send-email-btn',

            // Resource Sub Modules Indirect Resources Permissions
            'resource-indirect-index',
            'resource-indirect-filters',
            'resource-indirect-add-updated-data-btn',
            'resource-indirect-view-notes-history',
            'resource-indirect-download-resume',
            'resource-indirect-upload-resume',
            'resource-indirect-add-note',
            'resource-indirect-view-note',

            // Resource Sub Modules Category Wised Resources Permissions
            'resource-category-index',
            'resource-category-filters',
            'resource-category-view-note',
            'resource-category-add-note',
            'resource-category-view-notes-history',
            'resource-category-create-nursing-home-btn',
            'resource-category-download-resume',
            'resource-category-upload-applicant-resume',
            'resource-category-upload-crm-resume',
            'resource-category-view',

            // Resource Sub Modules Rejected Resources Permissions
            'resource-rejected-index',
            'resource-rejected-filters',
            'resource-rejected-view-note',
            'resource-rejected-view',
            'resource-rejected-view-notes-history',
            'resource-rejected-export',
            'resource-rejected-export-all',
            'resource-rejected-export-emails',

            // Resource Sub Modules Blocked Resources Permissions
            'resource-blocked-index',
            'resource-blocked-filters',
            'resource-blocked-view-note',
            'resource-blocked-add-note',
            'resource-blocked-view',
            'resource-blocked-view-notes-history',
            'resource-blocked-export',
            'resource-blocked-export-all',
            'resource-blocked-export-emails',
            'resource-blocked-mark-unblock',

            // Resource Sub Modules Not Interested Resources Permissions
            'resource-not-interested-index',
            'resource-not-interested-filters',
            'resource-not-interested-view',
            'resource-not-interested-view-notes-history',
            'resource-not-interested-revert',

            // Resource Sub Modules CRM Paid Resources Permissions
            'resource-crm-paid-index',
            'resource-crm-paid-view-note',
            'resource-crm-paid-view',
            'resource-crm-paid-view-notes-history',
            'resource-crm-paid-filters',
            'resource-crm-paid-export',
            'resource-crm-paid-export-all',
            'resource-crm-paid-export-emails',

            // Resource Sub Modules No Job Resources Permissions
            'resource-no-job-index',
            'resource-no-job-filters',
            'resource-no-job-view-note',
            'resource-no-job-add-note',
            'resource-no-job-view-notes-history',
            'resource-no-job-view',
            'resource-no-job-export',
            'resource-no-job-export-all',
            'resource-no-job-export-emails',
            'resource-no-job-revert-btn',


            // Quality Assurance Permissions
            // Quality Assurance Sub Modules Resources Permissions
            'quality-assurance-resource-index',
            'quality-assurance-resource-filters',
            'quality-assurance-resource-view-note',
            'quality-assurance-resource-view-notes-history',
            'quality-assurance-resource-view-job-details',
            'quality-assurance-resource-manager-details',
            'quality-assurance-resource-change-status',
            'quality-assurance-resource-open-cv',
            'quality-assurance-resource-clear-cv',
            'quality-assurance-resource-reject-cv',
            'quality-assurance-resource-revert-cv',
            'quality-assurance-resource-upload-resume',
            'quality-assurance-resource-download-resume',

            // Quality Assurance Sub Modules Sales Permissions
            'quality-assurance-sale-index',
            'quality-assurance-sale-filters',
            'quality-assurance-sale-view-note',
            'quality-assurance-sale-view-notes-history',
            'quality-assurance-sale-view',
            'quality-assurance-sale-manager-details',
            'quality-assurance-sale-change-status',
            'quality-assurance-sale-view-documents',
            'quality-assurance-sale-clear',
            'quality-assurance-resources-tab-requested-cvs',
            'quality-assurance-resources-tab-open-cvs',
            'quality-assurance-resources-tab-no-job-cvs',
            'quality-assurance-resources-tab-rejected-cvs',
            'quality-assurance-resources-tab-cleared-cvs',

            // Regions Permissions
            // Region Sub Modules Resources Permissions
            'region-resource-index',
            'region-resource-filters',
            'region-resource-view-note',
            'region-resource-add-note',
            'region-resource-view-notes-history',
            'region-resource-view',
            'region-resource-download-resume',
            'region-export',
            'region-resource-export-all',
            'region-resource-export-emails',

            // Region Sub Modules Sales Permissions
            'region-sale-index',
            'region-sale-filters',
            'region-sale-view-note',
            'region-sale-view',
            'region-sale-view-notes-history',
            'region-sale-view-documents',
            'region-sale-view-manager-details',

            // CRM Permissions
            'crm-index',
            'crm-filters',
            'crm-view-note',
            'crm-add-note',
            'crm-schedule-interview',
            'crm-send-confirmation',
            'crm-accept-confirmation',
            'crm-accept-rebook',
            'crm-accept-attended',
            'crm-accept-start-date',
            'crm-accept-invoice',
            'crm-accept-invoice-sent',
            'crm-view-notes-history',
            'crm-view-manager-details',
            'crm-send-request',
            'crm-revert',
            'crm-revert-in-quality',
            'crm-revert-request',
            'crm-paid-toggle-status',
            'crm-paid-revert',

            // CRM listing
            'crm-sent-cv-list',
            'crm-open-cv-list',
            'crm-request-list',
            'crm-sent-cv-no-job-list',
            'crm-rejected-cv-list',
            'crm-request-no-job-list',
            'crm-rejected-by-request-list',
            'crm-confirmation-list',
            'crm-rebook-list',
            'crm-attended-to-pre-start-date-list',
            'crm-declined-list',
            'crm-not-attended-list',
            'crm-start-date-list',
            'crm-start-date-hold-list',
            'crm-invoice-list',
            'crm-invoice-sent-list',
            'crm-dispute-list',
            'crm-paid-list',

            // CRM filters
            'crm-filter-sent-cv',
            'crm-filter-open-cv',
            'crm-filter-sent-cv-no-job',
            'crm-filter-rejected-cv',
            'crm-filter-request',
            'crm-filter-request-no-job',
            'crm-filter-request-rejected',
            'crm-filter-confirmation',
            'crm-filter-rebook',
            'crm-filter-attended',
            'crm-filter-not-attended',
            'crm-filter-declined',
            'crm-filter-start-date',
            'crm-filter-start-date-hold',
            'crm-filter-invoice',
            'crm-filter-invoice-sent',
            'crm-filter-dispute',
            'crm-filter-paid',

            // Message Permissions
            'message-index',

            // Email Permissions
            'email-index',

            // sent email Permissions
            'sent-email-index',

            // Postcode Permissions
            'postcode-index',

            // Scrap Permissions
            'scrap-index',

            // Report Permissions
            'report-user-login',

            //Administrator Permissions
            'administrator-user-index',
            'administrator-user-create',
            'administrator-user-edit',
            'administrator-user-delete',
            'administrator-user-change-password',
            'administrator-user-view',
            'administrator-user-activity-log',
            'administrator-user-export',
            'administrator-user-export-all',
            'administrator-user-import',
            'administrator-user-filters',
            'administrator-user-change-status',
            'administrator-user-assign-role',

            // Administrator Sub Modules Role Permissions
            'administrator-role-index',
            'administrator-role-create',
            'administrator-role-edit',
            'administrator-role-delete',

            // Administrator Sub Modules Permission Permissions
            'administrator-permission-index',
            'administrator-permission-create',
            'administrator-permission-edit',
            'administrator-permission-delete',

            // Administrator Sub Modules IP Address Permissions
            'administrator-ip-address-index',
            'administrator-ip-address-create',
            'administrator-ip-address-edit',
            'administrator-ip-address-delete',
            'administrator-ip-address-export',
            'administrator-ip-address-export-all',

            // Administrator Sub Modules Job Category Permissions
            'administrator-job-category-index',
            'administrator-job-category-create',
            'administrator-job-category-edit',
            'administrator-job-category-delete',

            // Administrator Sub Modules Job Title Permissions
            'administrator-job-title-index',
            'administrator-job-title-create',
            'administrator-job-title-edit',
            'administrator-job-title-delete',

            // Administrator Sub Modules Job Source Permissions
            'administrator-job-source-index',
            'administrator-job-source-create',
            'administrator-job-source-edit',
            'administrator-job-source-delete',

            // Administrator Sub Modules Email Templates Permissions
            'administrator-email-template-index',
            'administrator-email-template-create',
            'administrator-email-template-edit',
            'administrator-email-template-delete',

            // Administrator Sub Modules SMS Template Permissions
            'administrator-sms-template-index',
            'administrator-sms-template-create',
            'administrator-sms-template-edit',
            'administrator-sms-template-delete',

            // Administrator Sub Modules Settings Permissions
            'administrator-setting-index',

            //settings Permissions
            'show-private-data',

            // Xplogent (AI phone agent) Permissions
            'xplogent-agent-call',
            'xplogent-view-transcript',

        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }
    }
}
