<!doctype html>
<html>
<head><meta charset="utf-8"><title>Merchant Approval Needed</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f7fb;padding:24px;color:#0f172a;">
  <div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e2e8f0;">
    <h1 style="margin:0 0 16px;font-size:24px;">New merchant registration</h1>
    <p>A new merchant is waiting for admin approval.</p>
    <ul>
      <li><strong>Business:</strong> {{ $merchant->business_name }}</li>
      <li><strong>Type:</strong> {{ $merchant->business_type }}</li>
      <li><strong>Email:</strong> {{ $merchant->contact_email }}</li>
      <li><strong>Phone:</strong> {{ $merchant->contact_phone }}</li>
    </ul>
    <p>Please review the request in the admin dashboard.</p>
  </div>
</body>
</html>
