<?php

namespace App\Repositories;


use App\Models\Setting;

class SettingRepo
{
    public function update($type, $desc)
    {
        /* updateOrCreate: settings introduced by newer batches
           (Batch M3's principal_name / school_stamp) must persist
           even in a database seeded before their rows existed. */
        return Setting::updateOrCreate(['type' => $type], ['description' => $desc]);
    }

    public function getSetting($type)
    {
        return Setting::where('type', $type)->get();
    }

    public function all()
    {
        return Setting::all();
    }
}