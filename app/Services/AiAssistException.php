<?php

namespace App\Services;

use Exception;

/**
 * Every AI failure the user can hit (AI off, daily cap reached, provider
 * unreachable) is raised as this exception carrying a message that is
 * safe to show verbatim. Raw provider errors never reach the screen.
 */
class AiAssistException extends Exception
{
}
