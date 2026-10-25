@extends('layouts.login_master')

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="text-center mb-3">
                <h5 class="mb-0">Admission Enquiry</h5>
                <span class="d-block text-muted">{{ $app_name }}</span>
            </div>

            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif

            <p class="text-muted">Tell us about the seat you are looking for and the school office will contact you.</p>

            <form method="POST" action="{{ route('admission_enquiry.store') }}">
                @csrf
                <div class="form-group">
                    <label for="name">Parent / Guardian Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="150" class="form-control @error('name') is-invalid @enderror" placeholder="Full name">
                    @error('name')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="phone">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="phone" value="{{ old('phone') }}" required maxlength="20" class="form-control @error('phone') is-invalid @enderror" placeholder="Phone number">
                    @error('phone')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="my_class_id">Class Sought</label>
                    <select name="my_class_id" id="my_class_id" class="form-control @error('my_class_id') is-invalid @enderror">
                        <option value="">Not sure yet</option>
                        @foreach($my_classes as $c)
                            <option value="{{ $c->id }}" {{ old('my_class_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('my_class_id')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea name="message" id="message" rows="3" maxlength="1000" class="form-control @error('message') is-invalid @enderror" placeholder="Anything the office should know">{{ old('message') }}</textarea>
                    @error('message')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send Enquiry</button>
            </form>
            <div class="text-center mt-3">
                <a href="{{ route('login') }}">Back to Login</a>
            </div>
        </div>
    </div>
@endsection
