<?php

namespace App\Support\Bulk;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\StaffRecord;
use App\Models\Subject;
use App\Repositories\UserRepo;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff ADD on the bulk engine (Phase A) + staff bulk EDIT
 * (Phase B, Wave 11).
 *
 * Mirrors UserController@store field-for-field — same user payload,
 * same generated staff id (APPCODE/STAFF/YYYY/MM/#### from the joining
 * date, stored as both username and staff_records.code), same default
 * password convention (the role string) — with two deliberate
 * hardenings the audit calls for:
 *  - the Workstream CM level check runs PER ROW at validate time, so
 *    a row asking for a role above the actor's level is `invalid` in
 *    the preview, never partially applied (§4.2);
 *  - email is the identity key: the generated staff code is NOT
 *    stable (UserController@update regenerates it), so it is only
 *    ever an output here — never imported, never matched on (§1.2).
 * Passwords are never taken from the spreadsheet.
 *
 * EDIT rules (Wave 11): rows match on email, which is the key and
 * can never be changed by bulk edit; only filled cells change (a
 * blank cell never overwrites); role, code, password and status are
 * never editable in bulk — role changes stay in the single User
 * form with its per-target guards. Only teacher / accountant /
 * librarian accounts are editable in bulk; admin accounts use the
 * single User form. Old DB values are recorded at validation time
 * (Wave 9 pattern) so rollback can restore them.
 */
class StaffBulkModule
{
    public const COLUMNS = [
        'name', 'user_type', 'email', 'gender', 'phone', 'phone2', 'dob',
        'address', 'nationality', 'state', 'lga', 'blood_group', 'emp_date',
    ];

    public const REQUIRED_HEADER = ['name', 'user_type', 'gender', 'address', 'nationality', 'state', 'lga'];

    protected const IDENTITY_MESSAGES = [
        'Email already exists in the system.',
        'Email is duplicated within this file.',
    ];

    protected $userRepo;

    public function __construct(UserRepo $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    public function requiredHeader(string $mode): array
    {
        // Edit matches on email alone — the key column.
        return $mode === 'update' ? ['email'] : self::REQUIRED_HEADER;
    }

    public function validateAdd(array $raw, BulkContext $ctx): array
    {
        $get = function ($key) use ($raw) {
            return isset($raw[$key]) ? trim((string) $raw[$key]) : '';
        };
        $errors = [];
        $err = function ($field, $value, $message) use (&$errors) {
            $errors[] = ['field' => $field, 'value' => $value, 'message' => $message];
        };

        // name — required|string|min:6|max:150 (UserRequest store)
        $name = $get('name');
        if ($name === '') {
            $err('name', $name, 'Name is required.');
        } elseif (Str::length($name) < 6 || Str::length($name) > 150) {
            $err('name', $name, 'Name must be between 6 and 150 characters.');
        }

        // user_type — required, must be a staff role the actor may
        // create (per-row CM level check, audit §4.2).
        $type = null;
        $typeRaw = $get('user_type');
        if ($typeRaw === '') {
            $err('user_type', $typeRaw, 'Role is required.');
        } else {
            $key = strtolower(str_replace([' ', '-'], '_', $typeRaw));
            $type = $ctx->typeMap[$key] ?? null;
            if (!$type) {
                $err('user_type', $typeRaw, "Role '{$typeRaw}' was not recognised.");
            } elseif (!in_array($type->title, Qs::getStaff(), true)) {
                $err('user_type', $typeRaw, "Role '{$typeRaw}' is not a staff role — use Bulk Add Students for students.");
                $type = null;
            } elseif ($ctx->actorType && (int) $type->level < (int) $ctx->actorType->level) {
                $err('user_type', $typeRaw, "You cannot create a {$type->name} account — that role is above your level.");
                $type = null;
            }
        }

        // gender — required, Male / Female
        $gender = null;
        if ($get('gender') === '') {
            $err('gender', '', 'Gender is required.');
        } elseif (in_array(strtolower($get('gender')), ['male', 'm'], true)) {
            $gender = 'Male';
        } elseif (in_array(strtolower($get('gender')), ['female', 'f'], true)) {
            $gender = 'Female';
        } else {
            $err('gender', $get('gender'), 'Gender must be Male or Female.');
        }

        // address — required|string|min:6|max:120
        $address = $get('address');
        if ($address === '') {
            $err('address', $address, 'Address is required.');
        } elseif (Str::length($address) < 6 || Str::length($address) > 120) {
            $err('address', $address, 'Address must be between 6 and 120 characters.');
        }

        // email — optional, unique (identity key for staff)
        $email = $get('email');
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || Str::length($email) > 100) {
                $err('email', $email, 'Email is not a valid email address.');
            } elseif ($ctx->emailClaimed($email)) {
                $err('email', $email, 'Email is duplicated within this file.');
            } elseif (User::where('email', $email)->exists()) {
                $err('email', $email, 'Email already exists in the system.');
            } else {
                $ctx->claimEmail($email);
            }
        }

