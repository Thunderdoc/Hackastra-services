@if(Qs::userIsTeamSATWrite())
    <div class="card">
        <div class="card-header header-elements-inline marks-section-header">
            <h6 class="card-title font-weight-bold">Exam Comments</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body collapse">
            {{-- Workstream BW: fields moved onto the pattern grid
                 (pat-field-wide rows). Same .ajax-update PUT to
                 marks.comment_update, same fields and gates; the
                 collapsible card shell is unchanged. --}}
            <form class="ajax-update" method="post" action="{{ route('marks.comment_update', $exr->id) }}">
                @csrf @method('PUT')

                <div class="pat-fields">
                    @if(Qs::userIsTeamSAT())
                        <div class="pat-field pat-field-wide">
                            <label for="t_comment">Teacher's Comment</label>
                            <input id="t_comment" name="t_comment" value="{{ $exr->t_comment }}" type="text" class="form-control" placeholder="Teacher's Comment">
                            @if(\App\Services\AiAssist::enabled())
                                {{-- Workstream CX (Assist 2): AI remark from the student's real computed subject totals for this exam. Fills the field above only — the teacher edits and submits this same form to save. --}}
                                <div class="mt-2">
                                    <button type="button" class="btn btn-info btn-sm ai-comment-btn"
                                            data-student="{{ $exr->student_id }}" data-exam="{{ $exr->exam_id }}" data-year="{{ $exr->year }}">
                                        Generate with AI <i class="icon-magic-wand ml-1"></i>
                                    </button>
                                    <span class="ai-comment-status ml-2 text-muted"></span>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if(Qs::userIsTeamSA())
                        <div class="pat-field pat-field-wide">
                            <label for="p_comment">Head Teacher's Comment</label>
                            <input id="p_comment" name="p_comment" value="{{ $exr->p_comment }}" type="text" class="form-control" placeholder="Head Teacher's Comment">
                        </div>
                    @endif
                </div>

                <div class="text-right">
                    <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        </div>
    </div>
    @if(\App\Services\AiAssist::enabled())
    <script>
        if (!window.__aiCommentWired) {
            window.__aiCommentWired = true;
            document.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.ai-comment-btn') : null;
                if (!btn) return;
                var form = btn.closest('form');
                var field = form ? form.querySelector('[name="t_comment"]') : null;
                var status = btn.parentElement.querySelector('.ai-comment-status');
                if (!field) return;
                var meta = document.querySelector('meta[name="csrf-token"]');
                var body = 'student_id=' + encodeURIComponent(btn.getAttribute('data-student'))
                    + '&exam_id=' + encodeURIComponent(btn.getAttribute('data-exam'))
                    + '&year=' + encodeURIComponent(btn.getAttribute('data-year'));
                btn.disabled = true;
                if (status) status.textContent = 'Generating…';
                fetch('{{ route('ai.report_comment') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '', 'Accept': 'application/json' },
                    body: body
                }).then(function (r) {
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                }).then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) { if (status) status.textContent = res.data.message || 'AI is unavailable right now.'; return; }
                    field.value = res.data.comment;
                    if (status) status.textContent = 'Draft ready — edit it if you like, then Submit.';
                }).catch(function () {
                    btn.disabled = false;
                    if (status) status.textContent = 'AI is unavailable right now. Please try again later.';
                });
            });
        }
    </script>
    @endif
@endif
