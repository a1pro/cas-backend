<!doctype html>
<html>
<head><meta charset="utf-8"><title>Merchant Approved</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f7fb;padding:24px;color:#0f172a;">
  <div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e2e8f0;">
    <h1 style="margin:0 0 16px;font-size:24px;">Your merchant account is approved</h1>
    <p>Hi {{ $merchant->user?->name ?? $merchant->business_name }},</p>
    <p>Your venue <strong>{{ $merchant->business_name }}</strong> has been approved on TALK TO CAS.</p>
    <p>You can now log in and access the merchant dashboard.</p>
    <p style="margin-top:24px;">Regards,<br>TALK TO CAS</p>
  </div>
</body>
</html>
