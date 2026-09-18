<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\User;
use App\Services\RichText\RichTextProfile;
use App\Services\RichText\RichTextSanitizer;
use App\Support\Manual;
use Illuminate\Support\Facades\App;
use League\CommonMark\CommonMarkConverter;
use RuntimeException;

/**
 * Reads the manual off disk.
 *
 * The chapters are Markdown files in the repository rather than rows in the
 * database, and that is the whole design. A manual that lives in the database
 * is a manual that ships empty, drifts from the version of Ticktz somebody is
 * running, and cannot be reviewed alongside the change it describes. These
 * files travel with the code, so the manual an instance shows is the manual
 * for the version it is running.
 *
 * They are not the knowledge base either, which is the other tempting place to
 * put them. The knowledge base belongs to the desk — its articles are about
 * printers and passwords and whatever this organisation happens to support.
 * The manual is about Ticktz, and an upgrade should replace it without
 * touching anything anybody wrote.
 *
 * A chapter's title and opening line are its first heading and first
 * paragraph, so the table of contents cannot promise something the chapter
 * does not say.
 */
class ManualLibrary
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * The chapters this person may read, in the order they are declared.
     *
     * @return array<int, array{slug: string, title: string, summary: string}>
     */
    public function tableOfContents(User $reader, ?string $locale = null): array
    {
        $chapters = [];

        foreach (Manual::CHAPTERS as $slug => $chapter) {
            if (! $this->mayRead($reader, $chapter['permission'])) {
                continue;
            }

            $source = $this->source($slug, $locale);

            if ($source === null) {
                continue;
            }

            $chapters[] = [
                'slug' => $slug,
                'title' => $this->titleOf($source, $slug),
                'summary' => $this->summaryOf($source),
            ];
        }

        return $chapters;
    }

    /**
     * One chapter, rendered, or null when this reader may not have it.
     *
     * Null rather than an empty page for both reasons it can happen — the
     * chapter does not exist, or it does and this reader has no business with
     * it. The caller answers 404 either way, because "you may not read the
     * chapter about automation" tells somebody that automation is there.
     *
     * @return array{slug: string, title: string, html: string}|null
     */
    public function chapter(string $slug, User $reader, ?string $locale = null): ?array
    {
        $chapter = Manual::CHAPTERS[$slug] ?? null;

        if ($chapter === null || ! $this->mayRead($reader, $chapter['permission'])) {
            return null;
        }

        $source = $this->source($slug, $locale);

        if ($source === null) {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => $this->titleOf($source, $slug),
            // Sanitised although we wrote it: these files are trusted, and
            // running them through the same profile the knowledge base uses
            // costs nothing and means the manual cannot be the one place in
            // Ticktz that renders markup nobody checked.
            'html' => $this->sanitizer->clean(
                (new CommonMarkConverter(['html_input' => 'strip']))->convert($source)->getContent(),
                RichTextProfile::Article,
            ),
        ];
    }

    /**
     * The chapter explaining a given page, ready for the `?` panel.
     *
     * @return array{slug: string, title: string, html: string}|null
     */
    public function forPage(string $component, User $reader, ?string $locale = null): ?array
    {
        $slug = Manual::chapterForPage($component);

        return $slug === null ? null : $this->chapter($slug, $reader, $locale);
    }

    /**
     * The Markdown for a chapter, falling back to the default locale.
     *
     * A chapter translated into one language and not the other must not
     * disappear for half the desk: better the English text than no text. The
     * test suite is what stops that being the normal state.
     */
    private function source(string $slug, ?string $locale = null): ?string
    {
        $locale ??= App::getLocale();

        foreach ([$locale, config('app.fallback_locale', 'en')] as $candidate) {
            $path = resource_path("manual/{$candidate}/{$slug}.md");

            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    /**
     * @param  string|array<int, string>|null  $permission
     */
    private function mayRead(User $reader, string|array|null $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        return $reader->hasAnyPermission(...(array) $permission);
    }

    private function titleOf(string $source, string $slug): string
    {
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(substr($line, 2));
            }
        }

        throw new RuntimeException("The manual chapter [{$slug}] has no heading to take its title from.");
    }

    /**
     * The first paragraph, flattened to a line for the table of contents.
     */
    private function summaryOf(string $source): string
    {
        $paragraph = '';

        foreach (explode("\n", $source) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                if ($paragraph !== '') {
                    break;
                }

                continue;
            }

            $paragraph .= ($paragraph === '' ? '' : ' ').$line;
        }

        // The Markdown emphasis markers would otherwise show up as asterisks
        // in a plain-text summary.
        return trim(preg_replace('/[*_`]/', '', $paragraph) ?? $paragraph);
    }
}
