<?php

declare(strict_types=1);

use App\Support\UiTranslations;
use Illuminate\Support\Facades\File;

/**
 * Every `t('...')` in the frontend resolves to a real string.
 *
 * A missing key does not throw. It renders its own name — a button that reads
 * `common.close` — so it survives a typecheck, a build and a test run, and is
 * found by a person looking at the screen. That is too late and too expensive:
 * the whole point of a key is that it is cheap to check mechanically.
 *
 * Only literal keys can be checked. Keys built at runtime
 * (`t(\`api.events.\${event}\`)`) are skipped here and covered by the tests
 * that render those screens with real data.
 */
it('resolves every literal translation key used in the frontend', function (): void {
    $translations = UiTranslations::for('en');
    $missing = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $contents = $file->getContents();

        // `t('some.key')` and `t("some.key")`, single-argument or with
        // replacements. Deliberately not matching backticks: a template
        // literal is a runtime key.
        preg_match_all('/\bt\(\s*[\'"]([a-z0-9_]+(?:\.[a-z0-9_]+)+)[\'"]/i', $contents, $matches);

        foreach ($matches[1] as $key) {
            if (! array_key_exists($key, $translations)) {
                $missing[] = $file->getRelativePathname().': '.$key;
            }
        }
    }

    expect(array_values(array_unique($missing)))
        ->toBe([], 'Translation keys with nothing behind them: '.implode(', ', array_unique($missing)));
});
