<?php

namespace App\Services\Xplogent;

use Horsefly\EmailTemplate;
use Horsefly\JobCategory;
use Horsefly\Sale;
use Horsefly\SentEmail;
use Horsefly\Unit;
use Horsefly\User;

/**
 * The "send_job_vacancy_details" email: filling the template for a sale, and
 * queueing it in sent_emails (status '0') for the emails:send-bulk cron, the
 * way the CRM sends vacancy emails today. Extracted from
 * CommunicationController::sendEmailsToApplicants, which now calls fill().
 */
class VacancyEmailService
{
    public const TEMPLATE_SLUG = 'send_job_vacancy_details';

    /**
     * @return array{subject: string, body: string, from: string, title: string}|null
     *         null when the template is missing or inactive.
     */
    public function fill(Sale $sale, string $agentName): ?array
    {
        // The sale's own unit (an office can have several); the office's first unit only for
        // an old sale with none.
        $unit = ($sale->unit_id ? Unit::find($sale->unit_id) : null)
            ?? Unit::where('office_id', $sale->office_id)->first();
        $jobCategory = JobCategory::where('id', $sale->job_category_id)->first();

        $siteName = \Horsefly\Setting::where('key', 'site_name')->value('value') ?: '-';
        $salary = $sale->salary ?? '-';

        // Placeholder => value. (agent_pseudo_name), (site_name) and (benefits) are the
        // current template's; (agent_name) is kept so older templates still fill.
        $values = [
            '(agent_pseudo_name)' => $agentName,
            '(agent_name)' => $agentName,
            '(site_name)' => $siteName,
            '(salary)' => $salary,
            '(benefits)' => $sale->benefits ?? '-',
            '(job_category)' => $jobCategory ? ucwords($jobCategory->name) : '-',
            '(unit_name)' => optional($unit)->unit_name ?? '-',
            '(qualification)' => $sale->qualification ?? '-',
            // (job_type) is the candidate's "full time / part time" on purpose:
            // sales.job_type is the CRM's internal specialist/regular class.
            '(job_type)' => ucwords((string) ($sale->position_type ?? '')) ?: '-',
            '(timing)' => $sale->timing ?? '-',
            '(experience)' => $sale->experience ?? '-',
            '(location)' => \App\Support\Xplogent::district($sale->sale_postcode) ?: '-',    // e.g. "LS6"
        ];

        $template = EmailTemplate::where('slug', self::TEMPLATE_SLUG)->where('is_active', 1)->first();
        if (!$template || empty($template->template)) {
            return null;
        }

        $body = str_replace(array_keys($values), array_values($values), $template->template);
        // The editor can wrap "salary" in tags, e.g. "(<b>salary</b>)", which str_replace misses.
        $body = preg_replace_callback(
            '/\(\s*(?:<[^>]*>\s*)*salary(?:\s*<[^>]*>)*\)/i',
            fn () => e((string) $salary),
            $body
        ) ?? $body;

        return [
            'subject' => (string) $template->subject,
            'body' => nl2br($body),
            'from' => (string) $template->from_email,
            'title' => (string) $template->title,
        ];
    }

    /**
     * Queue the filled email for one recipient, exactly like SendEmails::saveEmailDB.
     * $callId: the Xplogent call that asked for it (send-job-details), so a repeat is a no-op.
     */
    public function queue(array $filled, string $to, int $applicantId, int $saleId, User $by, ?int $callId = null): SentEmail
    {
        $sent = new SentEmail();
        $sent->action_name = strtolower(str_replace(' ', '_', $filled['title']));
        $sent->sent_from = $filled['from'];
        $sent->sent_to = $to;
        $sent->cc_emails = '';
        $sent->subject = $filled['subject'];
        $sent->title = $filled['title'];
        $sent->template = $filled['body'];
        $sent->applicant_id = $applicantId;
        $sent->sale_id = $saleId;
        $sent->user_id = $by->id;
        if ($callId !== null && self::tracksCalls()) {
            $sent->xplogent_call_id = $callId;
        }
        $sent->save();

        return $sent;
    }

    /** The vacancy emails an Xplogent call queued (optionally for one candidate and vacancy). */
    public function sentForCall(int $callId, ?int $applicantId = null, ?int $saleId = null): \Illuminate\Support\Collection
    {
        if (!self::tracksCalls()) {
            return collect();
        }

        return SentEmail::where('xplogent_call_id', $callId)
            ->when($applicantId !== null, fn ($q) => $q->where('applicant_id', $applicantId))
            ->when($saleId !== null, fn ($q) => $q->where('sale_id', $saleId))
            ->orderBy('id')->get();
    }

    /** Whether sent_emails has xplogent_call_id yet (its migration may not have run). */
    public static function tracksCalls(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn('sent_emails', 'xplogent_call_id');
    }
}
