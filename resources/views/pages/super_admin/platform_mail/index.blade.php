@extends('layouts.master')
@section('page_title', 'Sent Mail')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">Every platform mail send, with its real outcome</span>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p><a href="{{ route('platform_mail.create') }}" class="btn btn-primary"><i class="icon-envelop5 mr-2"></i>Compose Mail</a></p>

            @if($sent_mails->isEmpty())
                <p class="text-muted mb-0">No platform mail has been sent yet. Sends from the mail composer will appear here — delivered, log-recorded, or failed.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>When</th>
                            <th>Sent By</th>
                            <th>To</th>
                            <th>Recipients</th>
                            <th>Subject</th>
                            <th>Driver</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($sent_mails as $mail)
                            <tr>
                                <td>{{ $mail->created_at->format('d M Y, h:i A') }}</td>
                                <td>{{ optional($mail->sender)->name ?: '—' }}</td>
                                <td>{{ $mail->audience_label }}</td>
                                <td>{{ $mail->recipient_count }}</td>
                                <td>{{ $mail->subject }}</td>
                                <td><code>{{ $mail->driver }}</code></td>
                                <td>
                                    @if($mail->status === 'sent')
                                        <span class="badge badge-success">Sent</span>
                                    @elseif($mail->status === 'logged')
                                        <span class="badge badge-warning">Logged (not delivered)</span>
                                    @else
                                        <span class="badge badge-danger" title="{{ $mail->error }}">Failed</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $sent_mails->links() }}
            @endif
        </div>
    </div>
@endsection
