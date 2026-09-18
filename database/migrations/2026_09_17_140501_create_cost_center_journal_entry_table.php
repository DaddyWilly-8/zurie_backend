<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A journal entry can be tagged to one or more cost centers — a
        // pivot at the entry level, not a column on each line. Previously
        // every line within one postEntry() call carried the *same*
        // cost_center_id value repeated (every caller — Order, Purchase,
        // Expense — always passed one cost center per call, never
        // different ones per line), so this isn't a behavior change for
        // any existing caller, just a cleaner storage shape that also
        // supports a future entry tagged to more than one cost center
        // without a schema change (Extensibility Constitution, Rule 3).
        Schema::create('cost_center_journal_entry', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained()->cascadeOnDelete();

            $table->primary(['journal_entry_id', 'cost_center_id']);
        });

        // Backfill: one pivot row per distinct (journal_entry_id,
        // cost_center_id) pair found across that entry's existing lines.
        $pairs = DB::table('journal_entry_lines')
            ->select('journal_entry_id', 'cost_center_id')
            ->whereNotNull('cost_center_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            DB::table('cost_center_journal_entry')->insertOrIgnore([
                'journal_entry_id' => $pair->journal_entry_id,
                'cost_center_id' => $pair->cost_center_id,
            ]);
        }

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
        });

        // Best-effort restore: apply each entry's first pivoted cost
        // center back onto all of its lines. Not a perfect inverse if an
        // entry ever had more than one cost center attached (that
        // information doesn't fit back into the old one-per-line shape),
        // but this migration's down() is for local rollback during
        // development, not a production revert path.
        $entries = DB::table('cost_center_journal_entry')
            ->select('journal_entry_id', DB::raw('MIN(cost_center_id) as cost_center_id'))
            ->groupBy('journal_entry_id')
            ->get();

        foreach ($entries as $entry) {
            DB::table('journal_entry_lines')
                ->where('journal_entry_id', $entry->journal_entry_id)
                ->update(['cost_center_id' => $entry->cost_center_id]);
        }

        Schema::dropIfExists('cost_center_journal_entry');
    }
};
