<?php

declare(strict_types=1);

use App\Services\RichText\RichTextProfile;
use App\Services\RichText\RichTextSanitizer;

/**
 * The article profile's contract is covered by {@see ArticleSanitizerTest} —
 * these are the parts that are new: the message profile, and the conversions
 * that let plain text and rich text live in the same column.
 */
beforeEach(function (): void {
    $this->rich = app(RichTextSanitizer::class);
});

// -----------------------------------------------------------------
// The message profile
// -----------------------------------------------------------------

it('keeps the markup a reply is made of', function (): void {
    $clean = $this->rich->clean(
        '<p>Try this <strong>first</strong>:</p><ul><li>Reboot</li></ul>'
        .'<blockquote>It worked</blockquote><p><a href="https://example.test">Guide</a></p>',
    );

    foreach (['<p>', '<strong>', '<ul>', '<li>', '<blockquote>', '<a href="https://example.test"'] as $fragment) {
        expect($clean)->toContain($fragment);
    }
});

/**
 * A reply is a message, not a document. Somebody who can set a heading in a
 * comment can make their sentence twice the size of everybody else's.
 */
it('unwraps a heading in a message but keeps its words', function (): void {
    $clean = $this->rich->clean('<h1>URGENT</h1><p>Please look at this.</p>');

    expect($clean)->toContain('URGENT')
        ->and($clean)->toContain('Please look at this.')
        ->and($clean)->not->toContain('<h1');
});

it('keeps a heading in an article', function (): void {
    $clean = $this->rich->clean('<h2>Connecting</h2>', RichTextProfile::Article);

    expect($clean)->toContain('<h2>');
});

it('refuses a script in either profile', function (RichTextProfile $profile): void {
    $clean = $this->rich->clean('<p>Before</p><script>alert(document.cookie)</script>', $profile);

    expect($clean)->toContain('Before')
        ->and($clean)->not->toContain('script')
        ->and($clean)->not->toContain('alert');
})->with([
    'basic' => RichTextProfile::Basic,
    'article' => RichTextProfile::Article,
]);

/**
 * A remote image in a message is a tracking pixel: whoever controls the URL
 * learns the IP, the time and the user agent of every agent who opens the
 * ticket, and the requester needs nothing but a mail signature to plant one.
 * An article is written by staff on purpose, which is a different act.
 */
it('refuses a remote image in a message and keeps one in an article', function (): void {
    $img = '<p>Look<img src="https://tracker.test/pixel.png" alt="A diagram"></p>';

    expect($this->rich->clean($img, images: true))->toContain('Look')
        ->and($this->rich->clean($img, images: true))->not->toContain('<img')
        ->and($this->rich->clean($img, RichTextProfile::Article, images: true))->toContain('<img');
});

it('keeps an image this instance is serving, in either profile', function (RichTextProfile $profile): void {
    $uuid = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';
    $img = '<p><img src="/rich-text/images/'.$uuid.'" alt="Screenshot"></p>';

    expect($this->rich->clean($img, $profile, images: true))->toContain('/rich-text/images/'.$uuid);
})->with([
    'basic' => RichTextProfile::Basic,
    'article' => RichTextProfile::Article,
]);

it('refuses an image url that only looks like ours', function (string $src): void {
    expect($this->rich->clean('<p><img src="'.$src.'" alt=""></p>', images: true))->not->toContain('<img');
})->with([
    // Our path, somebody else's host. The whole tracking pixel, wearing our
    // own URL shape.
    'our path on their host' => 'https://evil.test/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
    'protocol relative' => '//evil.test/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
    'with a query string' => '/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0?x=1',
    'not a uuid' => '/rich-text/images/../../etc/passwd',
    'a prefix of the path' => '/rich-text/images/',
    'extra path after it' => '/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0/../secret',
]);

// -----------------------------------------------------------------
// Plain text in, rich text out
// -----------------------------------------------------------------

/**
 * Tickets have been arriving as plain text since phase 2 — from a textarea,
 * from a mailbox, from an API client written against 1.0.0. All three have to
 * land in the same shape as something typed into the editor, and none of them
 * may end up showing their own angle brackets to the person who wrote them.
 */
