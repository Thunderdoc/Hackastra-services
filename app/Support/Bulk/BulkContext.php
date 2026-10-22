<?php

namespace App\Support\Bulk;

/**
 * Per-job validation state shared by the bulk modules (Phase A).
 * Built once per job by BulkImportService and threaded through every
 * row validation: the name→id lookups, the actor (for the staff level
 * check), and the duplicate rails of audit §6.2 — emails and row
 * fingerprints already claimed inside this file, plus the fingerprints
 * of rows APPLIED by earlier jobs (a re-uploaded file must not create
 * the same person twice).
 */
class BulkContext
{
    /** @var \App\User */
    public $actor;

    /** @var array toolkit buildLookups() output */
    public $lookups = [];

    /** @var array<string,bool> lower-cased emails claimed in this job */
    public $seenEmails = [];

    /** @var array<string,int> update mode: claimed email → owning user id */
    public $seenEmailsForUser = [];

    /** @var array<string,bool> fingerprints of valid rows seen in this job */
    public $seenFingerprints = [];

    /** @var array<string,bool> fingerprints applied by earlier jobs */
    public $priorAppliedFingerprints = [];

    /** @var array<string,\App\Models\UserType> staff: normalised title/name → type */
    public $typeMap = [];

    /** @var \App\Models\UserType|null the actor's own type (level check) */
    public $actorType;

    public function claimEmail(string $email): void
    {
        $this->seenEmails[strtolower($email)] = true;
    }

    public function emailClaimed(string $email): bool
    {
        return isset($this->seenEmails[strtolower($email)]);
    }
}
