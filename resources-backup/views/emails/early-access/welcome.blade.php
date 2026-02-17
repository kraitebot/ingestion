{{-- resources/views/emails/early-access/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <title>{{ $appName }} — Early access</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!--[if mso]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:AllowPNG/>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->
    <style>
        /* Base resets for email clients */
        html, body { margin:0 !important; padding:0 !important; height:100% !important; width:100% !important; }
        * { -ms-text-size-adjust:100%; -webkit-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt !important; mso-table-rspace:0pt !important; }
        table { border-collapse:collapse !important; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; display:block; }
        a { text-decoration:none; }
        /* iOS auto-link fix */
        a[x-apple-data-detectors], .unstyle-auto-detected-links a, .aBn { border-bottom:0 !important; cursor:default !important; color:inherit !important; text-decoration:none !important; }
        /* Gmail iOS right-gutter fix */
        u + .body .email-container { min-width:100% !important; }
        /* Responsive tweaks */
        @media screen and (max-width: 600px) {
            .email-container { width:100% !important; }
            .stack-sm { display:block !important; width:100% !important; }
            .px-sm-16 { padding-left:16px !important; padding-right:16px !important; }
            .py-sm-16 { padding-top:16px !important; padding-bottom:16px !important; }
            h1 { font-size:24px !important; line-height:1.35 !important; }
            .brand { font-size:22px !important; }
            .copy { font-size:16px !important; line-height:1.7 !important; }
        }
        /* Optional dark-mode friendly colors (supported clients only) */
        @media (prefers-color-scheme: dark) {
            body, .bg-page { background:#0c0a0b !important; }
            .card { background:#0e1428 !important; border-color:#1f2937 !important; }
            .text-900 { color:#e5e7eb !important; }
            .text-700 { color:#cbd5e1 !important; }
            .text-500 { color:#94a3b8 !important; }
            .brand { color:#f87171 !important; }
        }
    </style>
</head>
<body class="body bg-page" style="margin:0;padding:0;background-color:#f7f7f7;">
    <!-- Hidden preheader text: improves inbox preview -->
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        You're on the {{ $appName }} early-access list—look out for your discount coupon and launch updates.
    </div>

    <!-- Full-width background table -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-page" style="background:#f7f7f7;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <!--[if mso]>
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="640"><tr><td>
                <![endif]-->
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" class="email-container" style="max-width:640px;">

                    <!-- Brand header -->
                    <tr>
                        <td align="center" class="px-sm-16" style="padding:0 32px 16px 32px;">
                            <div class="brand" style="
                                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol',sans-serif;
                                font-size:24px; font-weight:800; letter-spacing:0.4px; color:#111827; text-align:center;">
                                Martingalian
                            </div>
                        </td>
                    </tr>

                    <!-- Card -->
                    <tr>
                        <td style="padding:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                   class="card" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                <tr>
                                    <td class="px-sm-16 py-sm-16" style="padding:28px 32px 8px 32px;
                                        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol',sans-serif;">

                                        {{-- Optional small logo aligned right (pass $logoCid from Mailable) --}}
                                        @isset($logoCid)
                                            <div style="text-align:right;margin-bottom:8px;">
                                                <img src="cid:{{ $logoCid }}" alt="{{ $appName }} logo" width="24" height="24"
                                                     style="display:inline-block;border:0;outline:none;text-decoration:none;">
                                            </div>
                                        @endisset

                                        <p class="copy text-700" style="margin:0 0 18px 0;font-size:17px;line-height:1.75;color:#374151;">
                                            Hi there!
                                        </p>

                                        <p class="copy text-700" style="margin:0 0 18px 0;font-size:17px;line-height:1.75;color:#374151;">
                                            Thanks for joining the early-access list. You will benefit from a special discount coupon to reward your trust and interest on our crypto bot. You will not regret it.
                                        </p>

                                        <p class="copy text-700" style="margin:0 0 18px 0;font-size:17px;line-height:1.75;color:#374151;">
                                            We will send you another communication when we are close to the launch date. Until then, you can reply to this email in case you have any question.
                                        </p>

                                        <p class="copy text-700" style="margin:24px 0 0 0;font-size:17px;line-height:1.75;color:#374151;">
                                            Thanks,<br>
                                            The Martingalian Team
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="px-sm-16 py-sm-16" style="padding:16px 32px 28px 32px;
                                        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol',sans-serif;border-top:1px solid #f3f4f6;">
                                        <p class="text-500" style="margin:0 0 6px 0;font-size:12px;line-height:1.7;color:#6b7280;">
                                            Sent to {{ $email }} because you requested early access to {{ $appName }}.
                                        </p>
                                        <p class="text-500" style="margin:0;font-size:12px;line-height:1.7;color:#9ca3af;">
                                            © {{ date('Y') }} {{ $appName }}. All rights reserved.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Spacer under card -->
                    <tr><td style="height:24px; line-height:24px;">&nbsp;</td></tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
