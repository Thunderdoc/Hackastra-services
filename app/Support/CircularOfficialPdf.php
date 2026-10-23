<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Circular;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The official signed PDF of a circular (owner's university model,
 * 2026-10-23): the moment a circular is published, the system
 * itself produces the definitive PDF document — school letterhead,
 * the principal's signature and the school seal exactly as uploaded
 * in Settings — and files it with the circular, so every recipient
 * downloads the signed original instead of a printout they made
 * themselves. No manual download / re-upload step exists.
 *
 * The render is the controller's on-demand PDF, byte for byte: the
 * same `circulars.pdf` view over the same `_document` partial, with
 * the same data-URI asset workaround (dompdf cannot fetch the
 * Settings asset URLs). The two can never diverge; this class only
 * decides WHEN the bytes are produced and WHERE they are filed.
 *
 * Lifecycle, kept in one place (CircularController calls sync()):
 *  - published (is_published)  -> generate / refresh the file;
 *  - draft, unpublished, gone  -> no official file may exist.
 * Generation failures surface as exceptions on purpose: the
 * controller wraps its eager calls in try/catch so a PDF problem
 * can never fail the publish itself, and the lazy path (detail
 * view / official download) heals a missing file on first touch.
 */
class CircularOfficialPdf
{
    /** Deterministic storage path (local disk) for a circular. */
    public static function pathFor(Circular $circular): string
    {
        return 'uploads/circulars/circular-' . $circular->id . '-official.pdf';
    }

    /** Whether a usable official file is currently on record. */
    public static function exists(Circular $circular): bool
    {
        return $circular->official_pdf_path
            && Storage::disk('local')->exists($circular->official_pdf_path);
    }

    /**
     * Render the official PDF and file it, overwriting any older
     * copy in place (the path is deterministic per circular).
     * Returns the storage path. Throws on failure — callers that
     * must not fail (the publish flow) wrap this in try/catch.
     */
    public static function generateOfficialPdf(Circular $circular): string
    {
        $circular->load(['publisher', 'attachments']);

        $d = [
            'circular' => $circular,
            'audiences' => Circular::audiences(),
            'priorities' => Circular::priorities(),
            // dompdf cannot fetch the Settings asset URLs, so the
            // letterhead, signature and seal go in as data URIs —
            // the identical workaround CircularController@pdf uses.
            // Unset settings stay null and the document renders its
            // blank signature space, exactly like the manual PDF.
            'doc_logo' => self::pdfImageDataUri(Qs::getSetting('logo')),
            'doc_signature' => self::pdfImageDataUri(Qs::getSetting('principal_signature')),
            'doc_stamp' => self::pdfImageDataUri(Qs::getSetting('school_stamp')),
        ];

        $bytes = Pdf::loadView('pages.support_team.circulars.pdf', $d)->output();

        $path = self::pathFor($circular);
        Storage::disk('local')->put($path, $bytes);

        $circular->forceFill([
            'official_pdf_path' => $path,
            'official_pdf_generated_at' => now(),
        ])->save();

        return $path;
    }

    /** Delete the stored file (if any) and clear its record. */
    public static function remove(Circular $circular): void
    {
        if ($circular->official_pdf_path) {
            Storage::disk('local')->delete($circular->official_pdf_path);
        }

        if ($circular->official_pdf_path || $circular->official_pdf_generated_at) {
            $circular->forceFill([
                'official_pdf_path' => null,
                'official_pdf_generated_at' => null,
            ])->save();
        }
    }

    /**
     * Bring the stored file in line with the circular's publish
     * state: a published circular always carries a fresh official
     * PDF; anything else carries none. Throws on failure.
     */
    public static function sync(Circular $circular): void
    {
        if ($circular->is_published) {
            self::generateOfficialPdf($circular);
        } else {
            self::remove($circular);
        }
    }

    /**
     * The lazy heal: a published circular whose official file is
     * missing (seeded rows, rows published before this feature
     * existed, an eager run that failed) gets it generated on
     * first touch. Returns true when a usable file exists after
     * the call. Never throws — a failed render just leaves the
     * state for the next touch, logged by the caller if wanted.
     */
    public static function ensureOfficialPdf(Circular $circular): bool
    {
        if (!$circular->is_published) {
            return false;
        }

        if (self::exists($circular)) {
            return true;
        }

        try {
            self::generateOfficialPdf($circular);
        } catch (\Throwable $e) {
            Log::warning('Circular official PDF lazy generation failed for circular ' . $circular->id . ': ' . $e->getMessage());
            return false;
        }

        return self::exists($circular);
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI
       read from the local file, for renderers that cannot fetch
       URLs (dompdf). Anything that is not a local storage file is
       returned unchanged. Mirrors CircularController::pdfImageDataUri. */
    protected static function pdfImageDataUri($url)
    {
        if (!$url) {
            return null;
        }
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
