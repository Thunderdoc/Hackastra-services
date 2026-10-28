<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wave 2 feature 6 — one stock movement for an inventory item.
 * Type 'in' adds to computed stock, type 'out' subtracts from it.
 * Rows are the audit trail: they are never edited, only listed.
 */
class InventoryMovement extends Model
{
    public const TYPES = ['in', 'out'];

    protected $fillable = [
        'inventory_item_id', 'type', 'quantity', 'note', 'recorded_by', 'movement_date',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'movement_date' => 'date',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function recorder()
    {
        return $this->belongsTo(\App\User::class, 'recorded_by');
    }
}
