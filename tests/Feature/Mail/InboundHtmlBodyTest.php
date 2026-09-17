<?php

declare(strict_types=1);

use App\Services\Mail\InboundHtmlBody;

/**
 * Every fixture here is the shape a real client emits. The hard part of
 * inbound mail is not the markup, it is knowing where the person stopped
 * writing, and every client marks that differently.
 */
beforeEach(function (): void {
    $this->body = new InboundHtmlBody;
});

it('keeps the reply and drops what Gmail quoted', function (): void {
    $html = <<<'HTML'
        <div dir="ltr">Still broken, I am afraid.<div><br></div><div>Tried it twice.</div></div>
        <div class="gmail_quote">
          <div dir="ltr" class="gmail_attr">On Tue, 3 Jun 2025 at 09:12, Service Desk &lt;desk@example.org&gt; wrote:<br></div>
          <blockquote class="gmail_quote"><p>Have you tried turning it off?</p></blockquote>
        </div>
        HTML;

    $kept = $this->body->extract($html);

    expect($kept)->toContain('Still broken')
        ->and($kept)->toContain('Tried it twice')
        ->and($kept)->not->toContain('turning it off')
        ->and($kept)->not->toContain('Service Desk');
});

it('cuts at the header Outlook puts above the thread', function (): void {
    $html = <<<'HTML'
        <div>Thanks, that worked.</div>
        <div id="appendonsend"></div>
        <hr>
        <div id="divRplyFwdMsg">
          <b>From:</b> Service Desk<br><b>Sent:</b> Tuesday 3 June<br>
        </div>
        <div>Have you tried turning it off?</div>
        HTML;

    $kept = $this->body->extract($html);

    expect($kept)->toContain('that worked')
        ->and($kept)->not->toContain('turning it off')
        ->and($kept)->not->toContain('Sent:');
});

it('cuts at an Apple Mail cited blockquote', function (): void {
    $html = <<<'HTML'
        <div>It is working again.</div>
        <br>
        <div><blockquote type="cite"><div>Have you tried turning it off?</div></blockquote></div>
        HTML;

    $kept = $this->body->extract($html);

    expect($kept)->toContain('working again')
        ->and($kept)->not->toContain('turning it off');
});

it('cuts at the line Thunderbird writes', function (): void {
    $html = <<<'HTML'
        <p>Nog steeds stuk.</p>
        <div class="moz-cite-prefix">Op 03-06-2025 om 09:12 schreef Service Desk:</div>
        <blockquote><p>Heb je hem uit en aan gezet?</p></blockquote>
        HTML;

    $kept = $this->body->extract($html);

    expect($kept)->toContain('Nog steeds stuk')
        ->and($kept)->not->toContain('uit en aan');
});

/**
 * Plenty of clients mark the boundary with nothing but the sentence itself.
 */
it('cuts at a bare attribution line', function (string $attribution): void {
    $html = '<p>One more thing.</p><p>'.$attribution.'</p><blockquote><p>Earlier message.</p></blockquote>';

    $kept = $this->body->extract($html);

    expect($kept)->toContain('One more thing')
        ->and($kept)->not->toContain('Earlier message');
})->with([
    'english' => 'On Tue, 3 Jun 2025 at 09:12, Service Desk wrote:',
    'dutch' => 'Op 3 juni 2025 om 09:12 schreef Service Desk:',
    'german' => 'Am 03.06.2025 um 09:12 schrieb Service Desk:',
    'outlook separator' => '-----Original Message-----',
    'dutch separator' => '----- Oorspronkelijk bericht -----',
    'header line' => 'From: desk@example.org',
]);

// -----------------------------------------------------------------
// What must not be cut
// -----------------------------------------------------------------

/**
 * Over-trimming loses what the customer wrote, which is far worse than a reply
 * carrying one quoted paragraph too many.
 */
it('leaves a mail alone when it has no quote in it', function (): void {
    $html = '<p>The lift is stuck on floor 3.</p><p>Nobody is inside it.</p>';

    expect($this->body->extract($html))->toContain('floor 3')
        ->and($this->body->extract($html))->toContain('Nobody is inside');
});

it('keeps a bare forward whole rather than returning nothing', function (): void {
    $html = '<div class="gmail_quote"><blockquote><p>The whole message.</p></blockquote></div>';

    expect($this->body->extract($html))->toContain('The whole message');
});

it('does not read a sentence that mentions a header as a header', function (): void {
    $html = '<p>The bounce says "From: postmaster" and I do not know what that means. '
        .'It happens every time I send to the finance list, which is why I am asking.</p>';

    expect($this->body->extract($html))->toContain('do not know what that means');
});

it('keeps the formatting of what it does keep', function (): void {
    $html = '<p>Two things:</p><ul><li>It is <strong>slow</strong></li><li>And <em>loud</em></li></ul>'
        .'<div class="gmail_quote"><p>Earlier.</p></div>';

    $kept = $this->body->extract($html);

    expect($kept)->toContain('<ul>')
        ->and($kept)->toContain('<strong>slow</strong>')
        ->and($kept)->toContain('<em>loud</em>')
        ->and($kept)->not->toContain('Earlier');
});

it('reads a reply in a language that is not English without mangling it', function (): void {
    $kept = $this->body->extract('<p>Hij doet het weer — dankjewel! Groeten, Renée</p>');

    expect($kept)->toContain('Renée')->and($kept)->toContain('—');
});

it('says nothing for an empty body', function (string $html): void {
    expect($this->body->extract($html))->toBe('');
})->with(['', '   ']);
