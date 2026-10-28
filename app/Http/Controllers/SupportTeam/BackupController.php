<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backup & Monitoring page (Hackstra Wave 2, feature 8 of 8 — safe
 * subset only).
 *
 * Deliberately NOT built here, per the Wave 15 decision in
 * docs/BACKUP_AND_MONITORING.md: no in-app raw database download,
 * no dump route, no database file download under any name. One
 * database holds all school data, and the demo database is
 * ephemeral (every deploy reseeds it), so a demo "backup" would
 * be a false sense of safety plus a file of password hashes.
 *
 * What this page does show, for the school Admin only (teamSA
 * middleware; the Super Admin is kept off non-allowlisted routes
 * by PlatformOnly, so in practice this is admin-only):
 *   - database connectivity: ok / fail, with no details
 *   - storage writability per configured disk: disk names only,
 *     never credentials, never filesystem paths, never values
 *   - the public /health status (same database ping the public
 *     /health endpoint performs; the page does not HTTP-call
 *     itself, which would loop on a sleeping free-tier host)
 *   - a "last verified" timestamp the Admin can refresh, stored
 *     as a Setting row (type backup_last_verified; Setting only
 *     allows type + description to be filled)
 *
 * The page contains no secrets by construction: nothing from
 * config values, environment values, or file contents is ever
 * passed to the view — only ok/fail and yes/no/not-probed flags.
 */
class BackupController extends Controller
{
    public function index()
    {
        $d['database'] = $this->databaseStatus();
        $d['health'] = $d['database'];
        $d['disks'] = $this->diskStatuses();
        $d['last_verified'] = Setting::where('type', 'backup_last_verified')
            ->value('description');

        return view('pages.support_team.backup.index', $d);
    }

    public function refresh()
    {
        Setting::updateOrCreate(
            ['type' => 'backup_last_verified'],
            ['description' => now()->toDateTimeString()]
        );

        return redirect()->route('backup.index')
            ->with('flash_success', 'Backup checks refreshed.');
    }

    protected function databaseStatus()
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (Throwable $e) {
            return 'fail';
        }
    }

    protected function diskStatuses()
    {
        $statuses = [];

        foreach (config('filesystems.disks', []) as $name => $disk) {
            $driver = is_array($disk) ? ($disk['driver'] ?? null) : null;

            if ($driver !== 'local') {
                // Remote disks are named but never probed from the
                // app: a probe would need live remote credentials
                // and would write an object to remote storage.
                $statuses[] = [
                    'name' => $name,
                    'writable' => 'not-probed',
                ];

                continue;
            }

            try {
                Storage::disk($name)->put('.backup_probe', 'ok');
                Storage::disk($name)->delete('.backup_probe');
                $writable = 'yes';
            } catch (Throwable $e) {
                $writable = 'no';
            }

            $statuses[] = [
                'name' => $name,
                'writable' => $writable,
            ];
        }

        return $statuses;
    }
}
