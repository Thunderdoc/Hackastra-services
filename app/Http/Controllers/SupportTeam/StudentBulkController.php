<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\BloodGroup;
use App\Models\Dorm;
use App\Models\Lga;
use App\Models\MyClass;
use App\Models\Nationality;
use App\Models\Section;
use App\Models\State;
use App\Models\StudentRecord;
use App\Repositories\LocationRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\StudentRepo;
use App\Repositories\UserRepo;
use App\Support\Audit;
use App\Support\CsvExport;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bulk student operations (Workstream AA): CSV import + bulk delete.
 *
 * A school office admits a whole class at once from a spreadsheet —
 * nobody should have to type students one by one. Everything here is
 * gated teamSA (super_admin + admin), exactly like student create/store:
 * the school Admin runs the school day to day.
 *
 * The import mirrors StudentRecordController@store field-for-field:
 * same user/student-record split (Qs::getUserRecord / Qs::getStudentData),
 * same generated admission number, same default password ('student'),
 * same validation rules as StudentRecordCreate — so a student admitted
 * by CSV is indistinguishable from one admitted through the form.
 */
class StudentBulkController extends Controller
{
    protected $loc, $my_class, $user, $student;

    /**
     * CSV columns, in template order. Relational values are given by
     * NAME (class, section, state, ...) and resolved to ids per row —
     * a spreadsheet has no business carrying database ids.
     */
    public const COLUMNS = [
        'name', 'email', 'gender', 'phone', 'phone2', 'dob', 'address',
        'nationality', 'state', 'lga', 'blood_group',
        'class', 'section', 'parent_email', 'year_admitted',
        'dorm', 'dorm_room_no', 'house', 'adm_no',
    ];

    /** Columns a row cannot be imported without. */
    public const REQUIRED = [
        'name', 'gender', 'address', 'nationality', 'state', 'lga',
        'class', 'section', 'year_admitted',
    ];

    public function __construct(LocationRepo $loc, MyClassRepo $my_class, UserRepo $user, StudentRepo $student)
    {
        $this->middleware('teamSA');
        // Workstream CM: import + bulk delete are school writes —
        // Admin only; SA keeps the page and the template download.
        $this->middleware('teamSAWrite', ['only' => ['importStore', 'bulkDelete']]);

        $this->loc = $loc;
        $this->my_class = $my_class;
        $this->user = $user;
        $this->student = $student;
    }

    /**************** CSV IMPORT ****************/

    public function import()
    {
        $data['my_classes'] = $this->my_class->all();
        $data['sections'] = $this->my_class->getAllSections();
        $data['states'] = $this->loc->getStates();
        $data['nationals'] = $this->loc->getAllNationals();
        $data['columns'] = self::COLUMNS;
        $data['required'] = self::REQUIRED;

        return view('pages.support_team.students.import', $data);
    }

    /** Downloadable template: header row + one worked example row. */
    public function importTemplate()
    {
        $example = [
            'Adaeze Chiamaka Obi', 'adaeze.obi@example.com', 'Female', '08031234567', '',
            '2012-05-14', '12 Allen Avenue, Ikeja', 'Nigerian', 'Lagos', 'Ikeja', 'O+',
            'JSS 1', 'A', '', (string) date('Y'), '', '', '', '',
        ];

        return CsvExport::download('students-import-template.csv', self::COLUMNS, [$example]);
    }

    public function importStore(Request $req)
    {
        $req->validate([
            'import_file' => 'required|file|max:10240',
        ]);

        $file = $req->file('import_file');
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return back()->with('flash_danger', 'Please upload a .csv file. You can download the template from this page.');
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('flash_danger', 'The uploaded file could not be read.');
        }

        $headers = $this->readHeaderRow($handle);
        if ($headers === null) {
            fclose($handle);
            return back()->with('flash_danger', 'The CSV file is empty or has no header row.');
        }

        $missing = array_diff(self::REQUIRED, $headers);
        if ($missing) {
            fclose($handle);
            return back()->with('flash_danger', 'The CSV is missing required column(s): ' . implode(', ', $missing) . '. Please use the template.');
        }

