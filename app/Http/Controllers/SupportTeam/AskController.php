<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Services\RagChat;
use App\Services\RagException;
use App\Services\RagRetriever;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ask Hackstra (Workstream DH) — the RAG chat page. One page, one
 * form: the answer comes back flashed with its sources. The service
 * enforces the permission filter and the grounding contract; this
 * controller only shapes the page's honest states (AI off, nothing
 * indexed for this role yet, answer, refusal).
 */
class AskController extends Controller
{
    public function index(RagRetriever $retriever)
    {
        $user = Auth::user();
        $d['enabled'] = RagChat::enabled();
        $d['visible_chunks'] = $d['enabled'] ? $retriever->visibleChunkCount($user) : 0;
        $d['answer'] = session('rag_answer');
        $d['sources'] = session('rag_sources', []);
        $d['refused'] = session('rag_refused', false);
        $d['question'] = session('rag_question');

        return view('pages.support_team.ask.index', $d);
    }

    public function ask(Request $req)
    {
        $req->validate([
            'question' => 'required|string|max:500',
        ]);

        try {
            $result = RagChat::ask(Auth::user(), trim($req->input('question')));
        } catch (RagException $e) {
            return back()->with('flash_danger', $e->getMessage())->withInput();
        }

        return redirect()->route('ask.index')
            ->with('rag_answer', $result['answer'])
            ->with('rag_sources', $result['sources'])
            ->with('rag_refused', $result['refused'])
            ->with('rag_question', trim($req->input('question')));
    }
}
