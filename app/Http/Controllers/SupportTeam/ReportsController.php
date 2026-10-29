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
 *
 * Builder redesign 2026-10-29 — live preview, first: the preview
 * iframe auto-updates (debounced) from the same config as the
 * download, and an invalid preview config no longer redirects
 * inside the iframe (that rendered the builder page inside the
 * frame, i.e. a blank/broken "live preview"). It now returns a
 * small error document with a 422 status for iframe requests.
 */
class ReportsController extends Controller
{
    /** Roles allowed anywhere in this controller: teacher,
     *  principal and ADMIN. An overnight change (2026-10-29) had
     *  removed admin; the owner corrected that the same evening —
     *  he works in the builder as Admin himself (live screenshot,
     *  "ye thik kro") — so admin access is restored. This does NOT
     *  weaken the source gate: ReportSources::allowedFor() still
     *  decides which sources each role sees in the builder
     *  (principal maps to admin sources there), and
     *  ReportEngine::validateConfig() still aborts 403 on any
     *  role-disallowed source on every request. */
    protected function gateRole(): void
    {
        $user = Auth::user();
        if (!$user || (!Qs::userIsTeacher() && !Qs::userIsPrincipal() && !Qs::userIsAdmin())) {
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
        // Page numbers (PDF polish, owner 2026-10-29 night):
        // dompdf's CSS has no per-page counter, so the canvas
        // draws "Page X of Y" into the footer's empty right zone
        // on every page, in the footer's own grey. dompdf 3.1's
        // CPDF adapter runs page callbacks against the pages that
        // exist AT REGISTRATION TIME — registering before the
        // render stamps only page 1, with a count of 1 — so the
        // document is rendered first (the wrapper's callers reuse
        // that render) and the text is stamped afterwards, when
        // every page exists and the count is true.
        if ($config['options']['show_footer']) {
            $pdf->render();
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $font = $metrics->getFont('DejaVu Sans', 'normal');
            $probeWidth = $metrics->getTextWidth('Page 99 of 99', $font, 8);
            $canvas->page_text(
                $canvas->get_width() - $probeWidth - $config['options']['margin_mm'] * 2.83465,
                $canvas->get_height() - 30,
                'Page {PAGE_NUM} of {PAGE_COUNT}',
                $font, 8, [0.443, 0.502, 0.588]
            );
        }
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
            // Iframe preview path (the builder's hidden preview form
            // carries preview_frame=1): a redirect here used to load
            // the builder page itself inside the iframe, which is
            // exactly the "live preview is not working" report — a
            // blank frame, or a nested builder. Return a small error
            // document with a 422 status instead, so the frame shows
            // a helpful error. Direct navigation keeps the old
            // redirect-with-flash behaviour, unchanged.
            if ($request->input('preview_frame')) {
                return response($this->previewErrorHtml($e->getMessage()), 422, [
                    'Content-Type' => 'text/html; charset=utf-8',
                ]);
            }
            return redirect()->back()->with('flash_danger', $e->getMessage());
        }
    }

    /** Error document for the preview iframe: no app chrome, just
     * the reason and what to do. The builder's JS detects the
     * data-rb-preview-error marker on the body to report the error
     * state above the frame. The message is escaped; it comes from
     * the engine's own validation, never from user input verbatim. */
    protected function previewErrorHtml(string $message): string
    {
        $safe = e($message);
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<style>body{font-family:Arial,Helvetica,sans-serif;color:#172536;background:#f4f8fd;margin:0;padding:24px;}'
            . '.box{background:#fff;border:1px solid #dfe8f3;border-left:4px solid #1765f5;border-radius:12px;padding:20px;max-width:520px;margin:0 auto;}'
            . 'h2{font-size:16px;margin:0 0 8px;color:#10213f;}p{font-size:13px;line-height:1.6;color:#4a5a6e;margin:0 0 8px;}'
            . '.msg{color:#172536;font-weight:600;}</style></head>'
            . '<body data-rb-preview-error="1"><div class="box">'
            . '<h2>Preview could not be generated</h2>'
            . '<p class="msg">' . $safe . '</p>'
            . '<p>Fix the report settings on the left and the preview will update automatically. No PDF was generated.</p>'
            . '</div></body></html>';
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
