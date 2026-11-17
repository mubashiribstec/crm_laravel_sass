@if ($isCandidateApplication ?? false)
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? $title ?? 'New Candidate Application' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e4e8ee;">
                    <tr>
                        <td style="background:#0b1f3a;padding:28px 32px;">
                            <p style="margin:0 0 8px;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#c6831d;font-weight:700;">Kingsbury Personnel</p>
                            <h1 style="margin:0;font-size:24px;line-height:1.3;color:#ffffff;font-weight:700;">{{ $title ?? 'New candidate application' }}</h1>
                            {{-- <p style="margin:10px 0 0;color:#c9d2dc;font-size:14px;line-height:1.5;">A candidate has submitted their profile through the website. Their CV is attached to this email.</p> --}}
                        </td>
                    </tr>
                   
                   
                    <tr>
                        <td style="padding:20px 32px 28px;">
                            {!! $content ?? '' !!}
                            {!! $signature ?? '' !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#08172b;padding:18px 32px;">
                            <p style="margin:0;font-size:11px;color:#6f8296;">This message was sent from the Kingsbury Personnel candidate form.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
@else
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? $title ?? 'New Candidate Application' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e4e8ee;">
                    <tr>
                        <td style="background:#0b1f3a;padding:28px 32px;">
                            <p style="margin:0 0 8px;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#c6831d;font-weight:700;">Kingsbury Personnel</p>
                            <h1 style="margin:0;font-size:24px;line-height:1.3;color:#ffffff;font-weight:700;">{{ $title ?? 'New candidate application' }}</h1>
                            {{-- <p style="margin:10px 0 0;color:#c9d2dc;font-size:14px;line-height:1.5;">A candidate has submitted their profile through the website. Their CV is attached to this email.</p> --}}
                        </td>
                    </tr>
                   
                   
                    <tr>
                        <td style="padding:20px 32px 28px;">
                            {!! $content ?? '' !!}
                            {!! $signature ?? '' !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#08172b;padding:18px 32px;">
                            <p style="margin:0;font-size:11px;color:#6f8296;">{{ $signatureAddress ?? '' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
@endif
