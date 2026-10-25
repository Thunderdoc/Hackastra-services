<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Certificate register (Wave 14, CASE_STUDY gap 14): bonafide and
 * transfer certificates, each with a system-minted serial that is
 * the TC register. Rows are SNAPSHOTS — name, admission number,
 * class, section and session are copied at issue time, so a
 * certificate never changes when the student record later does.
 * Register rows are never deleted: a certificate, once issued,
 * stays auditable.
 */
class Certificate extends Model
{
    public const TYPES = ['bonafide', 'transfer'];

    public const PREFIXES = ['bonafide' => 'BON', 'transfer' => 'TC'];

    protected $fillable = [
        'user_id', 'student_record_id', 'type', 'serial', 'student_name',
        'adm_no', 'class_name', 'section_name', 'session', 'reason',
        'issued_by', 'issued_at',
    ];

    protected $casts = ['issued_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function issuer()
    {
        return $this->belongsTo(\App\User::class, 'issued_by');
    }

    /** Next serial for a type (BON/2026/0001, TC/2026/0001…).
     *  The caller retries the whole insert on a unique collision,
     *  so two simultaneous issues can never share a serial. */
    public static function mintSerial(string $type): string
    {
        $prefix = self::PREFIXES[$type];
        $year = date('Y');
        $n = static::where('type', $type)
            ->where('serial', 'like', $prefix . '/' . $year . '/%')
            ->count() + 1;
        return $prefix . '/' . $year . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
