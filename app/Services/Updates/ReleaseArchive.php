<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Fetches a release's installable archive and proves it is what it claims.
 *
 * Nothing here replaces anything. This is the half that can be got wrong
 * safely: download, check, unpack, look inside. By the time it returns, the
 * new version is sitting beside the installation and has been checked; the
 * part that touches the running application happens afterwards and separately.
 *
 * **Three things are checked, and they answer different questions.**
 *
 * The digest, published beside the archive, says the bytes did not change on
 * the way here. That is integrity, and it is the weakest of the three: both
 * files come from the same place, so it catches a truncated download and a
 * broken mirror, and not a compromised release.
 *
 * `build.json` says the archive is Ticktz and which version it is. That is what
 * stops an instance installing something for the wrong product, or a 1.0.9
 * archive attached to a 1.1.0 release by a mistake in a workflow.
 *
 * The shape check says it looks like an application: an `artisan`, a `vendor`,
 * a `public/build`. An archive missing any of those would install and then
 * fail to boot, with the old tree already moved aside.
 *
 * What none of them prove is provenance — that the archive came from the
 * repository it says it did. GitHub's own attestation does prove that, and
 * verifying it needs a sigstore client that does not exist in PHP, so it is
 * documented for an operator to run by hand rather than claimed here.
 */
class ReleaseArchive
{
    public function __construct(private readonly ReleaseChecker $checker) {}

    /**
     * Download, verify and unpack a release. Returns the directory holding the
     * new tree, ready to be swapped in.
     *
     * @param  callable(string): void|null  $progress  told what is happening, step by step
     */
    public function stage(Release $release, string $workingDirectory, ?callable $progress = null): string
    {
        $say = $progress ?? static fn (string $line) => null;

        $assets = $this->assetsFor($release);
        $zipUrl = $assets['archive'];
        $digestUrl = $assets['digest'];

        $this->ensureRoom($workingDirectory, $assets['size']);

        if (! is_dir($workingDirectory) && ! mkdir($workingDirectory, 0750, true) && ! is_dir($workingDirectory)) {
            throw new RuntimeException("Could not create {$workingDirectory}.");
        }

        $say('Downloading '.basename($zipUrl));
        $archive = $workingDirectory.'/release.zip';
        $this->download($zipUrl, $archive);

        $say('Checking the archive against its published digest');
        $this->verifyDigest($archive, trim($this->fetch($digestUrl)));

        $say('Unpacking');
        $tree = $this->unpack($archive, $workingDirectory);

        $say('Checking what was unpacked');
        $this->verifyContents($tree, $release);

        @unlink($archive);

        return $tree;
    }

    /**
     * The two files a release has to carry for an instance to install it.
     *
     * A release with an image but no archive is a normal thing to find — the
     * jobs run in parallel and one can fail — so the message says which one is
     * missing rather than that something went wrong.
     *
     * @return array{archive: string, digest: string, size: int}
     */
    public function assetsFor(Release $release): array
    {
        $assets = $this->checker->assetsFor($release);

        $archive = null;
        $digest = null;
        $size = 0;

        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');

            if (preg_match('/^ticktz-.+\.zip$/', $name) === 1) {
                $archive = (string) $asset['browser_download_url'];
                $size = (int) ($asset['size'] ?? 0);
            }

            if (preg_match('/^ticktz-.+\.zip\.sha256$/', $name) === 1) {
                $digest = (string) $asset['browser_download_url'];
            }
        }

        if ($archive === null) {
            throw new RuntimeException('This release has no installable archive attached to it.');
        }

        if ($digest === null) {
            throw new RuntimeException('This release has an archive but no digest to check it against.');
        }

        return ['archive' => $archive, 'digest' => $digest, 'size' => $size];
    }

    /**
     * The precise disk check, made where the precise number is.
     *
     * Three times the archive: the download, what it unpacks to, and room to
     * move the old tree aside rather than delete it first.
     */
    private function ensureRoom(string $directory, int $archiveBytes): void
    {
        $probe = is_dir($directory) ? $directory : dirname($directory);
        $free = @disk_free_space($probe);
        $needed = max($archiveBytes * 3, 256 * 1024 * 1024);

        if (is_float($free) && $free < $needed) {
            throw new RuntimeException(sprintf(
                'Not enough room: %d MB free, %d MB needed.',
                (int) ($free / 1048576),
                (int) ($needed / 1048576),
            ));
        }
    }

    private function download(string $url, string $destination): void
    {
        $response = Http::timeout(300)->withOptions(['sink' => $destination])->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Could not download the archive ({$response->status()}).");
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('The archive downloaded as an empty file.');
        }
    }

    private function fetch(string $url): string
    {
        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Could not read the digest ({$response->status()}).");
        }

        return $response->body();
    }

    private function verifyDigest(string $archive, string $expected): void
    {
        // The published file may carry the filename beside the digest.
        $expected = mb_strtolower(trim(explode(' ', $expected)[0]));

        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new RuntimeException('The published digest is not a SHA-256.');
        }

        $actual = hash_file('sha256', $archive);

        if (! hash_equals($expected, (string) $actual)) {
            throw new RuntimeException('The archive does not match its published digest.');
        }
    }

    /**
     * Unpack, and hand back the single directory inside.
     */
    private function unpack(string $archive, string $directory): string
    {
        $target = $directory.'/tree';

        // A retry of a failed upgrade must not unpack on top of the last one.
        $this->deleteTree($target);

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('The archive could not be opened.');
        }

        // A zip whose entries climb out of the directory they are extracted
        // into is the oldest trick there is. ZipArchive::extractTo does guard
        // against it, and saying so here is cheaper than trusting it silently.
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();

                throw new RuntimeException("The archive contains an entry that climbs out of it: {$name}");
            }
        }

        $ok = $zip->extractTo($target);
        $zip->close();

        if (! $ok) {
            throw new RuntimeException('The archive could not be unpacked.');
        }

        $inside = array_values(array_diff(scandir($target) ?: [], ['.', '..']));

        if (count($inside) === 1 && is_dir($target.'/'.$inside[0])) {
            return $target.'/'.$inside[0];
        }

        return $target;
    }

    private function verifyContents(string $tree, Release $release): void
    {
        foreach (['artisan', 'composer.json', 'vendor/autoload.php', 'public/build', 'build.json'] as $expected) {
            if (! file_exists($tree.'/'.$expected)) {
                throw new RuntimeException("The archive is missing {$expected}; it is not a complete release.");
            }
        }

        $build = json_decode((string) file_get_contents($tree.'/build.json'), true);

        if (! is_array($build) || ($build['product'] ?? null) !== 'ticktz') {
            throw new RuntimeException('The archive does not say it is Ticktz.');
        }

        $built = Version::parse((string) ($build['version'] ?? ''));

        if ($built === null || $built->compare($release->version) !== 0) {
            throw new RuntimeException(sprintf(
                'The archive says it is %s, and the release says %s.',
                $build['version'] ?? 'nothing',
                (string) $release->version,
            ));
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
