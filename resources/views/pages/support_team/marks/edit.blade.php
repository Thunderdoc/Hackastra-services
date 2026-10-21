<form class="ajax-update" action="{{ route('marks.update', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" method="post">
    @csrf @method('put')

    @if($scheme['configured'])
        {{-- Batch M2: configured scheme — one input per component,
             headed by the component's name and its own max marks. --}}
        <div class="alert alert-info border-0">
            <span>This subject uses a custom marking scheme:
                <strong>{{ collect($scheme['components'])->map(function($c){ return $c['name'].' ('.$c['max_marks'].')'; })->implode(' + ') }} = {{ $scheme['max_total'] }}</strong>.
                Leave a cell blank if the score has not been entered; enter 0 for a real zero.</span>
        </div>
        <table class="table table-striped">
            <thead>
            <tr>
                <th>S/N</th>
                <th>Name</th>
                <th>ADM_NO</th>
                @foreach($scheme['components'] as $comp)
                    <th>{{ strtoupper($comp['name']) }} ({{ $comp['max_marks'] }})</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach($marks->sortBy('user.name') as $mk)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $mk->user->name }} </td>
                    <td>{{ $mk->user->student_record->adm_no }}</td>
                    @foreach($scheme['components'] as $comp)
                        <td><input title="{{ $comp['name'] }}" min="0" max="{{ $comp['max_marks'] }}" class="text-center" name="comp_{{ $mk->id }}_{{ $comp['id'] }}" value="{{ \App\Support\MarkScheme::scoreFor($mk, $comp) }}" type="number"></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        {{-- Legacy scheme — the grid exactly as it has always been
             (CA 20 + CA 20 + Exam 60 over t1/t2/exm). One change, from
             the Batch M2 audit: min is 0, not 1 — a real zero must be
             enterable, and a blank cell means "not entered". --}}
        <table class="table table-striped">
            <thead>
            <tr>
                <th>S/N</th>
                <th>Name</th>
                <th>ADM_NO</th>
                <th>1ST CA (20)</th>
                <th>2ND CA (20)</th>
                <th>EXAM (60)</th>
            </tr>
            </thead>
            <tbody>
            @foreach($marks->sortBy('user.name') as $mk)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $mk->user->name }} </td>
                    <td>{{ $mk->user->student_record->adm_no }}</td>

    {{--                CA AND EXAMS --}}
                    <td><input title="1ST CA" min="0" max="20" class="text-center" name="t1_{{ $mk->id }}" value="{{ $mk->t1 }}" type="number"></td>
                    <td><input title="2ND CA" min="0" max="20" class="text-center" name="t2_{{ $mk->id }}" value="{{ $mk->t2 }}" type="number"></td>
                    <td><input title="EXAM" min="0" max="60" class="text-center" name="exm_{{ $mk->id }}" value="{{ $mk->exm }}" type="number"></td>

                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="text-center mt-2">
        <button type="submit" class="btn btn-primary">Update Marks <i class="icon-paperplane ml-2"></i></button>
    </div>
</form>
