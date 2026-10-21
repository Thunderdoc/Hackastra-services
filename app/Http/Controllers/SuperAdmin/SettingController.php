<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\SettingRepo;

class SettingController extends Controller
{
    protected $setting, $my_class;

    public function __construct(SettingRepo $setting, MyClassRepo $my_class)
    {
        $this->setting = $setting;
        $this->my_class = $my_class;
    }

    public function index()
    {
         $s = $this->setting->all();
         $d['class_types'] = $this->my_class->getTypes();
         $d['s'] = $s->flatMap(function($s){
            return [$s->type => $s->description];
        });
        return view('pages.super_admin.settings', $d);
    }

    public function update(SettingUpdate $req)
    {
        $sets = $req->except('_token', '_method', 'logo', 'school_stamp', 'school_stamp_remove');
        $sets['lock_exam'] = $sets['lock_exam'] == 1 ? 1 : 0;
        $keys = array_keys($sets);
        $values = array_values($sets);
        for($i=0; $i<count($sets); $i++){
            $this->setting->update($keys[$i], $values[$i]);
        }

        if($req->hasFile('logo')) {
            $logo = $req->file('logo');
            $f = Qs::getFileMetaData($logo);
            $f['name'] = 'logo.' . $f['ext'];
            $f['path'] = $logo->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $logo_path = asset('storage/' . $f['path']);
            $this->setting->update('logo', $logo_path);
        }

        /* Batch M3: the school stamp follows the exact logo path —
           uploaded file -> public uploads -> settings row holding the
           asset URL. The report card renders it only while the
           setting is non-empty; the form's remove checkbox (or a
           fresh upload) is the only way it changes. */
        if($req->hasFile('school_stamp')) {
            $stamp = $req->file('school_stamp');
            $f = Qs::getFileMetaData($stamp);
            $f['name'] = 'school_stamp.' . $f['ext'];
            $f['path'] = $stamp->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $stamp_path = asset('storage/' . $f['path']);
            $this->setting->update('school_stamp', $stamp_path);
        }
        elseif($req->filled('school_stamp_remove')) {
            $this->setting->update('school_stamp', '');
        }

        return back()->with('flash_success', __('msg.update_ok'));

    }
}
