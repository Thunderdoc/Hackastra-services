@extends('layouts.master')
@section('page_title', 'Manage Users')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Manage Users</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-highlight">
                <li class="nav-item"><a href="#new-user" class="nav-link active" data-toggle="tab">Create New User</a></li>
                <li class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Manage Users</a>
                    <div class="dropdown-menu dropdown-menu-right">
                        @foreach($user_types as $ut)
                            <a href="#ut-{{ Qs::hash($ut->id) }}" class="dropdown-item" data-toggle="tab">{{ $ut->name }}s</a>
                        @endforeach
                    </div>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="new-user">
                    <form method="post" enctype="multipart/form-data" class="wizard-form steps-validation ajax-store" action="{{ route('users.store') }}" data-fouc>
                        @csrf
                    <h6>Personal Data</h6>
                        <fieldset>
                            <div class="row">
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label for="user_type"> Select User: <span class="text-danger">*</span></label>
                                        <select required data-placeholder="Select User" class="form-control select @error('user_type') is-invalid @enderror" name="user_type" id="user_type">
                                @foreach($user_types as $ut)
                                    <option value="{{ Qs::hash($ut->id) }}">{{ $ut->name }}</option>
                                @endforeach
                                        </select>
                                        @include('partials.errors.field', ['field' => 'user_type'])
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="name">Full Name: <span class="text-danger">*</span></label>
                                        <input value="{{ old('name') }}" required type="text" name="name" id="name" placeholder="Full Name" class="form-control @error('name') is-invalid @enderror">
                                        @include('partials.errors.field', ['field' => 'name'])
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="address">Address: <span class="text-danger">*</span></label>
                                        <input value="{{ old('address') }}" class="form-control @error('address') is-invalid @enderror" placeholder="Address" name="address" id="address" type="text" required>
                                        @include('partials.errors.field', ['field' => 'address'])
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="email">Email address: </label>
                                        <input value="{{ old('email') }}" type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" placeholder="your@email.com">
                                        @include('partials.errors.field', ['field' => 'email'])
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="username">Username: </label>
                                        <input value="{{ old('username') }}" type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror" placeholder="Username">
                                        @include('partials.errors.field', ['field' => 'username'])
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="phone">Phone:</label>
                                        <input value="{{ old('phone') }}" type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="+2341234567" >
                                        @include('partials.errors.field', ['field' => 'phone'])
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="phone2">Telephone:</label>
                                        <input value="{{ old('phone2') }}" type="text" name="phone2" id="phone2" class="form-control @error('phone2') is-invalid @enderror" placeholder="+2341234567" >
                                        @include('partials.errors.field', ['field' => 'phone2'])
                                    </div>
                                </div>

                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="emp_date">Date of Employment:</label>
                                        <input autocomplete="off" name="emp_date" id="emp_date" value="{{ old('emp_date') }}" type="text" class="form-control date-pick @error('emp_date') is-invalid @enderror" placeholder="Select Date...">
                                        @include('partials.errors.field', ['field' => 'emp_date'])

                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="password">Password: </label>
                                        <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror"  >
                                        @include('partials.errors.field', ['field' => 'password'])
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="gender">Gender: <span class="text-danger">*</span></label>
                                        <select class="select form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required data-fouc data-placeholder="Choose..">
                                            <option value=""></option>
                                            <option {{ (old('gender') == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                                            <option {{ (old('gender') == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                                        </select>
                                        @include('partials.errors.field', ['field' => 'gender'])
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="nal_id">Nationality: <span class="text-danger">*</span></label>
                                        <select data-placeholder="Choose..." required name="nal_id" id="nal_id" class="select-search form-control @error('nal_id') is-invalid @enderror">
                                            <option value=""></option>
                                            @foreach($nationals as $nal)
                                                <option {{ (old('nal_id') == $nal->id ? 'selected' : '') }} value="{{ $nal->id }}">{{ $nal->name }}</option>
                                            @endforeach
                                        </select>
                                        @include('partials.errors.field', ['field' => 'nal_id'])
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                {{--State--}}
                                <div class="col-md-4">
                                    <label for="state_id">State: <span class="text-danger">*</span></label>
                                    <select onchange="getLGA(this.value)" required data-placeholder="Choose.." class="select-search form-control @error('state_id') is-invalid @enderror" name="state_id" id="state_id">
                                        <option value=""></option>
                                        @foreach($states as $st)
                                            <option {{ (old('state_id') == $st->id ? 'selected' : '') }} value="{{ $st->id }}">{{ $st->name }}</option>
                                        @endforeach
                                    </select>
                                    @include('partials.errors.field', ['field' => 'state_id'])
                                </div>
                                {{--LGA--}}
                                <div class="col-md-4">
                                    <label for="lga_id">LGA: <span class="text-danger">*</span></label>
                                    <select required data-placeholder="Select State First" class="select-search form-control @error('lga_id') is-invalid @enderror" name="lga_id" id="lga_id">
                                        <option value=""></option>
                                    </select>
                                    @include('partials.errors.field', ['field' => 'lga_id'])
                                </div>
                                {{--BLOOD GROUP--}}
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="bg_id">Blood Group: </label>
                                        <select class="select form-control @error('bg_id') is-invalid @enderror" id="bg_id" name="bg_id" data-fouc data-placeholder="Choose..">
                                            <option value=""></option>
                                            @foreach($blood_groups as $bg)
                                                <option {{ (old('bg_id') == $bg->id ? 'selected' : '') }} value="{{ $bg->id }}">{{ $bg->name }}</option>
                                            @endforeach
                                        </select>
                                        @include('partials.errors.field', ['field' => 'bg_id'])
                                    </div>
                                </div>

                            </div>

                            <div class="row">
                                {{--PASSPORT--}}
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="photo" class="d-block">Upload Passport Photo:</label>
                                        <input value="{{ old('photo') }}" accept="image/*" type="file" name="photo" id="photo" class="form-input-styled @error('photo') is-invalid @enderror" data-fouc>
                                        @include('partials.errors.field', ['field' => 'photo'])
                                        <span class="form-text text-muted">Accepted Images: jpeg, png. Max file size 2Mb</span>
                                    </div>
                                </div>
                            </div>

                        </fieldset>



                    </form>
                </div>

                @foreach($user_types as $ut)
                    <div class="tab-pane fade" id="ut-{{Qs::hash($ut->id)}}">                         <table class="table datatable-button-html5-columns">
                            <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($users->where('user_type', $ut->title) as $u)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $u->photo }}" alt="photo"></td>
                                    <td>{{ $u->name }}</td>
                                    <td>{{ $u->username }}</td>
                                    <td>{{ $u->phone }}</td>
                                    <td>{{ $u->email }}</td>
                                    <td class="text-center">
                                        <div class="list-icons">
                                            <div class="dropdown">
                                                <a href="#" class="list-icons-item" data-toggle="dropdown">
                                                    <i class="icon-menu9"></i>
                                                </a>

                                                <div class="dropdown-menu dropdown-menu-left">
                                                    {{--View Profile--}}
                                                    <a href="{{ route('users.show', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                                    {{--Edit--}}
                                                    <a href="{{ route('users.edit', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                @if(Qs::userIsTeamSA())

                                                        <a href="{{ route('users.reset_pass', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-lock"></i> Reset password</a>
                                                        {{--Delete--}}
                                                        <a id="{{ Qs::hash($u->id) }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                        <form method="post" id="item-delete-{{ Qs::hash($u->id) }}" action="{{ route('users.destroy', Qs::hash($u->id)) }}" class="hidden">@csrf @method('delete')</form>
                                                @endif

                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted" style="padding:22px 12px"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#3478f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:.55;display:block;margin:0 auto 6px" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>No users of this type yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    {{--Student List Ends--}}

@endsection
