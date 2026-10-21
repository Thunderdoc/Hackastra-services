<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One staff salary record for one month (Workstream AI).
 *
 * net_pay is a stored, computed column: it is (re)written on every
 * save as basic_salary + allowances - deductions, so the index
 * totals, the summary strip and the printed payslip always agree.
 */
class Payroll extends Model
{
    protected $fillable = [
        'user_id', 'year', 'month', 'basic_salary', 'allowances',
        'deductions', 'net_pay', 'status', 'paid_at', 'created_by',
        'remarks',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::saving(function (Payroll $payroll) {
            $payroll->net_pay = round(
                (float) $payroll->basic_salary
                + (float) $payroll->allowances
                - (float) $payroll->deductions,
                2
            );
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForMonth($query, $year, $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function monthName(): string
    {
        return date('F', mktime(0, 0, 0, (int) $this->month, 1));
    }
}
