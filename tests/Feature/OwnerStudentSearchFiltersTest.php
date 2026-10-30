<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: "search student me koi filter hi
 * nahi — saab add kro". The Student Information landing now
 * carries a real student search: text (name / admission number
 * / email / phone) plus class, section, gender, house,
 * category, status and admission-year filters, all under the
 * same TeacherScope rules as the class lists. The class picker
 * itself is untouched.
 */
class OwnerStudentSearchFiltersTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** The picker and its search live on the BARE students.list
     *  URL — query parameters only. (Passing class_id through
     *  route() would fill the {class_id?} route parameter and
     *  land on the class list page instead.) */
    protected function pickerUrl(array $params): string
    {
        return route('students.list') . '?' . http_build_query($params);
    }

    protected function aarav(): StudentRecord
    {
        return StudentRecord::whereHas('user', function ($u) {
            $u->where('email', 'student@hackstra.app');
        })->firstOrFail();
    }

    public function test_search_finds_a_student_by_name_and_adm_no()
    {
        $aarav = $this->aarav();

        $byName = $this->actingAs($this->admin())
            ->get($this->pickerUrl(['search' => 1, 'q' => 'Aarav']))
            ->assertOk()
            ->assertSee('Search Students');
        $byName->assertViewHas('results', function ($results) use ($aarav) {
            return $results->contains('id', $aarav->id);
        });

        $this->actingAs($this->admin())
            ->get($this->pickerUrl(['search' => 1, 'q' => $aarav->adm_no]))
            ->assertOk()
            ->assertViewHas('results', function ($results) use ($aarav) {
                return $results->count() === 1 && (int) $results->first()->id === (int) $aarav->id;
            });
    }

    public function test_gender_and_section_filters_narrow_the_results()
    {
        $aarav = $this->aarav();
        $this->assertSame('Male', $aarav->user->gender);

        $this->actingAs($this->admin())
            ->get($this->pickerUrl(['search' => 1, 'q' => 'Aarav', 'gender' => 'Female']))
            ->assertOk()
            ->assertViewHas('results', function ($results) use ($aarav) {
                return !$results->contains('id', $aarav->id);
            });

        $this->actingAs($this->admin())
            ->get($this->pickerUrl([
                'search' => 1, 'class_id' => $aarav->my_class_id, 'section_id' => $aarav->section_id,
            ]))
            ->assertOk()
            ->assertViewHas('results', function ($results) use ($aarav) {
                return $results->isNotEmpty()
                    && $results->every(function ($sr) use ($aarav) {
                        return (int) $sr->section_id === (int) $aarav->section_id;
                    });
            });
    }

    public function test_status_filter_distinguishes_active_from_graduated()
    {
        $aarav = $this->aarav();
        StudentRecord::where('id', $aarav->id)->update(['grad' => 1]);

        $this->actingAs($this->admin())
            ->get($this->pickerUrl(['search' => 1, 'q' => 'Aarav', 'status' => 'active']))
            ->assertViewHas('results', function ($results) use ($aarav) {
                return !$results->contains('id', $aarav->id);
            });

        $this->actingAs($this->admin())
            ->get($this->pickerUrl(['search' => 1, 'q' => 'Aarav', 'status' => 'graduated']))
            ->assertViewHas('results', function ($results) use ($aarav) {
                return $results->contains('id', $aarav->id);
            });
    }

    public function test_teacher_search_never_leaves_their_scope()
    {
        $teacher = $this->teacher();
        $scope = TeacherScope::classIds($teacher);
        $this->assertNotEmpty($scope);

        $this->actingAs($teacher)
            ->get($this->pickerUrl(['search' => 1, 'status' => 'all']))
            ->assertOk()
            ->assertViewHas('results', function ($results) use ($scope) {
                return $results->every(function ($sr) use ($scope) {
                    return in_array((int) $sr->my_class_id, $scope, true);
                });
            });

        // A foreign class filter is silently out of scope, never
        // another class's students.
        $foreign = $this->aarav()->my_class_id === 2 ? 3 : 2;
        if (!in_array($foreign, $scope, true)) {
            $this->actingAs($teacher)
                ->get($this->pickerUrl(['search' => 1, 'class_id' => $foreign, 'status' => 'all']))
                ->assertViewHas('results_total', 0);
        }
    }

    public function test_picker_contract_is_unchanged_without_a_search()
    {
        $this->actingAs($this->admin())
            ->get(route('students.list'))
            ->assertOk()
            ->assertSee('Choose a Class')
            ->assertSee('Search Students')
            ->assertViewMissing('searched');

        $this->actingAs($this->admin())
            ->get($this->pickerUrl(['class_id' => 1]))
            ->assertRedirect(route('students.list', 1));
    }
}
