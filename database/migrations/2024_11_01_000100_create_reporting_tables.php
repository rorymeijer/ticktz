<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting: the numbers, and the questions people keep asking of them.
 *
 * `report_daily_metrics` is a rollup — one row per day, per metric, per
 * dimension value — and everything the dashboards draw comes out of it. The
 * alternative is aggregating the ticket table on every page load, which is
 * fine at a thousand tickets and unusable at a million, and which puts a
 * full-table scan behind a screen people leave open all day.
 *
 * Two decisions are visible in the shape:
 *
 * - **Rows are recomputed, never incremented.** A counter that is bumped as
 *   things happen drifts the first time a job is retried or a ticket is edited
 *   in the database, and there is no way to tell that it has. A day rebuilt
 *   from its source rows is either right or reproducibly wrong.
 * - **`dimension_id` is 0, not NULL, for "no dimension".** MySQL treats NULLs
 *   as distinct in a unique index, so a nullable column would let the same
 *   (date, metric, dimension) pair be inserted any number of times and the
 *   upsert this table depends on would quietly become an append.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            // tickets.created · tickets.resolved · tickets.reopened
            // sla.first_response.met · sla.first_response.breached
            // sla.resolution.met · sla.resolution.breached
            // time.first_response · time.resolution  (seconds, in `total`)
            $table->string('metric', 48);
            // all · queue · team · assignee · priority · request_type · organization
            $table->string('dimension', 24)->default('all');
            // 0 means "none": the `all` dimension, or an unassigned ticket.
            $table->unsignedBigInteger('dimension_id')->default(0);
            $table->unsignedInteger('count')->default(0);
            // Sum of whatever the metric measures — seconds for the duration
            // metrics, and unused (0) for the counting ones. An average is
            // total/count, computed on read, because averaging pre-averaged
            // days would weight a quiet Sunday the same as a busy Monday.
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();

            $table->unique(['date', 'metric', 'dimension', 'dimension_id'], 'report_metric_unique');
            // The shape of every dashboard query: one metric, one dimension,
            // across a date range.
            $table->index(['metric', 'dimension', 'date']);
        });

        Schema::create('saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            // Which report this is a saved view of: sla · volume · workload · cycle_time
            $table->string('report', 32);
            // Period and filters, exactly as the screen submits them.
            $table->json('filters')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // A private report is the author's own bookmark; a shared one is
            // on the reporting screen for everybody who may read reports.
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['is_shared', 'report']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
        Schema::dropIfExists('report_daily_metrics');
    }
};
