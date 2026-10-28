<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\StudentRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA')->only(['index', 'print']);
        $this->middleware('teamSAWrite')->only(['create', 'store']);
    }

    /** The register: every issued certificate, newest first. */
    public function index(Request $request)
    {
        $query = Certificate::with('user')->orderByDesc('id');
        if (in_array($request->get('type'), Certificate::TYPES, true)) {
            $query->where('type', $request->get('type'));
        }
        return view('pages.support_team.certificates.index', [
            'certificates' => $query->paginate(25),
            'type' => $request->get('type', 'all'),
        ]);
    }

    public function create(Request $request)
    {
        $preselected = null;
        if ($request->filled('student')) {
            $srId = Qs::decodeHash($request->get('student'));
            $preselected = $srId ? StudentRecord::with(['user', 'my_class', 'section'])->find($srId) : null;
        }
        if (!$preselected && $request->filled('adm_no')) {
            // Find-by-admission-no: stored numbers are always uppercased
            // (generated and typed alike), so normalise the lookup too.
            $preselected = StudentRecord::with(['user', 'my_class', 'section'])
                ->where('adm_no', strtoupper(trim((string) $request->get('adm_no'))))
                ->first();
        }
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)->orderBy('id')->get();
        if ($preselected && !$students->contains('id', $preselected->id)) {
            $students->prepend($preselected);
        }
        return view('pages.support_team.certificates.create', [
            'students' => $students,
            'preselected' => $preselected,
            'type' => $request->get('type', 'bonafide'),
        ]);
    }

    /** Issue: snapshot the student, mint the serial, land on print. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_record_id' => 'required|exists:student_records,id',
            'type' => 'required|in:' . implode(',', Certificate::TYPES),
            'reason' => 'required_if:type,transfer|nullable|string|max:1000',
        ]);

        $sr = StudentRecord::with(['user', 'my_class', 'section'])->findOrFail($data['student_record_id']);

        $certificate = null;
        for ($i = 0; $i < 10; $i++) {
            try {
                $certificate = Certificate::create([
                    'user_id' => $sr->user_id,
                    'student_record_id' => $sr->id,
                    'type' => $data['type'],
                    'serial' => Certificate::mintSerial($data['type']),
                    'student_name' => $sr->user->name,
                    'adm_no' => $sr->adm_no,
                    'class_name' => optional($sr->my_class)->name,
                    'section_name' => optional($sr->section)->name,
                    'session' => $sr->session,
                    'reason' => $data['reason'] ?? null,
                    'issued_by' => Auth::id(),
                    'issued_at' => now(),
                ]);
                break;
            } catch (QueryException $e) {
                // Serial collision under concurrency — re-mint.
                if ($i === 9) {
                    throw $e;
                }
            }
        }

        return redirect()->route('certificates.print', $certificate->id)
            ->with('flash_success', 'Certificate issued — serial ' . $certificate->serial . '.');
    }

    /** Printable certificate (browser print; the @media print
     *  layer hides the sidebar, like the ID card). */
    public function print(Certificate $certificate)
    {
        $settings = [
            'school' => Qs::getSetting('system_name') ?: config('app.name'),
            'address' => Qs::getSetting('address'),
            'phone' => Qs::getSetting('phone'),
            'principal' => Qs::getSetting('principal_name'),
            'logo' => $this->settingImage('logo'),
            'stamp' => $this->settingImage('school_stamp'),
            'signature' => $this->settingImage('principal_signature'),
        ];
        $view = $certificate->type === 'transfer'
            ? 'pages.support_team.certificates.print_transfer'
            : 'pages.support_team.certificates.print_bonafide';
        return view($view, ['c' => $certificate, 's' => $settings]);
    }

    /** A settings image that exists on the public disk, as a web
     *  path — or null, so the print view never shows a broken
     *  image (and never fabricates a stamp or signature). */
    protected function settingImage(string $key): ?string
    {
        $path = Qs::getSetting($key);
        if (!$path) {
            return null;
        }
        $path = ltrim((string) $path, '/');
        // Absolute URLs whose path is /storage/... (a value saved
        // with its origin, e.g. a local http://127.0.0.1:8993 URL):
        // use just the path part so the on-disk check below works
        // and the certificate still carries the school's logo.
        // Truly external URLs keep returning null — the print page
        // never fetches remote branding.
        if (preg_match('#^https?://#', $path)) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            if ($urlPath) {
                $path = ltrim($urlPath, '/');
            }
        }
        if (str_starts_with($path, 'storage/')) {
            $diskPath = substr($path, strlen('storage/'));
            return Storage::disk('public')->exists($diskPath) ? '/' . $path : null;
        }
        return Storage::disk('public')->exists($path) ? '/storage/' . $path : null;
    }
}
