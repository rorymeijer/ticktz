<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket->key }}</title>
</head>
{{--
    Deliberately plain: inline styles, a table-free single column and a system
    font stack. Service desk mail is read in Outlook, on phones, and in
    screen readers, and none of them reward cleverness.
--}}
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#0f172a;">
    <div style="max-width:600px; margin:0 auto; padding:24px 16px;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
            <div style="padding:16px 24px; border-bottom:1px solid #e2e8f0;">
                <span style="font-size:13px; font-weight:600; color:#4f46e5; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                    {{ $ticket->key }}
                </span>
                <span style="font-size:13px; color:#64748b;"> · {{ $ticket->subject }}</span>
            </div>

            <div style="padding:24px; font-size:15px; line-height:1.6; color:#334155; white-space:pre-wrap;">{{ $body }}</div>
        </div>

        <p style="margin:16px 0 0; font-size:12px; line-height:1.5; color:#64748b;">
            {{ __('mail.footer.reply_hint') }}
            @if ($channel?->address)
                <br>{{ $channel->address }}
            @endif
        </p>
    </div>
</body>
</html>
