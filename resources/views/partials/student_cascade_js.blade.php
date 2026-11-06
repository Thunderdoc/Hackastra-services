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
     teacher-scoped server-side). --}}
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

    function initCascade($student) {
        var $form = $student.closest('form');
        var $class = $form.find('.hk-cascade-class').first();
        var $section = $form.find('.hk-cascade-section').first();
        if (!$class.length || !$section.length) { return; }

        $class.on('change', function () {
            loadSections($class, $section, $student, null);
        });
        $section.on('change', function () {
            loadStudents($student, $section.val(), null, null, null);
        });

        // Server-preselected student (edit forms, certificate
        // deep-links, validation bounces): walk the chain to them.
        var preClass = $student.attr('data-preselect-class');
        var preSection = $student.attr('data-preselect-section');
        var preValue = $student.attr('data-preselect-value');
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
