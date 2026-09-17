<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RichTextImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes images nobody ever saved.
 *
 * An image is uploaded the moment it is pasted, which means every draft
 * somebody abandons leaves a file behind. Most are a screenshot of a login
 * screen; a few are a payslip. Either way they belong to nothing, nobody but
 * their uploader can read them, and keeping them forever is keeping somebody's
 * data for no reason at all.
 *
 * Only unowned rows, and only ones older than the configured window — long
 * enough that a draft left open over a weekend is still there on Monday.
 */
class PruneRichTextImagesCommand extends Command
{
    protected $signature = 'ticktz:prune-images {--days= : Override the configured age}';

    protected $description = 'Delete pasted images that were never saved into anything';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ticktz.rich_text.images.orphan_days', 7));

        if ($days < 1) {
            $this->error('The age has to be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        $missing = 0;

        RichTextImage::query()
            ->whereNull('owner_type')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($images) use (&$deleted, &$missing): void {
                foreach ($images as $image) {
                    $disk = Storage::disk($image->disk);

                    if ($disk->exists($image->path)) {
                        $disk->delete($image->path);
                    } else {
                        // The row outlived its file. Worth counting — a lot of
                        // these means something else is deleting files — and
                        // not worth stopping for.
                        $missing++;
                    }

                    $image->delete();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} unsaved image(s) older than {$days} day(s).");

        if ($missing > 0) {
            $this->warn("{$missing} of them had no file on disk.");
        }

        return self::SUCCESS;
    }
}
