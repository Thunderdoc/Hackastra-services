<?php

namespace Tests\Feature;

use App\Models\Concession;
use App\Models\FeeHead;
use App\Models\FeeInstallment;
use App\Models\Payment;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave 2, feature 3 of 8 — Fee Structure Builder.
 *
 * The coordinator integrates the real route lines into routes/web.php
 * afterwards; until then this test registers the IDENTICAL lines
 * itself (same URIs, controller@method, names and middleware) so the
 * feature is verifiable in isolation. After integration the
 * re-registration below simply overwrites the same names with the
 * same controller, so the tests keep passing unchanged.
 *
 * Runs only against an isolated snapshot DB (see task: VACUUM INTO
 * /tmp/w2_feestructure.sqlite + only this migration). Never run
 * against the main DB.
 */
class OwnerFeeStructureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Production wraps web.php in 'web' (session, errors, CSRF)
        // plus the file's own 'auth' group — mirror both here.
        Route::middleware(['web', 'auth'])->group(function () {
            // Mirrors RouteServiceProvider: web routes live under
            // App\Http\Controllers, then the SupportTeam namespace.
            Route::group(['namespace' => 'App\Http\Controllers'], function () {
            Route::group(['namespace' => 'SupportTeam', 'prefix' => 'fee-structure'], function () {
                Route::get('/', 'FeeStructureController@index')->name('fee_structure.index')->middleware('teamSA');
                Route::get('/create', 'FeeStructureController@create')->name('fee_structure.create')->middleware('teamSAWrite');
                Route::post('/', 'FeeStructureController@store')->name('fee_structure.store')->middleware('teamSAWrite');
                Route::get('/concessions/create', 'FeeStructureController@concessionCreate')->name('fee_structure.concessions.create')->middleware('teamSAWrite');
                Route::post('/concessions', 'FeeStructureController@concessionStore')->name('fee_structure.concessions.store')->middleware('teamSAWrite');
                Route::get('/concessions/{concession}/edit', 'FeeStructureController@concessionEdit')->name('fee_structure.concessions.edit')->middleware('teamSAWrite');
                Route::put('/concessions/{concession}', 'FeeStructureController@concessionUpdate')->name('fee_structure.concessions.update')->middleware('teamSAWrite');
                Route::delete('/concessions/{concession}', 'FeeStructureController@concessionDestroy')->name('fee_structure.concessions.destroy')->middleware('teamSAWrite');
                Route::get('/net', 'FeeStructureController@netIndex')->name('fee_structure.net.index')->middleware('teamSA');
                Route::get('/net/{student_record}', 'FeeStructureController@net')->name('fee_structure.net')->middleware('teamSA');
                Route::get('/{fee_head}', 'FeeStructureController@show')->name('fee_structure.show')->middleware('teamSA');
                Route::get('/{fee_head}/edit', 'FeeStructureController@edit')->name('fee_structure.edit')->middleware('teamSAWrite');
                Route::put('/{fee_head}', 'FeeStructureController@update')->name('fee_structure.update')->middleware('teamSAWrite');
                Route::delete('/{fee_head}', 'FeeStructureController@destroy')->name('fee_structure.destroy')->middleware('teamSAWrite');
                Route::post('/{fee_head}/installments', 'FeeStructureController@installmentStore')->name('fee_structure.installments.store')->middleware('teamSAWrite');
                Route::put('/{fee_head}/installments/{installment}', 'FeeStructureController@installmentUpdate')->name('fee_structure.installments.update')->middleware('teamSAWrite');
                Route::delete('/{fee_head}/installments/{installment}', 'FeeStructureController@installmentDestroy')->name('fee_structure.installments.destroy')->middleware('teamSAWrite');
                Route::post('/{fee_head}/apply', 'FeeStructureController@apply')->name('fee_structure.apply')->middleware('teamSAWrite');
            });
            });
        });

        // Fluent ->name() runs AFTER the route is added to the
        // collection, so the name lookup is stale until refreshed
        // (RouteServiceProvider does this once after loading the
        // real route files; dynamically added routes need it here).
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
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

    protected function studentRecord()
    {
        return StudentRecord::where('grad', 0)->orderBy('id')->firstOrFail();
    }

    protected function headPayload($overrides = [])
    {
        return array_merge([
            'my_class_id' => 1,
            'title' => 'W2Test Head ' . Str::random(8),
            'amount' => 10000,
            'year' => '2026-2027',
        ], $overrides);
    }

    protected function makeHead($overrides = [])
    {
        return FeeHead::create($this->headPayload($overrides));
    }

    public function test_head_crud_with_balanced_installments()
    {
        $payload = $this->headPayload([
            'installments' => [
                ['installment_no' => 1, 'due_date' => '2026-11-01', 'amount' => 6000],
                ['installment_no' => 2, 'due_date' => '2027-01-01', 'amount' => 4000],
            ],
        ]);

        $this->actingAs($this->admin())
            ->post(route('fee_structure.store'), $payload)
            ->assertRedirect(route('fee_structure.index'));

        $head = FeeHead::where('title', $payload['title'])->firstOrFail();
        $this->assertSame(10000, (int) $head->installments()->sum('amount'));
        $this->assertCount(2, $head->installments);

        $this->actingAs($this->admin())
            ->get(route('fee_structure.show', ['fee_head' => $head->id]))
            ->assertOk()
            ->assertSee($payload['title']);

        $this->actingAs($this->admin())
            ->put(route('fee_structure.update', ['fee_head' => $head->id]), $this->headPayload([
                'my_class_id' => $head->my_class_id,
                'title' => $payload['title'] . ' Updated',
                'amount' => 8000,
                'installments' => [
                    ['installment_no' => 1, 'amount' => 8000],
                ],
            ]))
            ->assertRedirect(route('fee_structure.index'));
        $this->assertSame(8000, (int) $head->fresh()->amount);
        $this->assertCount(1, $head->fresh()->installments);

        $this->actingAs($this->admin())
            ->delete(route('fee_structure.destroy', ['fee_head' => $head->id]))
            ->assertRedirect(route('fee_structure.index'));
        $this->assertDatabaseMissing('fee_heads', ['id' => $head->id]);
    }

    public function test_installment_sum_validation_refuses_unbalanced_head()
    {
        $payload = $this->headPayload([
            'installments' => [
                ['installment_no' => 1, 'amount' => 6000],
                ['installment_no' => 2, 'amount' => 3000],
            ],
        ]);

        $this->actingAs($this->admin())
            ->post(route('fee_structure.store'), $payload)
            ->assertSessionHasErrors('installments');
        $this->assertDatabaseMissing('fee_heads', ['title' => $payload['title']]);
    }

    public function test_single_installment_crud_and_over_sum_refused()
    {
        $head = $this->makeHead();

        $this->actingAs($this->admin())
            ->post(route('fee_structure.installments.store', ['fee_head' => $head->id]), [
                'installment_no' => 1, 'amount' => 6000,
            ])
            ->assertRedirect();
        $this->assertSame(6000, (int) $head->fresh()->installments()->sum('amount'));

        $this->actingAs($this->admin())
            ->post(route('fee_structure.installments.store', ['fee_head' => $head->id]), [
                'installment_no' => 2, 'amount' => 5000,
            ])
            ->assertSessionHasErrors('amount');
        $this->assertSame(6000, (int) $head->fresh()->installments()->sum('amount'));

        $inst = FeeInstallment::where('fee_head_id', $head->id)->firstOrFail();
        $this->actingAs($this->admin())
            ->put(route('fee_structure.installments.update', ['fee_head' => $head->id, 'installment' => $inst->id]), [
                'installment_no' => 1, 'amount' => 10000,
            ])
            ->assertRedirect();
        $this->assertSame(10000, (int) $inst->fresh()->amount);

        $this->actingAs($this->admin())
            ->delete(route('fee_structure.installments.destroy', ['fee_head' => $head->id, 'installment' => $inst->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('fee_installments', ['id' => $inst->id]);
    }

    public function test_concession_validation_refuses_over_amount_and_over_100_percent()
    {
        $sr = $this->studentRecord();
        $head = $this->makeHead(['my_class_id' => $sr->my_class_id]);

        $this->actingAs($this->admin())
            ->post(route('fee_structure.concessions.store'), [
                'student_record_id' => $sr->id,
                'fee_head_id' => $head->id,
                'type' => 'amount',
                'value' => 11000,
            ])
            ->assertSessionHasErrors('value');

        $this->actingAs($this->admin())
            ->post(route('fee_structure.concessions.store'), [
                'student_record_id' => $sr->id,
                'fee_head_id' => $head->id,
                'type' => 'percent',
                'value' => 101,
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame(0, Concession::where('fee_head_id', $head->id)->count());

        $this->actingAs($this->admin())
            ->post(route('fee_structure.concessions.store'), [
                'student_record_id' => $sr->id,
                'fee_head_id' => $head->id,
                'type' => 'percent',
                'value' => 10,
                'reason' => 'Test concession',
            ])
            ->assertRedirect(route('fee_structure.index'));
        $concession = Concession::where('fee_head_id', $head->id)->firstOrFail();
        $this->assertSame($this->admin()->id, (int) $concession->approved_by);
        $this->assertSame(1000.0, $head->fresh()->concessionDiscountFor($sr->id));
    }

    public function test_concession_crud()
    {
        $sr = $this->studentRecord();
        $head = $this->makeHead(['my_class_id' => $sr->my_class_id]);
        $concession = Concession::create([
            'student_record_id' => $sr->id,
            'fee_head_id' => $head->id,
            'type' => 'amount',
            'value' => 2000,
            'reason' => 'Initial',
            'approved_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())
            ->put(route('fee_structure.concessions.update', ['concession' => $concession->id]), [
                'student_record_id' => $sr->id,
                'fee_head_id' => $head->id,
                'type' => 'amount',
                'value' => 2500,
                'reason' => 'Revised',
            ])
            ->assertRedirect(route('fee_structure.index'));
        $this->assertSame(2500.0, (float) $concession->fresh()->value);

        $this->actingAs($this->admin())
            ->delete(route('fee_structure.concessions.destroy', ['concession' => $concession->id]))
            ->assertRedirect(route('fee_structure.index'));
        $this->assertDatabaseMissing('concessions', ['id' => $concession->id]);
    }

    public function test_apply_is_idempotent()
    {
        $head = $this->makeHead();

        $this->actingAs($this->admin())
            ->post(route('fee_structure.apply', ['fee_head' => $head->id]))
            ->assertRedirect();
        $this->actingAs($this->admin())
            ->post(route('fee_structure.apply', ['fee_head' => $head->id]))
            ->assertRedirect();

        $payments = Payment::where('title', $head->title)
            ->where('my_class_id', $head->my_class_id)
            ->where('year', $head->year)->get();
        $this->assertCount(1, $payments);
        $this->assertSame((int) $head->amount, (int) $payments->first()->amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fee_structure.applied']);
    }

    public function test_form_pages_render()
    {
        $sr = $this->studentRecord();
        $head = $this->makeHead(['my_class_id' => $sr->my_class_id]);
        $concession = Concession::create([
            'student_record_id' => $sr->id, 'fee_head_id' => $head->id,
            'type' => 'amount', 'value' => 1000, 'approved_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('fee_structure.create'))->assertOk();
        $this->actingAs($this->admin())
            ->get(route('fee_structure.edit', ['fee_head' => $head->id]))->assertOk();
        $this->actingAs($this->admin())
            ->get(route('fee_structure.concessions.create'))->assertOk();
        $this->actingAs($this->admin())
            ->get(route('fee_structure.concessions.edit', ['concession' => $concession->id]))->assertOk();
        $this->actingAs($this->admin())
            ->get(route('fee_structure.net.index'))->assertOk();
    }

    public function test_net_computation()
    {
        $sr = $this->studentRecord();
        $gross = $this->makeHead(['my_class_id' => $sr->my_class_id, 'amount' => 10000]);
        $other = $this->makeHead(['my_class_id' => $sr->my_class_id, 'amount' => 5000]);
        Concession::create([
            'student_record_id' => $sr->id, 'fee_head_id' => $gross->id,
            'type' => 'percent', 'value' => 10, 'approved_by' => $this->admin()->id,
        ]);
        Concession::create([
            'student_record_id' => $sr->id, 'fee_head_id' => $other->id,
            'type' => 'amount', 'value' => 2000, 'approved_by' => $this->admin()->id,
        ]);

        $this->assertSame(9000.0, $gross->fresh()->netFor($sr->id));
        $this->assertSame(3000.0, $other->fresh()->netFor($sr->id));

        $this->actingAs($this->admin())
            ->get(route('fee_structure.net', ['student_record' => $sr->id]))
            ->assertOk()
            ->assertSee('9,000')
            ->assertSee('3,000')
            ->assertSee('12,000');
    }

    public function test_teacher_denied_and_super_admin_blocked_by_platform_only()
    {
        $head = $this->makeHead();

        $this->actingAs($this->teacher())
            ->get(route('fee_structure.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())
            ->post(route('fee_structure.store'), $this->headPayload())
            ->assertRedirect(route('login'));

        // The controller's read gate is teamSA (admin + SA), but the
        // global PlatformOnly middleware blocks SA from every route
        // not on its allowlist — and this task forbids touching that
        // allowlist. So today SA reads are PlatformOnly-blocked (GET
        // redirects to the dashboard, POST aborts 403), and the
        // feature must — and does — work fully on admin alone. If SA
        // reads are ever wanted, that is the coordinator's allowlist
        // decision, not this feature's.
        $this->actingAs($this->superAdmin())
            ->get(route('fee_structure.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())
            ->get(route('fee_structure.show', ['fee_head' => $head->id]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())
            ->post(route('fee_structure.store'), $this->headPayload())
            ->assertForbidden();
    }
}
