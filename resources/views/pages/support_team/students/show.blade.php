@extends('layouts.master')
@section('page_title', 'Student Profile - '.$sr->user->name)
@section('content')
<div class="row">
    <div class="col-md-3 text-center">
        <div class="card">
            <div class="card-body">
                {{-- Photo rule (audit Fix Wave 1, R-02): a real uploaded
                     photo, else an initials tile — never the stock
                     silhouette the seeded/default photo value points
                     at. Same rule as the report cards (Workstream BR). --}}
                @if(Qs::hasRealPhoto($sr->user->photo))
                    <img style="width: 90%; height:90%" src="{{ $sr->user->photo }}" alt="photo" class="rounded-circle">
                @else
                    @php
                        $profileInitials = '';
                        foreach (preg_split('/\s+/', trim((string) $sr->user->name)) as $profileWord) {
                            if ($profileWord !== '') {
                                $profileInitials .= strtoupper(substr($profileWord, 0, 1));
                                if (strlen($profileInitials) >= 2) { break; }
                            }
                        }
                    @endphp
                    <span class="rounded-circle" aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; width: 90%; aspect-ratio: 1/1; background: #3478f6; color: #fff; font-size: 2.6rem; font-weight: 700;">{{ $profileInitials ?: 'S' }}</span>
                @endif
                <br>
                <h3 class="mt-3">{{ $sr->user->name }}</h3>
                {{-- Loop 102: honest incompleteness marker — the fields the
                     full admission form requires (address, nationality,
                     state, LGA) are still blank, e.g. after a Quick
                     Admit. Computed from the real blanks; no flag column. --}}
                @if(empty($sr->user->address) || empty($sr->user->nal_id) || empty($sr->user->state_id) || empty($sr->user->lga_id))
                    <div><span class="badge badge-warning qa102-pill">Incomplete profile</span></div>
                @endif
                @if(Qs::userIsTeamSA())
                    <a href="{{ route('students.id_card', Qs::hash($sr->id)) }}" class="btn btn-primary mt-2"><i class="icon-vcard mr-1"></i> ID Card</a>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-9">
        <div class="card">
            <div class="card-body">
                <ul class="nav nav-tabs nav-tabs-highlight">
                    <li class="nav-item">
                        <span class="nav-link active">{{ $sr->user->name }}</span>
                    </li>
                </ul>

                <div class="tab-content">
                    {{--Basic Info--}}
                    <div class="tab-pane fade show active" id="basic-info">
                        <table class="table table-bordered">
                            <tbody>
                            <tr>
                                <td class="font-weight-bold">Name</td>
                                <td>{{ $sr->user->name }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold">ADM_NO</td>
                                <td>{{ $sr->adm_no }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold">Class</td>
                                <td>{{ $sr->my_class->name.' '.$sr->section->name }}</td>
                            </tr>
                            @if($sr->my_parent_id)
                                <tr>
                                    <td class="font-weight-bold">Parent</td>
                                    <td>
                                        <span><a target="_blank" href="{{ route('users.show', Qs::hash($sr->my_parent_id)) }}">{{ $sr->my_parent->name }}</a></span>
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td class="font-weight-bold">Year Admitted</td>
                                <td>{{ $sr->year_admitted }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold">Gender</td>
                                <td>{{ $sr->user->gender }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold">Address</td>
                                <td>{{ $sr->user->address }}</td>
                            </tr>
                            @if($sr->user->email)
                            <tr>
                                <td class="font-weight-bold">Email</td>
                                <td>{{$sr->user->email }}</td>
                            </tr>
                            @endif
                            @if($sr->user->phone)
                                <tr>
                                    <td class="font-weight-bold">Phone</td>
                                    <td>{{$sr->user->phone.' '.$sr->user->phone2 }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="font-weight-bold">Birthday</td>
                                <td>{{$sr->user->dob }}</td>
                            </tr>
                            @if($sr->user->bg_id)
                            <tr>
                                <td class="font-weight-bold">Blood Group</td>
                                <td>{{$sr->user->blood_group->name }}</td>
                            </tr>
                            @endif
                            @if($sr->user->nal_id)
                            <tr>
                                <td class="font-weight-bold">Nationality</td>
                                <td>{{$sr->user->nationality->name }}</td>
                            </tr>
                            @endif
                            @if($sr->user->state_id)
                            <tr>
                                <td class="font-weight-bold">State</td>
                                <td>{{$sr->user->state->name }}</td>
                            </tr>
                            @endif
                            @if($sr->user->lga_id)
                            <tr>
                                <td class="font-weight-bold">LGA</td>
                                <td>{{$sr->user->lga->name }}</td>
                            </tr>
                            @endif
                            @if($sr->dorm_id)
                                <tr>
                                    <td class="font-weight-bold">Dormitory</td>
                                    <td>{{$sr->dorm->name.' '.$sr->dorm_room_no }}</td>
                                </tr>
                            @endif

                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>


    {{--Student Profile Ends--}}

@endsection
