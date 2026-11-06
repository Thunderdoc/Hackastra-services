<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ID card data (item 14, owner 2026-11-01 + amendments): ONE
 * builder feeds every ID-card surface (single, mine, bulk, staff)
 * so the design can never drift between them. Rules baked in:
 *  - Branding is fully dynamic from Settings (system name, logo,
 *    address, office email, phone via Qs::getSchoolPhone(), the
 *    uploaded principal signature + school stamp). Nothing is
 *    hard-coded to a school; a missing logo becomes a monogram
 *    tile, a missing stamp/signature stays blank — never faked.
 *  - Fields come from REAL columns only; a missing value OMITS
 *    its tile/row entirely.
 *  - STABLE FACTS ONLY: nothing that can change mid-session is
 *    printed (a teacher card carries no subject, no class-teacher
 *    line, no periods/week).
 *  - The parent is the app's single my_parent record: the tile is
 *    labelled "Parent" (the app's own word — students/show uses
 *    it too), never "Father": the store holds one parent/guardian
 *    and does not say which.
 *  - No emergency-contact field exists for staff, and none for
 *    students either: the student back falls back to the parent
 *    phone (owner's rule); the staff back omits the section.
 *  - Barcode value: student Adm No / staff users.code, rendered
 *    by App\Support\Code39.
 */
class IdCardFactory
{
    public const ROLE_META = [
        'student'    => ['label' => 'STUDENT', 'designation' => 'Student'],
        'teacher'    => ['label' => 'TEACHER', 'designation' => 'Teacher'],
        'principal'  => ['label' => 'PRINCIPAL', 'designation' => 'Principal'],
        'admin'      => ['label' => 'ADMINISTRATOR', 'designation' => 'School Admin'],
        'accountant' => ['label' => 'ACCOUNTANT', 'designation' => 'Accountant'],
        'librarian'  => ['label' => 'LIBRARIAN', 'designation' => 'Librarian'],
    ];

    /** Settings-driven brand block shared by every card. */
    public static function brand(bool $forPdf = false): array
    {
        $name = (string) Qs::getSystemName();
        $logo = Qs::getSetting('logo');

        return [
            'name' => $name,
            'monogram' => self::monogram($name),
            'logo' => $logo ?: null,
            'logo_img' => self::imageSrc($logo, $forPdf),
            'phone' => Qs::getSchoolPhone(),
            'address' => Qs::getSetting('address') ?: null,
            'email' => Qs::getSetting('system_email') ?: null,
            'session' => Qs::getCurrentSession(),
            'stamp_img' => self::imageSrc(Qs::getSetting('school_stamp'), $forPdf),
            'sign_img' => self::imageSrc(Qs::getSetting('principal_signature'), $forPdf),
        ];
    }

    /** Two-letter monogram from the school name (logo fallback). */
    public static function monogram(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $ini = '';
        foreach ($words as $w) {
            if ($w !== '' && ctype_alpha($w[0])) {
                $ini .= strtoupper($w[0]);
            }
            if (strlen($ini) >= 2) {
                break;
            }
        }

        return $ini !== '' ? $ini : 'S';
    }

    /**
     * Roster position (1-based) of a student inside their section,
     * in the admission order the attendance roster uses
     * (adm_no, then id). Null when the student has no section.
     */
    public static function rollNo(StudentRecord $sr): ?int
    {
        if (!$sr->section_id) {
            return null;
        }
        $ids = StudentRecord::where('section_id', $sr->section_id)
            ->where('grad', 0)->orderBy('adm_no')->orderBy('id')->pluck('id');
        $idx = $ids->search($sr->id);

        return $idx === false ? null : $idx + 1;
    }

    public static function forStudent(StudentRecord $sr, bool $forPdf = false): array
    {
        $sr->loadMissing(['user', 'my_class', 'section', 'my_parent']);
        $user = $sr->user;
        $parent = $sr->my_parent;
        $roll = self::rollNo($sr);

        $tiles = [];
        $tiles[] = ['Class', optional($sr->my_class)->name];
        $tiles[] = ['Section', optional($sr->section)->name];
        $tiles[] = ['Adm No', $sr->adm_no, true, true];
        $tiles[] = ['Roll No', $roll !== null ? str_pad((string) $roll, 2, '0', STR_PAD_LEFT) : null];
        $tiles[] = ['House', $sr->house];
        $tiles[] = ['DOB', self::dateOrRaw(optional($user)->dob)];
        $tiles[] = ['Blood Group', optional(optional($user)->blood_group)->name];
        $tiles[] = ['Parent', optional($parent)->name];
        // Order note: Roll|House, DOB|Blood, Parent|Phone pair up in
        // the two-column tile grid when every value is present.
        $tiles[] = ['Parent Phone', optional($parent)->phone, false, true];
        $tiles = self::cleanTiles($tiles);

        $parentRows = self::cleanRows([
            ['Parent', optional($parent)->name],
            ['Phone', optional($parent)->phone],
        ]);
        $sections = [];
        if ($parentRows) {
            $sections[] = ['PARENT DETAILS', $parentRows];
        }
        if (optional($user)->address) {
            $sections[] = ['ADDRESS', $user->address];
        }
        if ($parent && $parent->phone) {
            $sections[] = ['EMERGENCY CONTACT', $parent->name . ' · ' . $parent->phone];
        }

        return [
            'role' => 'student',
            'role_label' => self::ROLE_META['student']['label'],
            'name' => optional($user)->name,
            'initials' => Qs::initials(optional($user)->name ?? ''),
            'photo_img' => Qs::hasRealPhoto(optional($user)->photo)
                ? self::imageSrc($user->photo, $forPdf) : null,
            'sub' => trim(optional($sr->my_class)->name . ' — ' . optional($sr->section)->name)
                . ($roll !== null ? ' · Roll No ' . str_pad((string) $roll, 2, '0', STR_PAD_LEFT) : ''),
            'front_tiles' => $tiles,
            'back_sections' => $sections,
            'code' => $sr->adm_no,
            'is_student' => true,
            'is_principal' => false,
        ];
    }

    public static function forStaff(User $user, bool $forPdf = false): ?array
    {
        $type = $user->user_type;
        if (!isset(self::ROLE_META[$type]) || $type === 'student') {
            return null; // super_admin is platform staff, not a school card
        }
        $brand = self::brand($forPdf);
        $meta = self::ROLE_META[$type];

        $tiles = self::cleanTiles([
            ['Designation', $meta['designation'], true],
            ['Emp ID', $user->code, false, true],
            ['Phone', $user->phone, false, true],
            ['Blood Group', optional($user->blood_group)->name],
            ['Joined', $user->created_at ? $user->created_at->format('d M Y') : null],
            ['Email', $user->email, true, true],
            ['School Office', $brand['phone'], true, true],
        ]);

        $contact = self::cleanRows([
            ['Phone', $user->phone],
            ['Email', $user->email],
            ['Emp ID', $user->code],
        ]);
        $sections = [];
        if ($contact) {
            $sections[] = ['CONTACT', $contact];
        }
        if ($user->address) {
            $sections[] = ['ADDRESS', $user->address];
        }
        // No emergency-contact field exists for staff: section omitted.

        return [
            'role' => $type,
            'role_label' => $meta['label'],
            'name' => $user->name,
            'initials' => Qs::initials($user->name ?? ''),
            'photo_img' => Qs::hasRealPhoto($user->photo)
                ? self::imageSrc($user->photo, $forPdf) : null,
            'sub' => $meta['designation'],
            'front_tiles' => $tiles,
            'back_sections' => $sections,
            'code' => $user->code,
            'is_student' => false,
            'is_principal' => $type === 'principal',
        ];
    }

    /** Drop tiles whose value is empty; normalise to assoc rows. */
    private static function cleanTiles(array $tiles): array
    {
        $out = [];
        foreach ($tiles as $t) {
            [$label, $value] = [$t[0], $t[1] ?? null];
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'value' => (string) $value,
                'wide' => !empty($t[2]),
                'mono' => !empty($t[3]),
            ];
        }

        return $out;
    }

    private static function cleanRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($r) {
            return isset($r[1]) && trim((string) $r[1]) !== '';
        }));
    }

    /** 'd M Y' when the stored value parses as a date, else raw. */
    private static function dateOrRaw($value): ?string
    {
        if (!$value || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('d M Y');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }

    /**
     * Image src for a stored upload: local /storage files become
     * data URIs for PDF (dompdf cannot fetch URLs); HTML keeps the
     * URL. An SVG logo has no raster data URI for dompdf — PDF
     * falls back to null (the blade then shows the monogram).
     */
    public static function imageSrc($url, bool $forPdf = false)
    {
        if (!$url || trim((string) $url) === '') {
            return null;
        }
        if (!$forPdf) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && Str::endsWith(strtolower($path), '.svg')) {
            return null;
        }
        if ($path && Str::startsWith($path, '/') && !Str::startsWith($path, '/storage/')) {
            $local = public_path(ltrim($path, '/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';

                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        if ($path && Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';

                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }

        return null;
    }
}
