<?php

namespace App\Console\Commands;

use App\Services\RagCorpus;
use App\Services\RagException;
use Illuminate\Console\Command;

class RebuildRagIndex extends Command
{
    /**
     * Rebuild the RAG knowledge index (Workstream DH): published
     * circulars + Knowledge Base policy texts are (re)chunked and
     * (re)embedded. Idempotent — unchanged sources keep their chunks.
     */
    protected $signature = 'rag:rebuild';

    protected $description = 'Rebuild the Ask Hackstra knowledge index from circulars and policy documents';

    public function handle()
    {
        try {
            $stats = RagCorpus::rebuild();
        } catch (RagException $e) {
            $this->error('Knowledge index rebuild failed: ' . $e->getMessage());

            return 1;
        }

        $this->info('Knowledge index rebuilt: ' . $stats['documents'] . ' documents, ' . $stats['chunks'] . ' chunks.');
        $this->line('Re-embedded: ' . $stats['reembedded'] . ' · unchanged: ' . $stats['skipped'] . ' · removed: ' . $stats['removed'] . ' · embeddings: ' . $stats['model'] . ($stats['mock'] ? ' (mock transport)' : ''));

        return 0;
    }
}
