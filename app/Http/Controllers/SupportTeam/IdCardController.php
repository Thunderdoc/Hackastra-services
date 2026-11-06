<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Support\IdCardFactory;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PDF;

class IdCardController extends Controller
{
    /**
     * "My ID Card" — a student sees their OWN card (identity derived
     * server-side from their student record; there is deliberately no
     * student-id parameter to tamper with). A parent sees a card per
     * linked child, with a ?child= switcher validated against their
     * OWN children — the same tamper pattern as TimeTableController@mine.
     * Staff use the per-student route (students.id_card) instead.
     */
    public function mine(Request $request)
    {
        $user = Auth::user();
        $d = ['mode' => null, 'children' => collect(), 'sr' => null];

        if (Qs::userIsStudent()) {
            $d['mode'] = 'student';
            $d['sr'] = Qs::findStudentRecord($user->id);
            if ($d['sr']) {
                $d['sr']->loadMissing(['user', 'my_class', 'section']);
                $d['card'] = IdCardFactory::forStudent($d['sr']);
                $d['brand'] = IdCardFactory::brand();
            }
            return view('pages.support_team.students.id_card_mine', $d);
        }

        if (Qs::userIsParent()) {
            $d['mode'] = 'parent';
            $d['children'] = $children = Qs::findMyChildren($user->id);
            // Tamper guard: only an id from the parent's OWN children is
            // honoured; anything else falls back to the first child.
            $sr = $children->firstWhere('id', (int) $request->query('child')) ?: $children->first();
            if ($sr) {
                $sr->loadMissing(['user', 'my_class', 'section']);
                $d['card'] = IdCardFactory::forStudent($sr);
                $d['brand'] = IdCardFactory::brand();
            }
            $d['sr'] = $sr;
            return view('pages.support_team.students.id_card_mine', $d);
        }

        return redirect()->route('dashboard');
    }

    /**
     * Staff view (teamSA, enforced by route middleware): render the
     * same card for any student, from the student profile.
     */
    public function show($sr_id)
    {
        $sr_id = Qs::decodeHash($sr_id);
        if (!$sr_id) {
            return Qs::goWithDanger();
        }

        $sr = StudentRecord::with(['user', 'my_class', 'section'])->find($sr_id);
        if (!$sr) {
            return Qs::goWithDanger();
        }

        return view('pages.support_team.students.id_card', [
            'sr' => $sr,
            'card' => IdCardFactory::forStudent($sr),
            'brand' => IdCardFactory::brand(),
        ]);
    }

    /**
     * Wave 2 feature 4 — BULK ID CARDS (whole-class/section PDF sheet).
     *
     * Selector page (GET, teamSAT): pick a class + optional section.
     * With a class chosen it previews the admission-ordered roster and
     * links the PDF download. Teachers only ever see their assigned
     * classes here (TeacherScope), exactly like the student list picker.
     * Class/section travel as QUERY params (class_id, section_id) —
     * never as {id} route params, so no hash decoding applies.
     */
    public function bulkSelector(Request $request)
    {
        $user = Auth::user();
        $classes = MyClass::orderBy('id')->get();
        if (Qs::userIsTeacher()) {
            $classes = $classes->whereIn('id', TeacherScope::classIds($user))->values();
        }

        $d = [
            'classes' => $classes,
            'sections' => Section::with('my_class')->whereIn('my_class_id', $classes->pluck('id'))->orderBy('my_class_id')->orderBy('id')->get(),
            'my_class' => null,
            'section' => null,
            'students' => collect(),
        ];

        $classId = (int) $request->query('class_id');
        if ($classId) {
            [$mc, $section, $students] = $this->resolveBulkScope($request, $classId);
            $d['my_class'] = $mc;
            $d['section'] = $section;
            $d['students'] = $students;
        }

        return view('pages.support_team.students.id_card_bulk', $d);
    }

    /**
     * Wave 2 feature 4 — the bulk PDF itself (GET, teamSAT).
     * One card per student of the class/section, several cards per
     * page, downloaded as a single dompdf file. Admin: any class.
     * Teacher: ONLY classes they teach — a foreign class aborts 403.
     *
     * QR: there is NO qr/barcode library in this app (verified in
     * composer.json, composer.lock and vendor/), so bulk cards carry
     * NO QR code — no paid service, no fake QR image. The card
     * carries photo-or-initials, name, class/section, admission no
     * and the school name from Settings at generation time.
     */
    public function bulk(Request $request)
    {
        /* A whole class can be 520 cards: the stock 128M limit and
           30s cap die mid-render (same lesson as Mark bulkDownload). */
        Qs::ensurePdfMemory();
        set_time_limit(300);

        $classId = (int) $request->query('class_id');
        if (!$classId) {
            return redirect()->route('students.id_cards.bulk')
                ->with('flash_info', 'Choose a class first.');
        }

        [$mc, $section, $students] = $this->resolveBulkScope($request, $classId);
        if ($students->isEmpty()) {
            return back()->with('flash_info', 'No students found for this class and section.');
        }

        /* Item 14 design: every card is built by IdCardFactory
           (photos inlined as data URIs for dompdf; no real photo ->
           the initials tile; branding from Settings). One card per
           CR80-vertical page pair: front page, then back page. */
        $brand = IdCardFactory::brand(true);
        $cards = $students->map(function ($sr) {
            return IdCardFactory::forStudent($sr, true);
        });

        $pdfName = 'ID_Cards_' . Str::slug($mc->name)
            . ($section ? '_' . Str::slug($section->name) : '_All_Sections') . '.pdf';

        return PDF::loadView('pages.support_team.students.id_card_bulk_pdf', [
            'cards' => $cards,
            'brand' => $brand,
            'my_class' => $mc,
            'section' => $section,
        ])->setPaper([0, 0, 153, 242.6])->download($pdfName);
    }

