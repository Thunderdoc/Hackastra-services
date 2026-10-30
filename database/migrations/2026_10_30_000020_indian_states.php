<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner order 2026-10-30: Hackstra serves Indian schools, but the
 * states lookup still carried the legacy Nigerian list (Abia,
 * Lagos, …) and the lgas lookup its 774 Nigerian districts — so
 * the State dropdown on every user/student form offered the wrong
 * country. No user or student references a state or LGA row
 * (verified: users.state_id / lga_id all NULL), so the lookups are
 * rewritten in place: Indian states + UTs in, orphan Nigerian
 * LGAs out (they would otherwise surface under Indian state ids
 * through the State -> LGA cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lgas')->delete();
        DB::table('states')->delete();
        $now = now();
        $rows = [];
        foreach ([
            'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam',
            'Bihar', 'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir',
            'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh',
            'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
            'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
            'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        ] as $name) {
            $rows[] = ['name' => $name, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('states')->insert($rows);
    }

    public function down(): void
    {
        // One-way data correction: the legacy list is not restored.
    }
};
