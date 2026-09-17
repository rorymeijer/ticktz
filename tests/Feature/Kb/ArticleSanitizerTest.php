<?php

declare(strict_types=1);

use App\Services\Kb\ArticleSanitizer;

/**
 * Article bodies are the one place in Ticktz where somebody's input becomes
 * markup in somebody else's browser. An agent who can plant a script in a help
 * article can run it with an administrator's session, which is a privilege
 * escalation wearing a helpful hat.
 *
 * So these are not "does the library work" tests — they are the contract.
 */
beforeEach(function (): void {
    $this->sanitizer = new ArticleSanitizer;
});

it('strips a script tag and everything in it', function (): void {
    $clean = $this->sanitizer->clean('<p>Before</p><script>alert(document.cookie)</script><p>After</p>');

    expect($clean)->toContain('Before')
        ->and($clean)->toContain('After')
        ->and($clean)->not->toContain('script')
        ->and($clean)->not->toContain('alert');
});

it('strips event handlers', function (string $payload): void {
    $clean = $this->sanitizer->clean($payload);

    expect(mb_strtolower($clean))->not->toContain('onerror')
        ->and(mb_strtolower($clean))->not->toContain('onload')
        ->and(mb_strtolower($clean))->not->toContain('onclick')
        ->and(mb_strtolower($clean))->not->toContain('onmouseover')
        ->and($clean)->not->toContain('alert(');
})->with([
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'body onload' => ['<p onload="alert(1)">text</p>'],
    'div onclick' => ['<div onclick="alert(1)">click</div>'],
    'svg onload' => ['<svg onload=alert(1)></svg>'],
    'a onmouseover' => ['<a href="#" onmouseover="alert(1)">hover</a>'],
    'uppercase' => ['<IMG SRC=x ONERROR=alert(1)>'],
]);

it('refuses a javascript url', function (string $payload): void {
    $clean = $this->sanitizer->clean($payload);

    expect(mb_strtolower($clean))->not->toContain('javascript:')
        ->and($clean)->not->toContain('alert(');
})->with([
    'plain' => ['<a href="javascript:alert(1)">click</a>'],
    'spaced' => ['<a href="java script:alert(1)">click</a>'],
    'uppercase' => ['<a href="JaVaScRiPt:alert(1)">click</a>'],
    'entity encoded' => ['<a href="&#106;avascript:alert(1)">click</a>'],
    'tab separated' => ["<a href=\"java\tscript:alert(1)\">click</a>"],
    'on an image' => ['<img src="javascript:alert(1)">'],
]);

it('refuses a data url', function (): void {
    $clean = $this->sanitizer->clean(
        '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">click</a>',
    );

    expect($clean)->not->toContain('data:');
});

it('drops iframes, objects, embeds and forms', function (string $payload, string $forbidden): void {
    expect(mb_strtolower($this->sanitizer->clean($payload)))->not->toContain($forbidden);
})->with([
    'iframe' => ['<iframe src="https://evil.test"></iframe>', 'iframe'],
    'object' => ['<object data="evil.swf"></object>', 'object'],
    'embed' => ['<embed src="evil.swf">', 'embed'],
    'form' => ['<form action="https://evil.test"><input name="password"></form>', '<form'],
    'input' => ['<input type="password" name="p">', '<input'],
    'svg' => ['<svg><use href="#x"/></svg>', '<svg'],
    'style block' => ['<style>body{display:none}</style>', '<style'],
]);

/**
 * An article that can carry a class can be made to look like part of the
 * application — a fake "verified" badge, a fake system banner.
 */
it('drops style and class attributes', function (): void {
    $clean = $this->sanitizer->clean(
        '<p class="bg-red-500" style="position:fixed;top:0">Urgent system notice</p>',
    );

    expect($clean)->toContain('Urgent system notice')
        ->and($clean)->not->toContain('class=')
        ->and($clean)->not->toContain('style=');
});

// -----------------------------------------------------------------
// What must survive
// -----------------------------------------------------------------

it('keeps the markup a help article is made of', function (): void {
    $body = <<<'HTML'
        <h2>Connecting to the VPN</h2>
        <p>Open the client and <strong>sign in</strong> with your <em>work</em> account.</p>
        <ol><li>Open the app</li><li>Choose <code>office-nl</code></li></ol>
        <blockquote>You need a token for this.</blockquote>
        <table><thead><tr><th scope="col">Site</th></tr></thead><tbody><tr><td>Zandvliet</td></tr></tbody></table>
        <pre><code>ping 10.0.0.1</code></pre>
        HTML;

    $clean = $this->sanitizer->clean($body);

    foreach (['<h2', '<p>', '<strong>', '<em>', '<ol>', '<li>', '<code>', '<blockquote>', '<table>', '<th', '<td>', '<pre>'] as $tag) {
        expect($clean)->toContain($tag);
    }

    expect($clean)->toContain('Zandvliet')->and($clean)->toContain('ping 10.0.0.1');
});

it('keeps a normal link and a normal image', function (): void {
    $clean = $this->sanitizer->clean(
        '<p><a href="https://example.test/help" title="Help">Help</a> <img src="https://example.test/a.png" alt="A diagram"></p>',
    );

    expect($clean)->toContain('https://example.test/help')
        ->and($clean)->toContain('alt="A diagram"');
});

/**
 * An article pointing at another article, or at the portal, is the whole idea
 * of a knowledge base.
 */
it('keeps a relative link', function (): void {
    $clean = $this->sanitizer->clean('<p><a href="/portal/kb/vpn">See the VPN article</a></p>');

    expect($clean)->toContain('/portal/kb/vpn');
});

it('keeps the text of a tag it removes', function (): void {
    $clean = $this->sanitizer->clean('<marquee>Still readable</marquee>');

    expect($clean)->toContain('Still readable')->and($clean)->not->toContain('marquee');
});

// -----------------------------------------------------------------
// Flattening and excerpting
// -----------------------------------------------------------------

it('flattens markup to searchable text', function (): void {
    $text = $this->sanitizer->toText('<h2>VPN</h2><p>Open the <strong>client</strong>.</p>');

    expect($text)->toBe('VPN Open the client.');
});

/**
 * Without a space, "</p><p>" welds two sentences into one word and a search
 * for either of them misses.
 */
it('does not weld two blocks into one word', function (): void {
    $text = $this->sanitizer->toText('<p>printer</p><p>broken</p>');

    expect($text)->toContain('printer')
        ->and($text)->toContain('broken')
        ->and($text)->not->toContain('printerbroken');
});

it('decodes entities when flattening', function (): void {
    expect($this->sanitizer->toText('<p>Tom &amp; Jerry &mdash; friends</p>'))
        ->toBe('Tom & Jerry — friends');
});

it('does not index tag names or attribute values', function (): void {
    $text = $this->sanitizer->toText('<a href="https://secret.example.test" title="hidden">Link</a>');

    expect($text)->toBe('Link');
});

it('cuts an excerpt on a word boundary', function (): void {
    $body = '<p>'.str_repeat('word ', 80).'</p>';

    $excerpt = $this->sanitizer->excerpt($body, 100);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(101)
        ->and($excerpt)->toEndWith('…')
        ->and($excerpt)->not->toContain('wor…');
});

it('leaves a short body alone', function (): void {
    expect($this->sanitizer->excerpt('<p>Short enough.</p>', 200))->toBe('Short enough.');
});