    /**
     * Staff single card (teamSA route): any school staff member's
     * card, from their profile. Eligible types are exactly the
     * factory's roles (teacher, principal, admin, accountant,
     * librarian); super_admin is platform staff and has no school
     * card. The {user_id} param is deliberately NOT named {id}
     * (no hash binding applies to it).
     */
    public function staffShow($user_id)
    {
        $user = User::find((int) $user_id);
        $card = $user ? IdCardFactory::forStaff($user) : null;
        if (!$card) {
            return Qs::goWithDanger();
        }

        return view('pages.support_team.users.id_card', [
            'staff' => $user,
            'card' => $card,
            'brand' => IdCardFactory::brand(),
        ]);
    }

    /** Staff bulk selector (teamSA): pick a role, preview the list. */
    public function staffBulkSelector(Request $request)
    {
        $role = (string) $request->query('role', '');
        $staff = $this->staffQuery($role)->get();

        return view('pages.support_team.users.id_cards_bulk', [
            'role' => $role,
            'roles' => array_keys(IdCardFactory::ROLE_META),
            'staff' => $staff,
        ]);
    }

    /** Staff bulk PDF (teamSA): front+back per member, CR80 pages. */
    public function staffBulk(Request $request)
    {
        Qs::ensurePdfMemory();
        set_time_limit(300);

        $role = (string) $request->query('role', '');
        $staff = $this->staffQuery($role)->get();
        if ($staff->isEmpty()) {
            return back()->with('flash_info', 'No staff found for this selection.');
        }

        $brand = IdCardFactory::brand(true);
        $cards = $staff->map(function ($u) {
            return IdCardFactory::forStaff($u, true);
        })->filter()->values();

        $pdfName = 'Staff_ID_Cards' . ($role ? '_' . Str::slug($role) : '_All') . '.pdf';

        return PDF::loadView('pages.support_team.students.id_card_bulk_pdf', [
            'cards' => $cards,
            'brand' => $brand,
            'my_class' => null,
            'section' => null,
        ])->setPaper([0, 0, 153, 242.6])->download($pdfName);
    }

    /** School staff = the factory's card-eligible user types. */
    protected function staffQuery(string $role)
    {
        $types = ['teacher', 'principal', 'admin', 'accountant', 'librarian', 'nurse'];
        if ($role !== '' && !in_array($role, $types, true)) {
            $role = '';
        }

        return User::whereIn('user_type', $types)
            ->when($role !== '', function ($q) use ($role) {
                $q->where('user_type', $role);
            })
            ->orderBy('name');
    }

    /**
     * Shared bulk scope resolution: class must exist; an optional
     * section must belong to that class; a teacher must teach the
     * class (foreign class -> 403). Roster is active students only
     * (grad = 0, the StudentRepo::activeStudents rule), ordered by
     * admission number.
     *
     * @return array{MyClass, Section|null, \Illuminate\Support\Collection}
     */
    protected function resolveBulkScope(Request $request, $classId)
    {
        $mc = MyClass::find($classId);
        if (!$mc) {
            abort(404);
        }

        if (Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $mc->id)) {
            abort(403, 'You can only print ID cards for your assigned classes.');
        }

        $section = null;
        $sectionId = (int) $request->query('section_id');
        if ($sectionId) {
            $section = Section::find($sectionId);
            if (!$section || (int) $section->my_class_id !== (int) $mc->id) {
                abort(404);
            }
        }

        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->where('my_class_id', $mc->id)
            ->when($section, function ($q) use ($section) {
                $q->where('section_id', $section->id);
            })
            ->orderBy('adm_no')
            ->orderBy('id')
            ->get();

        return [$mc, $section, $students];
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI
       read from the local file, for renderers that cannot fetch URLs
       (dompdf). Anything that is not a local storage file is returned
       unchanged. Same pattern as MarkController::pdfImageDataUri. */
    protected function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';

                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }

        return $url;
    }
}
