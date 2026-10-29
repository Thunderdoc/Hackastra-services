<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\LibraryFine;
use App\Models\LibraryReservation;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 21: librarian portal depth. Round 3 locked the
 * librarian's own return/collect/waive paths; this round locks the
 * REST of the library surface, read from the gates first:
 * - Books / loans / fines are teamLibrary-gated: reads = SA +
 *   librarian; writes = librarian ONLY (getTeamLibraryWrite).
 *   Admin is OUT entirely (owner order 2026-10-25). AND the SA
 *   read side is itself overridden in practice: PlatformOnly sits
 *   in the web middleware group, is NOT allowlisted for any library
 *   route, and redirects SA GETs to /dashboard (non-GETs 403)
 *   BEFORE teamLibrary ever runs — so the effective SA library
 *   surface is nothing at all, not "view-only". The "Admin +
 *   Librarian" comments in BookController / BookRequestController /
 *   TeamLibrary docblock are STALE on both counts.
 * - Reservations are controller-guarded with their OWN list:
 *   ALLOWED_TYPES = student, parent, admin, librarian. Admin IS a
 *   staff viewer here by deliberate carve-out (docblock says so),
 *   NOT a removal violation; SA / teacher / principal get 403.
 * - Students have NO staff loan/fine surface at all (teamLibrary
 *   redirects them to login); their own view is /library/mine,
 *   scoped by user_id in PortalController@library.
 */
class OwnerLibrarianPortalRound21Test extends TestCase
{
    use DatabaseTransactions;

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function otherStudent()
    {
        // Parent@'s second child (user 58) — a real student, distinct
        // from student@ (user 57). Never use user 58 as a "foreign"
        // parent fixture: it IS parent@'s own child.
        return User::findOrFail(58);
    }

    protected function freshBook($name, $total = 2, $issued = 0)
    {
        return Book::create([
            'name' => $name, 'author' => 'R21', 'book_type' => 'Book',
            'total_copies' => $total, 'issued_copies' => $issued,
        ]);
    }

    protected function loanPayload(Book $book, $userId)
    {
        return [
            'book_id' => $book->id, 'user_id' => $userId,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
        ];
    }

    /* (a) Library removal: admin out of books/loans/fines entirely. */

