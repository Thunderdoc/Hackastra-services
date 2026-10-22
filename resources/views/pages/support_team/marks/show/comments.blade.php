@if(Qs::userIsTeamSAT())
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
@endif
