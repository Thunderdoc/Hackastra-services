<script>

    /* ROUND 5 SLICE 1 (P2/P20) — cascade selects get a visible
       Loading state while their options are in flight, and a failed
       fetch surfaces through the shared error floor (hkAjaxError,
       defined below) instead of silently leaving stale options.
       hkSilent keeps the global ajaxError floor from reporting the
       same failure twice. */
    function hkCascadeError(xhr, targets) {
        if (window.hkAjaxError) { window.hkAjaxError(xhr); }
        (targets || []).forEach(function (sel) {
            if (sel && sel.length && !sel.find('option.hk-load-failed').length) {
                sel.prepend('<option value="" class="hk-load-failed" disabled>Couldn\'t load — try again</option>');
            }
        });
    }

    function getLGA(state_id){
        var url = '{{ route('get_lga', [':id']) }}';
        url = url.replace(':id', state_id);
        var lga = $('#lga_id');

        hkSelectLoading(lga, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [lga]); },
            complete: function () { hkSelectLoading(lga, false); },
            success: function (resp) {

                lga.empty();
                $.each(resp, function (i, data) {
                    lga.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                lga.trigger('change');

            }
        })
    }

    function getClassSections(class_id, destination){
        var url = '{{ route('get_class_sections', [':id']) }}';
        url = url.replace(':id', class_id);
        var section = destination ? $(destination) : $('#section_id');

        hkSelectLoading(section, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [section]); },
            complete: function () { hkSelectLoading(section, false); },
            success: function (resp) {

                // Site-wide cascade (owner ask 2026-10-25): keep a
                // leading "no section" option when the select has
                // one ("All Sections" / "Whole class") — the old
                // rebuild wiped it, leaving no way back to all.
                var keep = section.find('option[value=""]').first().clone();
                section.empty();
                if (keep.length) { section.append(keep); }
                $.each(resp, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');

            }
        })
    }

    /* Scoped variant (owner, 2026-11-01): same fill contract, but
       the feed is asked for its opt-in effective mode — a teacher
       is offered only the sections she effectively teaches in.
       Used by the LMS create forms (assignments, materials) whose
       stores enforce exactly that scope. */
    function getClassSectionsTabulation(class_id, destination){
        var url = '{{ route('get_class_sections', [':id']) }}';
        url = url.replace(':id', class_id) + '?scope=tabulation';
        var section = destination ? $(destination) : $('#section_id');

        hkSelectLoading(section, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [section]); },
            complete: function () { hkSelectLoading(section, false); },
            success: function (resp) {
                var keep = section.find('option[value=""]').first().clone();
                section.empty();
                if (keep.length) { section.append(keep); }
                $.each(resp, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');
            }
        });
    }

    function getClassSectionsScoped(class_id, destination){
        var url = '{{ route('get_class_sections', [':id']) }}';
        url = url.replace(':id', class_id) + '?effective=1';
        var section = destination ? $(destination) : $('#section_id');

        hkSelectLoading(section, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [section]); },
            complete: function () { hkSelectLoading(section, false); },
            success: function (resp) {
                var keep = section.find('option[value=""]').first().clone();
                section.empty();
                if (keep.length) { section.append(keep); }
                $.each(resp, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');

            }
        })
    }

    function getClassSubjectsScoped(class_id){
        var url = '{{ route('get_class_subjects', [':id']) }}';
        url = url.replace(':id', class_id) + '?effective=1';
        var section = $('#section_id');
        var subject = $('#subject_id');

        hkSelectLoading(section, true);
        hkSelectLoading(subject, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [section, subject]); },
            complete: function () { hkSelectLoading(section, false); hkSelectLoading(subject, false); },
            success: function (resp) {
                var keepSection = section.find('option[value=""]').first().clone();
                var keepSubject = subject.find('option[value=""]').first().clone();
                section.empty();
                subject.empty();
                if (keepSection.length) { section.append(keepSection); }
                if (keepSubject.length) { subject.append(keepSubject); }
                $.each(resp.sections, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                $.each(resp.subjects, function (i, data) {
                    subject.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');
                subject.trigger('change');

            }
        })
    }

    function getClassSubjects(class_id){
        var url = '{{ route('get_class_subjects', [':id']) }}';
        url = url.replace(':id', class_id);
        var section = $('#section_id');
        var subject = $('#subject_id');

        hkSelectLoading(section, true);
        hkSelectLoading(subject, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            error: function (xhr) { hkCascadeError(xhr, [section, subject]); },
            complete: function () { hkSelectLoading(section, false); hkSelectLoading(subject, false); },
            success: function (resp) {
                // Same leading-option rule as getClassSections: keep a
                // "no section" / "select subject" option when the
                // select has one — the old rebuild wiped both, leaving
                // the first real option silently selected.
                var keepSection = section.find('option[value=""]').first().clone();
                var keepSubject = subject.find('option[value=""]').first().clone();
                section.empty();
                subject.empty();
                if (keepSection.length) { section.append(keepSection); }
                if (keepSubject.length) { subject.append(keepSubject); }
                $.each(resp.sections, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                $.each(resp.subjects, function (i, data) {
                    subject.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');
                subject.trigger('change');

            }
        })
    }

    /* Marks selector feed (owner item 4, 2026-11-01): same fill
       contract as getClassSubjects, but served by the marks
       selector's own endpoint, which scopes a teacher's sections
       and subjects to her effective units. Other pages keep using
       getClassSubjects untouched. */
    function getMarkClassSubjects(class_id){
        var url = '{{ route('marks.selector_options') }}';
        var section = $('#section_id');
        var subject = $('#subject_id');

        hkSelectLoading(section, true);
        hkSelectLoading(subject, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: url,
            data: { class_id: class_id },
            error: function (xhr) { hkCascadeError(xhr, [section, subject]); },
            complete: function () { hkSelectLoading(section, false); hkSelectLoading(subject, false); },
            success: function (resp) {
                var keepSection = section.find('option[value=""]').first().clone();
                var keepSubject = subject.find('option[value=""]').first().clone();
                section.empty();
                subject.empty();
                if (keepSection.length) { section.append(keepSection); }
                if (keepSubject.length) { subject.append(keepSubject); }
                $.each(resp.sections, function (i, data) {
                    section.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                $.each(resp.subjects, function (i, data) {
                    subject.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                section.trigger('change');
                subject.trigger('change');

            }
        })
    }

    /* Marks selector: a section was picked — narrow the subjects
       to the ones the caller effectively teaches in THAT section
       (the endpoint returns the full class list for non-teachers,
       so their picker is unchanged). A still-offered current
       subject survives the refill. */
    function refineMarkSubjects(){
        var class_id = $('#my_class_id').val();
        var section = $('#section_id');
        var subject = $('#subject_id');
        if (!class_id || !section.val() || !subject.length) { return; }
        var current = subject.val();

        hkSelectLoading(subject, true);
        $.ajax({
            dataType: 'json',
            hkSilent: true,
            url: '{{ route('marks.selector_options') }}',
            data: { class_id: class_id, section_id: section.val() },
            error: function (xhr) { hkCascadeError(xhr, [subject]); },
            complete: function () { hkSelectLoading(subject, false); },
            success: function (resp) {
                var keepSubject = subject.find('option[value=""]').first().clone();
                subject.empty();
                if (keepSubject.length) { subject.append(keepSubject); }
                $.each(resp.subjects, function (i, data) {
                    subject.append($('<option>', {
                        value: data.id,
                        text: data.name
                    }));
                });
                if (current && subject.find('option[value="' + current + '"]').length) {
                    subject.val(current);
                }
                subject.trigger('change');
            }
        })
    }

    /* Wave 8 — exam picker filtered by class: a class-scoped exam
       (wave 2) belongs to exactly one class, so once a class is
       picked the exam dropdown keeps only global exams (scope 0)
       and that class's exams. Options are rebuilt from a cached
       clone (Select2 reads options on open, so hiding them is not
       reliable); the server guard in MarkController is the real
       enforcement — this only stops the mis-pick at the UI. */
    function filterExamsByClass(class_id){
        var exam = $('#exam_id');
        if (!exam.length) return;
        if (!exam.data('allOptions')) { exam.data('allOptions', exam.find('option').clone()); }
        var all = exam.data('allOptions');
        var current = exam.val();
        exam.empty();
        all.each(function(){
            var scope = $(this).attr('data-scope');
            if (scope === undefined || scope === '' || scope === '0' || String(scope) === String(class_id)) {
                exam.append($(this).clone());
            }
        });
        if (exam.find('option[value="' + current + '"]').length) {
            exam.val(current);
        } else {
            exam.prop('selectedIndex', 0);
        }
        exam.trigger('change');
    }

    /* Manage page loads with a class preselected: apply the same
       filter once. Landing page (no class yet) keeps all exams. */
    $(function(){
        var cls = $('#my_class_id');
        if (cls.length && cls.val()) { filterExamsByClass(cls.val()); }
    });


    {{--Notifications--}}

    @if (session('pop_error'))
    pop({msg : '{{ session('pop_error') }}', type : 'error'});
    @endif

    @if (session('pop_warning'))
    pop({msg : '{{ session('pop_warning') }}', type : 'warning'});
    @endif

 @if (session('pop_success'))
    pop({msg : '{{ session('pop_success') }}', type : 'success', title: 'GREAT!!'});
    @endif

    @if (session('flash_info'))
      flash({msg : '{{ session('flash_info') }}', type : 'info'});
    @endif

    @if (session('flash_success'))
      flash({msg : '{{ session('flash_success') }}', type : 'success'});
    @endif

    @if (session('flash_warning'))
      flash({msg : '{{ session('flash_warning') }}', type : 'warning'});
    @endif

     @if (session('flash_error') || session('flash_danger'))
      flash({msg : '{{ session('flash_error') ?: session('flash_danger') }}', type : 'danger'});
    @endif

    {{--End Notifications--}}

    function pop(data){
        swal({
            title: data.title ? data.title : 'Oops...',
            text: data.msg,
            icon: data.type
        });
    }

    /* Loop 75 — calm inline confirmations (owner directive
       2026-10-22: the floating PNotify corner popups are gone).
       flash() keeps its exact call contract ({msg, type}); only
       the presentation changed: ONE slim banner docked at the top
       of the content wrapper, directly under the topbar. A new
       message replaces the current one — nothing ever stacks.
       success/info fade away after ~4s; warning/danger stay until
       the user closes them. */
    var l75FlashTimer = null;
    function flash(data){
        var type = data.type || 'info';
        if (type === 'error') { type = 'danger'; }
        if (['success', 'info', 'warning', 'danger'].indexOf(type) === -1) { type = 'info'; }
        var icons = {
            success: 'icon-checkmark2',
            info: 'icon-info22',
            warning: 'icon-warning2',
            danger: 'icon-info3'
        };
        var dock = document.getElementById('l75-flash-dock');
        if (!dock) {
            dock = document.createElement('div');
            dock.id = 'l75-flash-dock';
            var wrap = document.querySelector('.content-wrapper');
            if (wrap) { wrap.insertBefore(dock, wrap.firstChild); }
            else { document.body.appendChild(dock); }
        }
        if (l75FlashTimer) { clearTimeout(l75FlashTimer); l75FlashTimer = null; }
        dock.className = 'l75-flash-dock l75-flash-' + type;
        dock.setAttribute('role', 'status');
        dock.innerHTML = '';
        var icon = document.createElement('i');
        icon.className = icons[type];
        icon.setAttribute('aria-hidden', 'true');
        var msg = document.createElement('span');
        msg.className = 'l75-flash-msg';
        msg.textContent = data.msg;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'l75-flash-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { l75FlashHide(dock); });
        dock.appendChild(icon);
        dock.appendChild(msg);
        dock.appendChild(close);
        dock.hidden = false;
        // Next frame: ensure the entrance is a gentle fade-in.
        dock.classList.add('is-out');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { dock.classList.remove('is-out'); });
        });
        if (type === 'success' || type === 'info') {
            l75FlashTimer = setTimeout(function () { l75FlashHide(dock); }, 4000);
        }
    }

    function l75FlashHide(dock){
        if (l75FlashTimer) { clearTimeout(l75FlashTimer); l75FlashTimer = null; }
        dock.classList.add('is-out');
        setTimeout(function () { dock.hidden = true; }, 360);
    }

    /* Round 5 Slice 2 (P19) — confirmDelete / confirmReset are now
       thin bridges over the ONE shared confirmation modal
       (window.hackstraConfirm in hackstra-ux.js); the old SweetAlert
       prompts are gone. The hidden-form mechanics are unchanged:
       the same form#item-delete-{id} / form#item-reset-{id} is
       submitted, and only after the user confirms. hackstraConfirm
       fails safe (it resolves false when the modal is unavailable),
       and if it is missing entirely nothing is submitted either.
       The optional second argument is the record's display name:
       with a name the prompt names the record ("Delete <name>?
       This cannot be undone."); without one it stays generic. */
    function confirmDelete(id, name) {
        if (typeof window.hackstraConfirm !== 'function') { return; }
        var message = name ? 'Delete ' + name + '? This cannot be undone.'
                           : 'Delete this item? This cannot be undone.';
        window.hackstraConfirm(message, {title: 'Delete', action: 'Delete', danger: true}).then(function (ok) {
            if (ok) { $('form#item-delete-'+id).submit(); }
        });
    }

    function confirmReset(id, name) {
        if (typeof window.hackstraConfirm !== 'function') { return; }
        var message = name ? 'Reset ' + name + '? This cannot be undone.'
                           : 'Reset this item? This cannot be undone.';
        window.hackstraConfirm(message, {title: 'Reset', action: 'Reset', danger: true}).then(function (ok) {
            if (ok) { $('form#item-reset-'+id).submit(); }
        });
    }

    $('form#ajax-reg').on('submit', function(ev){
        ev.preventDefault();
        submitForm($(this), 'store');
        $('#ajax-reg-t-0').get(0).click();
    });

    /**** ROUND 5 SLICE 1 — P1: the payment row updates through
         hkOptimistic ONLY. The old handler fired the request and
         immediately rewrote the money cells, never looking back:
         a failed request left fees shown as paid that were never
         recorded. Now the pre-submit values are snapshotted, the
         request's real result decides, any failure restores the
         cells exactly and says so, and the row leaves the list
         only after the server confirms the payment. ****/
    $('form.ajax-pay').on('submit', function(ev){
        ev.preventDefault();
        var $form = $(this), form_id = $form.attr('id');
        var td_amt_paid = $('td#amt_paid-'+form_id);
        var td_bal = $('td#bal-'+form_id);
        var input = $('#val-'+form_id);
        var amt = parseInt($('td#amt-'+form_id).data('amount'));
        var before = {
            paid: parseInt(td_amt_paid.data('amount')),
            paidText: td_amt_paid.text(),
            balText: td_bal.text(),
            inputVal: input.val(),
            inputMax: input.attr('max')
        };
        var amt_paid = before.paid + parseInt(input.val());
        var bal = amt - amt_paid;
        window.hkOptimistic({
            apply: function(){
                td_bal.text(''+bal);
                td_amt_paid.text(''+amt_paid).data('amount', ''+amt_paid);
                input.attr('max', bal);
            },
            request: function(){ return submitForm($form, 'store'); },
            isOk: function(resp){ return !!resp && resp.ok !== false; },
            revert: function(){
                td_bal.text(before.balText);
                td_amt_paid.text(before.paidText).data('amount', ''+before.paid);
                input.attr('max', before.inputMax);
                if (input.val() === '') { input.val(before.inputVal); }
            },
            onError: function(){
                flash({msg: 'That payment was not recorded — the amounts on screen have been put back as they were.', type: 'warning'});
            },
            onSuccess: function(){
                if (bal < 1) { $('#'+form_id).fadeOut('slow').remove(); }
            }
        });
    });

    $('form.ajax-store').on('submit', function(ev){
        ev.preventDefault();
        submitForm($(this), 'store');
        var div = $(this).data('reload');
        div ? reloadDiv(div) : '';
    });

    $('form.ajax-update').on('submit', function(ev){
        ev.preventDefault();
        submitForm($(this));
        var div = $(this).data('reload');
        div ? reloadDiv(div) : '';
    });

    $('.download-receipt').on('click', function(ev){
        ev.preventDefault();
        $.get($(this).attr('href'));
        flash({msg : '{{ 'Download in Progress' }}', type : 'info'});
    });

    function reloadDiv(div){
        var url = window.location.href;
        url = url + ' '+ div;
        $(div).load( url );
    }

    /**** ROUND 5 SLICE 1 — P20: the shared AJAX error floor.
         A failed request must never fail silently:
           419 — session expired: say so, offer "Sign in again", and
                 stash the page's form entries in this tab first so
                 the round trip does not cost the user their typing
                 (the report builder's own draft already lives in
                 localStorage and is untouched by a failed POST).
           429 — too many requests: say so, honouring Retry-After.
           0   — offline / no response: say so; nothing was saved.
         submitForm() routes its previously-silent statuses here,
         the cascade helpers share it, and a global ajaxError floor
         catches any jQuery request a page forgot to handle. Fetch
         callers pass the Response (or the TypeError) straight in. */
    window.hkLoginUrl = '{{ route('login') }}';
    var hkSessionExpiredShown = false;

    function hkRetryAfterSeconds(source) {
        try {
            var header = null;
            if (source && typeof source.getResponseHeader === 'function') {
                header = source.getResponseHeader('Retry-After');
            } else if (source && source.headers && typeof source.headers.get === 'function') {
                header = source.headers.get('Retry-After');
            }
            if (!header) { return null; }
            header = String(header).trim();
            if (/^\d+$/.test(header)) { return parseInt(header, 10); }
            var when = Date.parse(header);
            if (!isNaN(when)) { return Math.max(0, Math.round((when - Date.now()) / 1000)); }
        } catch (e) { /* fall through */ }
        return null;
    }

    function hkFormDraftKey(form) {
        return 'hk-form-draft:' + (form.id ? '#' + form.id : (form.getAttribute('action') || 'form'));
    }

    /* Stash the entries of every ajax form on the page into this
       tab's sessionStorage. File inputs and the CSRF token are
       never stored. */
    window.hkStashFormDrafts = function () {
        try {
            document.querySelectorAll('form.ajax-pay, form.ajax-store, form.ajax-update, form#ajax-reg').forEach(function (form) {
                var data = {}, filled = false;
                new FormData(form).forEach(function (value, key) {
                    if (typeof value === 'string' && key !== '_token') { data[key] = value; filled = true; }
                });
                if (filled) { sessionStorage.setItem(hkFormDraftKey(form), JSON.stringify(data)); }
            });
        } catch (e) { /* storage unavailable — nothing to preserve */ }
    };

    window.hkClearFormDraft = function (form) {
        try { sessionStorage.removeItem(hkFormDraftKey(form)); } catch (e) { /* ignore */ }
    };

    function hkRestoreFormDrafts() {
        var restored = false;
        try {
            document.querySelectorAll('form.ajax-pay, form.ajax-store, form.ajax-update, form#ajax-reg').forEach(function (form) {
                var raw = sessionStorage.getItem(hkFormDraftKey(form));
                if (!raw) { return; }
                sessionStorage.removeItem(hkFormDraftKey(form));
                var data = JSON.parse(raw);
                Object.keys(data).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    if (field && field.type !== 'file' && field.value !== undefined && !field.disabled) {
                        field.value = data[name];
                        restored = true;
                    }
                });
            });
        } catch (e) { /* ignore */ }
        if (restored) {
            flash({msg: 'Your earlier entries were restored — please check them before saving.', type: 'info'});
        }
    }

    window.hkAjaxError = function (err, opts) {
        opts = opts || {};
        var status = (err && typeof err.status === 'number') ? err.status : 0;
        if (opts.message) {
            flash({msg: opts.message, type: opts.type || 'danger'});
            return status;
        }
        if (status === 419) {
            window.hkStashFormDrafts();
            if (!hkSessionExpiredShown) {
                hkSessionExpiredShown = true;
                var expiredMsg = 'Your session has expired — this page was open for a long time. Sign in again to continue; your entries on this page are kept in this tab.';
                if (window.hackstraConfirm) {
                    window.hackstraConfirm(expiredMsg, {title: 'Session expired', action: 'Sign in again'}).then(function (ok) {
                        if (ok) { window.location.href = window.hkLoginUrl || '/login'; }
                        else { hkSessionExpiredShown = false; flash({msg: expiredMsg, type: 'danger'}); }
                    });
                } else {
                    flash({msg: expiredMsg, type: 'danger'});
                }
            }
            return status;
        }
        if (status === 429) {
            var wait = hkRetryAfterSeconds(err);
            flash({msg: 'Too many requests just now — please wait' + (wait ? ' about ' + wait + ' seconds' : ' a moment') + ' and try again. Nothing was sent twice.', type: 'warning'});
            return status;
        }
        if (status === 0) {
            flash({msg: 'Connection problem — this device appears to be offline, or the school server did not respond. That action was not saved; check the connection and try again.', type: 'danger'});
            return status;
        }
        if (status >= 500) {
            flash({msg: 'Something went wrong on the server. Please try again — if it keeps happening, tell the school office.', type: 'danger'});
            return status;
        }
        if (status === 404) {
            flash({msg: 'That item could not be found — it may have been removed already. Please refresh the page.', type: 'warning'});
            return status;
        }
        flash({msg: 'That request did not go through (error ' + status + '). Please try again.', type: 'danger'});
        return status;
    };

    /* The floor itself: any jQuery failure without its own handling
       still surfaces. Callers that handle statuses themselves set
       hkSilent (the cascades) or hkFloorHandled (submitForm — the
       floor then covers only its silent statuses: 419, 429, 0). */
    $(document).ajaxError(function (event, xhr, settings) {
        if (!xhr || !settings || settings.hkSilent) { return; }
        if (settings.hkFloorHandled && [419, 429, 0].indexOf(xhr.status) === -1) { return; }
        window.hkAjaxError(xhr);
    });

    $(function () { hkRestoreFormDrafts(); });

    function submitForm(form, formType){
        var btn = form.find('button[type=submit]');
        disableBtn(btn);
        var ajaxOptions = {
            url:form.attr('action'),
            type:'POST',
            cache:false,
            processData:false,
            dataType:'json',
            contentType:false,
            hkFloorHandled:true,
            data:new FormData(form[0])
        };
        var req = $.ajax(ajaxOptions);
        req.done(function(resp){
            resp.ok && resp.msg
               ? flash({msg:resp.msg, type:'success'})
               : flash({msg:resp.msg, type:'danger'});
            hideAjaxAlert();
            if (resp.ok && window.hkClearFormDraft) { window.hkClearFormDraft(form[0]); }
            enableBtn(btn);
            formType == 'store' ? clearForm(form) : '';
            scrollTo('body');
            return resp;
        });
        req.fail(function(e){
            if (e.status == 419 || e.status == 429 || e.status == 0){
                window.hkAjaxError(e);
            }
            if (e.status == 422){
                var errors = e.responseJSON.errors;
                displayAjaxErr(errors);
            }
           if(e.status == 500){
               displayAjaxErr([e.status + ' ' + e.statusText + ' Please Check for Duplicate entry or Contact School Administrator/IT Personnel'])
           }
            if(e.status == 404){
               displayAjaxErr([e.status + ' ' + e.statusText + ' - Requested Resource or Record Not Found'])
           }
            enableBtn(btn);
            return e.status;
        });
        return req;
    }

    function disableBtn(btn){
        var btnText = btn.data('text') ? btn.data('text') : 'Submitting';
        btn.prop('disabled', true).html('<i class="icon-spinner mr-2 spinner"></i>' + btnText);
    }

    function enableBtn(btn){
        var btnText = btn.data('text') ? btn.data('text') : 'Submit Form';
        btn.prop('disabled', false).html(btnText + '<i class="icon-paperplane ml-2"></i>');
    }

    /* Round 5 Slice 2 (P9): a keyed 422 error also lands under its
       own field, not only in the top list. Array-style messages
       (numeric keys, e.g. the 500/404 strings) keep the top list
       only. The slot is the field's field-error span — the one the
       shared errors field partial renders — or one created here;
       the input gains is-invalid and the first errored field is
       scrolled into view. */
    function hkAjaxFieldSlot(input){
        var anchor = input;
        if (input.parentNode && input.parentNode.classList && input.parentNode.classList.contains('hkdp-wrap')) { anchor = input.parentNode; }
        var n = anchor.nextElementSibling;
        while (n) {
            if (n.classList && n.classList.contains('field-error')) { return n; }
            if (n.tagName === 'INPUT' || n.tagName === 'SELECT' || n.tagName === 'TEXTAREA') { break; }
            n = n.nextElementSibling;
        }
        var box = input.closest ? (input.closest('.form-group, .pat-field, .form-row') || input.parentNode) : input.parentNode;
        if (box && box.querySelectorAll && box.querySelectorAll('input[name], select[name], textarea[name]').length <= 1) {
            var existing = box.querySelector('.field-error');
            if (existing) { return existing; }
        }
        var span = document.createElement('span');
        span.className = 'field-error';
        span.setAttribute('role', 'alert');
        anchor.parentNode.insertBefore(span, anchor.nextSibling);
        return span;
    }

    function hkMapAjaxErrorsToFields(errors){
        var firstEl = null;
        $.each(errors, function (field, messages) {
            if (typeof field !== 'string' || /^\d+$/.test(field)) { return; }
            var base = field.split('.')[0];
            var msg = $.isArray(messages) ? messages[0] : messages;
            if (msg === undefined || msg === null) { return; }
            var input = document.querySelector('[name="' + base + '"]')
                || document.querySelector('[name^="' + base + '["]')
                || document.getElementById(base);
            if (!input) { return; }
            var slot = hkAjaxFieldSlot(input);
            if (slot) { slot.textContent = String(msg); }
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            if (!firstEl) { firstEl = input; }
        });
        if (firstEl && firstEl.scrollIntoView) {
            firstEl.scrollIntoView({block: 'center', behavior: 'smooth'});
        }
        return firstEl;
    }

    function displayAjaxErr(errors){
        $('#ajax-alert').show().html(' <div class="alert alert-danger border-0 alert-dismissible" id="ajax-msg"><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
        $.each(errors, function(k, v){
            $('#ajax-msg').append('<span><i class="icon-arrow-right5"></i> '+ v +'</span><br/>');
        });
        var firstField = (errors && typeof errors === 'object' && !$.isArray(errors)) ? hkMapAjaxErrorsToFields(errors) : null;
        if (!firstField) { scrollTo('body'); }
    }

    function scrollTo(el){
        $('html, body').animate({
            scrollTop:$(el).offset().top
        }, 2000);
    }

    function hideAjaxAlert(){
        $('#ajax-alert').hide();
    }

    function clearForm(form){
        form.find('.select, .select-search').val([]).select2({ placeholder: 'Select...'});
        form[0].reset();
    }



</script>
<script>
/* ==== Site-wide Select2 (2026-10-24) ================================
   Every plain <select> becomes the template's styled Select2 dropdown,
   so no page is left with the browser's native list. The demo init
   (form_select2.js) already covers .select/.select-search; this only
   touches selects with no Select2 yet. Excluded: DataTables page
   length menus, [data-native] opt-outs, .no-select2 subtrees. */
(function ($) {
    if (!$ || !$.fn || !$.fn.select2) { return; }

    function hkInitSelects(scope) {
        $(scope).find('select').addBack('select').each(function () {
            var $sel = $(this);
            if ($sel.data('select2')) { return; }
            if ($sel.closest('.dataTables_length').length) { return; }
            if ($sel.is('[data-native]')) { return; }
            if ($sel.closest('.no-select2').length) { return; }
            var hasEmpty = $sel.find('option[value=""]').length > 0;
            var hasPlaceholder = !!$sel.data('placeholder');
            var opts = {
                width: '100%',
                placeholder: $sel.data('placeholder') || 'Select...',
                allowClear: hasEmpty || hasPlaceholder
            };
            var modal = $sel.closest('.modal');
            if (modal.length) { opts.dropdownParent = modal; }
            if ($sel.find('option').length <= 6) { opts.minimumResultsForSearch = Infinity; }
            try { $sel.select2(opts); } catch (e) { /* leave native */ }
        });
    }
    window.hkInitSelects = hkInitSelects;

    $(function () { hkInitSelects(document); });

    // Selects hidden inside modals until open: init on show.
    $(document).on('shown.bs.modal', '.modal', function () { hkInitSelects(this); });

    // AJAX-injected selects (rows, partial reloads).
    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { hkInitSelects(node); }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})(window.jQuery);
</script>
