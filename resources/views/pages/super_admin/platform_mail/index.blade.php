@extends('layouts.master')
@section('page_title', 'Sent Mail')
@section('content')
    {{-- Loop 106: rebuilt from the raw Limitless card onto the
         platform design scope (.l106) — same sends, columns,
         status badges and pagination. --}}
  <div class="l75-gw l106 l106-mail">
    <section class="l106-panel">
        <div class="l106-panel-head">
            <div>
                <h5><i class="icon-envelop5"></i> Sent Mail</h5>
                <p>Every platform mail send, with its real outcome</p>
            </div>
            <a href="{{ route('platform_mail.create') }}" class="btn btn-primary"><i class="icon-envelop5 mr-2"></i>Compose Mail</a>
        </div>
        @if($sent_mails->isEmpty())
            <div class="l106-empty">
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-envelop5',
                    'message' => 'No platform mail has been sent yet. Sends from the mail composer will appear here — delivered, log-recorded, or failed.',
                ])
            </div>
        @else
            <div class="table-responsive">
                <table class="l106-table">
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
                            <td class="l106-nowrap">{{ $mail->created_at->format('d M Y, h:i A') }}</td>
                            <td>{{ optional($mail->sender)->name ?: '—' }}</td>
                            <td>{{ $mail->audience_label }}</td>
                            <td>{{ $mail->recipient_count }}</td>
                            <td><strong>{{ $mail->subject }}</strong></td>
                            <td><code class="l106-code">{{ $mail->driver }}</code></td>
                            <td>
                                @if($mail->status === 'sent')
                                    <span class="l106-pill l106-pill-ok">Sent</span>
                                @elseif($mail->status === 'logged')
                                    <span class="l106-pill l106-pill-warn">Logged (not delivered)</span>
                                @else
                                    <span class="l106-pill l106-pill-bad" title="{{ $mail->error }}">Failed</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="l106-pager">{{ $sent_mails->links() }}</div>
        @endif
    </section>
  </div>
@endsection
