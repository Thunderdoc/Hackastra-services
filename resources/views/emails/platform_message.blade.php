<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f5f9;font-family:Arial,Helvetica,sans-serif;color:#172536;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f5f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:92%;">
                    <tr>
                        <td style="background:#10263b;padding:20px 28px;">
                            <span style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;">HACKSTRA</span>
                            <span style="color:#9fb6cc;font-size:12px;display:block;margin-top:2px;">School Management Platform</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="font-size:18px;margin:0 0 16px;color:#10263b;">{{ $subjectLine }}</h1>
                            <div style="font-size:14px;line-height:1.65;color:#33475b;">{!! nl2br(e($bodyText)) !!}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;background:#eef2f7;color:#718096;font-size:12px;">
                            This message was sent by the Hackstra platform administration. Please do not reply to this email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
