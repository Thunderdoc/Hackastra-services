<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admissions pipeline (Wave 12, CASE_STUDY gap 13): a public
 * enquiry becomes a row the Admin works through states
 * new → contacted → test_scheduled → approved → converted, or
 * rejected. Convert does NOT create the student — it pre-fills
 * the Admit Student form (old input) so the office still checks
 * every field; the Admin marks the enquiry Converted once the
 * student record actually exists.
 */
class Admission extends Model
{
    public const STATUSES = ['new', 'contacted', 'test_scheduled', 'approved', 'converted', 'rejected'];

    public const STATUS_LABELS = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'test_scheduled' => 'Test Scheduled',
        'approved' => 'Approved',
        'converted' => 'Converted',
        'rejected' => 'Rejected',
    ];

    protected $fillable = ['name', 'phone', 'my_class_id', 'message', 'status', 'notes', 'session'];

    public function myClass()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
