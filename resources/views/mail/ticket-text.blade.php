{{ $ticket->key }} — {{ $ticket->subject }}

{{ $body }}

--
{{ __('mail.footer.reply_hint') }}
@if ($channel?->address)
{{ $channel->address }}
@endif
