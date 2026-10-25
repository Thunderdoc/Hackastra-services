<script>

    function getLGA(state_id){
        var url = '{{ route('get_lga', [':id']) }}';
        url = url.replace(':id', state_id);
        var lga = $('#lga_id');

        $.ajax({
            dataType: 'json',
            url: url,
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

        $.ajax({
            dataType: 'json',
            url: url,
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

    function getClassSubjects(class_id){
        var url = '{{ route('get_class_subjects', [':id']) }}';
        url = url.replace(':id', class_id);
        var section = $('#section_id');
        var subject = $('#subject_id');

        $.ajax({
            dataType: 'json',
            url: url,
            success: function (resp) {
                section.empty();
                subject.empty();
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

    function confirmDelete(id) {
        swal({
            title: "Are you sure?",
            text: "Once deleted, you will not be able to recover this item!",
            icon: "warning",
            buttons: true,
            dangerMode: true
        }).then(function(willDelete){
            if (willDelete) {
             $('form#item-delete-'+id).submit();
            }
        });
    }

    function confirmReset(id) {
        swal({
            title: "Are you sure?",
            text: "This will reset this item to default state",
            icon: "warning",
            buttons: true,
            dangerMode: true
        }).then(function(willDelete){
            if (willDelete) {
             $('form#item-reset-'+id).submit();
            }
        });
    }

    $('form#ajax-reg').on('submit', function(ev){
        ev.preventDefault();
        submitForm($(this), 'store');
        $('#ajax-reg-t-0').get(0).click();
    });

    $('form.ajax-pay').on('submit', function(ev){
        ev.preventDefault();
        submitForm($(this), 'store');

//        Retrieve IDS
        var form_id = $(this).attr('id');
        var td_amt = $('td#amt-'+form_id);
        var td_amt_paid = $('td#amt_paid-'+form_id);
        var td_bal = $('td#bal-'+form_id);
        var input = $('#val-'+form_id);

        // Get Values
        var amt = parseInt(td_amt.data('amount'));
        var amt_paid = parseInt(td_amt_paid.data('amount'));
        var amt_input = parseInt(input.val());

//        Update Values
        amt_paid = amt_paid + amt_input;
        var bal = amt - amt_paid;

        td_bal.text(''+bal);
        td_amt_paid.text(''+amt_paid).data('amount', ''+amt_paid);
        input.attr('max', bal);
        bal < 1 ? $('#'+form_id).fadeOut('slow').remove() : '';
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
            data:new FormData(form[0])
        };
        var req = $.ajax(ajaxOptions);
        req.done(function(resp){
            resp.ok && resp.msg
               ? flash({msg:resp.msg, type:'success'})
               : flash({msg:resp.msg, type:'danger'});
            hideAjaxAlert();
            enableBtn(btn);
            formType == 'store' ? clearForm(form) : '';
            scrollTo('body');
            return resp;
        });
        req.fail(function(e){
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
    }

    function disableBtn(btn){
        var btnText = btn.data('text') ? btn.data('text') : 'Submitting';
        btn.prop('disabled', true).html('<i class="icon-spinner mr-2 spinner"></i>' + btnText);
    }

    function enableBtn(btn){
        var btnText = btn.data('text') ? btn.data('text') : 'Submit Form';
        btn.prop('disabled', false).html(btnText + '<i class="icon-paperplane ml-2"></i>');
    }

    function displayAjaxErr(errors){
        $('#ajax-alert').show().html(' <div class="alert alert-danger border-0 alert-dismissible" id="ajax-msg"><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
        $.each(errors, function(k, v){
            $('#ajax-msg').append('<span><i class="icon-arrow-right5"></i> '+ v +'</span><br/>');
        });
        scrollTo('body');
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
