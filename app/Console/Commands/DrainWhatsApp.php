<?php

namespace App\Console\Commands;

use App\Support\ParentWhatsApp;
use Illuminate\Console\Command;

/**
 * F1 (Round 4, design §11.2) — drain the WhatsApp outbox from the
 * shell after a whole-school event (circular / results), and the
 * re-runnable bulk sender. Safe to re-run: only queued rows with
 * attempts < 3 are picked. Registered in the scheduler nowhere —
 * the current deploy runs no scheduler, and this command does
 * not pretend otherwise.
 */
class DrainWhatsApp extends Command
{
    protected $signature = 'whatsapp:drain {--limit=200}';

    protected $description = 'Send queued WhatsApp messages from the outbox';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $res = ParentWhatsApp::drain($limit > 0 ? $limit : 200);

        $this->line('WhatsApp drain: ' . $res['sent'] . ' sent, ' . $res['mocked'] . ' mocked, '
            . $res['failed'] . ' failed, ' . $res['remaining'] . ' still queued.');

        return self::SUCCESS;
    }
}
