<?php

/*
 * AI layer — runtime knobs that are NOT school settings.
 *
 * The school/platform-facing switches live in the settings table
 * (ai_enabled, ai_provider, ai_model, ai_embedding_model, ai_api_key —
 * edited from Settings → AI Assistant; the key is write-only). This file
 * holds deployment-level behaviour:
 *
 *  - mock: AI_MOCK=1 forces the deterministic mock transports even when
 *    a key is configured (used for testing and demos without a real
 *    key). The mock transports also engage automatically when AI is
 *    switched on but no API key has been pasted yet, so features can be
 *    previewed before go-live.
 *  - daily_cap: maximum successful AI generations per user per day,
 *    enforced from the ai_usage metering table (shared by AiAssist and
 *    the RAG chat).
 *  - gemini.base_url: override point for the Gemini REST endpoint
 *    (tests point this at a local stub; production leaves the default).
 *  - rag: the RAG core's retrieval/chunking parameters (Workstream DH,
 *    design docs/AI_LAYER.md §1). Corpus scale is small by design, so
 *    retrieval is brute-force cosine over JSON embeddings in PHP.
 */

return [

    'mock' => (bool) env('AI_MOCK', false),

    'daily_cap' => (int) env('AI_DAILY_CAP', 50),

    'gemini' => [
        'base_url' => env('AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout' => (int) env('AI_GEMINI_TIMEOUT', 20),
    ],

    'rag' => [
        // Target chunk size in characters (paragraph-aware, §1.2 of the
        // design scales this to ~600 tokens for long documents; Wave 1
        // sources are circulars and pasted policy text).
        'chunk_chars' => (int) env('AI_RAG_CHUNK_CHARS', 800),
        'chunk_overlap' => (int) env('AI_RAG_CHUNK_OVERLAP', 120),
        // Chunks handed to the answer composer per question.
        'top_k' => (int) env('AI_RAG_TOP_K', 6),
        // Below this cosine similarity the assistant refuses honestly
        // instead of improvising (§1.4 rule 4).
        'threshold' => (float) env('AI_RAG_THRESHOLD', 0.15),
        // Dimension of the deterministic mock embedding vectors.
        'mock_dims' => (int) env('AI_RAG_MOCK_DIMS', 256),
    ],

];
