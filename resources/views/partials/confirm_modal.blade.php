{{-- Loop 37 — Styled confirmation modal (Workstream AY).
     ONE in-app confirmation dialog for the whole app, driven by
     public/assets/js/hackstra-ux.js: any element or form carrying a
     data-confirm="Message" attribute (optional data-confirm-title,
     data-confirm-action, data-confirm-danger) is confirmed here
     instead of the browser's native dialog. Page scripts can also
     call window.hackstraConfirm(message, opts) -> Promise<boolean>. --}}
<div class="modal fade hxc-modal" id="hackstra-confirm-modal" tabindex="-1" role="dialog" aria-labelledby="hxc-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered hxc-dialog" role="document">
        <div class="modal-content hxc-content">
            <button type="button" class="close hxc-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <div class="modal-body hxc-body">
                <div class="hxc-icon" id="hxc-icon"><i class="icon-question6" id="hxc-glyph"></i></div>
                <h5 class="hxc-title" id="hxc-title">Are you sure?</h5>
                <p class="hxc-message" id="hxc-message"></p>
                <div class="hxc-actions">
                    <button type="button" class="btn btn-light hxc-cancel" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary hxc-ok" id="hxc-ok">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
