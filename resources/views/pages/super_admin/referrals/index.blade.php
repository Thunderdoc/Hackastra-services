@extends('layouts.master')
@section('page_title', 'Referrals')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Platform &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Round 5 Slice 4 (P16): SA-side referral plumbing. The
         programme is ON only while a reward text is saved —
         the school Admin then sees the code + that exact text
         read-only. The log below is SA-side only; schools never
         submit entries. No reward is invented anywhere here:
         the text and notes are exactly what the SA types. --}}
  <div class="l106 l106-referrals">

    <section class="dashboard-panel mb-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1"><i class="icon-users2 text-blue mr-1"></i> Referral Programme</h5><p class="dashboard-muted mb-0">The school Admin sees the code and reward text read-only while the programme is on.</p></div>
            <span class="dashboard-status {{ $programme_on ? 'status-success' : 'status-warning' }}">{{ $programme_on ? 'Programme on' : 'Programme off' }}</span>
        </div>
        <form method="POST" action="{{ route('platform_referrals.programme') }}">
            @csrf
            <div class="row">
                <div class="col-md-4">
                    <label for="referral_code">Referral code</label>
                    <input type="text" name="referral_code" id="referral_code" class="form-control" maxlength="50" value="{{ old('referral_code', $referral_code ?? '') }}">
                    @error('referral_code')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="col-md-8">
                    <label for="referral_reward_text">Reward text (leave blank to switch the programme off)</label>
                    <input type="text" name="referral_reward_text" id="referral_reward_text" class="form-control" maxlength="500" value="{{ old('referral_reward_text', $referral_reward_text ?? '') }}">
                    @error('referral_reward_text')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Programme Settings</button>
            </div>
        </form>
        <form method="POST" action="{{ route('platform_referrals.regenerate_code') }}" class="mt-2">
            @csrf
            <button type="submit" class="btn btn-light">Regenerate Code</button>
            <span class="dashboard-muted">Replaces the code above with a fresh generated one.</span>
        </form>
    </section>

    <section class="dashboard-panel mb-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1"><i class="icon-plus3 text-blue mr-1"></i> Add a Referral Entry</h5><p class="dashboard-muted mb-0">Record who referred whom — logged by hand at onboarding.</p></div>
        </div>
        <form method="POST" action="{{ route('platform_referrals.store') }}">
            @csrf
            <div class="row">
                <div class="col-md-3">
                    <label for="referrer_name">Referrer <span class="text-danger">*</span></label>
                    <input type="text" name="referrer_name" id="referrer_name" class="form-control" maxlength="255" value="{{ old('referrer_name') }}" required>
                    @error('referrer_name')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="col-md-3">
                    <label for="referred_name">Referred <span class="text-danger">*</span></label>
                    <input type="text" name="referred_name" id="referred_name" class="form-control" maxlength="255" value="{{ old('referred_name') }}" required>
                    @error('referred_name')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="col-md-2">
                    <label for="ref_status">Status <span class="text-danger">*</span></label>
                    <select name="status" id="ref_status" class="form-control">
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" {{ old('status') === $status ? 'selected' : '' }}>{{ \App\Models\Referral::statusLabel($status) }}</option>
                        @endforeach
                    </select>
                    @error('status')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="col-md-4">
                    <label for="reward_note">Reward note</label>
                    <input type="text" name="reward_note" id="reward_note" class="form-control" maxlength="2000" value="{{ old('reward_note') }}">
                    @error('reward_note')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Add Entry</button>
            </div>
        </form>
    </section>

    <section class="dashboard-panel mb-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1"><i class="icon-list text-blue mr-1"></i> Referral Log</h5><p class="dashboard-muted mb-0">Every recorded referral, latest first.</p></div>
        </div>
        @if($referrals->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users2',
                'message' => 'No referrals have been logged yet. Entries you add above will appear here.',
            ])
        @else
            <div class="table-responsive">
                <table class="table hk-std hk-std-referrals">
                    <colgroup><col style="width:14%"><col style="width:18%"><col style="width:18%"><col style="width:22%"><col style="width:12%"><col style="width:16%"></colgroup>
                    <thead>
                    <tr>
                        <th>Logged on</th>
                        <th>Referrer</th>
                        <th>Referred</th>
                        <th>Reward note</th>
                        <th>Logged by</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($referrals as $referral)
                        <tr>
                            <td><strong>{{ $referral->created_at ? $referral->created_at->format('d M Y') : '—' }}</strong>@if($referral->created_at)<br><small class="text-muted">{{ $referral->created_at->format('h:i A') }}</small>@endif</td>
                            <td><strong>{{ $referral->referrer_name }}</strong></td>
                            <td>{{ $referral->referred_name }}</td>
                            <td>{{ $referral->reward_note ?: '—' }}</td>
                            <td>{{ optional($referral->creator)->name ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('platform_referrals.status', $referral->id) }}" class="d-flex">
                                    @csrf
                                    <select name="status" class="form-control form-control-sm" aria-label="Status for {{ $referral->referrer_name }} to {{ $referral->referred_name }}">
                                        @foreach($statuses as $status)
                                            <option value="{{ $status }}" {{ $referral->status === $status ? 'selected' : '' }}>{{ \App\Models\Referral::statusLabel($status) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-light btn-sm ml-1">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <span class="text-muted">Showing {{ $referrals->firstItem() }} to {{ $referrals->lastItem() }} of {{ number_format($referrals->total()) }} entries</span>
                <span>{{ $referrals->links() }}</span>
            </div>
        @endif
    </section>
  </div>{{-- /.l106-referrals --}}
@endsection
