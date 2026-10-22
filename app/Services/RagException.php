<?php

namespace App\Services;

use Exception;

/**
 * A user-safe AI/RAG failure (Workstream DH). Messages carried by this
 * exception are written for the person on screen — provider details,
 * keys and stack traces never travel in it (same convention as CX's
 * AiAssistException).
 */
class RagException extends Exception
{
}
