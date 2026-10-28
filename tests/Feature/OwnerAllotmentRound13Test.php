<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Support\Allocation;
use App\Support\Staffing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent F round 13 — allocation/staffing stream coherence
 * (companion to D round 9's Manage/validator stream guard).
 *
 * The per-section surface was already stream-aware (round 2:
 * a PCM section is never flagged for lacking a Commerce
 * teacher). But Allocation::recommendations() and
 * Staffing::report() iterated EVERY class-subject row, so a
 * subject no section of its class offers still showed up as
 * staffing demand: an admin would be sent to staff a subject
 * nobody takes, while the generator, the readiness check and
 * the Manage lists all ignore it. That is the same family of
 * incoherence D round 9 fixed on the validator side.
 *
 * Rule (mirrors the generator/readiness universe): a subject
 * row counts as demand for its class only if at least one
 * section of the class offers it (Streams::appliesTo; an
 * untagged section offers everything). A class with NO
 * sections still teaches whole-class, so everything counts
 * there. Dead rows remain staffable via the Subjects page —
 * only the demand signals stop.
 *
 * Fixture: a probe class with ONLY pcm sections, holding an
 * unstaffed common subject, an unstaffed pcm subject and an
 * unstaffed commerce subject. Distinct names keep the
 * Staffing per-name aggregates exact.
 */
class OwnerAllotmentRound13Test extends TestCase
{
    use DatabaseTransactions;

    protected function probeClass(string $name): MyClass
    {
        return MyClass::create(['name' => $name]);
    }

    protected function probeSubject(MyClass $class, string $name, ?string $stream): Subject
    {
        return Subject::create([
            'name' => $name,
            'my_class_id' => $class->id,
            'teacher_id' => null,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $class->id,
            'periods_per_week' => 6,
            'stream' => $stream,
        ]);
    }

    protected function setupStreamFixture(): array
    {
        $class = $this->probeClass('ProbeStreamClass');
        Section::create(['name' => 'A', 'my_class_id' => $class->id, 'stream' => 'pcm']);
        Section::create(['name' => 'B', 'my_class_id' => $class->id, 'stream' => 'pcm']);
        $this->probeSubject($class, 'Probe English', null);
        $this->probeSubject($class, 'Probe Maths', 'pcm');
        $this->probeSubject($class, 'Probe Accountancy', 'commerce');

        return [$class];
    }

    protected function flaggedNamesForClass(int $classId): array
    {
        return collect(Allocation::recommendations())
            ->filter(function ($row) use ($classId) {
                return (int) $row['subject']->my_class_id === $classId
                    && $row['issue'] === 'unassigned';
            })
            ->map(function ($row) { return $row['subject']->name; })
            ->values()->all();
    }

    public function test_allocation_skips_subject_no_section_offers()
    {
        [$class] = $this->setupStreamFixture();
        $names = $this->flaggedNamesForClass($class->id);

        $this->assertContains('Probe English', $names);
        $this->assertContains('Probe Maths', $names);
        $this->assertNotContains('Probe Accountancy', $names);
    }

    public function test_staffing_excludes_unoffered_demand()
    {
        // Round 14 tightened this: a name with no offered row
        // anywhere is not a zero row in the report — it is absent
        // entirely, so the staffing page cannot render it as
        // "0 classes, Covered" (and its eligible-teacher capacity
        // cannot inflate the totals). The demand numbers for
        // offered names are unchanged (see keeps_offered_demand).
        $this->setupStreamFixture();
        $subjects = Staffing::report()['subjects'];

        $this->assertArrayNotHasKey('Probe Accountancy', $subjects);
    }

    public function test_allocation_keeps_whole_class_and_untagged_section_demand()
    {
        // Whole-class control: a class with NO sections teaches
        // every subject, so its unstaffed subject stays flagged.
        $bareClass = $this->probeClass('ProbeBareClass');
        $this->probeSubject($bareClass, 'Probe NoSection Subject', 'commerce');
        $this->assertContains(
            'Probe NoSection Subject',
            $this->flaggedNamesForClass($bareClass->id)
        );

        // Untagged-section control: an untagged section offers
        // everything, so its commerce subject stays flagged.
        $openClass = $this->probeClass('ProbeOpenClass');
        Section::create(['name' => 'A', 'my_class_id' => $openClass->id, 'stream' => null]);
        $this->probeSubject($openClass, 'Probe Commerce Untagged', 'commerce');
        $this->assertContains(
            'Probe Commerce Untagged',
            $this->flaggedNamesForClass($openClass->id)
        );
    }

    public function test_staffing_keeps_offered_demand()
    {
        [$class] = $this->setupStreamFixture();
        $report = Staffing::report()['subjects'];

        $this->assertSame(6, $report['Probe English']['demand']);
        $this->assertSame(6, $report['Probe Maths']['demand']);
        $this->assertSame(6, $report['Probe English']['unassigned']);
        $this->assertSame(6, $report['Probe Maths']['unassigned']);
        $this->assertSame(1, $report['Probe English']['classes']);
        $this->assertSame(1, $report['Probe Maths']['classes']);
    }
}
