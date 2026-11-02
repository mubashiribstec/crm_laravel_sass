<?php

namespace App\Services\Xplogent;

use Horsefly\Applicant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use Spatie\PdfToText\Pdf;

/**
 * A short, redacted plain-text excerpt of an applicant's CV (opt-in:
 * XPLOGENT_SHARE_CV_EXCERPT). The file itself never leaves the CRM. Emails,
 * phone numbers, UK postcodes and date-of-birth lines are removed; the result
 * is capped at 2,000 characters and cached per file version.
 */
class CvExcerpt
{
    public const MAX = 2000;

    public function excerpt(Applicant $applicant): string
    {
        $path = $this->resolve($applicant->updated_cv) ?? $this->resolve($applicant->applicant_cv);
        if ($path === null) {
            return '';
        }
        $key = 'xplogent:cv:' . md5($path . '|' . @filemtime($path));

        return Cache::remember($key, now()->addDays(7), function () use ($path) {
            try {
                return self::redact($this->text($path));
            } catch (\Throwable $e) {
                Log::warning('xplogent cv excerpt failed: ' . $e->getMessage());
                return '';
            }
        });
    }

    /**
     * Remove contact details and anything that looks like a date of birth, then cap.
     *
     * $keepRecentDates is for recruiter notes: a numeric date from the last 14 years stays
     * ("callback 12/11/2026"), an older one is likely a date of birth and goes. A date
     * labelled DOB / date of birth / born always goes.
     */
    public static function redact(string $text, bool $keepRecentDates = false): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        // Phone numbers: 10+ digits (keeps work-history years like "2015 - 2019").
        $text = preg_replace_callback('/\+?\d[\d\s().-]{8,}\d/', fn ($m) =>
            strlen(preg_replace('/\D/', '', $m[0])) >= 10 ? '[phone]' : $m[0], $text);
        $text = preg_replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', '[postcode]', $text);
        $text = preg_replace('/\b(?:d\.?o\.?b\.?|date\s+of\s+birth|born)\b[\s:]*'
            . '(?:\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}|[^\n.;,]{0,25})/i', '[date of birth]', $text);
        $oldest = (int) date('Y') - 14;
        $text = preg_replace_callback('/\b\d{1,2}[\/.-]\d{1,2}[\/.-]((?:19|20)\d{2})\b/', fn ($m) =>
            $keepRecentDates && (int) $m[1] > $oldest ? $m[0] : '[date]', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::MAX ? rtrim(mb_substr($text, 0, self::MAX - 1)) . '…' : $text;
    }

    private function resolve(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || str_contains($stored, '..')) {
            return null;
        }
        foreach ([public_path($stored), public_path('storage/' . $stored), storage_path('app/public/' . $stored),
                     storage_path('app/' . $stored)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function text(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return Pdf::getText($path);
        }
        if (in_array($ext, ['docx', 'doc', 'odt', 'rtf'], true)) {
            $reader = ['docx' => 'Word2007', 'doc' => 'MsDoc', 'odt' => 'ODText', 'rtf' => 'RTF'][$ext];
            $doc = IOFactory::load($path, $reader);
            $parts = [];
            foreach ($doc->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $parts[] = $this->elementText($element);
                }
            }

            return implode("\n", array_filter($parts));
        }
        if ($ext === 'txt') {
            return (string) file_get_contents($path, false, null, 0, 200000);
        }

        return '';
    }

    private function elementText($element): string
    {
        if (method_exists($element, 'getText')) {
            $t = $element->getText();
            if (is_string($t)) {
                return $t;
            }
        }
        if (method_exists($element, 'getElements')) {
            return implode(' ', array_map(fn ($e) => $this->elementText($e), $element->getElements()));
        }

        return '';
    }
}
