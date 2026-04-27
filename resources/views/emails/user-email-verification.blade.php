<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your TALK TO CAS account</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:28px 32px;background:linear-gradient(135deg,#08111d,#10213c);color:#ffffff;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;opacity:0.82;">TALK TO CAS</div>
                            <h1 style="margin:12px 0 8px;font-size:28px;line-height:1.2;">Verify your email</h1>
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#d6e3f5;">Complete your temporary email-based signup so you can enter the user dashboard.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Hi {{ $name }},</p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#334155;">
                                Click the button below to verify your email address for TALK TO CAS. This link stays active for {{ $expiresInMinutes }} minutes.
                            </p>
                            <p style="margin:28px 0;">
                                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 22px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:12px;font-weight:700;">Verify my email</a>
                            </p>
                            <p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#475569;">
                                If the button does not open, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 18px;font-size:13px;line-height:1.7;word-break:break-all;color:#1d4ed8;">{{ $verificationUrl }}</p>
                            <p style="margin:0;font-size:14px;line-height:1.7;color:#64748b;">
                                This is the temporary email verification flow. Phone verification can be added later without changing the rest of your user registration screens.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
