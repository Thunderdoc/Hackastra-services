<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('settings')->delete();

        $data = [
            ['type' => 'current_session', 'description' => '2026-2027'],
            ['type' => 'system_title', 'description' => 'HA'],
            ['type' => 'system_name', 'description' => 'Hackstra Academy'],
            ['type' => 'term_ends', 'description' => '31/3/2027'],
            ['type' => 'term_begins', 'description' => '1/6/2026'],
            ['type' => 'phone', 'description' => '0123456789'],
            ['type' => 'address', 'description' => '18B North Central Park, Behind Central Square Tourist Center'],
            ['type' => 'system_email', 'description' => 'info@hackstra.app'],
            ['type' => 'alt_email', 'description' => ''],
            ['type' => 'email_host', 'description' => ''],
            ['type' => 'email_pass', 'description' => ''],
            ['type' => 'lock_exam', 'description' => 0],
            ['type' => 'ai_enabled', 'description' => 0],
            ['type' => 'ai_provider', 'description' => 'gemini'],
            ['type' => 'ai_model', 'description' => 'gemini-2.5-flash'],
            ['type' => 'ai_api_key', 'description' => ''],
            ['type' => 'ai_embedding_model', 'description' => 'text-embedding-004'],
            ['type' => 'logo', 'description' => ''],
            ['type' => 'principal_name', 'description' => ''],
            ['type' => 'school_stamp', 'description' => ''],
            // Workstream DG: Google Search Console verification token
            // (platform-owned, set by the Super Admin in Settings).
            ['type' => 'google_site_verification', 'description' => ''],
            ['type' => 'next_term_fees_j', 'description' => '20000'],
            ['type' => 'next_term_fees_pn', 'description' => '25000'],
            ['type' => 'next_term_fees_p', 'description' => '25000'],
            ['type' => 'next_term_fees_n', 'description' => '25600'],
            ['type' => 'next_term_fees_s', 'description' => '15600'],
            ['type' => 'next_term_fees_c', 'description' => '1600'],
        ];

        DB::table('settings')->insert($data);

        /* Loop 106: a fresh demo must not nag "Missing: School logo"
           on the platform Configuration Checklist. Seed the bundled
           Hackstra mark exactly the way a Settings upload would land
           it — same public-disk location and file name the uploader
           produces (uploads/logo.<ext>), and the same asset-URL value
           shape SettingController stores — so the login page, the
           settings preview and printed documents (incl. the PDF
           image inliner, which resolves /storage/ URLs) all render
           the seeded logo with no special cases anywhere. */
        $bundled_logo = public_path('images/hackstra-logo.svg');
        if (is_file($bundled_logo)) {
            Storage::disk('public')->put('uploads/logo.svg', file_get_contents($bundled_logo));
            DB::table('settings')
                ->where('type', 'logo')
                ->update(['description' => asset('storage/uploads/logo.svg')]);
        }

    }
}
