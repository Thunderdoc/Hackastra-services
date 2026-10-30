<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\UserRequest;
use App\Repositories\LocationRepo;
use App\Support\Audit;
use App\User;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class UserController extends Controller
{
    protected $user, $loc, $my_class;

    public function __construct(UserRepo $user, LocationRepo $loc, MyClassRepo $my_class)
    {
        // Workstream CM: user management stays reachable by teamSA,
        // but WHO may write WHOM is split by account level/target in
        // the methods below (mayWriteUserTarget + the store() level
        // check) — the constructor gate alone cannot express it.
        // ('create' joins the gated list: it previously rendered the
        // account form to any authenticated user who guessed the URL.)
        // Limited principal role (owner order 2026-10-28): the
        // staff LIST is readable by the principal (monitoring);
        // every write stays teamSA — the index buttons are
        // userIsAdmin/userIsSuperAdmin-gated in the blade, so a
        // principal viewer sees no action buttons by construction.
        $this->middleware('teamSA', ['only' => ['create', 'store', 'edit', 'update'] ]);
        $this->middleware('teamOrPrincipal:SA', ['only' => ['index'] ]);
        $this->middleware('teamSA', ['only' => ['reset_pass','destroy','suspend','activate','terminate'] ]);

        $this->user = $user;
        $this->loc = $loc;
        $this->my_class = $my_class;
    }

    public function index()
    {
        $ut = $this->user->getAllTypes();
        // Workstream CM: the school Admin manages every school account
        // incl. peer Admins (level >= 2); only super_admin (level 1)
        // sits outside the school. store() enforces the same line
        // server-side — the dropdown is no longer the only check.
        $ut2 = $ut->where('level', '>=', 2);

        $d['user_types'] = Qs::userIsAdmin() ? $ut2 : $ut;
        /* Loop 106 (owner ruling, flagged live 2026-10-23): the Super
           Admin is the PLATFORM owner — "numbers, not records". Their
           Manage Users list carries platform accounts only (Super
           Admins + School Admins); the school's people (teachers,
           parents, accountants, librarians) are the school Admin's
           domain and never appear in the SA list, its counts or its
           role filter. Scoped by the VIEWER's role only — the school
           Admin's own list below is untouched. */
        if (Qs::userIsSuperAdmin()) {
            $d['user_types'] = $ut->whereIn('title', ['super_admin', 'admin'])->values();
        }
        $d['states'] = $this->loc->getStates();
        $d['users'] = $this->user->getPTAUsers();
        if (Qs::userIsSuperAdmin()) {
            $d['users'] = $d['users']->whereIn('user_type', ['super_admin', 'admin'])->values();
        }
        /* Owner direction 2026-10-24: the school Admin's Users list
           is the STAFF list — admins, teachers, accountants,
           librarians. Students never appear here (getPTAUsers already
           excludes them) and parents are now excluded too; both live
           in their own surfaces (Students list, parent portal).
           Scoped server-side like the SA rule above, so a tampered
           ?user_type=parent cannot reveal them — the role filter only
           offers roles the viewer may see.
           Limited principal role (owner order 2026-10-28): the
           principal reads the same staff list as the Admin — and
           never platform accounts: without this branch a principal
           viewer would get the unfiltered list (super_admin rows
           included), since neither the SA nor the Admin branch
           applies to the new type. */
        if (Qs::userIsAdmin()) {
            $d['user_types'] = $ut2->whereNotIn('title', ['student', 'parent'])->values();
            $d['users'] = $d['users']->whereNotIn('user_type', ['student', 'parent', 'super_admin'])->values();
        }
        if (Qs::userIsPrincipal()) {
            $d['user_types'] = $ut2->whereNotIn('title', ['student', 'parent'])->values();
            $d['users'] = $d['users']->whereNotIn('user_type', ['student', 'parent', 'super_admin'])->values();
        }
        $d['nationals'] = $this->loc->getAllNationals();
        $d['blood_groups'] = $this->user->getBloodGroups();

        /* Batch 5A: server-side role filter for the rebuilt index —
           ?user_type=<title> narrows the list to that role; anything
           else (absent/unknown) shows every non-student user. */
        $selected = request('user_type');
        $d['selected_user_type'] = $d['user_types']->contains('title', $selected) ? $selected : null;
        if ($d['selected_user_type']) {
            $d['users'] = $d['users']->where('user_type', $d['selected_user_type']);
        }

        /* Account lifecycle filter (Workstream DF): ?status= narrows
           the list to one lifecycle state; the states themselves are
           managed by suspend()/activate()/terminate() below. */
        $status = request('status');
        $d['selected_status'] = in_array($status, ['active', 'suspended', 'terminated'], true) ? $status : null;
        if ($d['selected_status']) {
            $d['users'] = $d['users']->filter(function ($u) {
                return ($u->getAttribute('status') ?: 'active') === request('status');
            });
        }

        return view('pages.support_team.users.index', $d);
    }

    public function edit($id)
    {
        $id = Qs::decodeHash($id);
        $d['user'] = $this->user->find($id);

        /* Audit R-12 (2026-10-23): a raw or otherwise undecodable id
           resolves to no user — answer 404 instead of letting the
           view dereference null (was a 500 Ignition page). */
        if (!$d['user']) {
            abort(404);
        }

        if (!$this->mayWriteUserTarget($d['user']->user_type)) {
            return back()->with('flash_danger', __('msg.denied'));
        }
        $d['states'] = $this->loc->getStates();
        $d['users'] = $this->user->getPTAUsers();
        $d['blood_groups'] = $this->user->getBloodGroups();
        $d['nationals'] = $this->loc->getAllNationals();
        return view('pages.support_team.users.edit', $d);
    }

    public function reset_pass($id)
    {
        // Redirect if Making Changes to Head of Super Admins
        if(Qs::headSA($id)){
            return back()->with('flash_danger', __('msg.denied'));
        }

        $target = $this->user->find($id);
        /* Audit R-12 companion guard: an unresolvable id is refused,
           never dereferenced. */
        if (!$target) {
            return back()->with('flash_danger', __('msg.denied'));
        }
        if (!$this->mayWriteUserTarget($target->user_type)) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        $data['password'] = Hash::make('user');
        $this->user->update($id, $data);
        return back()->with('flash_success', __('msg.pu_reset'));
    }

    /**
     * Cross-boundary account protection (Workstream CM). School
     * accounts and platform accounts are different domains:
     *   - the Super Admin writes platform accounts (super_admin) and
     *     the school's Admin accounts (provisioned at onboarding) —
     *     never school staff accounts, which are school records;
     *   - the school Admin writes every school account (admin and
     *     below) — never a super_admin account.
     * The head-SA rule (Qs::headSA) still applies on top of this.
     */
    protected function mayWriteUserTarget($targetType): bool
    {
        if (Qs::userIsSuperAdmin()) {
            return in_array($targetType, ['super_admin', 'admin'], true);
        }

        if (Qs::userIsAdmin()) {
            return $targetType !== 'super_admin';
        }

        return false;
    }

    public function store(UserRequest $req)
    {
        /* Server-side level check (Workstream CM): the create form's
           dropdown filter used to be the ONLY enforcement, so a
           crafted POST could create an above-level account (e.g. an
           Admin creating a super_admin). Nobody creates an account
           type above their own level. */
        $type = $this->user->findType($req->user_type);
        $actorType = $this->user->getAllTypes()->firstWhere('title', Auth::user()->user_type);
        if (!$type || !$actorType || (int) $type->level < (int) $actorType->level) {
            return Qs::json(__('msg.denied'), FALSE);
        }
        /* Owner ruling 2026-10-25: the Super Admin provisions platform
           accounts only (Super Admin + School Admin). The level gate
           above does NOT stop an SA from creating a librarian (level 6
           is not below the SA's level), so this gate mirrors the
           create form's SA options — a crafted POST cannot bypass it.
           School staff, including the librarian, is the school
           Admin's to provision. */
        if (Qs::userIsSuperAdmin() && !in_array($type->title, ['super_admin', 'admin'], true)) {
            return Qs::json(__('msg.denied'), FALSE);
        }

        /* One Admin per school (owner order 2026-10-29 night):
           a school has exactly ONE Admin account. A second one
           can never be created — whoever the existing Admin is
           (active or not), the role is taken; the way to change
           the Admin is to edit that account. */
        if ($type->title === 'admin' && \App\User::where('user_type', 'admin')->exists()) {
            return Qs::json('This school already has an Admin. A school can have only one Admin — please edit that account instead.', FALSE);
        }

        $user_type = $type->title;

        $data = $req->except(Qs::getStaffRecord());
        $data['name'] = ucwords($req->name);
        $data['user_type'] = $user_type;
        // email_verified_at is fillable on the model and was never
        // overwritten here, so a crafted create could set it (mass
        // assignment). A provisioned account starts unverified.
        $data['email_verified_at'] = null;
        $data['photo'] = Qs::getDefaultUserImage();
        $data['code'] = strtoupper(Str::random(10));

        $user_is_staff = in_array($user_type, Qs::getStaff());
        $user_is_teamSA = in_array($user_type, Qs::getTeamSA());

        $staff_id = Qs::getAppCode().'/STAFF/'.date('Y/m', strtotime($req->emp_date)).'/'.mt_rand(1000, 9999);
        $data['username'] = $uname = ($user_is_teamSA) ? $req->username : $staff_id;

        $pass = $req->password ?: $user_type;
        $data['password'] = Hash::make($pass);

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath($user_type).$data['code'], $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        /* Ensure that both username and Email are not blank*/
        if(!$uname && !$req->email){
            return back()->with('pop_error', __('msg.user_invalid'));
        }

        $user = $this->user->create($data); // Create User

        /* CREATE STAFF RECORD */
        if($user_is_staff){
            $d2 = $req->only(Qs::getStaffRecord());
            $d2['user_id'] = $user->id;
            $d2['code'] = $staff_id;
            $this->user->createStaffRecord($d2);
        }

        // Loop 112: post-save lands on the users index (owner ruling) —
        // was Qs::jsonStoreOk() (AJAX stay-on-form).
        return Qs::storeOk('users.index');
    }

    public function update(UserRequest $req, $id)
    {
        $id = Qs::decodeHash($id);

        // Redirect if Making Changes to Head of Super Admins
        if(Qs::headSA($id)){
            return Qs::json(__('msg.denied'), FALSE);
        }

        $user = $this->user->find($id);

        /* Audit R-12 companion guard (see edit()). */
        if (!$user) {
            return Qs::json(__('msg.denied'), FALSE);
        }

        if (!$this->mayWriteUserTarget($user->user_type)) {
            return Qs::json(__('msg.denied'), FALSE);
        }

        $user_type = $user->user_type;
        $user_is_staff = in_array($user_type, Qs::getStaff());
        $user_is_teamSA = in_array($user_type, Qs::getTeamSA());

        $data = $req->except(Qs::getStaffRecord());
        $data['name'] = ucwords($req->name);
        $data['user_type'] = $user_type;
        // Same mass-assignment guard as store(): never let the request
        // rewrite the account's verification timestamp.
        $data['email_verified_at'] = $user->email_verified_at;

        if($user_is_staff && !$user_is_teamSA){
            $data['username'] = Qs::getAppCode().'/STAFF/'.date('Y/m', strtotime($req->emp_date)).'/'.mt_rand(1000, 9999);
        }
        else {
            $data['username'] = $user->username;
        }

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath($user_type).$user->code, $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        $this->user->update($id, $data);   /* UPDATE USER RECORD */

        /* UPDATE STAFF RECORD */
        if($user_is_staff){
            $d2 = $req->only(Qs::getStaffRecord());
            $d2['code'] = $data['username'];
            $this->user->updateStaffRecord(['user_id' => $id], $d2);
        }

        // Loop 112: post-save lands on the users index (owner ruling) —
        // was Qs::jsonUpdateOk() (AJAX stay-on-form).
        return Qs::updateOk('users.index');
    }

    public function show($user_id)
    {
        $user_id = Qs::decodeHash($user_id);
        if(!$user_id){return back();}

        $data['user'] = $this->user->find($user_id);

        /* Audit R-12 companion guard: a decodable-but-nonexistent id
           404s instead of dereferencing null in the profile view. */
        if (!$data['user']) {
            abort(404);
        }

        /* Prevent Other Students from viewing Profile of others*/
        if(Auth::user()->id != $user_id && !Qs::userIsTeamSAT() && !Qs::userIsMyChild(Auth::user()->id, $user_id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        return view('pages.support_team.users.show', $data);
    }

    /* ---- Account lifecycle (Workstream DF) ----------------------
       Suspend / activate / terminate are STATE changes, never
       deletions: the person's records stay intact, sign-in is
       blocked while the state is not active, and a live session
       ends on its next request (EnsureActiveAccount middleware).
       The same boundary rules as edit/destroy apply — head-SA is
       untouchable, mayWriteUserTarget decides who may touch whom —
       plus two rails of their own: nobody acts on their own
       account, and the platform must always keep at least one
       active Super Admin. Every change writes an Audit row. */

    public function suspend($id)
    {
        return $this->changeAccountStatus($id, User::STATUS_SUSPENDED, 'user.suspended', 'suspended');
    }

    public function activate($id)
    {
        return $this->changeAccountStatus($id, User::STATUS_ACTIVE, 'user.activated', 'reactivated');
    }

    public function terminate($id)
    {
        return $this->changeAccountStatus($id, User::STATUS_TERMINATED, 'user.terminated', 'terminated');
    }

    protected function changeAccountStatus($id, string $newStatus, string $auditAction, string $verb)
    {
        /* $id arrives ALREADY decoded: these routes use the {id}
           parameter, which RouteServiceProvider binds through
           Qs::decodeHash (the same contract reset_pass relies on).
           Decoding a second time here would reject every request. */
        $id = (int) $id;
        if (!$id) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        // Nobody suspends/terminates/reactivates their own account.
        if ((int) $id === (int) Auth::user()->id) {
            return back()->with('flash_danger', 'You cannot change the status of your own account.');
        }

        // The head Super Admin is untouchable (same rule as destroy).
        if (Qs::headSA($id)) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        $user = $this->user->find($id);
        if (!$user) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        // Cross-boundary rule (Workstream CM) — unchanged here.
        if (!$this->mayWriteUserTarget($user->user_type)) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        // The platform must never be left without an active Super Admin.
        if ($newStatus !== User::STATUS_ACTIVE && $user->user_type === 'super_admin') {
            $otherActive = User::where('user_type', 'super_admin')
                ->where('status', User::STATUS_ACTIVE)
                ->where('id', '!=', $user->id)
                ->count();
            if ($otherActive === 0) {
                return back()->with('flash_danger', 'This is the last active Super Admin account — it cannot be ' . $verb . '.');
            }
        }

        $this->user->update($user->id, ['status' => $newStatus, 'status_at' => now()]);

        Audit::log($auditAction, $user, 'Account ' . $verb . ': ' . $user->name . ' (' . $user->email . ')');

        $messages = [
            User::STATUS_ACTIVE => 'Account reactivated — ' . $user->name . ' can sign in again.',
            User::STATUS_SUSPENDED => 'Account suspended — ' . $user->name . ' can no longer sign in.',
            User::STATUS_TERMINATED => 'Account terminated — ' . $user->name . ' can no longer sign in. Their records are preserved.',
        ];

        /* Owner order 2026-10-30: a suspended/terminated teacher
           cannot hold substitution cover — the system moves every
           future period they were covering to the next free
           teacher (and alerts the office about any period nobody
           can take), so a class never stands uncovered behind a
           dead account. */
        if ($newStatus !== User::STATUS_ACTIVE && $user->user_type === 'teacher') {
            $heal = \App\Support\LeaveAutoCover::reassignForDepartedTeacher(
                $user, (int) Auth::id(), $verb);
            if ($heal['reassigned'] > 0) {
                $messages[$newStatus] .= ' ' . $heal['reassigned'] . ' substitution cover(s) moved to other teachers.';
            }
            if ($heal['lost'] > 0) {
                $messages[$newStatus] .= ' ' . $heal['lost'] . ' period(s) could not be re-covered — admins and the principal have been alerted.';
            }
        }

        return back()->with('flash_success', $messages[$newStatus]);
    }

    public function destroy($id)
    {
        $id = Qs::decodeHash($id);

        // Redirect if Making Changes to Head of Super Admins
        if(Qs::headSA($id)){
            return back()->with('pop_error', __('msg.denied'));
        }

        $user = $this->user->find($id);

        /* Audit R-12 companion guard (see edit()). */
        if (!$user) {
            return back()->with('pop_error', __('msg.denied'));
        }

        if (!$this->mayWriteUserTarget($user->user_type)) {
            return back()->with('pop_error', __('msg.denied'));
        }

        if($user->user_type == 'teacher' && $this->userTeachesSubject($user)) {
            return back()->with('pop_error', __('msg.del_teacher'));
        }

        /* Allotment guard (parent, 2026-10-28, agent F's F8 gap):
           a teacher who leads sections or holds per-section subject
           overrides must not be deletable either — otherwise the
           section/override rows orphan onto a deleted id (the
           subject-teacher guard above covers only subjects.teacher_id).
           The Admin removes the assignments first, then deletes. */
        if($user->user_type == 'teacher' && $this->userHasTeachingAttachments($user)) {
            return back()->with('pop_error', 'This teacher still leads sections or holds section subject overrides. Remove those assignments first, then delete.');
        }

        /* Owner order 2026-10-30: before a teacher's account goes,
           the substitution cover they hold moves to the next free
           teacher (periods nobody can take alert the office) —
           deleting the account must never silently uncover a class. */
        $healNote = '';
        if ($user->user_type === 'teacher') {
            $heal = \App\Support\LeaveAutoCover::reassignForDepartedTeacher(
                $user, (int) Auth::id(), 'removed');
            if ($heal['reassigned'] > 0) {
                $healNote .= ' ' . $heal['reassigned'] . ' substitution cover(s) moved to other teachers.';
            }
            if ($heal['lost'] > 0) {
                $healNote .= ' ' . $heal['lost'] . ' period(s) could not be re-covered — admins and the principal have been alerted.';
            }
        }

        $path = Qs::getUploadPath($user->user_type).$user->code;
        Storage::exists($path) ? Storage::deleteDirectory($path) : true;
        $this->user->delete($user->id);

        return back()->with('flash_success', __('msg.del_ok') . $healNote);
    }

    protected function userTeachesSubject($user)
    {
        $subjects = $this->my_class->findSubjectByTeacher($user->id);
        return ($subjects->count() > 0) ? true : false;
    }

    protected function userHasTeachingAttachments($user)
    {
        if (\App\Models\Section::where('teacher_id', $user->id)->exists()) {
            return true;
        }
        return \Illuminate\Support\Facades\DB::table('section_subject_teachers')->where('teacher_id', $user->id)->exists();
    }


    public function create()
    {
        // Batch 5A: the create form is its own page (users/create) —
        // it used to render the whole index page with every user table.
        $ut = $this->user->getAllTypes();
        $ut2 = $ut->where('level', '>=', 2);

        /* Loop 106: the Super Admin provisions platform accounts only
           (Super Admin + School Admin) — the create form offers the SA
           the same roles their list shows. The school Admin's options
           (level >= 2) are unchanged. store()'s level gate is as-is,
           plus an SA-role gate (see store()).
           Owner ruling 2026-10-25 (SUPERSEDES Audit R-15's librarian
           carve-out): the school's internal Admin provisions school
           staff — including the librarian — so Librarian is NOT an SA
           create option anymore. The SA's LIST stays
           platform-accounts-only (Loop 106). */
        $d['user_types'] = Qs::userIsAdmin()
            ? $ut2
            : (Qs::userIsSuperAdmin() ? $ut->whereIn('title', ['super_admin', 'admin'])->values() : $ut);
        /* One Admin per school (owner order 2026-10-29 night):
           while an Admin account exists, the create form does not
           offer the Admin role at all — store() refuses it too,
           this just keeps the dead option out of the UI. */
        if (\App\User::where('user_type', 'admin')->exists()) {
            $d['user_types'] = collect($d['user_types'])->where('title', '!=', 'admin')->values();
        }
        $d['states'] = $this->loc->getStates();
        $d['nationals'] = $this->loc->getAllNationals();
        $d['blood_groups'] = $this->user->getBloodGroups();
        return view('pages.support_team.users.create', $d);
    }

}
