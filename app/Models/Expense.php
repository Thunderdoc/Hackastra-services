<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * School expense (Wave 2 feature 7) — money the school spends.
 *
 * Deliberately NOT payroll: staff salaries live in Payroll. An
 * expense is a one-off school outlay (utilities, maintenance,
 * stationery, transport, events, ...), recorded by an admin or the
 * accountant, and it feeds the income-expenditure report only as an
 * outlay against fee income from PaymentRecord.
 */
class Expense extends Model
{
    protected $fillable = [
        'title', 'category', 'amount', 'expense_date', 'note', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Month key (Y-m) this expense falls in, from its expense_date. */
    public function monthKey(): string
    {
        return $this->expense_date->format('Y-m');
    }
}
