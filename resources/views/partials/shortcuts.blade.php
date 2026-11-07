{{--
    partials/shortcuts — the keyboard-shortcuts help overlay and the
    per-role navigation registry (Round 5 Slice 1, P13).

    Included once from layouts/master, next to the confirm modal.
    The keydown handler itself lives in hackstra-ux.js (the
    registry); this partial renders (a) the navigation entries THIS
    user may actually open — gated by the same role rules as the
    menus, so a shortcut never leads somewhere the role cannot go —
    into window.hkShortcutNav, and (b) the help overlay listing
    exactly those bindings. Open it with "?" anywhere, or from any
    .hk-shortcuts-open button (My Account → Preferences).
--}}
@php
    $hkNav = [
        ['key' => 'd', 'label' => 'Go to Dashboard', 'url' => route('dashboard')],
        ['key' => 'm', 'label' => 'Go to My Account', 'url' => route('my_account')],
    ];
    if (Auth::check() && in_array(Auth::user()->user_type, ['admin', 'teacher', 'principal'], true)) {
        $hkNav[] = ['key' => 'a', 'label' => 'Go to Period Attendance', 'url' => route('attendance.periods.index')];
    }
    if (Auth::check() && Auth::user()->user_type === 'teacher') {
        $hkNav[] = ['key' => 't', 'label' => 'Go to My Timetable', 'url' => route('tt.mine')];
    }
@endphp
<script>
    window.hkShortcutNav = @json($hkNav);
</script>
<div class="modal fade" id="hk-shortcuts-modal" tabindex="-1" role="dialog" aria-labelledby="hk-shortcuts-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="hk-shortcuts-title"><i class="icon-keyboard mr-2"></i>Keyboard shortcuts</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
<div class="hk-shortcuts-list">
                    <div class="hk-shortcuts-row"><span class="hk-shortcuts-key"><kbd>Ctrl</kbd> + <kbd>K</kbd></span><span>Open command palette</span></div>
                    <div class="hk-shortcuts-row"><span class="hk-shortcuts-key"><kbd>?</kbd></span><span>Show this list</span></div>
                    <div class="hk-shortcuts-row"><span class="hk-shortcuts-key"><kbd>Ctrl</kbd> + <kbd>S</kbd></span><span>Save (on the marks-entry and attendance pages)</span></div>
                    @foreach($hkNav as $hkEntry)
                        <div class="hk-shortcuts-row"><span class="hk-shortcuts-key"><kbd>{{ strtoupper($hkEntry['key']) }}</kbd></span><span>{{ $hkEntry['label'] }}</span></div>
                    @endforeach
                    <div class="hk-shortcuts-row"><span class="hk-shortcuts-key"><kbd>Esc</kbd></span><span>Close a dialog</span></div>
                </div>
                <p class="hk-shortcuts-note">Shortcuts never fire while you are typing in a field, and the navigation keys pause while a dialog is open.</p>
            </div>
        </div>
    </div>
</div>
<script>
    window.hkShowShortcuts = function () {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery('#hk-shortcuts-modal').modal('show');
        }
    };
    document.addEventListener('click', function (event) {
        var opener = event.target && event.target.closest ? event.target.closest('.hk-shortcuts-open') : null;
        if (opener) { event.preventDefault(); window.hkShowShortcuts(); }
    });
</script>
