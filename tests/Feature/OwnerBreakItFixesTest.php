<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\LibraryReservation;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Break-it fix 2026-10-28: reservations had a 'collected' status
 * nothing could ever set, so a reserved book could never complete
 * its workflow. Staff-only collect closes it; plus the REG-01
 * closure (public registration must not exist at all).
 */
class OwnerBreakItFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function makeReservation($status = LibraryReservation::STATUS_RESERVED)
    {
        $book = Book::create([
            'name' => 'Collect Probe Book',
            'author' => 'Probe Author',
            'book_type' => 'Book',
            'total_copies' => 3,
            'issued_copies' => 0,
        ]);
        $record = StudentRecord::where('user_id', $this->student()->id)->firstOrFail();

        return LibraryReservation::create([
            'book_id' => $book->id,
            'user_id' => $this->student()->id,
            'student_record_id' => $record->id,
            'status' => $status,
        ]);
    }

    public function test_librarian_can_mark_reserved_collected()
    {
        $reservation = $this->makeReservation();

        $this->actingAs($this->librarian())
            ->post(route('library_reservations.collect', $reservation))
            ->assertRedirect();

        $this->assertSame(
            LibraryReservation::STATUS_COLLECTED,
            $reservation->fresh()->status
        );
    }

    public function test_collect_refuses_non_reserved()
    {
        $reservation = $this->makeReservation(LibraryReservation::STATUS_CANCELLED);

        $this->actingAs($this->librarian())
            ->post(route('library_reservations.collect', $reservation))
            ->assertRedirect();

        $this->assertSame(
            LibraryReservation::STATUS_CANCELLED,
            $reservation->fresh()->status
        );
    }

    public function test_student_cannot_mark_own_reservation_collected()
    {
        $reservation = $this->makeReservation();

        $this->actingAs($this->student())
            ->post(route('library_reservations.collect', $reservation))
            ->assertForbidden();

        $this->assertSame(
            LibraryReservation::STATUS_RESERVED,
            $reservation->fresh()->status
        );
    }

    public function test_public_registration_routes_do_not_exist()
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('register'));
        $this->get('/register')->assertNotFound();
    }
}
