{{-- Document design v2 (Workstream CY): fixed label column
     (rc-clabel, nowrap) so the labels never stack vertically the way
     the width-less table let them. Same texts, same defaults. --}}
<div>
    <table class="rc-comments">
        <tbody>
        <tr>
            <td class="rc-clabel">CLASS TEACHER'S COMMENT:</td>
            <td>{{ $exr->t_comment ?: str_repeat('__', 40) }}</td>
        </tr>
        <tr>
            <td class="rc-clabel">PRINCIPAL'S COMMENT:</td>
            <td>{{ $exr->p_comment ?: str_repeat('__', 40) }}</td>
        </tr>
        <tr>
            <td class="rc-clabel">NEXT TERM BEGINS:</td>
            <td>{{ date('l\, jS F\, Y', strtotime($s['term_begins'])) }}</td>
        </tr>
        <tr>
            <td class="rc-clabel">NEXT TERM FEES:</td>
            <td>&#8377;{{ $s['next_term_fees_'.strtolower($ct)] ?? '' }}</td>
        </tr>
        </tbody>
    </table>
</div>
