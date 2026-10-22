<?php

namespace App\Models;

use Eloquent;

class RagChunk extends Eloquent
{
    protected $table = 'rag_chunks';

    protected $fillable = [
        'rag_document_id', 'source_type', 'source_id', 'chunk_index',
        'section_title', 'text', 'audience_roles', 'embedding',
        'embedding_model', 'content_hash',
    ];

    protected $casts = [
        'audience_roles' => 'array',
        'embedding' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }
}
