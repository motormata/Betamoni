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

        $loans = Loan::whereNotNull('disbursement_date')
            ->whereNotNull('due_date')
            ->get();

        $this->info("Found {$loans->count()} disbursed loans to check.");

        $fixedLoans = 0;
        $fixedSchedules = 0;
        $loanService = new \App\Services\LoanCalculationService();

        foreach ($loans as $loan) {
            $schedules = \App\Models\RepaymentSchedule::where('loan_id', $loan->id)
                ->orderBy('installment_number', 'asc')
                ->get();

            $lastDueDate = null;
            $schedulesChanged = false;

            foreach ($schedules as $schedule) {
                $correctScheduleDate = $loanService->calculateDueDate(
                    $loan->disbursement_date,
                    $loan->repayment_frequency,
                    $schedule->installment_number
                );

                $currentScheduleDate = Carbon::parse($schedule->due_date);

                if (!$currentScheduleDate->isSameDay($correctScheduleDate)) {
                    if (!$dryRun) {
                        $schedule->update(['due_date' => $correctScheduleDate]);
                    }
                    $fixedSchedules++;
                    $schedulesChanged = true;
                }
                
                $lastDueDate = $correctScheduleDate;
            }

            // Determine the correct loan due date from the last schedule, or fallback
            if (!$lastDueDate) {
                $disbursementDate = Carbon::parse($loan->disbursement_date);
                if ($loan->repayment_frequency === 'daily') {
                    $lastDueDate = $disbursementDate->copy()->addWeekdays($loan->duration_days);
                } else {
                    $lastDueDate = $disbursementDate->copy()->addDays($loan->duration_days);
                }
            }

            $currentDueDate = Carbon::parse($loan->due_date);

            if (!$currentDueDate->isSameDay($lastDueDate)) {
                $this->line(
                    "  Loan {$loan->loan_number}: due_date {$currentDueDate->toDateString()} → {$lastDueDate->toDateString()}"
                );

                if (!$dryRun) {
                    $loan->update(['due_date' => $lastDueDate]);
                }
                $fixedLoans++;
            } elseif ($schedulesChanged) {
                $this->line("  Loan {$loan->loan_number}: schedules updated.");
            }
        }

        $action = $dryRun ? 'would be fixed' : 'fixed';
        $this->info("{$fixedLoans} loan(s) and {$fixedSchedules} schedule(s) {$action}.");

        return Command::SUCCESS;
    }
}