        $lookups = $this->buildLookups();
        $seenEmails = [];
        $seenUsernames = [];
        $imported = 0;
        $errors = [];
        $line = 1; // header row

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || $this->rowIsBlank($cells)) {
                continue; // tolerate trailing / blank lines
            }

            $row = $this->mapRow($headers, $cells);
            $rowErrors = $this->validateRow($row, $lookups, $seenEmails);

            if ($rowErrors) {
                $errors[] = ['row' => $line, 'name' => $row['name'] ?? '', 'messages' => $rowErrors];
                continue;
            }

            try {
                $username = $this->createStudent($row, $seenUsernames);
                $seenUsernames[] = $username;
                if (!empty($row['email'])) {
                    $seenEmails[] = strtolower($row['email']);
                }
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'name' => $row['name'] ?? '', 'messages' => ['Could not be saved: ' . Str::limit($e->getMessage(), 120)]];
            }
        }
        fclose($handle);

        Audit::log('student.imported', StudentRecord::class,
            "CSV import: {$imported} student(s) imported, " . count($errors) . ' row(s) skipped.');

        return redirect()->route('students.import')
            ->with('import_report', ['imported' => $imported, 'errors' => $errors])
            ->with($imported ? 'flash_success' : 'flash_danger',
                $imported
                    ? "{$imported} student(s) imported successfully." . (count($errors) ? ' ' . count($errors) . ' row(s) were skipped — see the report below.' : '')
                    : 'No students were imported. See the report below for the row errors.');
    }

    /**
     * Validate one CSV row against the same rules StudentRecordCreate
     * applies to the single-student form, resolving every *_by-name
     * reference to its id. Returns a list of human-readable problems
     * (empty = row is importable). Resolved ids are written back into
     * $row so createStudent() can use them verbatim.
     */
    protected function validateRow(array &$row, array $lookups, array $seenEmails): array
    {
        $errors = [];
        $get = function ($key) use ($row) {
            return isset($row[$key]) ? trim((string) $row[$key]) : '';
        };

        // name — required|string|min:6|max:150
        $name = $get('name');
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (Str::length($name) < 6 || Str::length($name) > 150) {
            $errors[] = 'Name must be between 6 and 150 characters.';
        }

        // gender — required; the form offers Male / Female only
        $gender = strtolower($get('gender'));
        if ($gender === '') {
            $errors[] = 'Gender is required.';
        } elseif (in_array($gender, ['male', 'm'], true)) {
            $row['gender'] = 'Male';
        } elseif (in_array($gender, ['female', 'f'], true)) {
            $row['gender'] = 'Female';
        } else {
            $errors[] = 'Gender must be Male or Female.';
        }

        // address — required|string|min:6|max:120
        $address = $get('address');
        if ($address === '') {
            $errors[] = 'Address is required.';
        } elseif (Str::length($address) < 6 || Str::length($address) > 120) {
            $errors[] = 'Address must be between 6 and 120 characters.';
        }

        // email — sometimes|nullable|email|max:100|unique:users
        $email = $get('email');
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || Str::length($email) > 100) {
                $errors[] = 'Email is not a valid email address.';
            } elseif (in_array(strtolower($email), $seenEmails, true)) {
                $errors[] = 'Email is duplicated within this file.';
            } elseif (User::where('email', $email)->exists()) {
                $errors[] = 'Email already exists in the system.';
            }
        }

        // phone — sometimes|nullable|string|min:6|max:20
        $phone = $get('phone');
        if ($phone !== '' && (Str::length($phone) < 6 || Str::length($phone) > 20)) {
            $errors[] = 'Phone must be between 6 and 20 characters.';
        }

        // dob — optional user field on the form (date picker)
        $dob = $get('dob');
        if ($dob !== '') {
            $ts = strtotime($dob);
            if ($ts === false) {
                $errors[] = 'Date of Birth is not a recognisable date (use YYYY-MM-DD).';
            } else {
                $row['dob'] = date('Y-m-d', $ts);
            }
        }

        // year_admitted — required (a year, as in the form's year select)
        $year = $get('year_admitted');
        if ($year === '') {
            $errors[] = 'Year Admitted is required.';
        } elseif (!preg_match('/^\d{4}$/', $year)) {
            $errors[] = 'Year Admitted must be a 4-digit year.';
        }

        // adm_no — sometimes|nullable|alpha_num|min:3|max:150
        $admNo = $get('adm_no');
        if ($admNo !== '' && (!ctype_alnum($admNo) || Str::length($admNo) < 3 || Str::length($admNo) > 150)) {
            $errors[] = 'Admission Number must be alphanumeric, 3–150 characters.';
        }

        // nationality — required, by name
        $nal = $lookups['nationals'][strtolower($get('nationality'))] ?? null;
        if ($get('nationality') === '') {
            $errors[] = 'Nationality is required.';
        } elseif (!$nal) {
            $errors[] = "Nationality '{$get('nationality')}' was not found.";
        } else {
            $row['nal_id'] = $nal->id;
        }

        // state — required, by name
        $state = $lookups['states'][strtolower($get('state'))] ?? null;
        if ($get('state') === '') {
            $errors[] = 'State is required.';
        } elseif (!$state) {
            $errors[] = "State '{$get('state')}' was not found.";
        } else {
            $row['state_id'] = $state->id;
        }

        // lga — required, by name, and must belong to the chosen state
        if ($get('lga') === '') {
            $errors[] = 'LGA is required.';
        } elseif ($state) {
            $lga = $lookups['lgas'][$state->id][strtolower($get('lga'))] ?? null;
            if (!$lga) {
                $errors[] = "LGA '{$get('lga')}' was not found in {$state->name}.";
            } else {
                $row['lga_id'] = $lga->id;
            }
        }

        // blood group — optional, by name
        if ($get('blood_group') !== '') {
            $bg = $lookups['blood_groups'][strtolower($get('blood_group'))] ?? null;
            if (!$bg) {
                $errors[] = "Blood Group '{$get('blood_group')}' was not found.";
            } else {
                $row['bg_id'] = $bg->id;
            }
        }

        // class — required, by name; the class must have a class type,
        // because the admission number is built from the type code
        // (StudentRecordController@store refuses without one too).
        $class = $lookups['classes'][strtolower($get('class'))] ?? null;
        if ($get('class') === '') {
            $errors[] = 'Class is required.';
        } elseif (!$class) {
            $errors[] = "Class '{$get('class')}' was not found.";
        } else {
            $row['my_class_id'] = $class->id;
            $row['class_type'] = $this->my_class->findTypeByClass($class->id);
            if (is_null($row['class_type'])) {
                $errors[] = "Class '{$class->name}' has no class type configured.";
            }
        }

        // section — required, by name, within the chosen class
        if ($get('section') === '') {
            $errors[] = 'Section is required.';
        } elseif ($class) {
            $section = $lookups['sections'][$class->id][strtolower($get('section'))] ?? null;
            if (!$section) {
                $errors[] = "Section '{$get('section')}' was not found in class {$class->name}.";
            } else {
                $row['section_id'] = $section->id;
            }
        }

        // parent — optional, by the parent account's email
        if ($get('parent_email') !== '') {
            $parent = User::where('email', $get('parent_email'))->where('user_type', 'parent')->first();
            if (!$parent) {
                $errors[] = "Parent email '{$get('parent_email')}' does not match an existing parent account.";
            } else {
                $row['my_parent_id'] = $parent->id;
            }
        }

        // dormitory — optional, by name
        if ($get('dorm') !== '') {
            $dorm = $lookups['dorms'][strtolower($get('dorm'))] ?? null;
            if (!$dorm) {
                $errors[] = "Dormitory '{$get('dorm')}' was not found.";
            } else {
                $row['dorm_id'] = $dorm->id;
            }
        }

        return $errors;
    }

    /**
     * Create the user + student record for one validated row, inside a
     * transaction, exactly as StudentRecordController@store does.
     * Returns the generated admission number (username).
     */
    protected function createStudent(array $row, array $seenUsernames): string
    {
        $admNo = isset($row['adm_no']) ? trim((string) $row['adm_no']) : '';
        $username = strtoupper(
            Qs::getAppCode() . '/' . $row['class_type']->code . '/' . trim($row['year_admitted']) . '/' .
            ($admNo !== '' ? $admNo : mt_rand(1000, 99999))
        );

        if (in_array($username, $seenUsernames, true) || StudentRecord::where('adm_no', $username)->exists()) {
            throw new \RuntimeException("Admission number {$username} already exists.");
        }

        $data = [
            'name' => ucwords(trim($row['name'])),
            'email' => !empty($row['email']) ? trim($row['email']) : null,
            'phone' => !empty($row['phone']) ? trim($row['phone']) : null,
            'phone2' => !empty($row['phone2']) ? trim($row['phone2']) : null,
            'dob' => !empty($row['dob']) ? $row['dob'] : null,
            'gender' => $row['gender'],
            'address' => trim($row['address']),
            'bg_id' => $row['bg_id'] ?? null,
            'nal_id' => $row['nal_id'],
            'state_id' => $row['state_id'],
            'lga_id' => $row['lga_id'],
            'user_type' => 'student',
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'),
            'photo' => Qs::getDefaultUserImage(),
            'username' => $username,
        ];

        $sr = [
            'my_class_id' => $row['my_class_id'],
            'section_id' => $row['section_id'],
            'my_parent_id' => $row['my_parent_id'] ?? null,
            'dorm_id' => $row['dorm_id'] ?? null,
            'dorm_room_no' => !empty($row['dorm_room_no']) ? trim($row['dorm_room_no']) : null,
            'year_admitted' => trim($row['year_admitted']),
            'house' => !empty($row['house']) ? trim($row['house']) : null,
            'adm_no' => $username,
            'session' => Qs::getSetting('current_session'),
        ];

        DB::transaction(function () use ($data, $sr) {
            $user = $this->user->create($data);
            $sr['user_id'] = $user->id;
            $this->student->createRecord($sr);
        });

        return $username;
    }

    /**************** BULK DELETE ****************/

    /**
     * Delete the selected students (ids arrive in the POST body — the
     * {id} route params in this app are hash-bound, so a bulk list must
     * never travel in a URL). Removes the record AND the login account,
     * mirroring the single destroy()'s storage cleanup.
     */
    public function bulkDelete(Request $req)
    {
        $ids = collect($req->input('student_ids', []))
            ->filter(function ($id) { return is_numeric($id); })
            ->map(function ($id) { return (int) $id; })
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return back()->with('flash_danger', 'No students were selected.');
        }

        $records = StudentRecord::with('user')->whereIn('id', $ids)->get();
        $deleted = [];

        foreach ($records as $sr) {
            if (!$sr->user) {
                $sr->delete();
                continue;
            }
            $path = Qs::getUploadPath('student') . $sr->user->code;
            if (Storage::exists($path)) {
                Storage::deleteDirectory($path);
            }
            $label = $sr->user->name . ' (' . $sr->adm_no . ')';
            $userId = $sr->user->id;
            $sr->delete();
            $this->user->delete($userId);
            $deleted[] = $label;
        }

        if ($deleted) {
            Audit::log('student.bulk_deleted', StudentRecord::class,
                'Bulk deleted ' . count($deleted) . ' student(s): ' . Str::limit(implode(', ', $deleted), 900));
        }

        return back()->with(
            $deleted ? 'flash_success' : 'flash_danger',
            $deleted ? count($deleted) . ' student(s) deleted successfully.' : 'None of the selected students could be deleted.'
        );
    }

    /**************** HELPERS ****************/

    /** First CSV row → normalised header names (BOM-safe). */
    protected function readHeaderRow($handle): ?array
    {
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            return null;
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

        return array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);
            return trim($h, '_');
        }, $headers);
    }

    protected function rowIsBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /** Zip a CSV row with the header names; unknown columns are ignored. */
    protected function mapRow(array $headers, array $cells): array
    {
        $row = [];
        foreach ($headers as $i => $name) {
            if ($name !== '' && in_array($name, self::COLUMNS, true)) {
                $row[$name] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
        }
        return $row;
    }

    /** Name → model lookups, all keyed by lower-cased name. */
    protected function buildLookups(): array
    {
        $byName = function ($models) {
            $out = [];
            foreach ($models as $m) {
                $out[strtolower(trim($m->name))] = $m;
            }
            return $out;
        };

        $lgas = [];
        foreach (Lga::all() as $lga) {
            $lgas[$lga->state_id][strtolower(trim($lga->name))] = $lga;
        }

        $sections = [];
        foreach (Section::all() as $section) {
            $sections[$section->my_class_id][strtolower(trim($section->name))] = $section;
        }

        return [
            'classes' => $byName(MyClass::all()),
            'sections' => $sections,
            'states' => $byName(State::all()),
            'lgas' => $lgas,
            'nationals' => $byName(Nationality::all()),
            'blood_groups' => $byName(BloodGroup::all()),
            'dorms' => $byName(Dorm::all()),
        ];
    }
}
