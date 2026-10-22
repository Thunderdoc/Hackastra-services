<?php

namespace App\Support\Bulk;

use App\Helpers\Qs;
use App\Models\StaffRecord;
use App\Repositories\UserRepo;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff ADD on the bulk engine (Phase A; staff bulk EDIT is Phase B).
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
        return self::REQUIRED_HEADER;
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
}
