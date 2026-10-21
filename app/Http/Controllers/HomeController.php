<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Repositories\UserRepo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    protected $user;
    public function __construct(UserRepo $user)
    {
        $this->user = $user;
    }


    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function privacy_policy()
    {
        $data['app_name'] = config('app.name');
        $data['app_url'] = config('app.url');
        $data['contact_phone'] = Qs::getSetting('phone');
        return view('pages.other.privacy_policy', $data);
    }

    public function terms_of_use()
    {
        $data['app_name'] = config('app.name');
        $data['app_url'] = config('app.url');
        $data['contact_phone'] = Qs::getSetting('phone');
        return view('pages.other.terms_of_use', $data);
    }

    public function dashboard()
    {
        $d=[];
        if(Qs::userIsTeamSAT()){
            $d['users'] = $this->user->getAll();
            $d['student_count'] = StudentRecord::where('grad', 0)->count();
            $d['teacher_count'] = $d['users']->where('user_type', 'teacher')->count();
            $d['administrator_count'] = $d['users']->whereIn('user_type', ['admin', 'super_admin'])->count();
            $d['parent_count'] = $d['users']->where('user_type', 'parent')->count();
            $d['recent_students'] = StudentRecord::with(['user', 'my_class', 'section'])
                ->latest()
                ->take(5)
                ->get();
            $d['recent_payments'] = PaymentRecord::with(['student', 'payment'])
                ->latest()
                ->take(5)
                ->get();
            $d['payment_total'] = (int) DB::table('payment_records')
                ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
                ->sum('payments.amount');
            $d['payment_collected'] = (int) DB::table('payment_records')->sum('amt_paid');
            $d['payment_pending'] = max(0, $d['payment_total'] - $d['payment_collected']);
            $d['enrollment_trend'] = collect(range(5, 0))->map(function ($monthsAgo) {
                $date = Carbon::now()->startOfMonth()->subMonths($monthsAgo);

                return [
                    'label' => $date->format('M'),
                    'value' => StudentRecord::where('grad', 0)
                        ->whereDate('created_at', '<=', $date->copy()->endOfMonth())
                        ->count(),
                ];
            });
        }

        return view('pages.support_team.dashboard', $d);
    }
}
