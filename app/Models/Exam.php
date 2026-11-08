<?php

namespace App\Models;

use Eloquent;

class Exam extends Eloquent
{
    protected $fillable = ['name', 'term', 'year', 'results_published', 'admit_cards_published', 'my_class_id', 'period_id'];

    protected $casts = [
        'results_published' => 'boolean',
        'admit_cards_published' => 'boolean',
    ];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    /** The assessment period this exam belongs to (Phase 1). */
    public function period()
    {
        return $this->belongsTo(AssessmentPeriod::class, 'period_id');
    }

    /**
     * The one label every surface prints for this exam's period:
     * the period's name when linked, else today's exact legacy
     * string ('Term '.$term) for unlinked rows (seeder inserts).
     */
    public function periodLabel(): string
    {
        return $this->period ? $this->period->name : 'Term ' . $this->term;
    }

    /**
     * Marks-entry safety (R1 design §1.3/§6 S7): an exam whose
     * period has no legacy term number has no tex{term} column to
     * store totals in, so it must not accept marks until the
     * generic total store (deferral D1) lands. Exceptions:
     *  - exams with no period stay enterable, exactly as today;
     *  - a period WITH legacy_term is enterable (its totals land
     *    in tex{legacy_term} on its own mark rows);
     *  - a unit period feeding a term period WITH a legacy_term
     *    is enterable — the exam row reuses the parent term.
     * Everything else (standalone, or a term period with
     * legacy_term NULL) is not enterable in Phase 1.
     */
    public function marksEnterable(): bool
    {
        if (!$this->period_id) {
            return true;
        }
        $period = $this->period;
        if (!$period) {
            return true;
        }
        if ($period->legacy_term !== null) {
            return true;
        }
        if ($period->kind === 'unit' && $period->feedsPeriod && $period->feedsPeriod->legacy_term !== null) {
            return true;
        }
        return false;
    }

    public function subjectComponents()
    {
        return $this->hasMany(ExamSubjectComponent::class);
    }
}
