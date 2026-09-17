{{ $ticket->key }} — {{ $ticket->subject }}

{{ $text }}

--
{{ __('mail.footer.reply_hint') }}
@if ($channel?->address)
{{ $channel->address }}
@endif
