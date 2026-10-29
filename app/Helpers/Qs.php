<?php

namespace App\Helpers;

use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\Subject;
use Hashids\Hashids;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Qs
{
    public static function displayError($errors)
    {
        foreach ($errors as $err) {
            $data[] = $err;
        }
        return '
                <div class="alert alert-danger alert-styled-left alert-dismissible">
									<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
									<span class="font-weight-semibold">Oops!</span> '.
        implode(' ', $data).'
							    </div>
                ';
    }

    public static function getAppCode()
    {
        return self::getSetting('system_title') ?: 'HACKSTRA';
    }

    /** Per-request sequence registry for generateAdmNo(), keyed by prefix. */
    protected static $admNoSeq = [];

    /**
     * Admission-number formats the school Admin picks in Settings
     * ("Admission Numbers"). The segments are: code = school acronym
     * (system_title), class = class-type code, year = year admitted.
     * The serial is always last. Changing the format starts a FRESH
     * series under the new prefix — old numbers never change, and a
     * series already started keeps counting from its last number
     * (adm_no_serial_start is the FIRST number of a fresh series
     * only — it never jumps a started series).
     */
    public const ADM_NO_FORMATS = [
        'code_class_year_serial' => ['code', 'class', 'year'],
        'code_year_serial' => ['code', 'year'],
        'class_year_serial' => ['class', 'year'],
        'code_serial' => ['code'],
        'year_serial' => ['year'],
    ];

    /**
     * A school-defined admission-number pattern is valid when it uses
     * only letters, digits, / - _ . and the placeholders {CODE}
     * (School Acronym), {CLASS} (class type code), {YEAR} (year
     * admitted), and ends with a separator (/ - _ .) so the serial
     * appended after it can never glue onto the pattern text and so
     * the LIKE-based series lookup can never match another prefix.
     * The serial itself is always appended last by generateAdmNo().
     */
    public static function isValidAdmNoCustomPattern($pattern): bool
    {
        if (!is_string($pattern) || $pattern === '' || strlen($pattern) > 50) {
            return false;
        }

        return (bool) preg_match('/^(?:[A-Za-z0-9]|\{(CODE|CLASS|YEAR)\})(?:[A-Za-z0-9\/\-_.]|\{(CODE|CLASS|YEAR)\})*[\/\-_.]$/', $pattern);
    }

    /**
     * The admission-number PREFIX for one (class type, year) under the
     * school's chosen format, trailing slash included, uppercased.
     * Every composite admission number in the app — system-minted AND
     * admin-typed (Admit Student, Quick Admit, both validators) — is
     * built from this one helper, so the stored shape can never drift
     * from the format the Admin picked.
     */
    public static function admNoPrefix($classTypeCode, $yearAdmitted)
    {
        $format = self::getSetting('adm_no_format') ?: 'code_class_year_serial';
        if ($format === 'custom') {
            $pattern = (string) (self::getSetting('adm_no_custom_pattern') ?: '');
            if (self::isValidAdmNoCustomPattern($pattern)) {
                $prefix = str_replace(
                    ['{CODE}', '{CLASS}', '{YEAR}'],
                    [self::getAppCode(), $classTypeCode, trim((string) $yearAdmitted)],
                    $pattern
                );

                return strtoupper($prefix);
            }
            // Invalid or empty custom pattern falls back to the
            // default format, same as a bogus format value — never
            // mint a broken shape.
            $format = 'code_class_year_serial';
        }
        $segments = self::ADM_NO_FORMATS[$format] ?? self::ADM_NO_FORMATS['code_class_year_serial'];
        $values = [
            'code' => self::getAppCode(),
            'class' => $classTypeCode,
            'year' => trim((string) $yearAdmitted),
        ];
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $values[$segment];
        }

        return strtoupper(implode('/', $parts) . '/');
    }

    /** First serial of a fresh series (Settings; default 1000). */
    public static function admNoSerialStart()
    {
        $start = (int) (self::getSetting('adm_no_serial_start') ?: 1000);
        if ($start < 1) {
            $start = 1;
        }
        if ($start > 999999) {
            $start = 999999;
        }

        return $start;
    }

    /** Leading-zero width for newly minted serials (Settings; 0 = none). */
    public static function admNoSerialWidth()
    {
        $width = (int) (self::getSetting('adm_no_serial_width') ?: 0);
        if ($width < 0) {
            $width = 0;
        }
        if ($width > 6) {
            $width = 6;
        }

        return $width;
    }

    /**
     * The ONE admission-number generator for the whole app (Loop 111):
     * <chosen format> / SERIAL, where SERIAL is the next number in
     * sequence for that prefix — never random, never taken from user
     * input. Bulk imports, the Admit Student form and Quick Admit all
     * mint numbers here, so a number means the same thing however the
     * student arrived. A started series continues from the highest
     * numeric suffix already issued under the prefix (historic numbers
     * were random 4–5 digit suffixes; the default start of 1000 keeps
     * the 4-digit shape for a school's first student) — the configured
     * start applies ONLY when no numeric serial exists under the
     * prefix yet, so raising it can never jump a live series.
     * Numbers burned by a row that later fails are simply skipped — a
     * gap from a failure, never a duplicate. With no settings saved,
     * the format is exactly the historic APPCODE / CLASS-TYPE / YEAR /
     * SERIAL.
     */
    public static function generateAdmNo($classTypeCode, $yearAdmitted)
    {
        $prefix = self::admNoPrefix($classTypeCode, $yearAdmitted);

        if (!isset(self::$admNoSeq[$prefix])) {
            $max = null;
            $existing = StudentRecord::where('adm_no', 'like', $prefix . '%')->pluck('adm_no');
            foreach ($existing as $adm) {
                $suffix = substr((string) $adm, strlen($prefix));
                if (ctype_digit($suffix)) {
                    $max = $max === null ? (int) $suffix : max($max, (int) $suffix);
                }
            }
            self::$admNoSeq[$prefix] = $max === null ? self::admNoSerialStart() - 1 : $max;
        }

        self::$admNoSeq[$prefix]++;

        $serial = (string) self::$admNoSeq[$prefix];
        $width = self::admNoSerialWidth();
        if ($width > 0) {
            $serial = str_pad($serial, $width, '0', STR_PAD_LEFT);
        }

        return $prefix . $serial;
    }

    /**
     * ADM-01 (program Phase 3, 2026-10-23): create a student with a
     * system-minted admission number, collision-safe. generateAdmNo()
     * sequences within one request, but two CONCURRENT requests can
     * mint the same next number — the DB UNIQUE index then turns the
     * loser's insert into a 500 (and, in the non-transactional manual
     * flow, an orphaned login account). Here the create runs through
     * $createWithAdmNo($admNo); on a unique violation the sequence is
     * re-seeded from the database and a fresh number is tried, up to
     * 5 attempts. Only admission-number/username unique violations
     * are retried — every other failure rethrows untouched. A number
     * the ADMIN typed is never routed through here (it must fail
     * loudly, never be silently renumbered).
     */
    public static function mintStudent(callable $createWithAdmNo, $classTypeCode, $yearAdmitted, int $tries = 5)
    {
        $last = null;
        for ($i = 0; $i < $tries; $i++) {
            if ($i > 0) {
                // A collision happened: forget the cached sequence so
                // the next mint re-reads the true database maximum.
                self::$admNoSeq = [];
            }
            $admNo = self::generateAdmNo($classTypeCode, $yearAdmitted);
            if (StudentRecord::where('adm_no', $admNo)->exists()) {
                $last = new \RuntimeException("Admission number {$admNo} already exists.");
                continue;
            }
            try {
                return $createWithAdmNo($admNo);
            } catch (\Illuminate\Database\QueryException $e) {
                // Retry ONLY an admission-number/username collision —
                // any other failure (including a different unique key,
                // e.g. a duplicate email) is the caller's real error
                // and must surface immediately, not burn retries.
                $msg = $e->getMessage();
                $admCollision = self::isUniqueViolation($e)
                    && (stripos($msg, 'adm_no') !== false || stripos($msg, 'username') !== false);
                if (!$admCollision) {
                    throw $e;
                }
                $last = $e;
            }
        }
        throw $last ?: new \RuntimeException('Could not allocate a unique admission number.');
    }

    /** True when the query exception is a unique-constraint violation. */
    public static function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
            return true;
        }
        $msg = $e->getMessage();
        return stripos($msg, 'unique') !== false || stripos($msg, 'duplicate entry') !== false;
    }

    public static function getDefaultUserImage()
    {
        return asset('global_assets/images/user.png');
    }

    /**
     * Workstream BR — does this photo value hold a real uploaded photo?
     * The users-table default and every seeded account carry
     * getDefaultUserImage() — the theme's stock silhouette — as their
     * photo value. That means "no photo": avatar renderings must fall
     * back to the initials tile and never show the stock silhouette.
     * Matches on the path suffix so any host/port variant of the
     * stored default is recognised.
     */
    public static function hasRealPhoto($photo)
    {
        if (!$photo) {
            return false;
        }

        return !Str::endsWith($photo, '/global_assets/images/user.png');
    }

    /**
     * Up to two initials from a display name (photo-rule fallback tile).
     */
    public static function initials($name)
    {
        $initials = '';
        foreach (preg_split('/\s+/', trim((string) $name)) as $word) {
            if ($word !== '') {
                $initials .= strtoupper(substr($word, 0, 1));
                if (strlen($initials) >= 2) {
                    break;
                }
            }
        }

        return $initials !== '' ? $initials : '?';
    }

    public static function getPanelOptions()
    {
        // Batch 1A (Loop 28): the legacy Limitless ⌄/× panel cluster
        // (collapse/remove card) was junk chrome on ~65 card headers —
        // Batch 11's Loop 24 already hid the anchors via CSS; now the
        // markup is removed at the source. The method is kept (and
        // returns an empty string) so all existing call sites keep
        // working untouched. Card bodies authored with the `collapse`
        // class (marksheet comments/skills) stay visible via the
        // Loop 24 companion rule (.card-body.collapse{display:block}).
        return '';
    }

    public static function displaySuccess($msg)
    {
        return '
 <div class="alert alert-success alert-bordered">
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span><span class="sr-only">Close</span></button> '.
        $msg.'  </div>
                ';
    }

    public static function getTeamSA()
    {
        return ['admin', 'super_admin'];
    }

    public static function getTeamAccount()
    {
        return ['admin', 'super_admin', 'accountant'];
    }

    public static function getTeamSAT()
    {
        return ['admin', 'super_admin', 'teacher'];
    }

    public static function getTeamAcademic()
    {
        return ['admin', 'super_admin', 'teacher', 'student'];
    }

    public static function getTeamAdministrative()
    {
        return ['admin', 'super_admin', 'accountant'];
    }

    public static function getTeamLibrary()
    {
        /* Owner order 2026-10-25: the Admin does not need the
           library — the Librarian runs it. Admin removed from the
           library team entirely (menu + routes), SA stays for
           platform parity (PlatformOnly redirects SA from school
           pages regardless). */
        return ['super_admin', 'librarian'];
    }

    /*
     * Write-team variants (Workstream CM — SA view-only boundary).
     * The Super Admin (company/platform owner) may VIEW everything in a
     * school but must never write school records; school writes belong
     * to the school's Admin and its staff. The read teams above keep
     * super_admin; the write teams below are the same teams minus
     * super_admin, and gate every create/update/delete path.
     */
    public static function getTeamSAWrite()
    {
        return ['admin'];
    }

    public static function getTeamSATWrite()
    {
        return ['admin', 'teacher'];
    }

    public static function getTeamAccountWrite()
    {
        return ['admin', 'accountant'];
    }

    public static function getTeamLibraryWrite()
    {
        return ['librarian'];
    }

    public static function hash($id)
    {
        $salt = config('app.key').'HACKSTRA';
        $hash = new Hashids($salt, 14);
        return $hash->encode($id);
    }

    public static function getUserRecord($remove = [])
    {
        $data = ['name', 'email', 'phone', 'phone2', 'dob', 'gender', 'address', 'bg_id', 'nal_id', 'state_id', 'lga_id'];

        return $remove ? array_values(array_diff($data, $remove)) : $data;
    }

    public static function getStaffRecord($remove = [])
    {
        $data = ['emp_date',];

        return $remove ? array_values(array_diff($data, $remove)) : $data;
    }

    public static function getStudentData($remove = [])
    {
        $data = ['my_class_id', 'section_id', 'my_parent_id', 'dorm_id', 'dorm_room_no', 'year_admitted', 'house', 'age'];

        return $remove ? array_values(array_diff($data, $remove)) : $data;

    }

    public static function decodeHash($str, $toString = true)
    {
        $salt = config('app.key').'HACKSTRA';
        $hash = new Hashids($salt, 14);
        $decoded = $hash->decode($str);
        return $toString ? implode(',', $decoded) : $decoded;
    }

    /**
     * Audit Fix Wave 1 (X-2): the one resolver for record route
     * parameters that are NOT bound through Route::bind('id').
     * Modules disagree historically — grades 500 on a hashed id,
     * events 404, classes/sections/subjects/dorms silently fall back
     * to the index — while their in-app links use raw ids. Accept
     * BOTH shapes: a purely numeric value is a raw id (returned
     * untouched, so the 177 in-app hrefs behave exactly as before);
     * anything else is tried as a Qs hash and, when it decodes, the
     * decoded id is used; when it does not decode, the value passes
     * through unchanged so the controller's normal not-found handling
     * (404 / goWithDanger) applies instead of a wrong record.
     */
    public static function resolveId($value)
    {
        if ($value === null || $value === '' || is_numeric($value)) {
            return $value;
        }

        $decoded = self::decodeHash($value);

        return $decoded !== '' ? $decoded : $value;
    }

    public static function userIsTeamAccount()
    {
        return in_array(Auth::user()->user_type, self::getTeamAccount());
    }

    public static function userIsTeamSA()
    {
        return in_array(Auth::user()->user_type, self::getTeamSA());
    }

    public static function userIsTeamSAT()
    {
        return in_array(Auth::user()->user_type, self::getTeamSAT());
    }

    public static function userIsTeamLibrary()
    {
        return in_array(Auth::user()->user_type, self::getTeamLibrary());
    }

    public static function userIsTeamSAWrite()
    {
        return in_array(Auth::user()->user_type, self::getTeamSAWrite());
    }

    public static function userIsTeamSATWrite()
    {
        return in_array(Auth::user()->user_type, self::getTeamSATWrite());
    }

    public static function userIsTeamAccountWrite()
    {
        return in_array(Auth::user()->user_type, self::getTeamAccountWrite());
    }

    public static function userIsTeamLibraryWrite()
    {
        return in_array(Auth::user()->user_type, self::getTeamLibraryWrite());
    }

    public static function userIsAcademic()
    {
        return in_array(Auth::user()->user_type, self::getTeamAcademic());
    }

    public static function userIsAdministrative()
    {
        return in_array(Auth::user()->user_type, self::getTeamAdministrative());
    }

    public static function userIsAdmin()
    {
        return Auth::user()->user_type == 'admin';
    }

    /**
     * Limited principal role (owner order 2026-10-28): 'principal'
     * is its own user_type — school-wide read + designated
     * approvals only. It is deliberately NOT in any team array, so
     * no team gate ever passes for a principal unless a route
     * explicitly uses teamOrPrincipal or a controller guard names
     * the role. An un-migrated install has no such users, so this
     * is false there by construction.
     */
    public static function userIsPrincipal()
    {
        return Auth::check() && Auth::user()->user_type == 'principal';
    }

    public static function getUserType()
    {
        return Auth::user()->user_type;
    }

    public static function userIsSuperAdmin()
    {
        return Auth::user()->user_type == 'super_admin';
    }

    public static function userIsStudent()
    {
        return Auth::user()->user_type == 'student';
    }

    public static function userIsTeacher()
    {
        return Auth::user()->user_type == 'teacher';
    }

    public static function userIsParent()
    {
        return Auth::user()->user_type == 'parent';
    }

    public static function userIsStaff()
    {
        return in_array(Auth::user()->user_type, self::getStaff());
    }

    public static function getStaff($remove=[])
    {
        $data =  ['super_admin', 'admin', 'teacher', 'accountant', 'librarian'];
        return $remove ? array_values(array_diff($data, $remove)) : $data;
    }

    public static function getAllUserTypes($remove=[])
    {
        $data =  ['super_admin', 'admin', 'teacher', 'accountant', 'librarian', 'student', 'parent'];
        return $remove ? array_values(array_diff($data, $remove)) : $data;
    }

    // Check if User is Head of Super Admins (Untouchable)
    public static function headSA(int $user_id)
    {
        return $user_id === 1;
    }

    public static function userIsPTA()
    {
        return in_array(Auth::user()->user_type, self::getPTA());
    }

    public static function userIsMyChild($student_id, $parent_id)
    {
        $data = ['user_id' => $student_id, 'my_parent_id' =>$parent_id];
        return StudentRecord::where($data)->exists();
    }

    public static function getSRByUserID($user_id)
    {
        return StudentRecord::where('user_id', $user_id)->first();
    }

    public static function getPTA()
    {
        return ['super_admin', 'admin', 'teacher', 'parent'];
    }

    /*public static function filesToUpload($programme)
    {
        return ['birth_cert', 'passport',  'neco_cert', 'waec_cert', 'ref1', 'ref2'];
    }*/

    public static function getPublicUploadPath()
    {
        return 'uploads/';
    }

    public static function getUserUploadPath()
    {
        return 'uploads/'.date('Y').'/'.date('m').'/'.date('d').'/';
    }

    public static function getUploadPath($user_type)
    {
        return 'uploads/'.$user_type.'/';
    }

    public static function getFileMetaData($file)
    {
        // Never trust the client-supplied filename or Content-Type: both are
        // attacker-controlled and were previously copied into the stored
        // file's extension, letting an upload named e.g. "evil.html" (with
        // image content) land on the public disk as a web-served HTML page
        // (stored XSS). Derive the extension and type from the file's real,
        // sniffed type instead, clamped to the image extensions the app
        // accepts on its upload forms (jpeg/png/gif/webp).
        $ext = strtolower((string) $file->guessExtension());
        $dataFile['ext'] = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $ext : 'jpg';
        $dataFile['type'] = $file->getMimeType();
        $dataFile['size'] = self::formatBytes($file->getSize());
        return $dataFile;
    }

    public static function generateUserCode()
    {
        return substr(uniqid(mt_rand()), -7, 7);
    }

    public static function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');

        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }

    public static function getSetting($type)
    {
        $setting = Setting::where('type', $type)->first();
        return $setting ? $setting->description : null;
    }

    /**
     * The school logo for branding spots (sidebar, login, settings preview):
     * the logo uploaded in Settings when one exists, otherwise a bundled
     * default so the <img> is never empty/broken.
     */
    public static function getLogo($fallback = null)
    {
        $logo = self::getSetting('logo');
        return $logo ?: ($fallback ?: asset('global_assets/images/logo_light.png'));
    }

    public static function getCurrentSession()
    {
        return self::getSetting('current_session');
    }

    public static function getNextSession()
    {
        $oy = self::getCurrentSession();
        $old_yr = explode('-', $oy);
        return ++$old_yr[0].'-'.++$old_yr[1];
    }

    public static function getSystemName()
    {
        return self::getSetting('system_name');
    }

    public static function findMyChildren($parent_id)
    {
        return StudentRecord::where('my_parent_id', $parent_id)->with(['user', 'my_class'])->get();
    }

    public static function findTeacherSubjects($teacher_id)
    {
        return Subject::where('teacher_id', $teacher_id)->with('my_class')->get();
    }

    public static function findStudentRecord($user_id)
    {
        return StudentRecord::where('user_id', $user_id)->first();
    }

    public static function getMarkType($class_type)
    {
       switch($class_type){
           case 'J' : return 'junior';
           case 'S' : return 'senior';
           case 'N' : return 'nursery';
           case 'P' : return 'primary';
           case 'PN' : return 'pre_nursery';
           case 'C' : return 'creche';
       }
        return $class_type;
    }

    public static function json($msg, $ok = TRUE, $arr = [])
    {
        return $arr ? response()->json($arr) : response()->json(['ok' => $ok, 'msg' => $msg]);
    }

    public static function jsonStoreOk()
    {
        return self::json(__('msg.store_ok'));
    }

    public static function jsonUpdateOk()
    {
        return self::json(__('msg.update_ok'));
    }

    public static function storeOk($routeName)
    {
        return self::goWithSuccess($routeName, __('msg.store_ok'));
    }

    public static function deleteOk($routeName)
    {
        return self::goWithSuccess($routeName, __('msg.del_ok'));
    }

    public static function updateOk($routeName)
    {
        return self::goWithSuccess($routeName, __('msg.update_ok'));
    }

    public static function goToRoute($goto, $status = 302, $headers = [], $secure = null)
    {
        $data = [];
        $to = (is_array($goto) ? $goto[0] : $goto) ?: 'dashboard';
        if(is_array($goto)){
            array_shift($goto);
            $data = $goto;
        }
        return app('redirect')->to(route($to, $data), $status, $headers, $secure);
    }

    public static function goWithDanger($to = 'dashboard', $msg = NULL)
    {
        $msg = $msg ? $msg : __('msg.rnf');
        return self::goToRoute($to)->with('flash_danger', $msg);
    }

    public static function goWithSuccess($to, $msg)
    {
        return self::goToRoute($to)->with('flash_success', $msg);
    }

    public static function getDaysOfTheWeek()
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    }

}