        foreach (['phone', 'phone2'] as $col) {
            $v = $get($col);
            if ($v !== '' && (Str::length($v) < 6 || Str::length($v) > 20)) {
                $err($col, $v, ucfirst($col) . ' must be between 6 and 20 characters.');
            }
        }

        $dob = null;
        if ($get('dob') !== '') {
            $ts = strtotime($get('dob'));
            if ($ts === false) {
                $err('dob', $get('dob'), 'Date of Birth is not a recognisable date (use YYYY-MM-DD).');
            } else {
                $dob = date('Y-m-d', $ts);
            }
        }

        $empDate = null;
        if ($get('emp_date') !== '') {
            $ts = strtotime($get('emp_date'));
            if ($ts === false) {
                $err('emp_date', $get('emp_date'), 'Joining Date is not a recognisable date (use YYYY-MM-DD).');
            } else {
                $empDate = date('Y-m-d', $ts);
            }
        }

        // nationality / state / lga — required, by name (same lookups
        // and messages as the student validator).
        $nal = $ctx->lookups['nationals'][strtolower($get('nationality'))] ?? null;
        if ($get('nationality') === '') {
            $err('nationality', '', 'Nationality is required.');
        } elseif (!$nal) {
            $err('nationality', $get('nationality'), "Nationality '{$get('nationality')}' was not found.");
        }

        $state = $ctx->lookups['states'][strtolower($get('state'))] ?? null;
        if ($get('state') === '') {
            $err('state', '', 'State is required.');
        } elseif (!$state) {
            $err('state', $get('state'), "State '{$get('state')}' was not found.");
        }

        $lga = null;
        if ($get('lga') === '') {
            $err('lga', '', 'LGA is required.');
        } elseif ($state) {
            $lga = $ctx->lookups['lgas'][$state->id][strtolower($get('lga'))] ?? null;
            if (!$lga) {
                $err('lga', $get('lga'), "LGA '{$get('lga')}' was not found in {$state->name}.");
            }
        }

        $bg = null;
        if ($get('blood_group') !== '') {
            $bg = $ctx->lookups['blood_groups'][strtolower($get('blood_group'))] ?? null;
            if (!$bg) {
                $err('blood_group', $get('blood_group'), "Blood Group '{$get('blood_group')}' was not found.");
            }
        }

        $fingerprint = $this->fingerprint($raw, $email, $empDate);

        $identityOnly = $errors && collect($errors)->every(function ($e) {
            return in_array($e['message'], self::IDENTITY_MESSAGES, true);
        });
        $isDuplicate = isset($ctx->priorAppliedFingerprints[$fingerprint])
            || isset($ctx->seenFingerprints[$fingerprint]);

        if ($errors && !$identityOnly) {
            $verdict = 'invalid';
        } elseif ($isDuplicate || $identityOnly) {
            $verdict = 'duplicate';
            if (!$errors) {
                $errors = [[
                    'field' => 'email', 'value' => $email,
                    'message' => 'This staff account was already created by an earlier import — row skipped.',
                ]];
            }
        } else {
            $verdict = 'valid_create';
            $ctx->seenFingerprints[$fingerprint] = true;
        }

        $resolved = [
            'name' => ucwords($name),
            'user_type' => $type ? $type->title : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $get('phone') !== '' ? $get('phone') : null,
            'phone2' => $get('phone2') !== '' ? $get('phone2') : null,
            'dob' => $dob,
            'gender' => $gender,
            'address' => $address,
            'bg_id' => $bg ? $bg->id : null,
            'nal_id' => $nal ? $nal->id : null,
            'state_id' => $state ? $state->id : null,
            'lga_id' => $lga ? $lga->id : null,
            'emp_date' => $empDate,
        ];

