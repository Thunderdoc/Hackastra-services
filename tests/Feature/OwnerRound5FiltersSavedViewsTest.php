<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\SavedView;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 5 Slice 3 (P12) — filter contract, users/books parity,
 * saved views.
 *
 *  - Saved views are owner-private rows (the ReportTemplate
 *    pattern): store filters the posted params down to the
 *    list's whitelist on the SavedView model, apply redirects
 *    the owner to the list with the stored params, and another
 *    user's apply/delete reads as a 404 with the row intact.
 *  - Users index is sorted (whitelist name/username/created_at
 *    x asc/desc) and server-paginated at 25/page off the same
 *    filtered collection the new CSV export streams.
 *  - Books export streams the index's own filtered query and
 *    honours the availability filter (librarian gate, like the
 *    catalogue page itself).
 *  - Payments manage narrows its per-class student list by the
 *    GET q (name / admission no) without touching the
 *    class-picker flow.
 *
 * Seeded users are fetched by email, never by numeric id.
 * Expectations are computed from the data (CI-order doctrine),
 * never hardcoded names or orderings.
 */
class OwnerRound5FiltersSavedViewsTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function librarian(): User
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $suffix): User
    {
        return User::create([
            'name' => 'P12 Probe ' . $suffix,
            'username' => 'p12probe' . strtolower($suffix),
            'email' => 'p12probe' . strtolower($suffix) . '@example.test',
            'phone' => null,
            'dob' => null,
            'gender' => 'Female',
            'address' => 'Test Address',
            'user_type' => 'teacher',
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('teacher'),
        ]);
    }

    /* ---------------- (a) saved view round trip + ownership ---------------- */

    public function test_saved_view_store_apply_round_trip_and_ownership()
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('saved_views.store'), [
                'list_key' => 'users',
                'name' => 'P12 Active Teachers',
                'user_type' => 'teacher',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.index'));

        $view = SavedView::where('user_id', $admin->id)
            ->where('name', 'P12 Active Teachers')->firstOrFail();
        $this->assertSame('users', $view->list_key);
        $this->assertSame(['user_type' => 'teacher', 'status' => 'active'], $view->params);

        // Apply lands on the list route carrying the stored params.
        $this->actingAs($admin)
            ->get(route('saved_views.apply', $view->id))
            ->assertRedirect(route('users.index', ['user_type' => 'teacher', 'status' => 'active']));

        // Another user cannot apply or delete it: both read 404
        // and the row survives untouched.
        $this->actingAs($this->teacher())
            ->get(route('saved_views.apply', $view->id))
            ->assertNotFound();
        $this->actingAs($this->teacher())
            ->delete(route('saved_views.destroy', $view->id))
            ->assertNotFound();
        $this->assertNotNull(SavedView::find($view->id));

        // The owner can delete it.
        $this->actingAs($admin)
            ->from(route('users.index'))
            ->delete(route('saved_views.destroy', $view->id))
            ->assertRedirect(route('users.index'));
        $this->assertNull(SavedView::find($view->id));
    }

    /* ---------------- (b) whitelist on store ---------------- */

    public function test_saved_view_store_keeps_only_whitelisted_scalar_params()
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('saved_views.store'), [
                'list_key' => 'users',
                'name' => 'P12 Whitelist Probe',
                'q' => 'abc',
                'bogus_param' => 'should-be-dropped',
                'sort' => ['array', 'attempt'],
            ])
            ->assertRedirect(route('users.index'));

        $view = SavedView::where('user_id', $admin->id)
            ->where('name', 'P12 Whitelist Probe')->firstOrFail();

        // Only the whitelisted scalar survives: the unknown key is
        // gone and the array-valued sort attempt is dropped too.
        $this->assertSame(['q' => 'abc'], $view->params);
    }

    /* ---------------- (c) users pagination + sort ---------------- */

    public function test_users_index_paginates_at_25_and_sorts_by_username_desc()
    {
        $response = $this->actingAs($this->admin())->get(route('users.index'))->assertOk();
        $users = $response->viewData('users');
        $this->assertInstanceOf(LengthAwarePaginator::class, $users);
        $this->assertSame(25, $users->perPage());

        // Expectation computed from the same admin-scoped set the
        // controller assembles (staff list: no student / parent /
        // super_admin rows), ordered the documented way.
        $expected = User::whereNotIn('user_type', ['student', 'parent', 'super_admin'])
            ->get()
            ->sortByDesc(function ($u) {
                return mb_strtolower((string) ($u->username ?? ''));
            })
            ->values()
            ->take(25)
            ->pluck('username')
            ->all();

        $sorted = $this->actingAs($this->admin())
            ->get(route('users.index', ['sort' => 'username', 'dir' => 'desc']))
            ->assertOk();
        $actual = collect($sorted->viewData('users')->items())->pluck('username')->all();

        $this->assertSame($expected, $actual);
    }

    /* ---------------- (d) users export honours the filter ---------------- */

    public function test_users_export_streams_the_filtered_set()
    {
        $probe = $this->makeTeacher('Export');

        $all = $this->actingAs($this->admin())->get(route('users.export'))->assertOk();
        $this->assertStringContainsString($probe->email, $all->streamedContent());

        $filtered = $this->actingAs($this->admin())
            ->get(route('users.export', ['q' => 'p12probeexport']))
            ->assertOk();
        $csv = $filtered->streamedContent();
        $this->assertStringContainsString($probe->email, $csv);
        // The unique substring narrows the set: a seeded colleague
        // who does not match it is excluded.
        $this->assertStringNotContainsString($this->teacher()->email, $csv);
        $this->assertStringNotContainsString($this->admin()->email, $csv);
    }

    /* ---------------- (e) books export honours availability ---------------- */

    public function test_books_export_honours_the_availability_filter()
    {
        $available = Book::create([
            'name' => 'P12Probe Available Title', 'author' => 'P12', 'book_type' => 'Book',
            'total_copies' => 3, 'issued_copies' => 0,
        ]);
        Book::create([
            'name' => 'P12Probe Issued Title', 'author' => 'P12', 'book_type' => 'Book',
            'total_copies' => 2, 'issued_copies' => 2,
        ]);
        Book::create([
            'name' => 'P12Probe Partial Title', 'author' => 'P12', 'book_type' => 'Book',
            'total_copies' => 3, 'issued_copies' => 1,
        ]);

        // Expectation from the filtered query itself: probe titles
        // whose counters satisfy the index's Available rule.
        $expectedCount = Book::where('name', 'like', '%P12Probe%')
            ->whereRaw('COALESCE(issued_copies, 0) = 0')
            ->whereRaw('COALESCE(total_copies, 0) > 0')
            ->count();
        $this->assertSame(1, $expectedCount);

        $response = $this->actingAs($this->librarian())
            ->get(route('books.export', ['q' => 'P12Probe', 'availability' => 'available']))
            ->assertOk();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($csv)))));

        // Header + exactly the expected filtered rows.
        $this->assertCount(1 + $expectedCount, $lines);
        $this->assertStringContainsString($available->name, $csv);
        $this->assertStringNotContainsString('P12Probe Issued Title', $csv);
        $this->assertStringNotContainsString('P12Probe Partial Title', $csv);
    }

    /* ---------------- (f) payments manage q narrows the list ---------------- */

    public function test_payments_manage_q_narrows_the_class_list()
    {
        $byClass = StudentRecord::all()->groupBy('my_class_id')
            ->filter(function ($group) {
                return $group->count() >= 2;
            });
        $this->assertTrue($byClass->isNotEmpty(), 'seed must hold a class with at least two students');
        $classId = $byClass->keys()->first();

        $baseline = $this->actingAs($this->admin())
            ->get(route('payments.manage', $classId))->assertOk();
        $students = $baseline->viewData('students');
        $this->assertGreaterThanOrEqual(2, $students->count());

        $target = $students->first();
        $other = $students->skip(1)->first();

        $filtered = $this->actingAs($this->admin())
            ->get(route('payments.manage', [$classId, 'q' => $target->adm_no]))
            ->assertOk();
        $listed = $filtered->viewData('students');

        $this->assertSame(1, $listed->count());
        $this->assertSame($target->adm_no, $listed->first()->adm_no);
        $this->assertFalse($listed->contains('adm_no', $other->adm_no));
    }
}
