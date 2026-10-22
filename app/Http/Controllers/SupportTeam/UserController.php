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
        $this->middleware('teamSA', ['only' => ['index', 'create', 'store', 'edit', 'update'] ]);
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

        return Qs::jsonStoreOk();
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

        return Qs::jsonUpdateOk();
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

        $path = Qs::getUploadPath($user->user_type).$user->code;
        Storage::exists($path) ? Storage::deleteDirectory($path) : true;
        $this->user->delete($user->id);

        return back()->with('flash_success', __('msg.del_ok'));
    }

    protected function userTeachesSubject($user)
    {
        $subjects = $this->my_class->findSubjectByTeacher($user->id);
        return ($subjects->count() > 0) ? true : false;
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
           (level >= 2) are unchanged. store()'s level gate is as-is.
           Audit R-15 (2026-10-23): Librarian joins the SA's create
           options — the role was un-creatable anywhere until the
           user_types row was seeded; the SA provisions the school's
           librarian account at onboarding just like its Admins. The
           SA's LIST stays platform-accounts-only (Loop 106). */
        $d['user_types'] = Qs::userIsAdmin()
            ? $ut2
            : (Qs::userIsSuperAdmin() ? $ut->whereIn('title', ['super_admin', 'admin', 'librarian'])->values() : $ut);
        $d['states'] = $this->loc->getStates();
        $d['nationals'] = $this->loc->getAllNationals();
        $d['blood_groups'] = $this->user->getBloodGroups();
        return view('pages.support_team.users.create', $d);
    }

}
