<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\ReportTemplate;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Support\Audit;
use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportPresets;
use App\Support\Reports\ReportSources;
use App\Support\TeacherScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Universal Custom PDF Report Builder (wave 31b).
 *
 * No single team middleware fits: teachers, accountants, librarians,
 * admins, and the Super Admin all use this controller, each against
 * a different source list — so gating lives HERE, enforced by
 * ReportEngine + ReportSources on every request, never by the menu.
 * Parents and students get a 403 on every action. The Super Admin
 * reaches only the platform source; every school source 403s for it
 * even though PlatformOnly lets it through the door.
 *
 * Preview returns the REAL generated PDF bytes (inline) — the exact
 * document the download produces — never a mockup. The print blade
 * reuses the wave-31a fixed-header technique, and page numbers come
 * from PdfFooter's canvas stamp (render first, then stamp, then
 * output — download/output do not re-render).
 */
class ReportsController extends Controller
{
    /** Roles allowed anywhere in this controller. */
    protected function gateRole(): void
    {
        $user = Auth::user();
        if (!$user || Qs::userIsStudent() || Qs::userIsParent()) {
            abort(403, 'Reports are not available for your role.');
        }
    }

    /** Decode the posted config (JSON string from the builder form, or an array). */
    protected function configFromRequest(Request $request): array
    {
        $raw = $request->input('config');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                abort(422, 'Invalid report configuration.');
            }
            return $decoded;
        }
        if (is_array($raw)) {
            return $raw;
        }
        abort(422, 'Missing report configuration.');
    }

    /** Branding for the print document: Settings at generation time, logo inlined. */
    protected function settingsForPrint(): array
    {
        $s = Setting::all()->flatMap(function ($setting) {
            return [$setting->type => $setting->description];
        })->all();
        if (!empty($s['logo'])) {
            $s['logo'] = $this->pdfImageDataUri($s['logo']);
        }
        return $s;
    }

    /** Same data-URI pattern as the roster PDF: dompdf cannot fetch remote URLs. */
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

    /** Validate + resolve + render a PDF for the posted config. */
    protected function buildPdf(Request $request)
    {
        // Same request-scoped headroom as the bulk marksheet download:
        // a whole-class sheet can hold hundreds of rows, and dompdf's
        // cell layout exhausts the stock 128M on such tables.
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $user = Auth::user();
        $config = ReportEngine::validateConfig($this->configFromRequest($request), $user);
        $doc = ReportEngine::resolve($config, $user);
        $pdf = Pdf::loadView('pages.support_team.reports.print', [
            'doc' => $doc,
            's' => $this->settingsForPrint(),
        ]);
        $pdf->setPaper('a4', $config['options']['orientation']);
        return [$pdf, $config, $doc];
    }

    /** Builder page: config on the left, live preview on the right. */
    public function builder()
    {
        $this->gateRole();
        $user = Auth::user();
        $type = $user->user_type;

        if (TeacherScope::appliesTo($user)) {
            $classIds = TeacherScope::classIds($user);
            $classes = MyClass::whereIn('id', $classIds)->orderBy('id')->get();
            $sections = Section::whereIn('my_class_id', $classIds)->orderBy('id')->get();
            $subjects = Subject::whereIn('my_class_id', $classIds)->orderBy('id')->get();
        } else {
            $classes = MyClass::orderBy('id')->get();
            $sections = Section::orderBy('id')->get();
            $subjects = Subject::orderBy('id')->get();
        }

        $sources = [];
        foreach (ReportSources::allowedFor($type) as $key) {
            $def = ReportSources::definition($key);
            $cols = [];
            foreach ($def['columns'] as $colKey => $colDef) {
                $cols[] = ['key' => $colKey, 'label' => $colDef[0], 'type' => $colDef[1]];
            }
            $sources[$key] = ['label' => $def['label'], 'columns' => $cols];
        }

        return view('pages.support_team.reports.builder', [
            'userType' => $type,
            'presets' => ReportPresets::forRole($type),
            'sources' => $sources,
            'classes' => $classes,
            'sectionsMap' => $sections->map(function ($s) {
                return ['id' => $s->id, 'name' => $s->name, 'my_class_id' => $s->my_class_id];
            })->values()->all(),
            'subjectsMap' => $subjects->map(function ($s) {
                return ['id' => $s->id, 'name' => $s->name, 'my_class_id' => $s->my_class_id];
            })->values()->all(),
            'exams' => Exam::orderBy('id')->get(),
            'templates' => ReportTemplate::where('user_id', $user->id)->orderBy('name')->get(),
            'templatesMap' => ReportTemplate::where('user_id', $user->id)->get()->mapWithKeys(function ($t) {
                return [$t->id => $t->config];
            })->all(),
            'currentSession' => Qs::getCurrentSession(),
        ]);
    }

    /** True paginated preview: the real PDF bytes in an iframe. */
    public function preview(Request $request)
    {
        $this->gateRole();
        try {
            [$pdf, $config, $doc] = $this->buildPdf($request);
            \App\Support\PdfFooter::stampPageNumbers($pdf, $config['options']['orientation']);
            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . Str::slug($config['title']) . '.pdf"',
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('flash_danger', $e->getMessage());
        }
    }

    /** Download the generated PDF. */
    public function pdf(Request $request)
    {
        $this->gateRole();
        try {
            [$pdf, $config, $doc] = $this->buildPdf($request);
            \App\Support\PdfFooter::stampPageNumbers($pdf, $config['options']['orientation']);
            Audit::log('report.exported', null, 'Report PDF exported: ' . $config['title'] . ' (source: ' . $config['source'] . ').');
            return $pdf->download(Str::slug($config['title']) . '.pdf');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('flash_danger', $e->getMessage());
        }
    }

    /** Save the posted config as an owner-private template. */
    public function storeTemplate(Request $request)
    {
        $this->gateRole();
        $request->validate(['name' => 'required|string|max:80']);
        $config = ReportEngine::validateConfig($this->configFromRequest($request), Auth::user());
        $template = ReportTemplate::create([
            'user_id' => Auth::id(),
            'name' => $request->input('name'),
            'config' => $config,
        ]);
        Audit::log('report.template_saved', $template, 'Report template saved: ' . $template->name);
        return redirect()->back()->with('flash_success', 'Template saved.');
    }

    /** Update an own template (owner only). */
    public function updateTemplate(Request $request, $template)
    {
        $this->gateRole();
        $template = $this->ownTemplate($template);
        $request->validate(['name' => 'required|string|max:80']);
        $config = ReportEngine::validateConfig($this->configFromRequest($request), Auth::user());
        $template->update(['name' => $request->input('name'), 'config' => $config]);
        Audit::log('report.template_saved', $template, 'Report template updated: ' . $template->name);
        return redirect()->back()->with('flash_success', 'Template updated.');
    }

    /** Delete an own template (owner only). */
    public function destroyTemplate($template)
    {
        $this->gateRole();
        $template = $this->ownTemplate($template);
        $name = $template->name;
        $template->delete();
        Audit::log('report.template_deleted', null, 'Report template deleted: ' . $name);
        return redirect()->back()->with('flash_success', 'Template deleted.');
    }

    /** Duplicate an own template (owner only). */
    public function duplicateTemplate($template)
    {
        $this->gateRole();
        $template = $this->ownTemplate($template);
        $copy = ReportTemplate::create([
            'user_id' => Auth::id(),
            'name' => $template->name . ' (Copy)',
            'config' => $template->config,
        ]);
        Audit::log('report.template_saved', $copy, 'Report template duplicated: ' . $copy->name);
        return redirect()->back()->with('flash_success', 'Template duplicated.');
    }

    /** Load a template or 404; a foreign template is a 403, never a leak. */
    protected function ownTemplate($template): ReportTemplate
    {
        $template = ReportTemplate::findOrFail($template);
        if ((int) $template->user_id !== (int) Auth::id()) {
            abort(403, 'That template belongs to another user.');
        }
        return $template;
    }
}