        return [
            'verdict' => $verdict,
            'resolved' => $resolved,
            'errors' => $errors ?: null,
            'changes' => null,
            'fingerprint' => $fingerprint,
        ];
    }

    public function applyAdd(array $resolved): int
    {
        $userType = $resolved['user_type'];

        // Generated staff id — APPCODE/STAFF/YYYY/MM/#### from the
        // joining date, exactly as UserController@store builds it.
        // Retried on the (rare) random-suffix collision; username and
        // staff_records.code are both unique.
        $empForCode = $resolved['emp_date'] ?: date('Y-m-d');
        $staffId = null;
        for ($i = 0; $i < 10; $i++) {
            $candidate = Qs::getAppCode() . '/STAFF/' . date('Y/m', strtotime($empForCode)) . '/' . mt_rand(1000, 9999);
            if (!User::where('username', $candidate)->exists()
                && !StaffRecord::where('code', $candidate)->exists()) {
                $staffId = $candidate;
                break;
            }
        }
        if ($staffId === null) {
            throw new \RuntimeException('Could not generate a unique staff code — please retry this row.');
        }

        $data = [
            'name' => $resolved['name'],
            'email' => $resolved['email'],
            'phone' => $resolved['phone'],
            'phone2' => $resolved['phone2'],
            'dob' => $resolved['dob'],
            'gender' => $resolved['gender'],
            'address' => $resolved['address'],
            'bg_id' => $resolved['bg_id'],
            'nal_id' => $resolved['nal_id'],
            'state_id' => $resolved['state_id'],
            'lga_id' => $resolved['lga_id'],
            'user_type' => $userType,
            'username' => $staffId,
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make($userType),
            'photo' => Qs::getDefaultUserImage(),
            'email_verified_at' => null,
        ];

        $userId = 0;
        DB::transaction(function () use ($data, $resolved, $staffId, &$userId) {
            $user = $this->userRepo->create($data);
            $userId = (int) $user->id;
            $this->userRepo->createStaffRecord([
                'user_id' => $userId,
                'emp_date' => $resolved['emp_date'],
                'code' => $staffId,
            ]);
        });

        return $userId;
    }

    /** Staff identity (audit §6.2): email when present, else name + joining date. */
    protected function fingerprint(array $raw, string $email, ?string $empDate): string
    {
        if ($email !== '') {
            return hash('sha256', 'staff|email|' . strtolower($email));
        }
        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($raw['name'] ?? ''))));
        return hash('sha256', 'staff|bio|' . $name . '|' . ($empDate ?: ''));
    }

    /**************** UPDATE MODE (Wave 11) ****************/

    public function validateUpdate(array $raw, BulkContext $ctx): array
    {
        $get = function ($key) use ($raw) {
            return isset($raw[$key]) ? trim((string) $raw[$key]) : '';
        };

        $email = $get('email');
        $fingerprint = hash('sha256', 'staff|upd|' . strtolower($email));
        $base = ['resolved' => null, 'changes' => null, 'fingerprint' => $fingerprint];

        if ($email === '') {
            return $base + ['verdict' => 'invalid', 'errors' => [[
                'field' => 'email', 'value' => '',
                'message' => 'Email is required for bulk edit — it is the key each row is matched on.',
            ]]];
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return $base + ['verdict' => 'unmatched', 'errors' => [[
                'field' => 'email', 'value' => $email,
                'message' => 'No staff account matches this email — row skipped (bulk edit never creates accounts).',
            ]]];
        }
        if (!in_array($user->user_type, ['teacher', 'accountant', 'librarian'], true)) {
            return $base + ['verdict' => 'invalid', 'errors' => [[
                'field' => 'email', 'value' => $email,
                'message' => 'Only teacher, accountant and librarian accounts can be edited in bulk — admin accounts use the single User form.',
            ]]];
        }

        if (isset($ctx->seenFingerprints[$fingerprint])) {
            return $base + ['verdict' => 'duplicate', 'errors' => [[
                'field' => 'email', 'value' => $email,
                'message' => 'This staff account appears more than once in the file — only the first row is used.',
            ]]];
        }

        $staffRecord = StaffRecord::where('user_id', $user->id)->first();
        $errors = [];
        $userChanges = [];
        $recChanges = [];
        $changes = [];
        $oldUser = [];
        $oldRec = [];

        $err = function ($field, $value, $message) use (&$errors) {
            $errors[] = ['field' => $field, 'value' => $value, 'message' => $message];
        };
        // Same shape as the student validator's $diff, plus the old
        // DB value (Wave 9 pattern) so rollback can restore exactly.
        $diff = function ($column, $oldDisplay, $newDisplay, $bucket, $dbField, $newValue) use (&$changes, &$userChanges, &$recChanges, &$oldUser, &$oldRec, $user, $staffRecord) {
            $changes[$column] = ['old' => $oldDisplay, 'new' => $newDisplay];
            if ($bucket === 'user') {
                $userChanges[$dbField] = $newValue;
                $oldUser[$dbField] = $user->$dbField;
            } else {
                $recChanges[$dbField] = $newValue;
                $oldRec[$dbField] = $staffRecord ? $staffRecord->$dbField : null;
            }
        };

        if ($get('name') !== '') {
            $name = $get('name');
            if (Str::length($name) < 6 || Str::length($name) > 150) {
                $err('name', $name, 'Name must be between 6 and 150 characters.');
            } elseif (strtolower(ucwords($name)) !== strtolower((string) $user->name)) {
                $diff('name', (string) $user->name, ucwords($name), 'user', 'name', ucwords($name));
            }
        }

        if ($get('gender') !== '') {
            $g = strtolower($get('gender'));
            if (in_array($g, ['male', 'm'], true)) {
                $gender = 'Male';
            } elseif (in_array($g, ['female', 'f'], true)) {
                $gender = 'Female';
            } else {
                $err('gender', $get('gender'), 'Gender must be Male or Female.');
                $gender = null;
            }
            if ($gender && $gender !== $user->gender) {
                $diff('gender', (string) $user->gender, $gender, 'user', 'gender', $gender);
            }
        }

        foreach (['phone', 'phone2'] as $col) {
            $v = $get($col);
            if ($v !== '') {
                if (Str::length($v) < 6 || Str::length($v) > 20) {
                    $err($col, $v, ucfirst($col) . ' must be between 6 and 20 characters.');
                } elseif ($v !== (string) $user->$col) {
                    $diff($col, (string) $user->$col, $v, 'user', $col, $v);
                }
            }
        }

        if ($get('dob') !== '') {
            $ts = strtotime($get('dob'));
            if ($ts === false) {
                $err('dob', $get('dob'), 'Date of Birth is not a recognisable date (use YYYY-MM-DD).');
            } else {
                $dob = date('Y-m-d', $ts);
                if ($dob !== (string) $user->dob) {
                    $diff('dob', (string) $user->dob, $dob, 'user', 'dob', $dob);
                }
            }
        }

        if ($get('address') !== '') {
            $address = $get('address');
            if (Str::length($address) < 6 || Str::length($address) > 120) {
                $err('address', $address, 'Address must be between 6 and 120 characters.');
            } elseif ($address !== (string) $user->address) {
                $diff('address', (string) $user->address, $address, 'user', 'address', $address);
            }
        }

        if ($get('nationality') !== '') {
            $nal = $ctx->lookups['nationals'][strtolower($get('nationality'))] ?? null;
            if (!$nal) {
                $err('nationality', $get('nationality'), "Nationality '{$get('nationality')}' was not found.");
            } elseif ((int) $nal->id !== (int) $user->nal_id) {
                $old = $user->nal_id ? optional(\App\Models\Nationality::find($user->nal_id))->name : '';
                $diff('nationality', (string) $old, $nal->name, 'user', 'nal_id', $nal->id);
            }
        }

        if ($get('state') !== '') {
            $state = $ctx->lookups['states'][strtolower($get('state'))] ?? null;
            if (!$state) {
                $err('state', $get('state'), "State '{$get('state')}' was not found.");
            } elseif ((int) $state->id !== (int) $user->state_id) {
                $old = $user->state_id ? optional(\App\Models\State::find($user->state_id))->name : '';
                $diff('state', (string) $old, $state->name, 'user', 'state_id', $state->id);
            }
        }

        if ($get('lga') !== '') {
            $stateId = $user->state_id;
            // A row changing state + lga together resolves the lga
            // against the NEW state, not the stored one.
            if (isset($userChanges['state_id'])) {
                $stateId = $userChanges['state_id'];
            }
            $lga = $ctx->lookups['lgas'][$stateId][strtolower($get('lga'))] ?? null;
            if (!$lga) {
                $err('lga', $get('lga'), "LGA '{$get('lga')}' was not found.");
            } elseif ((int) $lga->id !== (int) $user->lga_id) {
                $old = $user->lga_id ? optional(\App\Models\Lga::find($user->lga_id))->name : '';
                $diff('lga', (string) $old, $lga->name, 'user', 'lga_id', $lga->id);
            }
        }

        if ($get('blood_group') !== '') {
            $bg = $ctx->lookups['blood_groups'][strtolower($get('blood_group'))] ?? null;
            if (!$bg) {
                $err('blood_group', $get('blood_group'), "Blood Group '{$get('blood_group')}' was not found.");
            } elseif ((int) $bg->id !== (int) $user->bg_id) {
                $old = $user->bg_id ? optional(\App\Models\BloodGroup::find($user->bg_id))->name : '';
                $diff('blood_group', (string) $old, $bg->name, 'user', 'bg_id', $bg->id);
            }
        }

        if ($get('emp_date') !== '') {
            $ts = strtotime($get('emp_date'));
            if ($ts === false) {
                $err('emp_date', $get('emp_date'), 'Joining Date is not a recognisable date (use YYYY-MM-DD).');
            } else {
                $empDate = date('Y-m-d', $ts);
                $oldEmp = $staffRecord ? (string) $staffRecord->emp_date : '';
                if ($empDate !== $oldEmp) {
                    $diff('emp_date', $oldEmp, $empDate, 'record', 'emp_date', $empDate);
                }
            }
        }

        // user_type, code, password, status: never editable in bulk —
        // a filled user_type cell is silently ignored (the upload
        // page says so); it must never appear as a change.
        if ($errors) {
            return $base + ['verdict' => 'invalid', 'errors' => $errors];
        }

        $ctx->seenFingerprints[$fingerprint] = true;

        return [
            'verdict' => 'valid_update',
            'resolved' => [
                'user_id' => (int) $user->id,
                'staff_record_id' => $staffRecord ? (int) $staffRecord->id : null,
                'user' => $userChanges,
                'record' => $recChanges,
                'old_user' => $oldUser,
                'old_record' => $oldRec,
            ],
            'errors' => null,
            'changes' => $changes,
            'fingerprint' => $fingerprint,
        ];
    }

    public function applyUpdate(array $resolved): int
    {
        $userId = (int) $resolved['user_id'];
        DB::transaction(function () use ($resolved, $userId) {
            if (!empty($resolved['user'])) {
                $this->userRepo->update($userId, $resolved['user']);
            }
            if (!empty($resolved['record'])) {
                $this->userRepo->updateStaffRecord(['user_id' => $userId], $resolved['record']);
            }
        });
        return $userId;
    }

    /**
     * Undo one applied UPDATE row: restore the old DB values
     * recorded at validation time. Returns false always — staff
     * edits have no destructive side effect to warn about (unlike
     * student class changes, which delete marks).
     */
    public function rollbackUpdate(array $resolved): bool
    {
        $userId = (int) ($resolved['user_id'] ?? 0);
        $user = $userId ? User::find($userId) : null;
        if (!$user) {
            throw new \RuntimeException('Staff account no longer exists.');
        }
        $oldUser = $resolved['old_user'] ?? [];
        $oldRec = $resolved['old_record'] ?? [];
        if (empty($oldUser) && empty($oldRec)) {
            throw new \RuntimeException('This job was validated before old values were recorded — its edits cannot be restored.');
        }
        DB::transaction(function () use ($userId, $oldUser, $oldRec) {
            if (!empty($oldUser)) {
                $this->userRepo->update($userId, $oldUser);
            }
            if (!empty($oldRec)) {
                $this->userRepo->updateStaffRecord(['user_id' => $userId], $oldRec);
            }
        });
        return false;
    }

    /**************** ROLLBACK (Wave 9) ****************/

    /**
     * Undo one applied ADD row: delete the staff account the job
     * created. Refuses when the account has since been given
     * teaching responsibility — deleting it would orphan subjects
     * and class-teacher links.
     */
    public function rollbackAdd(int $userId): void
    {
        $user = User::find($userId);

        if ($user) {
            if (Subject::where('teacher_id', $userId)->exists()) {
                throw new \RuntimeException('Staff member is assigned as a subject teacher — rollback would orphan subjects.');
            }
            if (Section::where('teacher_id', $userId)->exists()) {
                throw new \RuntimeException('Staff member is a class teacher — rollback would orphan sections.');
            }
        }

        DB::transaction(function () use ($userId, $user) {
            StaffRecord::where('user_id', $userId)->delete();
            if ($user) {
                \App\Models\UserNotification::where('user_id', $userId)->delete();
                $user->delete();
            }
        });
    }
}
