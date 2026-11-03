<?php

namespace App\Http\Controllers;

use App\Support\CvStorage;
use Horsefly\Applicant;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET applicants/{id}/cv/{kind}: the only way to fetch a CV (kind: original | updated).
 *
 * Signed-in users only, holding one of DOWNLOAD_PERMISSIONS, and a blocked applicant's CV
 * also needs applicant-show-blocked-data (the rule the CV icons already followed).
 */
class ApplicantCvController extends Controller
{
    /**
     * Everyone who saw a CV icon before keeps it: the download permissions, plus the page
     * permissions of the lists that showed the icon without a download check (sales radius,
     * quality resources, regions, category-wise and no-job resources). Remove page
     * permissions from this list to make the download permissions the only way in.
     */
    public const DOWNLOAD_PERMISSIONS = [
        'applicant-download-resume',
        'resource-indirect-download-resume',
        'resource-category-download-resume',
        'region-resource-download-resume',
        'quality-assurance-resource-download-resume',
        'resource-no-job-index',
        'resource-category-index',
        'region-resource-index',
        'sale-view',
        'sale-direct-view',
        'sale-open-view',
        'quality-assurance-resources-tab-requested-cvs',
        'quality-assurance-resources-tab-open-cvs',
        'quality-assurance-resources-tab-no-job-cvs',
        'quality-assurance-resources-tab-rejected-cvs',
        'quality-assurance-resources-tab-cleared-cvs',
    ];

    public function __construct()
    {
        $this->middleware('permission:' . implode(',', self::DOWNLOAD_PERMISSIONS));
    }

    public function download(int $id, string $kind): BinaryFileResponse
    {
        abort_unless(isset(CvStorage::KINDS[$kind]), 404);
        $applicant = Applicant::findOrFail($id);
        abort_unless(CvStorage::visible((bool) $applicant->is_blocked), 403, 'This applicant is blocked.');

        $path = CvStorage::existingPath($applicant->{CvStorage::KINDS[$kind]});
        abort_if($path === null, 404, 'CV not found.');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = (Str::slug((string) $applicant->applicant_name) ?: 'applicant-' . $applicant->id)
            . ($kind === 'updated' ? '-crm-cv' : '-cv') . ($extension !== '' ? '.' . $extension : '');
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        // PDFs open in the browser's viewer as before; everything else (Word, text, and any
        // odd older file) downloads, so nothing a candidate uploaded is rendered as a page.
        if ($extension === 'pdf') {
            return response()->file($path, $headers + [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $name . '"',
            ]);
        }

        return response()->download($path, $name, $headers + ['Content-Type' => 'application/octet-stream']);
    }
}
