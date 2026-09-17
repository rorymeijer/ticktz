<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket->key }}</title>
    {{--
        A <style> block, which is the one place a rich body can be styled at
        all: the markup comes out of the database, so there is nothing to
        inline styles onto. Every rule here is a nicety — a client that strips
        the block still renders a paragraph as a paragraph and a list as a
        list, because that is what the tags mean.
    --}}
    <style>
        .body p { margin: 0 0 12px; }
        .body p:last-child { margin-bottom: 0; }
        .body ul, .body ol { margin: 0 0 12px; padding-left: 22px; }
        .body li { margin: 0 0 4px; }
        .body blockquote { margin: 0 0 12px; padding-left: 12px; border-left: 3px solid #cbd5e1; color: #64748b; }
        .body pre { margin: 0 0 12px; padding: 10px; background: #0f172a; color: #e2e8f0; border-radius: 6px; overflow-x: auto; font-size: 13px; }
        .body code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        .body pre code { background: none; color: inherit; }
        .body :not(pre) > code { padding: 1px 4px; background: #f1f5f9; border-radius: 4px; }
        .body a { color: #4f46e5; }
        .body table { border-collapse: collapse; margin: 0 0 12px; }
        .body th, .body td { border: 1px solid #e2e8f0; padding: 4px 8px; text-align: left; }
        .body hr { border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0; }
    </style>
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

            {{--
                Unescaped, and safe: everything that reaches $body was either
                written by a template administrator and escaped by
                TicketMailer::renderHtml(), or is rich text that went through
                RichTextSanitizer on its way into the database. Neither can
                carry a script. If that ever stops being true, this line is
                where it lands in somebody's inbox.
            --}}
            <div class="body" style="padding:24px; font-size:15px; line-height:1.6; color:#334155;">{!! $body !!}</div>
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
