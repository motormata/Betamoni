<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateDefaultedLoans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loans:update-defaulted';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks active and disbursed loans and updates their status to defaulted if they are overdue.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting update for defaulted loans...');

        // Using chunkById is critical here because syncStatus() might change the status,
        // which would cause standard chunk() to skip rows due to shifting offsets.
        \App\Models\Loan::whereIn('status', ['active', 'disbursed'])->chunkById(100, function ($loans) {
            /** @var \App\Models\Loan $loan */
            foreach ($loans as $loan) {
                // syncStatus internally checks if the loan is overdue and updates it
                $loan->syncStatus();
            }
        });

        $this->info('Finished updating defaulted loans.');
    }
}
