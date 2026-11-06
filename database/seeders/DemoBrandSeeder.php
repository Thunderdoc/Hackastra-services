<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Demo school brand assets (item 14 parity pass, 2026-11-01).
 *
 * The frozen ID-card design (final9) — like the certificate design —
 * shows the school stamp and the principal's signature FROM SETTINGS:
 * they render only when a school has uploaded its own. The demo school
 * ships with demo assets (public/demo-assets/) so the demo shows the
 * complete design; a real school replaces both from the Settings page
 * and these values are never overwritten once set.
 */
class DemoBrandSeeder extends Seeder
{
    public function run()
    {
        $assets = [
            'school_stamp' => '/demo-assets/school-stamp.png',
            'principal_signature' => '/demo-assets/principal-signature.png',
        ];

        foreach ($assets as $type => $path) {
            $current = Setting::where('type', $type)->value('description');
            if ($current === null || trim((string) $current) === '') {
                Setting::updateOrCreate(['type' => $type], ['description' => $path]);
            }
        }
    }
}
