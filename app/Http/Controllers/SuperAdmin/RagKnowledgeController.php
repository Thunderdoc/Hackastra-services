<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Services\RagCorpus;
use App\Services\RagException;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Knowledge Base (Workstream DH) — the policy-text half of the RAG
 * corpus, managed from Settings. Published circulars flow in through
 * the rebuild; policy texts are authored here (title + text +
 * visibility). Every change re-runs the idempotent rebuild so the
 * corpus never drifts from what this page shows.
 *
 * Routes sit in the teamSA settings group; the page itself is the
 * settings page's Knowledge Base card.
 */
class RagKnowledgeController extends Controller
{
    public function store(Request $req)
    {
        $data = $req->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:50000',
            'is_public' => 'sometimes|boolean',
        ]);

        $doc = RagDocument::create([
            'title' => trim($data['title']),
            'source_type' => 'policy_text',
            'source_id' => null,
            'body' => trim($data['body']),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'created_by' => Auth::id(),
        ]);

        Audit::log('ai.knowledge_created', $doc, 'Knowledge document "' . $doc->title . '" added (' . ($doc->is_public ? 'public' : 'staff only') . ')');

        return $this->rebuildAndBack('Knowledge document saved.');
    }

    public function destroy(RagDocument $document)
    {
        if ($document->source_type !== 'policy_text') {
            return back()->with('flash_danger', __('msg.denied'));
        }

        RagChunk::where('rag_document_id', $document->id)->delete();
        $title = $document->title;
        $document->delete();

        Audit::log('ai.knowledge_deleted', null, 'Knowledge document "' . $title . '" deleted');

        return back()->with('flash_success', 'Knowledge document deleted and removed from the index.');
    }

    public function rebuild()
    {
        return $this->rebuildAndBack(null);
    }

    /** Run the idempotent rebuild and report its real outcome. */
    protected function rebuildAndBack(?string $prefix)
    {
        try {
            $stats = RagCorpus::rebuild(Auth::user());
        } catch (RagException $e) {
            $message = ($prefix ? $prefix . ' ' : '') . 'The knowledge index could not be rebuilt: ' . $e->getMessage();

            return back()->with('flash_danger', $message);
        }

        $message = ($prefix ? $prefix . ' ' : '')
            . 'Knowledge index rebuilt: ' . $stats['documents'] . ' documents, ' . $stats['chunks'] . ' chunks'
            . ' (' . $stats['reembedded'] . ' re-embedded, ' . $stats['skipped'] . ' unchanged, ' . $stats['removed'] . ' removed).';

        return back()->with('flash_success', $message);
    }
}
