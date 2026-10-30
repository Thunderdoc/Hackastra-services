@extends('layouts.login_master')

@section('content')

<div class="page-content">
    <div class="content-wrapper">
        <div class="content">
            <div class="card pat-card">
                <div class="card-header">
                    <h1 class="card-title font-weight-bold text-center">PRIVACY POLICY</h1>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div style="font-size: 16px;" class="col-md-10 offset-md-1">
                            <p>Last updated: 30 October 2026</p>

                            <h4 class="font-weight-semibold">Introduction</h4>
                            <p>{{ $app_name }} ("We") runs a school management platform at <a target="_blank" href="{{ $app_url }}">{{ $app_url }}</a>. We respect the privacy of the schools, staff, students and parents who use it. This policy explains what information the Platform holds, why, who can see it, and the choices the School has.</p>
                            <p>In short: the Platform holds the School's own records so the School can run itself. The data belongs to the School. We never sell personal data, and we never use it for advertising.</p>

                            <h4 class="font-weight-semibold">Information the Platform Holds</h4>
                            <ul>
                                <li><strong>Account information</strong> — names, email addresses, phone numbers and login credentials of the School's administrators, teachers, students, parents and other staff.</li>
                                <li><strong>School records the School enters</strong> — admissions and student records (including class, section and admission numbers), attendance, timetables, examination marks and report data, fee records and receipts, library, transport, hostel and similar operational records.</li>
                                <li><strong>Communications inside the Platform</strong> — notices, circulars, messages and notifications exchanged between the School's own users.</li>
                                <li><strong>Technical basics</strong> — sign-in sessions and the minimum technical information needed to keep accounts secure and the service running.</li>
                            </ul>

                            <h4 class="font-weight-semibold">How the Information Is Used</h4>
                            <p>Only to provide the Platform's features to the School: running admissions and classes, recording attendance and marks, producing reports and documents, collecting fees, and letting the School's users communicate. We do not use school data to train models for other customers, to advertise, or for any purpose outside running the School's own instance.</p>

                            <h4 class="font-weight-semibold">Who Can See It</h4>
                            <ul>
                                <li>The School's own authorised users, according to their role — for example, a parent sees their own children's information, a teacher sees their classes, and the School's administrators see the School's records.</li>
                                <li>One school can never see another school's data.</li>
                                <li>Our team may access data only when needed to maintain the service or to help with a support request from the School.</li>
                                <li>We disclose information only if the law requires it.</li>
                            </ul>

                            <h4 class="font-weight-semibold">Children's Information</h4>
                            <p>Student information is entered and controlled by the School, which is responsible for obtaining any consent its policies and applicable law require. Students and parents should contact the School's office for questions about a student's record.</p>

                            <h4 class="font-weight-semibold">Storage and Security</h4>
                            <p>Data is transmitted over encrypted connections and protected by account-based access controls — every user signs in and sees only what their role allows. No system is perfectly secure, but we work to protect data with reasonable technical and organisational measures, and we will inform the School promptly if a breach affecting its data is confirmed.</p>

                            <h4 class="font-weight-semibold">Cookies</h4>
                            <p>The Platform uses only the essential cookies needed to keep you signed in and the service working. It does not use advertising or cross-site tracking cookies.</p>

                            <h4 class="font-weight-semibold">Retention and Deletion</h4>
                            <p>The School's data is kept while the School uses the Platform. When the School stops using it, the School may request an export of its data and its deletion from our active systems, which we will complete within a reasonable period.</p>

                            <h4 class="font-weight-semibold">Your Rights</h4>
                            <p>Users can ask the School's administrator to correct or remove their information held on the Platform. Requests about a student's record should go to the School, which controls that record.</p>

                            <h4 class="font-weight-semibold">Changes to This Policy</h4>
                            <p>We may update this policy from time to time; the current version will always be on this page.</p>

                            <h4 class="font-weight-semibold">Contact</h4>
                            <p>Questions about this policy: reach the School's office @if($contact_phone) at {{ $contact_phone }} @endif or write to us through the Platform's support channel.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
