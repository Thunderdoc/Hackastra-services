<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo-data completion for the ID cards (fix round 2026-11-01):
 * the card design carries Blood Group (every user), Joined
 * (staff) and House (students) tiles, but the base seeders never
 * populated those fields, so the tiles could never render on the
 * demo. Fills ONLY null values, deterministically (id-based
 * rotation, no randomness — the seed stays reproducible).
 * A few student records (id % 17 == 0) deliberately stay
 * house-less so the tile-omission behaviour stays exercised.
 */
class UserCardDataSeeder extends Seeder
{
    public function run()
    {
        $bgIds = DB::table('blood_groups')->orderBy('id')->pluck('id')->all();
        if ($bgIds) {
            DB::table('users')->whereNull('bg_id')->orderBy('id')
                ->pluck('id')->each(function ($id) use ($bgIds) {
                    DB::table('users')->where('id', $id)
                        ->update(['bg_id' => $bgIds[$id % count($bgIds)]]);
                });
        }

        // Staff "Joined" dates: spread deterministically over the
        // ~13 months before the 2026-2027 session opened.
        DB::table('users')->whereNull('created_at')
            ->whereIn('user_type', ['teacher', 'admin', 'principal', 'accountant', 'librarian'])
            ->orderBy('id')->pluck('id')->each(function ($id) {
                $joined = date('Y-m-d H:i:s', strtotime('2026-07-01 09:00:00') - ($id % 400) * 86400);
                DB::table('users')->where('id', $id)
                    ->update(['created_at' => $joined, 'updated_at' => $joined]);
            });

        $houses = ['Ruby', 'Emerald', 'Sapphire', 'Topaz'];
        DB::table('student_records')
            ->where(function ($q) {
                $q->whereNull('house')->orWhere('house', '');
            })
            ->orderBy('id')->pluck('id')->each(function ($id) use ($houses) {
                if ($id % 17 === 0) {
                    return; // keep a house-less minority (see docblock)
                }
                DB::table('student_records')->where('id', $id)
                    ->update(['house' => $houses[$id % count($houses)]]);
            });
    }
}