    public function test_admin_is_refused_all_team_library_pages()
    {
        $this->actingAs($this->admin())->get(route('books.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get(route('book_requests.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get(route('library_fines.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get(route('books.create'))
            ->assertRedirect(route('login'));
    }

    public function test_super_admin_redirected_off_team_library_pages()
    {
        // PlatformOnly (web group, no library route allowlisted)
        // fires before teamLibrary: SA GETs redirect to /dashboard.
        $this->actingAs($this->superAdmin())->get(route('books.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->get(route('book_requests.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->get(route('library_fines.index'))
            ->assertRedirect(route('dashboard'));
    }

    /* (b) Issue path: librarian issues; everyone else refused. */

    public function test_librarian_issues_book_and_counter_moves()
    {
        $book = $this->freshBook('R21-ISSUE-BOOK');
        $this->actingAs($this->librarian())
            ->post(route('book_requests.store'), $this->loanPayload($book, $this->student()->id))
            ->assertRedirect(route('book_requests.index'));
        $this->assertDatabaseHas('book_requests', [
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'status' => BookRequest::STATUS_ISSUED, 'returned' => 0,
        ]);
        $this->assertEquals(1, (int) $book->fresh()->issued_copies);
    }

    public function test_issue_refused_when_no_copies_available()
    {
        $book = $this->freshBook('R21-NOCOPY-BOOK', 1, 1);
        $this->actingAs($this->librarian())
            ->from(route('book_requests.create'))
            ->post(route('book_requests.store'), $this->loanPayload($book, $this->student()->id))
            ->assertSessionHas('flash_danger',
                'No copies of "R21-NOCOPY-BOOK" are available to issue right now.');
        $this->assertDatabaseMissing('book_requests', ['book_id' => $book->id]);
    }

    public function test_issue_refused_for_non_librarians()
    {
        $book = $this->freshBook('R21-ISSUE-DENIED-BOOK');
        foreach ([$this->admin(), $this->teacher(), $this->student(), $this->parent()] as $user) {
            $this->actingAs($user)
                ->post(route('book_requests.store'), $this->loanPayload($book, $this->student()->id))
                ->assertRedirect(route('login'));
        }
        // SA non-GET school request: PlatformOnly 403s before any
        // controller gate runs.
        $this->actingAs($this->superAdmin())
            ->post(route('book_requests.store'), $this->loanPayload($book, $this->student()->id))
            ->assertForbidden();
        $this->assertDatabaseMissing('book_requests', ['book_id' => $book->id]);
    }

    public function test_return_refused_for_super_admin_and_teacher()
    {
        $book = $this->freshBook('R21-RETURN-DENIED-BOOK', 2, 1);
        $loan = BookRequest::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        foreach ([$this->teacher(), $this->parent()] as $user) {
            $this->actingAs($user)
                ->patch(route('book_requests.return', Qs::hash($loan->id)))
                ->assertRedirect(route('login'));
        }
        $this->actingAs($this->superAdmin())
            ->patch(route('book_requests.return', Qs::hash($loan->id)))
            ->assertForbidden();
        $this->assertEquals(0, (int) $loan->fresh()->returned);
        $this->assertEquals(BookRequest::STATUS_ISSUED, $loan->fresh()->status);
    }

    public function test_librarian_return_restores_counter_no_fine_when_on_time()
    {
        $book = $this->freshBook('R21-RETURN-BOOK', 2, 1);
        $loan = BookRequest::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        $this->actingAs($this->librarian())->from(route('book_requests.index'))
            ->patch(route('book_requests.return', Qs::hash($loan->id)));
        $this->assertEquals(1, (int) $loan->fresh()->returned);
        $this->assertEquals(BookRequest::STATUS_RETURNED, $loan->fresh()->status);
        $this->assertEquals(0, (int) $book->fresh()->issued_copies);
        $this->assertDatabaseMissing('library_fines', ['book_request_id' => $loan->id]);
    }

    /* Catalogue writes: librarian only. */

    public function test_book_create_librarian_only()
    {
        $this->actingAs($this->librarian())
            ->post(route('books.store'), ['name' => 'R21-CATALOGUE-BOOK', 'book_type' => 'Book', 'total_copies' => 3])
            ->assertRedirect(route('books.index'));
        $this->assertDatabaseHas('books', ['name' => 'R21-CATALOGUE-BOOK']);
        $this->actingAs($this->admin())
            ->post(route('books.store'), ['name' => 'R21-DENIED-ADMIN', 'book_type' => 'Book'])
            ->assertRedirect(route('login'));
        $this->actingAs($this->superAdmin())
            ->post(route('books.store'), ['name' => 'R21-DENIED-SA', 'book_type' => 'Book'])
            ->assertForbidden();
        $this->assertDatabaseMissing('books', ['name' => 'R21-DENIED-ADMIN']);
        $this->assertDatabaseMissing('books', ['name' => 'R21-DENIED-SA']);
    }

    /* (b/c) Student staff surfaces closed; /library/mine is own-only. */

    public function test_student_staff_library_surfaces_are_closed()
    {
        $this->actingAs($this->student())->get(route('book_requests.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->student())->get(route('library_fines.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->student())->get(route('books.index'))
            ->assertRedirect(route('login'));
    }

    public function test_student_mine_shows_only_own_loans_and_fines()
    {
        $ownBook = $this->freshBook('R21-OWN-LOAN-BOOK');
        $otherBook = $this->freshBook('R21-OTHER-LOAN-BOOK');
        $ownLoan = BookRequest::create([
            'book_id' => $ownBook->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        $otherLoan = BookRequest::create([
            'book_id' => $otherBook->id, 'user_id' => $this->otherStudent()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        LibraryFine::create([
            'book_request_id' => $ownLoan->id, 'user_id' => $this->student()->id,
            'amount' => 50, 'days_overdue' => 5, 'reason' => 'R21-OWN-FINE',
            'status' => 'pending', 'imposed_by' => $this->librarian()->id,
        ]);
        LibraryFine::create([
            'book_request_id' => $otherLoan->id, 'user_id' => $this->otherStudent()->id,
            'amount' => 70, 'days_overdue' => 7, 'reason' => 'R21-OTHER-FINE',
            'status' => 'pending', 'imposed_by' => $this->librarian()->id,
        ]);
        $this->actingAs($this->student())->get(route('student.library'))
            ->assertOk()
            ->assertSee('R21-OWN-LOAN-BOOK')
            ->assertSee('R21-OWN-FINE')
            ->assertDontSee('R21-OTHER-LOAN-BOOK')
            ->assertDontSee('R21-OTHER-FINE');
    }

    /* (c) Reservations. */

    public function test_student_reserves_and_duplicate_is_refused()
    {
        $book = $this->freshBook('R21-RESERVE-BOOK');
        $this->actingAs($this->student())
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertRedirect(route('library_reservations.index'));
        $this->assertDatabaseHas('library_reservations', [
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->student())
            ->from(route('library_reservations.index'))
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertSessionHas('flash_danger',
                'You already have an active reservation for "R21-RESERVE-BOOK".');
        $this->assertEquals(1, LibraryReservation::active()
            ->where('user_id', $this->student()->id)->where('book_id', $book->id)->count());
    }

    public function test_reserve_refused_when_no_copies_available()
    {
        $book = $this->freshBook('R21-RESERVE-NOCOPY', 1, 1);
        $this->actingAs($this->student())
            ->from(route('library_reservations.index'))
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertSessionHas('flash_danger',
                'No copies of "R21-RESERVE-NOCOPY" are available to reserve right now.');
        $this->assertDatabaseMissing('library_reservations', ['book_id' => $book->id]);
    }

    public function test_collect_is_staff_only()
    {
        $book = $this->freshBook('R21-COLLECT-BOOK');
        $reservation = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->student())
            ->post(route('library_reservations.collect', $reservation->id))
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->post(route('library_reservations.collect', $reservation->id))
            ->assertForbidden();
        $this->assertEquals(LibraryReservation::STATUS_RESERVED, $reservation->fresh()->status);
        $this->actingAs($this->librarian())
            ->post(route('library_reservations.collect', $reservation->id))
            ->assertRedirect(route('library_reservations.index'));
        $this->assertEquals(LibraryReservation::STATUS_COLLECTED, $reservation->fresh()->status);
    }

    public function test_parent_reserves_only_for_own_child()
    {
        $book = $this->freshBook('R21-PARENT-RESERVE-BOOK');
        $ownChild = StudentRecord::where('my_parent_id', $this->parent()->id)->firstOrFail();
        $foreign = StudentRecord::whereNull('my_parent_id')->firstOrFail();
        $this->actingAs($this->parent())
            ->post(route('library_reservations.store'), [
                'book_id' => $book->id, 'student_record_id' => $ownChild->id,
            ])
            ->assertRedirect(route('library_reservations.index'));
        $this->assertDatabaseHas('library_reservations', [
            'book_id' => $book->id, 'user_id' => $this->parent()->id,
            'student_record_id' => $ownChild->id,
            'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->parent())
            ->post(route('library_reservations.store'), [
                'book_id' => $book->id, 'student_record_id' => $foreign->id,
            ])
            ->assertForbidden();
    }

    public function test_parent_index_hides_other_students_reservations()
    {
        $book = $this->freshBook('R21-PARENT-HIDDEN-BOOK');
        LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->parent())->get(route('library_reservations.index'))
            ->assertOk()
            ->assertSee('My reservations')
            ->assertSee('No reservations yet.');
    }

    public function test_reservation_index_role_shape()
    {
        // Owner correction 2026-10-29: the reservations carve-out
        // for admin is closed — the library is the Librarian's
        // alone (2026-10-25 ruling), so admin now 403s here too.
        $this->actingAs($this->admin())->get(route('library_reservations.index'))
            ->assertForbidden();
        $this->actingAs($this->librarian())->get(route('library_reservations.index'))
            ->assertOk()->assertSee('All reservations');
        $this->actingAs($this->superAdmin())->get(route('library_reservations.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->teacher())->get(route('library_reservations.index'))
            ->assertForbidden();
        $this->actingAs($this->principal())->get(route('library_reservations.index'))
            ->assertForbidden();
    }

    /* (d) Fines: librarian list + waive; SA redirected off by
       PlatformOnly, admin refused by teamLibrary. */

    public function test_fine_waive_is_librarian_only()
    {
        $book = $this->freshBook('R21-WAIVE-BOOK');
        $loan = BookRequest::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-12-01',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        $fine = LibraryFine::create([
            'book_request_id' => $loan->id, 'user_id' => $this->student()->id,
            'amount' => 40, 'days_overdue' => 4, 'reason' => 'R21-WAIVE-FINE',
            'status' => 'pending', 'imposed_by' => $this->librarian()->id,
        ]);
        $this->actingAs($this->superAdmin())->from(route('library_fines.index'))
            ->post(route('library_fines.waive', Qs::hash($fine->id)))
            ->assertForbidden();
        $this->actingAs($this->admin())->from(route('library_fines.index'))
            ->post(route('library_fines.waive', Qs::hash($fine->id)))
            ->assertRedirect(route('login'));
        $this->assertEquals('pending', $fine->fresh()->status);
        $this->actingAs($this->librarian())->from(route('library_fines.index'))
            ->post(route('library_fines.waive', Qs::hash($fine->id)));
        $this->assertEquals('waived', $fine->fresh()->status);
    }
}
