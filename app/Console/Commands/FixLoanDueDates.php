<?php

namespace App\Console\Commands;

use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-time fix: Recalculate due_date for all disbursed loans
 * using addWeekdays() instead of addDays() so that no loan
 * due_date falls on a Saturday or Sunday.
 */
class FixLoanDueDates extends Command
{
    protected $signature = 'loans:fix-due-dates {--dry-run : Show what would change without saving}';
    protected $description = 'Recalculate due_date for existing loans to exclude weekends';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY-RUN mode — no changes will be saved.');
        }

        // Get all loans that have been disbursed (have a disbursement_date)
        $loans = Loan::whereNotNull('disbursement_date')
            ->whereNotNull('due_date')
            ->get();

        $this->info("Found {$loans->count()} disbursed loans to check.");

        $fixed = 0;

        foreach ($loans as $loan) {
            $disbursementDate = Carbon::parse($loan->disbursement_date);
            $correctDueDate = $disbursementDate->copy()->addWeekdays($loan->duration_days);
            $currentDueDate = Carbon::parse($loan->due_date);

            // Only update if the dates differ
            if (!$currentDueDate->isSameDay($correctDueDate)) {
                $this->line(
                    "  Loan {$loan->loan_number}: {$currentDueDate->toDateString()} → {$correctDueDate->toDateString()}"
                );

                if (!$dryRun) {
                    $loan->update(['due_date' => $correctDueDate]);
                }

                $fixed++;
            }
        }

        if ($fixed === 0) {
            $this->info('All loan due dates are already correct. Nothing to fix.');
        } else {
            $action = $dryRun ? 'would be fixed' : 'fixed';
            $this->info("{$fixed} loan(s) {$action}.");
        }

        return Command::SUCCESS;
    }
}
