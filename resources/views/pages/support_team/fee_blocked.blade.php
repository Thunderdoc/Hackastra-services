@extends('layouts.master')
@section('page_title', 'Fee Payment Pending')
@section('content')

    {{-- Fee-defaulter document block (owner ruling 2026-10-22): a
         student with fees outstanding for the current session — or
         their parent — cannot generate the admit card or marksheet
         until the dues are paid. No document data of any kind is
         passed to this view; it carries only the student's name, the
         document name and the real outstanding balance, computed by
         App\Support\FeeDefaulter (the Fee Defaulters page's own
         definition). Admin (teamSA) generation is never blocked. --}}
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="mb-3">
                <i class="icon-lock" style="font-size:44px;color:#c0392b"></i>
            </div>
            <h4 class="font-weight-bold mb-2">{{ $document }} Cannot Be Generated Yet</h4>
            <p class="text-muted mb-1">
                The {{ strtolower($document) }} for <strong>{{ $sr->user->name }}</strong>
                ({{ $sr->adm_no }}) is on hold because a fee balance of
                <strong style="color:#c0392b">{{ \App\Helpers\Money::inr($due) }}</strong>
                is still outstanding for the <strong>{{ $year }}</strong> session.
            </p>
            <p class="text-muted">
                Please pay the fee to generate this document. It becomes available
                automatically as soon as the outstanding balance is cleared.
            </p>
            @if(!empty($viewer_is_parent))
                <a href="{{ route('my_fees.show', $sr->user_id) }}" class="btn btn-primary mt-2">
                    <i class="icon-coins mr-1"></i> Pay the Fee
                </a>
            @else
                <p class="text-muted mb-1">Please ask your parent to pay the fee, or contact the school office for help.</p>
            @endif
            <a href="{{ route('dashboard') }}" class="btn btn-light mt-2">Back to Dashboard</a>
        </div>
    </div>

@endsection
