<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\UserRequest;
use App\Repositories\LocationRepo;
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
        $this->middleware('teamSA', ['only' => ['reset_pass','destroy'] ]);

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
        $d['states'] = $this->loc->getStates();
        $d['users'] = $this->user->getPTAUsers();
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

        return view('pages.support_team.users.index', $d);
    }

    public function edit($id)
    {
        $id = Qs::decodeHash($id);
        $d['user'] = $this->user->find($id);

        if ($d['user'] && !$this->mayWriteUserTarget($d['user']->user_type)) {
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
        if ($target && !$this->mayWriteUserTarget($target->user_type)) {
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

        if ($user && !$this->mayWriteUserTarget($user->user_type)) {
            return Qs::json(__('msg.denied'), FALSE);
        }

        $user_type = $user->user_type;
        $user_is_staff = in_array($user_type, Qs::getStaff());
        $user_is_teamSA = in_array($user_type, Qs::getTeamSA());

        $data = $req->except(Qs::getStaffRecord());
        $data['name'] = ucwords($req->name);
        $data['user_type'] = $user_type;

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

        /* Prevent Other Students from viewing Profile of others*/
        if(Auth::user()->id != $user_id && !Qs::userIsTeamSAT() && !Qs::userIsMyChild(Auth::user()->id, $user_id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        return view('pages.support_team.users.show', $data);
    }

    public function destroy($id)
    {
        $id = Qs::decodeHash($id);

        // Redirect if Making Changes to Head of Super Admins
        if(Qs::headSA($id)){
            return back()->with('pop_error', __('msg.denied'));
        }

        $user = $this->user->find($id);

        if ($user && !$this->mayWriteUserTarget($user->user_type)) {
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

        $d['user_types'] = Qs::userIsAdmin() ? $ut2 : $ut;
        $d['states'] = $this->loc->getStates();
        $d['nationals'] = $this->loc->getAllNationals();
        $d['blood_groups'] = $this->user->getBloodGroups();
        return view('pages.support_team.users.create', $d);
    }

}
