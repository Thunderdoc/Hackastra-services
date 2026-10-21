<?php

namespace App\Http\Controllers;


use App\Helpers\Money;
use App\Helpers\Qs;
use App\Http\Requests\UserChangePass;
use App\Http\Requests\UserUpdate;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserPreference;
use App\Repositories\UserRepo;
use App\Support\Audit;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class MyAccountController extends Controller
{
    protected $user;

    public function __construct(UserRepo $user)
    {
        $this->user = $user;
    }

    public function edit_profile()
    {
        $me = Auth::user();

        $d['my'] = $me;
        $d['current_session'] = Qs::getCurrentSession();
        $d['prefs'] = UserPreference::allFor($me->id);
        $d['banner_stats'] = $this->bannerStats($me);
        $d['banner_chips'] = $this->bannerChips($me);
        $d['activity'] = Schema::hasTable('audit_logs')
            ? AuditLog::where('user_id', $me->id)
                ->where('action', 'auth.login')
                ->latest()
                ->take(8)
                ->get()
            : collect();

        return view('pages.support_team.my_account', $d);
    }

    public function update_profile(UserUpdate $req)
    {
        $user = Auth::user();

        $d = $user->username ? $req->only(['email', 'phone', 'address']) : $req->only(['email', 'phone', 'address', 'username']);

        if(!$user->username && !$req->username && !$req->email){
            return back()->with('pop_error', __('msg.user_invalid'));
        }

        $user_type = $user->user_type;
        $code = $user->code;

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath($user_type).$code, $f['name']);
            $d['photo'] = asset('storage/' . $f['path']);
        }

        $this->user->update($user->id, $d);
        return back()->with('flash_success', __('msg.update_ok'));
    }

    public function change_pass(UserChangePass $req)
    {
        $user_id = Auth::user()->id;
        $my_pass = Auth::user()->password;
        $old_pass = $req->current_password;
        $new_pass = $req->password;

        if(password_verify($old_pass, $my_pass)){
            $data['password'] = Hash::make($new_pass);
            $this->user->update($user_id, $data);
            return back()->with('flash_success', __('msg.p_reset'));
        }

        return back()->with('flash_danger', __('msg.p_reset_fail'));
    }

    /**
     * Batch 10: persist the My Account notification/display preferences.
     * Own account only (Auth user), whitelisted keys only. Each settings
     * form on the page posts just its own keys plus a `tab` to return to.
     */
    public function update_preferences(Request $req)
    {
        $user = Auth::user();

        $req->validate([
            'date_format' => 'sometimes|in:d M Y,d/m/Y,M d, Y,Y-m-d',
            'time_format' => 'sometimes|in:12,24',
        ]);

        foreach (['notify_email', 'notify_announcements', 'notify_attendance', 'notify_exams'] as $key) {
            if ($req->has($key) || $req->has($key . '_present')) {
                UserPreference::set($user->id, $key, $req->boolean($key) ? '1' : '0');
            }
        }

        foreach (['date_format', 'time_format'] as $key) {
            if ($req->filled($key)) {
                UserPreference::set($user->id, $key, $req->input($key));
            }
        }

        Audit::log('account.preferences_updated', $user, 'Account preferences updated');

        $tab = in_array($req->input('tab'), ['notifications', 'preferences'], true) ? $req->input('tab') : 'notifications';

        return redirect(route('my_account') . '#' . $tab)
            ->with('flash_success', 'Preferences saved.');
    }

    /**
     * Loop 50 (Workstream BX): the topbar dark-mode toggle. Persists the
     * signed-in user's theme in the SAME per-user preference store as
     * every other account preference (UserPreference key 'theme'), via
     * AJAX so the page never reloads. No audit row: a display toggle is
     * high-frequency and carries no account-data change.
     */
    public function update_theme(Request $req)
    {
        $req->validate(['theme' => 'required|in:dark,light']);

        UserPreference::set(Auth::id(), 'theme', $req->input('theme'));

        return response()->json(['theme' => $req->input('theme')]);
    }

    /**
     * Batch 10: "Download My Data" — a JSON export of the signed-in
     * user's OWN account record, preferences and recent sign-ins.
     * Nothing about any other user is ever included.
     */
    public function download_data()
    {
        $user = Auth::user();

        $signIns = Schema::hasTable('audit_logs')
            ? AuditLog::where('user_id', $user->id)
                ->where('action', 'auth.login')
                ->latest()
                ->take(25)
                ->get(['created_at', 'ip_address', 'description'])
                ->map(function ($row) {
                    return [
                        'signed_in_at' => optional($row->created_at)->toDateTimeString(),
                        'ip_address' => $row->ip_address,
                        'event' => $row->description,
                    ];
                })
                ->all()
            : [];

        $data = [
            'exported_at' => now()->toDateTimeString(),
            'school' => Qs::getSystemName(),
            'session' => Qs::getCurrentSession(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'user_type' => $user->user_type,
                'code' => $user->code,
                'phone' => $user->phone,
                'phone2' => $user->phone2,
                'gender' => $user->gender,
                'date_of_birth' => $user->dob,
                'address' => $user->address,
                'photo' => $user->photo,
                'member_since' => optional($user->created_at)->toDateTimeString(),
            ],
            'preferences' => UserPreference::allFor($user->id),
            'recent_sign_ins' => $signIns,
        ];

        Audit::log('account.data_downloaded', $user, 'User downloaded their account data');

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, 'my-data-' . $user->code . '.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Role-relevant summary numbers for the profile banner. Every value
     * comes from the same computations the role dashboards use; roles
     * without a meaningful metric simply get fewer (or no) stats.
     */
    protected function bannerStats(User $me): array
    {
        switch ($me->user_type) {
            case 'teacher':
                $subjects = Subject::where('teacher_id', $me->id)->get();
                $classIds = $subjects->pluck('my_class_id')->filter()->unique()->values();
                $students = $classIds->isEmpty()
                    ? 0
                    : StudentRecord::where('grad', 0)->whereIn('my_class_id', $classIds)->count();

                return [
                    ['icon' => 'icon-book', 'label' => 'Subjects', 'value' => $subjects->count()],
                    ['icon' => 'icon-users', 'label' => 'Classes', 'value' => $classIds->count()],
                    ['icon' => 'icon-user-check', 'label' => 'Students', 'value' => $students],
                ];

            case 'student':
                $sr = $me->student_record;
                if (!$sr) {
                    return [];
                }

                return [
                    ['icon' => 'icon-book', 'label' => 'Class', 'value' => optional($sr->my_class)->name ?: '—'],
                    ['icon' => 'icon-users', 'label' => 'Section', 'value' => optional($sr->section)->name ?: '—'],
                    ['icon' => 'icon-key', 'label' => 'Admission No', 'value' => $sr->adm_no ?: '—'],
                ];

            case 'parent':
                return [
                    ['icon' => 'icon-users', 'label' => 'Children', 'value' => Qs::findMyChildren($me->id)->count()],
                ];

            case 'super_admin':
                return [
                    ['icon' => 'icon-users', 'label' => 'Users', 'value' => User::count()],
                    ['icon' => 'icon-user-check', 'label' => 'Students', 'value' => StudentRecord::count()],
                    ['icon' => 'icon-book', 'label' => 'Teachers', 'value' => User::where('user_type', 'teacher')->count()],
                ];

            case 'admin':
                return [
                    ['icon' => 'icon-user-check', 'label' => 'Students', 'value' => StudentRecord::count()],
                    ['icon' => 'icon-book', 'label' => 'Teachers', 'value' => User::where('user_type', 'teacher')->count()],
                    ['icon' => 'icon-home5', 'label' => 'Classes', 'value' => MyClass::count()],
                ];

            case 'accountant':
                return [
                    ['icon' => 'icon-coins', 'label' => 'Payments Recorded', 'value' => PaymentRecord::count()],
                    ['icon' => 'icon-coins', 'label' => 'Fees Collected', 'value' => Money::inr(PaymentRecord::sum('amt_paid'))],
                ];

            case 'librarian':
                return [
                    ['icon' => 'icon-book', 'label' => 'Books', 'value' => Book::count()],
                    ['icon' => 'icon-users', 'label' => 'Loans Out', 'value' => BookRequest::where('returned', 0)->count()],
                ];
        }

        return [];
    }

    /** Small descriptive chips beside the role badge (real data only). */
    protected function bannerChips(User $me): array
    {
        $chips = [];

        if ($me->user_type === 'teacher') {
            $subjectNames = Subject::where('teacher_id', $me->id)->pluck('name')->unique()->take(3)->all();
            foreach ($subjectNames as $name) {
                $chips[] = $name;
            }
        }

        if ($me->user_type === 'parent') {
            foreach (Qs::findMyChildren($me->id) as $child) {
                $class = optional($child->my_class)->name;
                $chips[] = optional($child->user)->name . ($class ? ' · ' . $class : '');
            }
        }

        if ($me->user_type === 'student' && $me->student_record) {
            $chips[] = 'Session ' . $me->student_record->session;
        }

        return $chips;
    }
}
