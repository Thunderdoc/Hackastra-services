{{-- Document design v2 (Workstream CY): the grading key + outcome
     counts as two disciplined strips in the report-card family
     (rc-legend / rc-counts). Same bands, same counts, same figures —
     the loose cellspacing table let ranges wrap mid-number. --}}
<table class="rc-legend">
    <tr>
        <td class="rc-legend-label">KEY TO THE GRADING</td>
        @if(Mk::getGradeList($class_type->id)->count())
            @foreach(Mk::getGradeList($class_type->id) as $gr)
                <td><strong>{{ $gr->name }}</strong> =&gt; {{ $gr->mark_from.' - '.$gr->mark_to }}</td>
            @endforeach
        @endif
    </tr>
</table>

<table class="rc-counts">
    <tr>
        <td><strong>NUMBER OF :</strong></td>
        <td><strong>Distinctions:</strong> {{ Mk::countDistinctions($marks) }}</td>
        <td><strong>Credits:</strong> {{ Mk::countCredits($marks) }}</td>
        <td><strong>Passes:</strong> {{ Mk::countPasses($marks) }}</td>
        <td><strong>Failures:</strong> {{ Mk::countFailures($marks) }}</td>
        <td><strong>Subjects Offered:</strong> {{ Mk::countSubjectsOffered($marks) }}</td>
    </tr>
</table>
</div>
