<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;padding-top:28px;border-top:2px solid #c9a45c;font-family:Arial,Helvetica,sans-serif;">
    <tbody>
        <tr>
            <td style="padding:0 18px 0 0;border-right:1px solid #e8edf2;vertical-align:middle;">
                <img src="{{ $signatureImageUrl }}" alt="{{ $companyName }}" width="100" style="display:block;width:100px;max-width:100px;height:auto;">
            </td>
            <td style="padding:0 0 0 18px;vertical-align:middle;">
                <p style="margin:0 0 5px;color:#566273;font-size:13px;">Warm regards,</p>
                <p style="margin:0;color:#263445;font-size:16px;font-weight:700;">{{ $senderName }}</p>
                <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">{{ $companyName }}</p>
                <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">Phone: {{ $phone }}</p>
                <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">{{ $signatureEmail }}</p>
            </td>
        </tr>
    </tbody>
</table>
