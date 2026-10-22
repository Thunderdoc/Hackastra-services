<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
            ['type' => 'current_session', 'description' => '2018-2019'],
            ['type' => 'system_title', 'description' => 'HA'],
            ['type' => 'system_name', 'description' => 'Hackstra Academy'],
            ['type' => 'term_ends', 'description' => '7/10/2018'],
            ['type' => 'term_begins', 'description' => '7/10/2018'],
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

    }
}
