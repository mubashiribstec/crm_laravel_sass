<?php

namespace App\Services;

use Horsefly\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class EmailLayoutRenderer
{
    private const SETTING_KEY = 'email_layout_html';

    public function templateForEditing(): string
    {
        $template = Setting::where('key', self::SETTING_KEY)->value('value');

        return is_string($template) && $template !== '' ? $template : $this->defaultTemplate();
    }

    public function render(string $title, string $subject, string $content, bool $showSignature = true): string
    {
        $user = Auth::user();
        $senderName = $user?->pseudo_name ?: $user?->name ?: 'Kingsbury Personnel';
        $primaryEmail = Setting::where('key', 'primary_email_address')->value('value')
            ?: 'info@kingsburypersonnel.com';
        $signatureSettings = Setting::whereIn('key', [
            'email_signature_company_name',
            'email_signature_phone',
            'email_signature_email',
            'email_signature_address',
            'email_signature_image',
        ])->pluck('value', 'key');
        $companyName = $signatureSettings->get('email_signature_company_name') ?: 'Kingsbury Personnel';
        $phone = $signatureSettings->get('email_signature_phone') ?: '01494 418312';
        $signatureEmail = $signatureSettings->get('email_signature_email') ?: '';
        $signatureAddress = $signatureSettings->get('email_signature_address') ?: '';
        $signatureImagePath = $signatureSettings->get('email_signature_image');
        $signatureImageUrl = asset('images/Kingsbury-Personnel-Logo.png');
        if ($signatureImagePath) {
            if (str_starts_with($signatureImagePath, 'uploads/') && is_file(public_path($signatureImagePath))) {
                $signatureImageUrl = asset($signatureImagePath);
            } elseif (Storage::disk('public')->exists($signatureImagePath)) {
                $signatureImageUrl = Storage::disk('public')->url($signatureImagePath);
            }
        }
        $logoUrl = asset('images/Kingsbury-Personnel-Logo.png');
        $template = $this->templateForEditing();
        $template = preg_replace_callback(
            '/(<!-- EMAIL_SIGNATURE_START -->)(.*?)(<!-- EMAIL_SIGNATURE_END -->)/s',
            function ($matches) use ($primaryEmail, $signatureEmail, $companyName, $phone, $signatureImageUrl) {
                $signature = str_replace(
                    '{{ primary_email }}',
                    '{{ signature_email }}',
                    $matches[2]
                );
                $legacyEmailAddresses = array_filter(array_unique([
                    'info@kingsburypersonnel.com',
                    $primaryEmail,
                ]));
                $signature = str_replace(
                    array_merge($legacyEmailAddresses, ['Kingsbury Personnel', 'Phone: 01494 418312']),
                    array_merge(
                        array_fill(0, count($legacyEmailAddresses), e($signatureEmail)),
                        [e($companyName), 'Phone: ' . e($phone)]
                    ),
                    $signature
                );
                $signature = preg_replace_callback(
                    '/(<img\b[^>]*\bsrc=)(["\'])[^"\']*(\2)/i',
                    fn ($imageMatches) => $imageMatches[1] . '"'
                        . e($signatureImageUrl) . '"',
                    $signature,
                    1
                ) ?? $signature;

                return $matches[1] . $signature . $matches[3];
            },
            $template
        ) ?? $template;
        $template = str_replace(
            'This email was sent by Kingsbury Personnel.',
            e($signatureAddress),
            $template
        );

        if (!$showSignature) {
            $template = preg_replace(
                '/<!-- EMAIL_SIGNATURE_START -->.*?<!-- EMAIL_SIGNATURE_END -->/s',
                '',
                $template
            );
        }

        return strtr($template, [
            '{{ email_title }}' => e($title),
            '{{ email_subject }}' => e($subject),
            '{{ email_content }}' => $content,
            '{{ sender_name }}' => e($senderName),
            '{{ logo_url }}' => e($logoUrl),
            '{{ primary_email }}' => e($primaryEmail),
            '{{ signature_email }}' => e($signatureEmail),
            '{{ signature_address }}' => e($signatureAddress),
            '{{ signature_company_name }}' => e($companyName),
            '{{ signature_phone }}' => e($phone),
            '{{ signature_image_url }}' => e($signatureImageUrl),
        ]);
    }

    private function defaultTemplate(): string
    {
        $signature = '<!-- EMAIL_SIGNATURE_START -->'
            . view('emails.partials.signature', [
                'senderName' => '{{ sender_name }}',
                'signatureImageUrl' => '{{ signature_image_url }}',
                'companyName' => '{{ signature_company_name }}',
                'phone' => '{{ signature_phone }}',
                'signatureEmail' => '{{ signature_email }}',
            ])->render()
            . '<!-- EMAIL_SIGNATURE_END -->';

        return view('emails.email-layout', [
            'title' => '{{ email_title }}',
            'subject' => '{{ email_subject }}',
            'content' => '{{ email_content }}',
            'signature' => $signature,
            'senderName' => '{{ sender_name }}',
            'logoUrl' => '{{ logo_url }}',
            'signatureAddress' => '{{ signature_address }}',
        ])->render();
    }
}
