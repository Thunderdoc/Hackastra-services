<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\LibraryReservation;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Library Search + Reservation (2026-10-28).
 * Users are resolved by EMAIL, never hardcoded ids. Reservations
 * are their own table: no test touches BookRequest statuses or the
 * issue/return flow, and no Book counter is changed by reserving.
 */
class OwnerLibraryReservationTest extends TestCase
{
    use DatabaseTransactions;

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->first();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function makeBook($name, $total = 3, $issued = 0)
    {
        return Book::create([
            'name' => $name,
            'author' => 'Reservation Test Author',
            'book_type' => 'Book',
            'total_copies' => $total,
            'issued_copies' => $issued,
        ]);
    }

    public function test_student_searches_by_title_and_author()
    {
        $book = $this->makeBook('Zebra Reservation Search Title');
        $this->actingAs($this->student())
            ->get(route('library_reservations.index', ['q' => 'Zebra Reservation']))
            ->assertOk()
            ->assertSee('Zebra Reservation Search Title');
        $this->actingAs($this->student())
            ->get(route('library_reservations.index', ['q' => 'Reservation Test Author']))
            ->assertOk()
            ->assertSee('Zebra Reservation Search Title');
        $this->actingAs($this->student())
            ->get(route('library_reservations.index', ['q' => 'NoSuchBookXyz']))
            ->assertOk()
            ->assertDontSee('Zebra Reservation Search Title');
        $book->delete();
    }

    public function test_student_reserves_available_book_and_duplicate_refused()
    {
        $book = $this->makeBook('Reservable Test Book');
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertRedirect(route('library_reservations.index'));
        $this->assertDatabaseHas('library_reservations', [
            'book_id' => $book->id, 'user_id' => $student->id, 'status' => 'reserved',
        ]);
        // Reserving changes no Book counter.
        $this->assertEquals(0, (int) $book->fresh()->issued_copies);

        $this->actingAs($student)
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertRedirect();
        $this->assertEquals(1, LibraryReservation::active()
            ->where('user_id', $student->id)->where('book_id', $book->id)->count());

        LibraryReservation::where('book_id', $book->id)->delete();
        $book->delete();
    }

    public function test_unavailable_book_cannot_be_reserved()
    {
        $book = $this->makeBook('Fully Issued Test Book', 2, 2);
        $this->actingAs($this->student())
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertRedirect();
        $this->assertDatabaseMissing('library_reservations', [
            'book_id' => $book->id, 'user_id' => $this->student()->id, 'status' => 'reserved',
        ]);
        $book->delete();
    }

    public function test_parent_reserves_only_for_own_child()
    {
        $book = $this->makeBook('Parent Reservation Test Book');
        $parent = $this->parent();
        $ownChild = StudentRecord::where('my_parent_id', $parent->id)->first();
        $this->assertNotNull($ownChild, 'Seeded parent must have a child record');
        $foreignChild = StudentRecord::whereNotNull('my_parent_id')
            ->where('my_parent_id', '!=', $parent->id)->first();

        $this->actingAs($parent)
            ->post(route('library_reservations.store'), [
                'book_id' => $book->id, 'student_record_id' => $ownChild->id,
            ])
            ->assertRedirect(route('library_reservations.index'));
        $this->assertDatabaseHas('library_reservations', [
            'book_id' => $book->id, 'user_id' => $parent->id,
            'student_record_id' => $ownChild->id, 'status' => 'reserved',
        ]);

        if ($foreignChild) {
            $this->actingAs($parent)
                ->post(route('library_reservations.store'), [
                    'book_id' => $book->id, 'student_record_id' => $foreignChild->id,
                ])
                ->assertForbidden();
        }

        LibraryReservation::where('book_id', $book->id)->delete();
        $book->delete();
    }

    public function test_librarian_sees_all_and_cancels_any_but_user_cancels_only_own()
    {
        $book = $this->makeBook('Cancel Test Book');
        $student = $this->student();
        $mine = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $student->id,
            'student_record_id' => optional(StudentRecord::where('user_id', $student->id)->first())->id,
            'status' => 'reserved',
        ]);
        $other = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->parent()->id,
            'student_record_id' => null, 'status' => 'reserved',
        ]);

        // Librarian index lists both reservations.
        $this->actingAs($this->librarian())
            ->get(route('library_reservations.index'))
            ->assertOk()
            ->assertSee('Cancel Test Book');

        // A user cannot cancel someone else's reservation.
        $this->actingAs($student)
            ->delete(route('library_reservations.cancel', $other))
            ->assertForbidden();
        $this->assertEquals('reserved', $other->fresh()->status);

        // Librarian cancels any; the book is reservable again
        // (availability derives from copies, no counter repaired).
        $this->actingAs($this->librarian())
            ->delete(route('library_reservations.cancel', $other))
            ->assertRedirect(route('library_reservations.index'));
        $this->assertEquals('cancelled', $other->fresh()->status);
        $this->assertTrue($book->fresh()->availableCopies() > 0);

        // Owner cancels their own.
        $this->actingAs($student)
            ->delete(route('library_reservations.cancel', $mine))
            ->assertRedirect(route('library_reservations.index'));
        $this->assertEquals('cancelled', $mine->fresh()->status);

        LibraryReservation::where('book_id', $book->id)->delete();
        $book->delete();
    }

    public function test_teacher_cannot_use_reservations()
    {
        $this->actingAs($this->teacher())
            ->get(route('library_reservations.index'))
            ->assertForbidden();
    }
}
