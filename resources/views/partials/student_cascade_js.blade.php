{{-- Student-picker cascade (owner, 2026-11-01): Class > Section >
     Student on every picker form, replacing flat dropdowns of all
     3,041 students. The class/section selects are FILTER-ONLY —
     the student select keeps its original name and stays the one
     submitted truth; store logic is untouched.

     Markup contract (one cascade per form):
       <select class="hk-cascade-class">…classes…</select>
       <select class="hk-cascade-section"></select>
       <select name="…" class="hk-cascade-student"
               data-value-key="id|user_id"
               [data-preselect-class data-preselect-section
                data-preselect-value data-preselect-label]>
     Sections come from the existing get_class_subjects feed;
     students from get_section_students (active only, name order,
     teacher-scoped server-side).

     Opt-in type-to-search mode (owner, 2026-11-08): a student
     select carrying data-student-search="remote" becomes a
     Select2 remote search over the SAME get_section_students
     feed (which applies the ?q= filter server-side) instead of
     loading the section's whole roster into the dropdown. The
     search is bound to the currently chosen section; changing
     class or section rebuilds it and clears the picked student.
     [data-search-placeholder] overrides the search placeholder. --}}
<script>
(function ($) {
    var SECTIONS_URL = @json(route('get_class_subjects', ['class_id' => 'HKCLASS']));
    var STUDENTS_URL = @json(route('get_section_students', ['section_id' => 'HKSECTION']));

    function setOptions($sel, items, placeholder, valueOf, textOf) {
        $sel.empty();
        $sel.append($('<option>').val('').text(placeholder));
        $.each(items, function (_, item) {
            $sel.append($('<option>').val(valueOf(item)).text(textOf(item)));
        });
    }

    function loadSections($class, $section, $student, after) {
        var classId = $class.val();
        setOptions($section, [], 'Choose a class first', function (s) { return s.id; }, function (s) { return s.name; });
        setOptions($student, [], 'Choose a student…', function (s) { return s.id; }, function (s) { return s.name; });
        if (!classId) { if (after) { after(); } return; }
        $.getJSON(SECTIONS_URL.replace('HKCLASS', classId), function (data) {
            setOptions($section, (data && data.sections) || [], 'Choose a section…',
                function (s) { return s.id; }, function (s) { return s.name; });
            if (after) { after(); }
        });
    }

    function loadStudents($student, sectionId, keepValue, keepLabel, after) {
        var key = $student.attr('data-value-key') || 'id';
        setOptions($student, [], 'Choose a student…', function (s) { return s.id; }, function (s) { return s.name; });
        if (!sectionId) { if (after) { after(); } return; }
        $.getJSON(STUDENTS_URL.replace('HKSECTION', sectionId), function (items) {
            items = items || [];
            if (!items.length) {
                setOptions($student, [], 'No students available in this section',
                    function (s) { return s.id; }, function (s) { return s.name; });
                if (after) { after(); }
                return;
            }
            setOptions($student, items, 'Choose a student…',
                function (s) { return s[key]; },
                function (s) { return s.name + ' (' + s.adm_no + ')'; });
            if (keepValue) {
                // A pre-chosen student who is not in the active
                // list (e.g. a graduated student preselected for a
                // transfer certificate) keeps their option.
                if (!$student.find('option').filter(function () { return $(this).val() == keepValue; }).length && keepLabel) {
                    $student.append($('<option>').val(keepValue).text(keepLabel));
                }
                $student.val(keepValue);
            }
            if (after) { after(); }
        });
    }

    // Type-to-search mode: (re)build the student select as a
    // Select2 remote search bound to the section chosen RIGHT
    // NOW. Rebuilding on every class/section change is what
    // clears the previously picked student — results from another
    // section can never survive into this one. The concrete feed
    // URL is baked in at build time (no per-request URL callback,
    // so this works on the bundled Select2 build).
    function initRemoteStudent($student, $section, keepPreselect) {
        var key = $student.attr('data-value-key') || 'id';
        var preValue = keepPreselect ? $student.attr('data-preselect-value') : null;
        var preLabel = keepPreselect ? $student.attr('data-preselect-label') : null;

        if ($student.data('select2')) { $student.select2('destroy'); }
        $student.empty();
        $student.append($('<option>').val(''));
        if (preValue && preLabel) {
            $student.append($('<option>').val(preValue).text(preLabel));
            $student.val(preValue);
        }

        $student.select2({
            width: '100%',
            placeholder: $student.attr('data-search-placeholder') || 'Type a name or admission no…',
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: STUDENTS_URL.replace('HKSECTION', $section.val() || 0),
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term || '' }; },
                processResults: function (items) {
                    return {
                        results: (items || []).map(function (s) {
                            return { id: s[key], text: s.name + ' (' + s.adm_no + ')' };
                        })
                    };
                }
            },
            language: {
                inputTooShort: function () { return 'Type at least 2 letters of the name or admission no.'; },
                noResults: function () {
                    return $section.val() ? 'No students match in this section.' : 'Choose a class and section first.';
                },
                searching: function () { return 'Searching…'; }
            }
        });
    }

    function initCascade($student) {
        var $form = $student.closest('form');
        var $class = $form.find('.hk-cascade-class').first();
        var $section = $form.find('.hk-cascade-section').first();
        if (!$class.length || !$section.length) { return; }

        // Server-preselected student (edit forms, certificate
        // deep-links, validation bounces): walk the chain to them.
        var preClass = $student.attr('data-preselect-class');
        var preSection = $student.attr('data-preselect-section');
        var preValue = $student.attr('data-preselect-value');

        if ($student.attr('data-student-search') === 'remote') {
            $class.on('change', function () {
                loadSections($class, $section, $student, function () {
                    initRemoteStudent($student, $section, false);
                });
            });
            $section.on('change', function () {
                initRemoteStudent($student, $section, false);
            });
            if (preClass && preSection && preValue) {
                $class.val(preClass);
                loadSections($class, $section, $student, function () {
                    $section.val(preSection);
                    initRemoteStudent($student, $section, true);
                });
            } else {
                initRemoteStudent($student, $section, true);
            }
            return;
        }

        $class.on('change', function () {
            loadSections($class, $section, $student, null);
        });
        $section.on('change', function () {
            loadStudents($student, $section.val(), null, null, null);
        });

        if (preClass && preSection && preValue) {
            $class.val(preClass);
            loadSections($class, $section, $student, function () {
                $section.val(preSection);
                loadStudents($student, preSection, preValue, $student.attr('data-preselect-label'), null);
            });
        }

        // Admission-number Find (certificates): jump the cascade
        // straight to the looked-up student.
        window.hkCascadeSetStudent = function (record, done) {
            if (!record || !record.class_id || !record.section_id) { if (done) { done(false); } return; }
            var key = $student.attr('data-value-key') || 'id';
            $class.val(record.class_id);
            loadSections($class, $section, $student, function () {
                $section.val(record.section_id);
                loadStudents($student, record.section_id, record[key],
                    record.name + ' (' + record.adm_no + ')',
                    function () { if (done) { done(true); } });
            });
        };
    }

    $(function () {
        $('.hk-cascade-student').each(function () { initCascade($(this)); });
    });
})(jQuery);
</script>
