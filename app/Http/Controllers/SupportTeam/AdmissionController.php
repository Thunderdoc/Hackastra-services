<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use Illuminate\Http\Request;

class AdmissionController extends Controller
{
    public function __construct()
    {
        // Admin only: enquiries are a school-office queue, and the
        // write actions change pipeline state.
        $this->middleware('teamSAWrite')->only(['updateStatus', 'convert']);
    }

    /** Pipeline board: status filter + per-status counts. */
    public function index(Request $request)
    {
        $status = $request->get('status', 'all');
        $query = Admission::with(['myClass', 'studentRecord.user'])->orderByDesc('id');
        if (in_array($status, Admission::STATUSES, true)) {
            $query->where('status', $status);
        }
        $data['admissions'] = $query->paginate(25);
        $data['status'] = $status;
        $data['counts'] = Admission::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')->pluck('c', 'status');
        $data['statuses'] = Admission::STATUS_LABELS;
        return view('pages.support_team.admissions.index', $data);
    }

    /** Move one enquiry to another pipeline state (+ office notes). */
    public function updateStatus(Request $request, Admission $admission)
    {
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', Admission::STATUSES),
            'notes' => 'nullable|string|max:2000',
        ]);
        $admission->update($data);
        return back()->with('flash_success', 'Enquiry moved to ' . Admission::STATUS_LABELS[$data['status']] . '.');
    }

    /**
     * Convert: pre-fill the Admit Student form with the enquiry's
     * name, phone and class sought (flashed old input — the create
     * form already reads old()). The enquiry itself is NOT marked
     * converted here: conversion is real only once the student
     * record exists, so the Admin marks it Converted on the board
     * after admitting.
     */
    public function convert(Admission $admission)
    {
        // R31-1 (roles audit round 31): convert is the approved ->
        // converted transition. Pre-fix it accepted ANY status, so
        // a REJECTED enquiry could be converted and admitted, and
        // a 'converted' enquiry could be re-converted. Only the
        // approved state converts; anything else bounces back to
        // the board with the current state named. (Manual board
        // moves stay jump-allowed by design — this guard is on the
        // convert action only, not on updateStatus.)
        if ($admission->status !== 'approved') {
            return back()->with('flash_danger', 'Only approved enquiries can be converted — this enquiry is ' . Admission::STATUS_LABELS[$admission->status] . '.');
        }

        return redirect()->route('students.create')->withInput([
            'name' => $admission->name,
            'phone' => $admission->phone,
            'my_class_id' => $admission->my_class_id,
            // Carried so the admit can close the loop: when the
            // student is created, StudentRecordController marks
            // THIS enquiry converted and links the record — one
            // enquiry can therefore never be admitted twice
            // (owner loophole review, 2026-10-30).
            'admission_id' => $admission->id,
        ]);
    }
}
