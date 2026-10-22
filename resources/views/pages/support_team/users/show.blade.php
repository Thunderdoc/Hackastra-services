@extends('layouts.master')
@section('page_title', 'User Profile - '.$user->name)
@section('content')

    <div class="row">
        <div class="col-lg-4 col-xl-3">
            <div class="card profile-card">
                <div class="card-body text-center">
                    <img src="{{ $user->photo }}" alt="photo" class="profile-avatar">
                    <h5 class="profile-name">{{ $user->name }}</h5>
                    <span class="badge profile-role">{{ ucwords(str_replace('_', ' ', $user->user_type)) }}</span>

                    <ul class="profile-contact">
                        @if($user->email)
                            <li><i class="icon-envelop5"></i><span>{{ $user->email }}</span></li>
                        @endif
                        @if($user->username)
                            <li><i class="icon-user"></i><span>{{ $user->username }}</span></li>
                        @endif
                        @if($user->phone)
                            <li><i class="icon-phone2"></i><span>{{ $user->phone.' '.$user->phone2 }}</span></li>
                        @endif
                        @if($user->address)
                            <li><i class="icon-location3"></i><span>{{ $user->address }}</span></li>
                        @endif
                    </ul>

                    @if((Qs::userIsSuperAdmin() && in_array($user->user_type, ['super_admin', 'admin'], true)) || (Qs::userIsAdmin() && $user->user_type !== 'super_admin'))
                        <a href="{{ route('users.edit', Qs::hash($user->id)) }}" class="btn btn-primary btn-block profile-edit-btn"><i class="icon-pencil7 mr-2"></i>Edit Profile</a>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-xl-9">
            <div class="card">
                <div class="card-header bg-white header-elements-inline">
                    <h6 class="card-title font-weight-semibold mb-0">Profile Information</h6>
                </div>
                <div class="table-responsive">
                    <table class="table profile-table mb-0">
                        <tbody>
                        <tr>
                            <td class="profile-label">Name</td>
                            <td>{{ $user->name }}</td>
                        </tr>
                        <tr>
                            <td class="profile-label">Gender</td>
                            <td>{{ $user->gender ?: '—' }}</td>
                        </tr>
                        <tr>
                            <td class="profile-label">Address</td>
                            <td>{{ $user->address ?: '—' }}</td>
                        </tr>
                        @if($user->email)
                            <tr>
                                <td class="profile-label">Email</td>
                                <td>{{ $user->email }}</td>
                            </tr>
                        @endif
                        @if($user->username)
                            <tr>
                                <td class="profile-label">Username</td>
                                <td>{{ $user->username }}</td>
                            </tr>
                        @endif
                        @if($user->phone)
                            <tr>
                                <td class="profile-label">Phone</td>
                                <td>{{ $user->phone.' '.$user->phone2 }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="profile-label">Birthday</td>
                            <td>{{ $user->dob ?: '—' }}</td>
                        </tr>
                        @if($user->bg_id)
                            <tr>
                                <td class="profile-label">Blood Group</td>
                                <td>{{ $user->blood_group->name }}</td>
                            </tr>
                        @endif
                        @if($user->nal_id)
                            <tr>
                                <td class="profile-label">Nationality</td>
                                <td>{{ $user->nationality->name }}</td>
                            </tr>
                        @endif
                        @if($user->state_id)
                            <tr>
                                <td class="profile-label">State</td>
                                <td>{{ $user->state->name }}</td>
                            </tr>
                        @endif
                        @if($user->lga_id)
                            <tr>
                                <td class="profile-label">LGA</td>
                                <td>{{ $user->lga->name }}</td>
                            </tr>
                        @endif
                        @if($user->user_type == 'parent')
                            <tr>
                                <td class="profile-label">Children/Ward</td>
                                <td>
                                    @foreach(Qs::findMyChildren($user->id) as $sr)
                                        {{-- Workstream DD: the child name/class is this
                                             account's own data, but the student page is
                                             school domain — the platform-only Super
                                             Admin sees it as text, not a link. --}}
                                        <span> - @if(Qs::userIsSuperAdmin()){{ $sr->user->name.' - '.$sr->my_class->name. ' '.$sr->section->name }}@else<a href="{{ route('students.show', Qs::hash($sr->id)) }}">{{ $sr->user->name.' - '.$sr->my_class->name. ' '.$sr->section->name }}</a>@endif</span><br>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                        @if($user->user_type == 'teacher')
                            <tr>
                                <td class="profile-label">My Subjects</td>
                                <td>
                                    @foreach(Qs::findTeacherSubjects($user->id) as $sub)
                                        <span> - {{ $sub->name.' ('.$sub->my_class->name.')' }}</span><br>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{--User Profile Ends--}}

@endsection
