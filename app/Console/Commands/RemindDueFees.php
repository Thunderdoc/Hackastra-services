<?php

namespace App\Console\Commands;

use App\Support\FeeReminderEngine;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * F5 (design §3.4A) — thin CLI wrapper over
 * FeeReminderEngine::runDue, the shared entry for the Kernel
 * schedule line and for CLI verification. Registered even
 * though the current free deploy runs no cron: it activates
 * the day a real host/cron exists, and is never the only
 * trigger (opportunistic + ping triggers call the same runDue).
 */
class RemindDueFees extends Command
{
    protected $signature = 'fees:remind-due {--date= : Run as if on this date (Y-m-d)}';

    protected $description = 'Send due fee reminders (scheduled engine pass)';

    public function handle(FeeReminderEngine $engine)
    {
        $today = $this->option('date') ? Carbon::createFromFormat('Y-m-d', $this->option('date')) : Carbon::today();
        $res = $engine->runDue($today);

        if (!empty($res['disabled'])) {
            $this->line('Fee reminders are disabled in Settings — nothing sent.');

            return self::SUCCESS;
        }

        $this->line('Fee reminders: ' . $res['sent'] . ' sent (' . $res['bells'] . ' bells, '
            . $res['students'] . ' students), ' . $res['skipped_paid'] . ' skipped (paid), '
            . $res['skipped_no_channel'] . ' skipped (no channel).');

        return self::SUCCESS;
    }
}