it('turns blank lines into paragraphs and single newlines into breaks', function (): void {
    $html = $this->rich->fromPlainText("Printer doet niets.\nGeen lampje.\n\nGraag hulp.");

    expect($html)->toBe('<p>Printer doet niets.<br>Geen lampje.</p><p>Graag hulp.</p>');
});

it('escapes markup typed as plain text', function (): void {
    $html = $this->rich->fromPlainText('The <script> tag & the "quote"');

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($this->rich->toLine($html))->toBe('The <script> tag & the "quote"');
});

it('accepts plain text through clean() and wraps it', function (): void {
    expect($this->rich->clean('Just a sentence.'))->toBe('<p>Just a sentence.</p>');
});

it('leaves a comparison alone rather than reading it as a tag', function (): void {
    $clean = $this->rich->clean('Set it when a < b and the queue > 10.');

    expect($this->rich->toLine($clean))->toBe('Set it when a < b and the queue > 10.');
});

it('does not wrap something that is already markup', function (): void {
    expect($this->rich->clean('<p>Already a paragraph.</p>'))->toBe('<p>Already a paragraph.</p>');
});

// -----------------------------------------------------------------
// Emptiness
// -----------------------------------------------------------------

/**
 * An editor that has been focused and then emptied posts `<p></p>`, and one
 * that has been focused and never typed into posts `<p><br></p>`. Treating
 * either as content is how a reply with no words in it reaches a customer.
 */
it('treats an empty editor as empty', function (string $html): void {
    expect($this->rich->isEmpty($html))->toBeTrue()
        ->and($this->rich->clean($html))->toBe('');
})->with([
    'nothing' => '',
    'spaces' => '   ',
    'empty paragraph' => '<p></p>',
    'paragraph with a break' => '<p><br></p>',
    'two empty paragraphs' => '<p></p><p>  </p>',
    'a non-breaking space' => '<p>&nbsp;</p>',
]);

it('does not treat a word as empty', function (): void {
    expect($this->rich->isEmpty('<p>Hi</p>'))->toBeFalse();
});

/** An image says something even though it contributes no text. */
it('does not treat a lone image as empty', function (): void {
    expect($this->rich->isEmpty('<p><img src="/a.png" alt=""></p>'))->toBeFalse();
});

// -----------------------------------------------------------------
// Back to text
// -----------------------------------------------------------------

/**
 * `toText()` feeds the plain-text part of every outgoing e-mail, so the
 * paragraph breaks have to survive — a mail whose body is one long line is how
 * plain-text mail gets a reputation.
 */
it('keeps paragraph breaks when flattening for e-mail', function (): void {
    $text = $this->rich->toText('<p>First line.<br>Second line.</p><p>New paragraph.</p>');

    expect($text)->toBe("First line.\nSecond line.\n\nNew paragraph.");
});

it('collapses a flattened body to one line for a list column', function (): void {
    expect($this->rich->toLine('<p>First</p><p>Second</p>'))->toBe('First Second');
});

it('does not index tag names or attribute values', function (): void {
    expect($this->rich->toLine('<a href="https://secret.example.test" title="hidden">Link</a>'))
        ->toBe('Link');
});

it('survives a round trip through plain text', function (): void {
    $original = "Line one\nLine two\n\nParagraph two";

    expect($this->rich->toText($this->rich->fromPlainText($original)))->toBe($original);
});

/**
 * Images are a property of the field, not of the profile. An asset note has a
 * vocabulary but no readership of its own, so an image pasted into one would
 * be bound to a record nothing can authorise.
 */
it('refuses an image in a field that does not hold images, whatever the profile', function (RichTextProfile $profile): void {
    $img = '<p>Words<img src="/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0" alt=""></p>';

    expect($this->rich->clean($img, $profile))->toContain('Words')
        ->and($this->rich->clean($img, $profile))->not->toContain('<img');
})->with([
    'basic' => RichTextProfile::Basic,
    'article' => RichTextProfile::Article,
]);
