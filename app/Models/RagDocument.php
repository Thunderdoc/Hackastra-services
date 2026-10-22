<?php

namespace App\Models;

use App\User;
use Eloquent;

class RagDocument extends Eloquent
{
    protected $table = 'rag_documents';

    protected $fillable = [
        'title', 'source_type', 'source_id', 'body', 'audience', 'is_public', 'created_by',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function chunks()
    {
        return $this->hasMany(RagChunk::class, 'rag_document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Documents a person authored in Settings → Knowledge Base. */
    public function scopePolicies($query)
    {
        return $query->where('source_type', 'policy_text');
    }
}
