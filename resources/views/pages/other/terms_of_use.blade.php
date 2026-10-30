@extends('layouts.login_master')

@section('content')

    <div class="page-content">
        <div class="content-wrapper">
            <div class="content">
                <div class="card pat-card">
                    <div class="card-header">
                        <h1 class="card-title font-weight-bold text-center">TERMS OF USE</h1>
                    </div>

                    <div class="card-body">
                        <div class="row">
                            <div style="font-size: 16px;" class="col-md-10 offset-md-1">
                                <p>Last updated: 30 October 2026</p>

                                <h4 class="font-weight-semibold">1. About the Service</h4>
                                <p>{{ $app_name }} ("we", "us", the "Platform") is a school management platform available at <a target="_blank" href="{{ $app_url }}">{{ $app_url }}</a>. It helps a school manage admissions, students, staff, attendance, timetables, examinations and marks, fees, library, transport and day-to-day communication in one place. These Terms of Use govern access to and use of the Platform by the school and by every person the school authorises to use it.</p>
                                <p>By signing in or using the Platform, you agree to these Terms and to our <a target="_blank" href="{{ route('privacy_policy') }}">Privacy Policy</a>. If you do not agree, you must not use the Platform.</p>

                                <h4 class="font-weight-semibold">2. Who May Use the Platform</h4>
                                <p>The Platform is provided to a school (the "School"). Access is through accounts the School creates for its administrators, teachers, students, parents and other staff. Each user must use only their own account, keep their password private, and use the Platform only for the School's genuine educational and administrative work.</p>

                                <h4 class="font-weight-semibold">3. The School's Responsibilities</h4>
                                <ul>
                                    <li>The School is responsible for the accuracy of the data it enters — student, parent and staff records, marks, attendance and fee information.</li>
                                    <li>The School confirms it has the right to share the personal data it enters (including data about students and parents) with the Platform for the purpose of running the School.</li>
                                    <li>The School's administrator manages accounts and access for the School and is responsible for promptly disabling accounts of people who leave.</li>
                                    <li>The School is responsible for decisions it takes using the Platform's records and reports.</li>
                                </ul>

                                <h4 class="font-weight-semibold">4. School Data</h4>
                                <p>All data the School enters belongs to the School. We process it only to provide the Platform's features to the School — never for advertising, and never by selling it to anyone. On request at the end of the School's use of the Platform, we will make the School's data available for export and then delete it from our active systems within a reasonable period.</p>

                                <h4 class="font-weight-semibold">5. Acceptable Use</h4>
                                <p>You must not: attempt to access another school's data or another user's account; try to break, overload or reverse-engineer the Platform; upload unlawful, harmful or infringing content; or use the Platform for any purpose other than the School's work.</p>

                                <h4 class="font-weight-semibold">6. Fees</h4>
                                <p>Any fees for the Platform are as agreed in writing between the School and us. Demonstration instances are provided free for evaluation and may be reset or withdrawn at any time; a demonstration instance is not a commitment of service.</p>

                                <h4 class="font-weight-semibold">7. Availability</h4>
                                <p>We work to keep the Platform available and reliable, and we back up service data regularly. The Platform is provided "as is": we do not promise uninterrupted availability, and scheduled maintenance or factors outside our control may cause brief interruptions.</p>

                                <h4 class="font-weight-semibold">8. Intellectual Property</h4>
                                <p>The Platform's software, design and branding belong to us. The School's data, name and identity belong to the School. Neither side gains rights over the other's property by using the Platform.</p>

                                <h4 class="font-weight-semibold">9. Suspension and Ending Use</h4>
                                <p>We may suspend an account that is misused or that puts the Platform or other schools at risk, and we will inform the School's administrator. The School may stop using the Platform at any time; section 4 covers what happens to its data.</p>

                                <h4 class="font-weight-semibold">10. Liability</h4>
                                <p>To the extent permitted by law, our total liability arising from the Platform is limited to the fees the School paid us in the twelve months before the claim. We are not liable for indirect losses, or for losses caused by inaccurate data entered by the School or by decisions the School takes.</p>

                                <h4 class="font-weight-semibold">11. Changes to These Terms</h4>
                                <p>We may update these Terms from time to time. The current version will always be on this page, and continuing to use the Platform after a change means the School accepts the updated Terms.</p>

                                <h4 class="font-weight-semibold">12. Governing Law</h4>
                                <p>These Terms are governed by the laws of India.</p>

                                <h4 class="font-weight-semibold">13. Contact</h4>
                                <p>Questions about these Terms: reach the School's office @if($contact_phone) at {{ $contact_phone }} @endif or write to us through the Platform's support channel.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
