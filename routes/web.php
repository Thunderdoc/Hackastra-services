<?php

// Public registration is CLOSED (break-it fix 2026-10-28): the stock
// RegisterController never set users.code, so POST /register 500'd
// with no user created. School accounts are Admin-created only;
// re-enable by dropping the ['register' => false] option.
Auth::routes(['register' => false]);

Route::get('/privacy-policy', 'HomeController@privacy_policy')->name('privacy_policy');
Route::get('/terms-of-use', 'HomeController@terms_of_use')->name('terms_of_use');

/* Wave 12 — public admission enquiries (no login): a parent asking
   about a seat. Kept outside the auth group on purpose. */
Route::get('/admission-enquiry', 'HomeController@admission_form')->name('admission_enquiry.form');
Route::post('/admission-enquiry', 'HomeController@admission_store')->name('admission_enquiry.store');

/* Wave 15 — health endpoint for uptime monitors (no login, no
   sensitive data; 503 when the database is unreachable). */
Route::get('/health', 'HomeController@health')->name('health');


Route::group(['middleware' => 'auth'], function () {

    Route::get('/', 'HomeController@dashboard')->name('home');
    Route::get('/home', 'HomeController@dashboard');
    Route::get('/dashboard', 'HomeController@dashboard')->name('dashboard');
    Route::get('/dashboard/school', 'HomeController@schoolDashboard')->name('dashboard.school');
    Route::get('/dashboard/search', 'DashboardSearchController')->name('dashboard.search');

    // Workstream CZ (LMS): bell notifications — opening the bell
    // marks the caller's per-user notifications read.
    Route::post('/notifications/read', 'NotificationController@markRead')->name('notifications.read');
    // Owner order 2026-10-30: the full notifications page.
    Route::get('/notifications', 'NotificationController@index')->name('notifications.index');

    Route::group(['prefix' => 'my_account'], function() {
        Route::get('/', 'MyAccountController@edit_profile')->name('my_account');
        Route::put('/', 'MyAccountController@update_profile')->name('my_account.update');
        Route::put('/change_password', 'MyAccountController@change_pass')->name('my_account.change_pass');
        Route::post('/preferences', 'MyAccountController@update_preferences')->name('my_account.preferences');
        Route::post('/theme', 'MyAccountController@update_theme')->name('my_account.theme');
        Route::post('/bell-sound', 'MyAccountController@update_bell_sound')->name('my_account.bell_sound');
        Route::get('/download', 'MyAccountController@download_data')->name('my_account.download');
    });

    /*************** Support Team *****************/
    Route::group(['namespace' => 'SupportTeam',], function(){

        /*************** Students *****************/
        Route::group(['prefix' => 'students'], function(){
            Route::post('reset_pass/{st_id}', 'StudentRecordController@reset_pass')->name('st.reset_pass');
            Route::get('graduated', 'StudentRecordController@graduated')->name('students.graduated');
            Route::put('not_graduated/{id}', 'StudentRecordController@not_graduated')->name('st.not_graduated');
            Route::get('id_card/{sr_id}', 'IdCardController@show')->name('students.id_card')->middleware('teamSA');
            /* Owner ruling 2026-10-22: {class_id} is optional — with no
               class this URL is the Student Information landing
               (class/section picker in listByClass). */
            Route::get('list/{class_id?}', 'StudentRecordController@listByClass')->name('students.list')->middleware('teamOrPrincipal:SAT');
            Route::get('export/{class_id}/{section_id?}', 'StudentRecordController@export')->name('students.export')->middleware('teamSAT');

            /* Bulk operations — CSV import + bulk delete (Workstream AA).
               teamSA gate lives in StudentBulkController's constructor,
               same pattern as PromotionController. No {id} params: the
               delete list travels in the POST body. */
            Route::get('import', 'StudentBulkController@import')->name('students.import');
            Route::get('import/template', 'StudentBulkController@importTemplate')->name('students.import.template');
            Route::post('import', 'StudentBulkController@importStore')->name('students.import.store');
            Route::post('bulk_delete', 'StudentBulkController@bulkDelete')->name('students.bulk_delete');

            /* Quick Admit (2026-10-22): essentials-only admission.
               Gated teamSAWrite in StudentRecordController's
               constructor, same as store. */
            Route::post('quick_store', 'StudentRecordController@quickStore')->name('students.quick_store');

            /* Promotions */
            Route::post('promote_selector', 'PromotionController@selector')->name('students.promote_selector');
            Route::get('promotion/manage', 'PromotionController@manage')->name('students.promotion_manage');
            Route::delete('promotion/reset/{pid}', 'PromotionController@reset')->name('students.promotion_reset');
            Route::delete('promotion/reset_all', 'PromotionController@reset_all')->name('students.promotion_reset_all');
            Route::get('promotion/{fc?}/{fs?}/{tc?}/{ts?}', 'PromotionController@promotion')->name('students.promotion');
            Route::post('promote/{fc}/{fs}/{tc}/{ts}', 'PromotionController@promote')->name('students.promote');

        });

        /*************** Bulk Management (Phase A) *****************/
        /* Staged CSV pipeline: hub + upload/preview/confirm/history.
           Gates live in BulkManagementController (teamSA reads,
           teamSAWrite writes) — same pattern as StudentBulkController.
           {job} is implicit model binding; the {id} hash-decode rule
           does not apply, and bulk.* is deliberately NOT on the Super
           Admin's PlatformOnly allowlist (audit §8.1). */
        Route::group(['prefix' => 'bulk'], function(){
            Route::get('/', 'BulkManagementController@index')->name('bulk.index');
            Route::get('students/add', 'BulkManagementController@studentsAdd')->name('bulk.students.add');
            Route::get('students/edit', 'BulkManagementController@studentsEdit')->name('bulk.students.edit');
            Route::get('staff/add', 'BulkManagementController@staffAdd')->name('bulk.staff.add');
            Route::get('staff/edit', 'BulkManagementController@staffEdit')->name('bulk.staff.edit');
            Route::post('upload', 'BulkManagementController@upload')->name('bulk.upload');
            Route::get('jobs/{job}', 'BulkManagementController@show')->name('bulk.jobs.show');
            Route::get('jobs/{job}/errors', 'BulkManagementController@errors')->name('bulk.jobs.errors');
            Route::post('jobs/{job}/confirm', 'BulkManagementController@confirm')->name('bulk.jobs.confirm');
            Route::post('jobs/{job}/apply', 'BulkManagementController@apply')->name('bulk.jobs.apply');
            Route::post('jobs/{job}/discard', 'BulkManagementController@discard')->name('bulk.jobs.discard');
            Route::post('jobs/{job}/rollback', 'BulkManagementController@rollback')->name('bulk.jobs.rollback');
            Route::get('templates/students-edit', 'BulkManagementController@studentsEditTemplate')->name('bulk.templates.students_edit');
            Route::get('templates/staff-add', 'BulkManagementController@staffAddTemplate')->name('bulk.templates.staff_add');
            Route::get('templates/staff-edit', 'BulkManagementController@staffEditTemplate')->name('bulk.templates.staff_edit');
            Route::get('exports/students', 'BulkManagementController@exportsStudents')->name('bulk.exports.students');
            Route::get('exports/staff', 'BulkManagementController@exportsStaff')->name('bulk.exports.staff');
        });

        /*************** Admission Enquiries (Wave 12) ***************/
        Route::group(['prefix' => 'admissions', 'middleware' => 'teamSA'], function(){
            Route::get('/', 'AdmissionController@index')->name('admissions.index');
            Route::post('{admission}/status', 'AdmissionController@updateStatus')->name('admissions.status');
            Route::get('{admission}/convert', 'AdmissionController@convert')->name('admissions.convert');
        });

        /*************** HR-lite (Wave 13) ***************/
        Route::group(['prefix' => 'staff-attendance', 'middleware' => 'teamSAWrite'], function(){
            Route::get('/', 'StaffAttendanceController@index')->name('staff_attendance.index');
            Route::post('/', 'StaffAttendanceController@store')->name('staff_attendance.store');
        });
        /* Settings unlock requests — the two-stage approval page
           shared by Admin (his requests), Principal (stage 1) and
           Super Admin (stage 2, allowlisted in PlatformOnly). */
        Route::get('settings-unlock-requests', 'SettingsLockController@index')->name('settings_unlock.index');
        Route::post('settings-unlock-requests/{unlock_request}/decide', 'SettingsLockController@decide')->name('settings_unlock.decide');
        Route::group(['prefix' => 'leave'], function(){
            Route::get('my', 'LeaveRequestController@myIndex')->name('leave.my');
            Route::post('my', 'LeaveRequestController@store')->name('leave.store');
            Route::get('/', 'LeaveRequestController@index')->name('leave.index')->middleware('teamOrPrincipal:SAWrite');
            Route::get('student-requests', 'LeaveRequestController@studentRequests')->name('leave.student_requests');
            Route::post('{leave}/decide', 'LeaveRequestController@decide')->name('leave.decide');
        });

        /*************** Certificates (Wave 14) ***************/
        Route::group(['prefix' => 'certificates'], function(){
            Route::get('/', 'CertificateController@index')->name('certificates.index');
            Route::get('create', 'CertificateController@create')->name('certificates.create');
            Route::post('/', 'CertificateController@store')->name('certificates.store');
            Route::get('{certificate}/print', 'CertificateController@print')->name('certificates.print');
        });

        /*************** Users *****************/
        Route::group(['prefix' => 'users'], function(){
            Route::post('reset_pass/{id}', 'UserController@reset_pass')->name('users.reset_pass');
            /* Account lifecycle (Workstream DF) — state changes, not
               deletions; gates live in UserController (teamSA +
               mayWriteUserTarget + self/head-SA/last-SA rails). */
            Route::post('{id}/suspend', 'UserController@suspend')->name('users.suspend');
            Route::post('{id}/activate', 'UserController@activate')->name('users.activate');
            Route::post('{id}/terminate', 'UserController@terminate')->name('users.terminate');
        });

        /*************** TimeTables *****************/
        Route::group(['prefix' => 'timetables'], function(){
            Route::get('/', 'TimeTableController@index')->name('tt.index');
            Route::group(['middleware' => 'teamSAWrite'], function(){
                Route::get('generate', 'TimeTableController@generateForm')->name('tt.generate.form');
                Route::post('generate', 'TimeTableController@generate')->name('tt.generate');
            });
            Route::get('mine', 'TimeTableController@mine')->name('tt.mine');
            Route::get('approvals', 'TimeTableController@approvals')->name('tt.approvals');
            /* Loop 81: teacher's own weekly timetable — print + PDF */
            Route::get('mine/print', 'TimeTableController@mine_print')->name('tt.mine_print');
            Route::get('mine/pdf', 'TimeTableController@mine_pdf')->name('tt.mine_pdf');

            // Workstream CM: timetable writes are school writes — Admin only.
            Route::group(['middleware' => 'teamSAWrite'], function() {
                Route::post('/', 'TimeTableController@store')->name('tt.store');
                Route::put('/{tt}', 'TimeTableController@update')->name('tt.update');
                Route::delete('/{tt}', 'TimeTableController@delete')->name('tt.delete');
            });

            /*************** TimeTable Records *****************/
            Route::group(['prefix' => 'records'], function(){

                Route::group(['middleware' => 'teamSAWrite'], function(){
                    Route::get('manage/{ttr}', 'TimeTableController@manage')->name('ttr.manage');
                    Route::post('/', 'TimeTableController@store_record')->name('ttr.store');
                    Route::get('edit/{ttr}', 'TimeTableController@edit_record')->name('ttr.edit');
                    Route::put('/{ttr}', 'TimeTableController@update_record')->name('ttr.update');
                    Route::delete('/{ttr}', 'TimeTableController@delete_record')->name('ttr.destroy');
                    /* Owner fix 2026-10-25: validation is an admin QA
                       tool (clash report + teacher loads) — it was
                       reachable by every signed-in role, so students
                       and teachers could open it for their classes.
                       Now admin-only at the route gate, like manage. */
                    Route::get('validate/{ttr}', 'TimeTableController@validateRecord')->name('ttr.validate');
                    Route::post('submit-approval/{ttr}', 'TimeTableController@submitApproval')->name('ttr.submit_approval');
                });

                /* Timetable approval decisions are the Principal's
                   (owner order 2026-10-29) — the methods themselves
                   refuse every other role. */
                Route::post('approve/{ttr}', 'TimeTableController@approveRecord')->name('ttr.approve');
                Route::post('send-back/{ttr}', 'TimeTableController@sendBackRecord')->name('ttr.send_back');
                /* T4: restore a grid snapshot as a new draft (the
                   method itself refuses every non-Admin). */
                Route::post('snapshots/{snapshot}/restore', 'TimeTableController@restoreSnapshot')->name('tt.snapshot.restore');

                Route::get('show/{ttr}', 'TimeTableController@show_record')->name('ttr.show');
                Route::get('print/{ttr}', 'TimeTableController@print_record')->name('ttr.print');

            });

            /*************** Time Slots *****************/
            Route::group(['prefix' => 'time_slots', 'middleware' => 'teamSAWrite'], function(){
                Route::post('/', 'TimeTableController@store_time_slot')->name('ts.store');
                Route::post('/use/{ttr}', 'TimeTableController@use_time_slot')->name('ts.use');
                Route::get('edit/{ts}', 'TimeTableController@edit_time_slot')->name('ts.edit');
                Route::delete('/{ts}', 'TimeTableController@delete_time_slot')->name('ts.destroy');
                Route::put('/{ts}', 'TimeTableController@update_time_slot')->name('ts.update');
            });

        });

        /*************** Payments *****************/
        Route::group(['prefix' => 'payments'], function(){

            Route::get('student_search', 'PaymentController@student_search')->name('payments.student_search');
            Route::get('manage/{class_id?}', 'PaymentController@manage')->name('payments.manage');
            Route::get('export/{class_id}', 'PaymentController@export')->name('payments.export');
            Route::get('export_student/{id}/{year?}', 'PaymentController@export_student')->name('payments.export_student');
            Route::get('invoice/{id}/{year?}', 'PaymentController@invoice')->name('payments.invoice');
            Route::get('receipts/{id}', 'PaymentController@receipts')->name('payments.receipts');
            Route::get('pdf_receipts/{id}', 'PaymentController@pdf_receipts')->name('payments.pdf_receipts');
            Route::post('select_year', 'PaymentController@select_year')->name('payments.select_year');
            Route::post('select_class', 'PaymentController@select_class')->name('payments.select_class');
            Route::delete('reset_record/{id}', 'PaymentController@reset_record')->name('payments.reset_record');
            Route::post('pay_now/{id}', 'PaymentController@pay_now')->name('payments.pay_now');

            /* Online payments (Workstream CU) — staff transaction log */
            Route::get('gateway-transactions', 'PaymentController@gateway_transactions')->name('payments.gateway_transactions');

            /* Fee reports (Loop 9, Workstream O) */
            Route::get('reports/defaulters', 'FeeReportController@defaulters')->name('payments.defaulters');
        Route::post('reports/defaulters/remind', 'FeeReportController@remindDefaulters')->name('payments.defaulters.remind');
            Route::get('reports/defaulters/export', 'FeeReportController@defaultersExport')->name('payments.defaulters.export');
            Route::get('reports/collection', 'FeeReportController@collection')->name('payments.collection');
            Route::get('reports/collection/export', 'FeeReportController@collectionExport')->name('payments.collection.export');
            Route::get('reports/collection/pdf', 'FeeReportController@collectionPdf')->name('payments.collection.pdf');
            Route::get('reports/collection/day', 'FeeReportController@collectionDay')->name('payments.collection.day');
        });

        /*************** Payroll (Workstream AI) *****************/
        Route::group(['prefix' => 'payroll'], function(){
            Route::get('/', 'PayrollController@index')->name('payroll.index');
            Route::post('/', 'PayrollController@store')->name('payroll.store');
            Route::post('generate', 'PayrollController@generate')->name('payroll.generate');
            Route::put('{payroll}', 'PayrollController@update')->name('payroll.update');
            Route::post('{payroll}/pay', 'PayrollController@pay')->name('payroll.pay');
            Route::get('{payroll}/payslip', 'PayrollController@payslip')->name('payroll.payslip');
        });

        /* Staff self-service payslips: own records only, enforced in the controller. */
        Route::group(['prefix' => 'my-payslips'], function(){
            Route::get('/', 'PayrollController@myIndex')->name('my_payslips.index');
            Route::get('download-all', 'PayrollController@myPayslipsPdfAll')->name('my_payslips.pdf_all');
            Route::get('{payroll}', 'PayrollController@myPayslip')->name('my_payslips.payslip');
            Route::get('{payroll}/pdf', 'PayrollController@myPayslipPdf')->name('my_payslips.pdf');
        });

        /*************** Pins — REMOVED (Workstream BH) *****************/
        /* The scratch-card PIN system is retired by owner decision
           (2026-10-21): results are gated by login + the per-exam
           results_published switch instead. The pins table and its
           model/repo/request classes stay dormant in the codebase;
           no routes, menus or pages point at them any more. */

        /*************** Marks *****************/
        Route::group(['prefix' => 'marks'], function(){

           // FOR teamSA — views only (Workstream CM): SA keeps the
           // tabulation print document; the sheet VIEW is teamSAT
           // since the owner grant of 2026-10-25 (assigned class
           // teachers read their own students' sheets, scoped in
           // MarkController@tabulation; print stays teamSA-only).
           // Limited principal role (owner order 2026-10-28): the
           // print/bulk documents are school-wide monitoring reads.
            Route::group(['middleware' => 'teamOrPrincipal:SA'], function(){
                Route::get('tabulation/print/{exam}/{class}/{sec_id}', 'MarkController@print_tabulation')->name('marks.print_tabulation');

                /* Marksheet (bulk class sheet) — owner ruling
                   2026-10-22: marksheets belong to the school office
                   and the student, never the teacher. Moved here from
                   the teamSATWrite group so the teacher role is refused
                   at the route gate; Admin keeps generation. */
                Route::get('bulk/{class?}/{section?}', 'MarkController@bulk')->name('marks.bulk');
                Route::post('bulk', 'MarkController@bulk_select')->name('marks.bulk_select');
                /* Wave 5 — owner order 2026-10-25: section-wise bulk
                   marksheet PDF for the Admin panel (one card per
                   student of the section). teamSA-only like bulk. */
                Route::get('bulk-download', 'MarkController@bulkDownload')->name('marks.bulk_download');
            });

            // FOR teamSAT — the tabulation sheet view + its selector
            // POST (which only redirects into that view).
            // Limited principal role: same read for the principal.
            Route::group(['middleware' => 'teamOrPrincipal:SAT'], function(){
                Route::get('tabulation/{exam?}/{class?}/{sec_id?}', 'MarkController@tabulation')->name('marks.tabulation');
                Route::post('tabulation', 'MarkController@tabulation_select')->name('marks.tabulation_select');
            });

            /* Marks Correction (batch fix) REMOVED by owner decision
               2026-10-23: the standalone page, its routes and its menu
               item are gone (direct URLs 404). The recompute pipeline
               itself (App\Support\MarkComputation) stays — marks entry
               and the assignment internal-marks push still use it. */

            // FOR teamSATWrite (Workstream CM): marks entry is a write
            // surface — Admin + assigned teachers; SA's marks read is
            // show/tabulation below, untouched.
            Route::group(['middleware' => 'teamSATWrite'], function(){
                Route::get('/', 'MarkController@index')->name('marks.index');
                Route::get('manage/{exam}/{class}/{section}/{subject}', 'MarkController@manage')->name('marks.manage');
                Route::put('update/{exam}/{class}/{section}/{subject}', 'MarkController@update')->name('marks.update');
                Route::post('submit/{exam}/{class}/{section}/{subject}', 'MarkController@submit')->name('marks.submit');
                Route::post('import-internal/{exam}/{class}/{section}/{subject}', 'MarkController@importInternal')->name('marks.import_internal');
                Route::post('reopen/{exam}/{class}/{section}/{subject}', 'MarkController@reopen')->name('marks.reopen');
                Route::put('comment_update/{exr_id}', 'MarkController@comment_update')->name('marks.comment_update');
                Route::put('skills_update/{skill}/{exr_id}', 'MarkController@skills_update')->name('marks.skills_update');
                Route::post('selector', 'MarkController@selector')->name('marks.selector');
            });

            Route::get('select_year/{id}', 'MarkController@year_selector')->name('marks.year_selector');
            Route::post('select_year/{id}', 'MarkController@year_selected')->name('marks.year_select');
            Route::get('show/{id}/{year}', 'MarkController@show')->name('marks.show');
            Route::get('print/{id}/{exam_id}/{year}', 'MarkController@print_view')->name('marks.print');
            Route::get('annual/{id}/{year}', 'MarkController@show_annual')->name('marks.annual');
            Route::get('print_annual/{id}/{year}', 'MarkController@print_annual')->name('marks.print_annual');
            Route::get('pdf_annual/{id}/{year}', 'MarkController@pdf_annual')->name('marks.pdf_annual');

        });

        /*************** Admit Cards *****************/
        Route::group(['prefix' => 'exams/admit-cards'], function(){
            Route::get('/', 'AdmitCardController@index')->name('admit_cards.index');
            Route::get('print/{exam}/{class}', 'AdmitCardController@printSheet')->name('admit_cards.print');
        });
        Route::get('my-admit-cards', 'AdmitCardController@myCards')->name('admit_cards.my');
        Route::get('my-admit-cards/{exam}/{student_id}', 'AdmitCardController@myCard')->name('admit_cards.my_card');

        /* Result Publish switch (Workstream BH) — registered before the
           exams resource so the action names win cleanly. */
        Route::post('exams/{exam}/publish-results', 'ExamController@publishResults')->name('exams.publish_results');
        Route::post('exams/{exam}/unpublish-results', 'ExamController@unpublishResults')->name('exams.unpublish_results');
        Route::get('exams/{exam}/publication', 'ExamController@publication')->name('exams.publication');
        Route::post('exams/{exam}/notify-pending', 'ExamController@notifyPending')->name('exams.notify_pending');
        Route::post('exams/{exam}/request-publication', 'ExamController@requestPublication')->name('exams.request_publication');

        /* Exam Date Sheet (owner-ordered 2026-10-22) — the Admin sets
           per-subject dates per exam + class; teachers, students and
           parents read their scoped sheet. Registered before the
           exams resource like the publish switches above. The manage
           page is teamSA / its save is teamSAWrite (controller
           middleware); the read routes resolve their scope per role
           inside ExamDateController. Super Admin is kept out by
           PlatformOnly (none of these names are allowlisted). */
        Route::get('exams/date-sheet/manage', 'ExamDateController@manage')->name('exam_dates.manage');
        Route::post('exams/date-sheet/manage', 'ExamDateController@save')->name('exam_dates.save');
        Route::get('exams/date-sheet/generate', 'ExamDateController@generateForm')->name('exam_dates.generate.form');
        Route::post('exams/date-sheet/generate', 'ExamDateController@generate')->name('exam_dates.generate');
        /* Date sheet approval (overnight menus 2026-10-29, item 22):
           admin submits, principal approves (= publishes). The
           submit is teamSAWrite (controller middleware); the
           approvals queue and approve action are principal-guarded
           inside ExamDateController, not teamSA (the principal is
           not teamSA, and Manage stays teamSA-only). */
        Route::post('exams/date-sheet/submit', 'ExamDateController@submit')->name('exam_dates.submit');
        Route::get('exams/date-sheet/approvals', 'ExamDateController@approvals')->name('exam_dates.approvals');
        Route::post('exams/date-sheet/approve', 'ExamDateController@approve')->name('exam_dates.approve');
        Route::get('exam-dates', 'ExamDateController@show')->name('exam_dates.index');
        Route::get('exams/duties', 'ExamDutyController@index')->name('exam_duties.index');
        Route::post('exams/duties/assign', 'ExamDutyController@assign')->name('exam_duties.assign');
        Route::post('exams/duties/assign-bulk', 'ExamDutyController@assignBulk')->name('exam_duties.assign_bulk');
        Route::post('exams/duties/auto', 'ExamDutyController@auto')->name('exam_duties.auto');
        Route::get('exams/my-duties', 'ExamDutyController@myDuties')->name('exam_duties.my');
        Route::get('exam-dates/show', 'ExamDateController@show')->name('exam_dates.show');
        Route::get('exam-dates/print', 'ExamDateController@printSheet')->name('exam_dates.print');
        Route::get('exam-dates/pdf', 'ExamDateController@pdf')->name('exam_dates.pdf');

        Route::resource('students', 'StudentRecordController');
        Route::resource('users', 'UserController');
        Route::resource('classes', 'MyClassController');
        Route::put('sections/{id}/teachers', 'SectionController@updateTeachers')->name('sections.teachers.update');
        Route::post('sections/{id}/whatsapp', 'SectionController@updateWhatsapp')->name('sections.whatsapp');
        Route::resource('sections', 'SectionController');
        Route::resource('subjects', 'SubjectController');

        /*************** Academic Planning (Scheduling Engine) *****************/
        Route::get('planning/readiness', 'PlanningController@readiness')->name('planning.readiness');
        Route::get('planning/staffing', 'PlanningController@staffing')->name('planning.staffing');
        Route::get('planning/allocation', 'PlanningController@allocation')->name('planning.allocation');
        Route::post('planning/allocation/apply', 'PlanningController@applyAllocation')->name('planning.allocation.apply');
        Route::get('planning/eligibility', 'PlanningController@eligibility')->name('planning.eligibility');
        Route::post('planning/eligibility', 'PlanningController@saveEligibility')->name('planning.eligibility.save');
        Route::resource('grades', 'GradeController');
        Route::resource('exams', 'ExamController');

        /*************** Marking Schemes (Batch M1, teamSA in controller) *****************/
        Route::get('exams/{exam}/scheme', 'MarkSchemeController@index')->name('exams.scheme');
        Route::get('exams/{exam}/scheme/{class}', 'MarkSchemeController@edit')->name('exams.scheme.edit');
        Route::post('exams/{exam}/scheme/{class}/copy', 'MarkSchemeController@copy')->name('exams.scheme.copy');
        Route::post('exams/{exam}/scheme/{class}/{subject}', 'MarkSchemeController@update')->name('exams.scheme.update');
        Route::resource('dorms', 'DormController');
        Route::resource('payments', 'PaymentController');

        /*************** Attendance *****************/
        Route::get('attendance', 'AttendanceController@index')->name('attendance.index');
        Route::post('attendance', 'AttendanceController@store')->name('attendance.store');
        Route::get('attendance/report', 'AttendanceController@report')->name('attendance.report');
        Route::get('attendance/report/export', 'AttendanceController@export')->name('attendance.export');
        Route::get('attendance/report/print', 'AttendanceController@reportPrint')->name('attendance.report.print');
        Route::get('attendance/report/pdf', 'AttendanceController@reportPdf')->name('attendance.report.pdf');
        Route::get('attendance/defaulters', 'AttendanceController@defaulters')->name('attendance.defaulters');
        Route::get('attendance/defaulters/export', 'AttendanceController@defaultersExport')->name('attendance.defaulters.export');
        Route::get('attendance/defaulters/pdf', 'AttendanceController@defaultersPdf')->name('attendance.defaulters.pdf');
        Route::get('attendance/student/{student_id}', 'AttendanceController@studentReport')->name('attendance.student_report');
        Route::get('attendance/student/{student_id}/pdf', 'AttendanceController@studentReportPdf')->name('attendance.student_report.pdf');
        Route::get('attendance/student/{student_id}/export', 'AttendanceController@studentReportExport')->name('attendance.student_report.export');
        Route::get('attendance/my', 'AttendanceController@my')->name('attendance.my');

        /*************** Student ID Card (Workstream AJ) *****************/
        /* Personal card: identity comes from the signed-in user's own
           record — no student-id parameter exists to tamper with. */
        Route::get('my_id_card', 'IdCardController@mine')->name('id_card.mine');

        /*************** Period Attendance (subject-wise, Workstream AC) *****************/
        /* Submitted sessions are final: there is deliberately no
           edit/update/delete route for a period register. */
        Route::get('attendance/periods', 'PeriodAttendanceController@index')->name('attendance.periods.index');
        Route::get('attendance/periods/report', 'PeriodAttendanceController@report')->name('attendance.periods.report');
        Route::get('attendance/periods/export', 'PeriodAttendanceController@export')->name('attendance.periods.export');
        Route::get('attendance/periods/session/{session_id}', 'PeriodAttendanceController@show')->name('attendance.periods.show');
        /* Read-only outputs of a submitted register (Loop 77): the
           print view + PDF share one v2 document blade, the CSV
           follows the report export pattern. Owner rule: submitted
           registers are gone from the teacher's end — Admin /
           Super Admin oversight only. */
        Route::get('attendance/periods/session/{session_id}/print', 'PeriodAttendanceController@printRegister')->name('attendance.periods.print');
        Route::get('attendance/periods/session/{session_id}/pdf', 'PeriodAttendanceController@pdfRegister')->name('attendance.periods.pdf');
        Route::get('attendance/periods/session/{session_id}/export', 'PeriodAttendanceController@exportRegister')->name('attendance.periods.export_register');
        Route::get('attendance/periods/{tt_id}/take', 'PeriodAttendanceController@take')->name('attendance.periods.take');
        Route::post('attendance/periods/{tt_id}', 'PeriodAttendanceController@store')->name('attendance.periods.store');
        /* Take-page roster outputs: the same admission-ordered
           roster + system roll numbers as the take page, for the
           teacher's own classroom use (CSV opens in Excel, PDF
           prints with blank P/A/L boxes for manual marking). */
        Route::get('attendance/periods/{tt_id}/roster-export', 'PeriodAttendanceController@rosterExport')->name('attendance.periods.roster_export');
        Route::get('attendance/periods/{tt_id}/roster-pdf', 'PeriodAttendanceController@rosterPdf')->name('attendance.periods.roster_pdf');

        /*************** Reports & PDF Builder (wave 31b) *****************/
        Route::group(['prefix' => 'reports'], function() {
            Route::get('/', 'ReportsController@builder')->name('reports.builder');
            Route::post('preview', 'ReportsController@preview')->name('reports.preview');
            Route::post('pdf', 'ReportsController@pdf')->name('reports.pdf');
            Route::post('templates', 'ReportsController@storeTemplate')->name('reports.templates.store');
            Route::put('templates/{template}', 'ReportsController@updateTemplate')->name('reports.templates.update');
            Route::delete('templates/{template}', 'ReportsController@destroyTemplate')->name('reports.templates.destroy');
            Route::post('templates/{template}/duplicate', 'ReportsController@duplicateTemplate')->name('reports.templates.duplicate');
        });

        /*************** Wave 1: teacher/classroom features *****************/
        /* Controller-guarded (no team middleware): students must reach
           quiz attempts, so each controller gates its own actions.
           Super Admin reaches none of these (not PlatformOnly-listed).
           Route params are NOT {id}, so no hash binding applies. */
        Route::group(['prefix' => 'quizzes'], function() {
            Route::get('/', 'QuizController@index')->name('quizzes.index');
            Route::get('create', 'QuizController@create')->name('quizzes.create');
            Route::post('/', 'QuizController@store')->name('quizzes.store');
            Route::get('{quiz}', 'QuizController@show')->name('quizzes.show');
            Route::get('{quiz}/attempt', 'QuizController@attempt')->name('quizzes.attempt');
            Route::post('{quiz}/attempt', 'QuizController@submitAttempt')->name('quizzes.submit');
            Route::get('{quiz}/results', 'QuizController@results')->name('quizzes.results');
            Route::get('{quiz}/review', 'QuizController@review')->name('quizzes.review');
            Route::post('{quiz}/questions', 'QuizController@storeQuestion')->name('quizzes.questions.store');
            Route::put('{quiz}/questions/{question}', 'QuizController@updateQuestion')->name('quizzes.questions.update');
            Route::delete('{quiz}/questions/{question}', 'QuizController@destroyQuestion')->name('quizzes.questions.destroy');
            Route::post('{quiz}/publish', 'QuizController@publish')->name('quizzes.publish');
            Route::post('{quiz}/unpublish', 'QuizController@unpublish')->name('quizzes.unpublish');
            Route::post('{quiz}/push-internal', 'QuizController@pushInternal')->name('quizzes.push_internal');
        });
        Route::group(['prefix' => 'lesson-plans'], function() {
            Route::get('/', 'LessonPlanController@index')->name('lesson_plans.index');
            Route::get('create', 'LessonPlanController@create')->name('lesson_plans.create');
            Route::post('/', 'LessonPlanController@store')->name('lesson_plans.store');
            Route::get('{lesson}', 'LessonPlanController@show')->name('lesson_plans.show');
            Route::delete('{lesson}', 'LessonPlanController@destroy')->name('lesson_plans.destroy');
        });
        Route::group(['prefix' => 'homework-tracker'], function() {
            Route::get('/', 'HomeworkTrackerController@index')->name('homework_tracker.index');
        });
        Route::group(['prefix' => 'substitutions'], function() {
            Route::get('/', 'SubstitutionController@index')->name('substitutions.index');
            Route::get('mine', 'SubstitutionController@mine')->name('substitutions.mine');
            Route::get('create', 'SubstitutionController@create')->name('substitutions.create');
            Route::post('/', 'SubstitutionController@store')->name('substitutions.store');
            Route::post('confirm', 'SubstitutionController@confirm')->name('substitutions.confirm');
            Route::post('{substitution}/switch', 'SubstitutionController@switchSubstitute')->name('substitutions.switch');
            Route::delete('{substitution}', 'SubstitutionController@destroy')->name('substitutions.destroy');
        });
        Route::group(['prefix' => 'class-overview'], function() {
            Route::get('/', 'ClassOverviewController@index')->name('class_overview.index');
        });
        Route::group(['prefix' => 'question-bank'], function() {
            Route::get('/', 'QuestionBankController@index')->name('question_bank.index');
            Route::get('picker', 'QuestionBankController@picker')->name('question_bank.picker');
            Route::get('create', 'QuestionBankController@create')->name('question_bank.create');
            Route::post('/', 'QuestionBankController@store')->name('question_bank.store');
            Route::get('{question}/edit', 'QuestionBankController@edit')->name('question_bank.edit');
            Route::put('{question}', 'QuestionBankController@update')->name('question_bank.update');
            Route::delete('{question}', 'QuestionBankController@destroy')->name('question_bank.destroy');
        });

        /*************** Wave 2: admin/operations features *****************/
        /* Analytics Dashboard: live aggregates only, CSS bars, no JS deps. */
        Route::get('analytics', 'AnalyticsController@index')->name('analytics.index')->middleware('teamSAT');

        /*************** Transport (Wave 2) *****************/
        Route::group(['prefix' => 'transport'], function() {
            Route::get('/', 'TransportController@index')->name('transport.index')->middleware('teamOrPrincipal:SA');
            Route::get('routes/create', 'TransportController@routeCreate')->name('transport.routes.create')->middleware('teamSAWrite');
            Route::post('routes', 'TransportController@routeStore')->name('transport.routes.store')->middleware('teamSAWrite');
            Route::get('routes/{transport_route}/edit', 'TransportController@routeEdit')->name('transport.routes.edit')->middleware('teamSAWrite');
            Route::put('routes/{transport_route}', 'TransportController@routeUpdate')->name('transport.routes.update')->middleware('teamSAWrite');
            Route::delete('routes/{transport_route}', 'TransportController@routeDestroy')->name('transport.routes.destroy')->middleware('teamSAWrite');
            Route::post('routes/{transport_route}/stops', 'TransportController@stopStore')->name('transport.stops.store')->middleware('teamSAWrite');
            Route::delete('stops/{transport_stop}', 'TransportController@stopDestroy')->name('transport.stops.destroy')->middleware('teamSAWrite');
            Route::get('vehicles/create', 'TransportController@vehicleCreate')->name('transport.vehicles.create')->middleware('teamSAWrite');
            Route::post('vehicles', 'TransportController@vehicleStore')->name('transport.vehicles.store')->middleware('teamSAWrite');
            Route::get('vehicles/{transport_vehicle}/edit', 'TransportController@vehicleEdit')->name('transport.vehicles.edit')->middleware('teamSAWrite');
            Route::put('vehicles/{transport_vehicle}', 'TransportController@vehicleUpdate')->name('transport.vehicles.update')->middleware('teamSAWrite');
            Route::delete('vehicles/{transport_vehicle}', 'TransportController@vehicleDestroy')->name('transport.vehicles.destroy')->middleware('teamSAWrite');
            Route::get('assignments', 'TransportController@assignments')->name('transport.assignments')->middleware('teamSA');
            Route::post('assignments', 'TransportController@assignmentStore')->name('transport.assignments.store')->middleware('teamSAWrite');
            Route::delete('assignments/{transport_assignment}', 'TransportController@assignmentDestroy')->name('transport.assignments.destroy')->middleware('teamSAWrite');
            Route::get('fees', 'TransportController@fees')->name('transport.fees')->middleware('teamSA');
            Route::post('fees', 'TransportController@feeStore')->name('transport.fees.store')->middleware('teamSAWrite');
            Route::post('fees/{transport_fee}/mark-paid', 'TransportController@feeMarkPaid')->name('transport.fees.mark_paid')->middleware('teamSAWrite');
            Route::get('mine', 'TransportController@mine')->name('transport.mine');
        });

        /*************** Hostel (Wave 2; overnight menus 2026-10-29,
           item 20: the Hostel Warden runs it — these routes carry
           NO team middleware any more, so every action guards
           itself in HostelController: warden (+ super_admin read)
           in, admin and everyone else out. The student/parent
           self-view (hostel.my) is untouched.) ***************/
        Route::group(['prefix' => 'hostel'], function() {
            Route::get('dorms', 'HostelController@dormsIndex')->name('hostel.dorms.index');
            Route::post('dorms', 'HostelController@dormsStore')->name('hostel.dorms.store');
            Route::get('dorms/{hostel_dorm}/edit', 'HostelController@dormsEdit')->name('hostel.dorms.edit');
            Route::put('dorms/{hostel_dorm}', 'HostelController@dormsUpdate')->name('hostel.dorms.update');
            Route::delete('dorms/{hostel_dorm}', 'HostelController@dormsDestroy')->name('hostel.dorms.destroy');
            Route::get('rooms', 'HostelController@roomsIndex')->name('hostel.rooms.index');
            Route::post('rooms', 'HostelController@roomsStore')->name('hostel.rooms.store');
            Route::get('rooms/{hostel_room}/edit', 'HostelController@roomsEdit')->name('hostel.rooms.edit');
            Route::put('rooms/{hostel_room}', 'HostelController@roomsUpdate')->name('hostel.rooms.update');
            Route::delete('rooms/{hostel_room}', 'HostelController@roomsDestroy')->name('hostel.rooms.destroy');
            Route::get('allocations', 'HostelController@allocationsIndex')->name('hostel.allocations.index');
            Route::get('allocations/create', 'HostelController@allocationCreate')->name('hostel.allocations.create');
            Route::post('allocations', 'HostelController@allocationStore')->name('hostel.allocations.store');
            Route::delete('allocations/{hostel_record}', 'HostelController@allocationDestroy')->name('hostel.allocations.destroy');
            Route::get('my', 'HostelController@myAllocation')->name('hostel.my');
        });

        /*************** Fee Structure Builder (Wave 2) *****************/
        Route::group(['prefix' => 'fee-structure'], function(){
            Route::get('/', 'FeeStructureController@index')->name('fee_structure.index')->middleware('teamSA');
            Route::get('/create', 'FeeStructureController@create')->name('fee_structure.create')->middleware('teamSAWrite');
            Route::post('/', 'FeeStructureController@store')->name('fee_structure.store')->middleware('teamSAWrite');
            Route::get('/concessions/create', 'FeeStructureController@concessionCreate')->name('fee_structure.concessions.create')->middleware('teamSAWrite');
            Route::post('/concessions', 'FeeStructureController@concessionStore')->name('fee_structure.concessions.store')->middleware('teamSAWrite');
            Route::get('/concessions/{concession}/edit', 'FeeStructureController@concessionEdit')->name('fee_structure.concessions.edit')->middleware('teamSAWrite');
            Route::put('/concessions/{concession}', 'FeeStructureController@concessionUpdate')->name('fee_structure.concessions.update')->middleware('teamSAWrite');
            Route::delete('/concessions/{concession}', 'FeeStructureController@concessionDestroy')->name('fee_structure.concessions.destroy')->middleware('teamSAWrite');
            Route::get('/net', 'FeeStructureController@netIndex')->name('fee_structure.net.index')->middleware('teamSA');
            Route::get('/net/{student_record}', 'FeeStructureController@net')->name('fee_structure.net')->middleware('teamSA');
            Route::get('/{fee_head}', 'FeeStructureController@show')->name('fee_structure.show')->middleware('teamSA');
            Route::get('/{fee_head}/edit', 'FeeStructureController@edit')->name('fee_structure.edit')->middleware('teamSAWrite');
            Route::put('/{fee_head}', 'FeeStructureController@update')->name('fee_structure.update')->middleware('teamSAWrite');
            Route::delete('/{fee_head}', 'FeeStructureController@destroy')->name('fee_structure.destroy')->middleware('teamSAWrite');
            Route::post('/{fee_head}/installments', 'FeeStructureController@installmentStore')->name('fee_structure.installments.store')->middleware('teamSAWrite');
            Route::put('/{fee_head}/installments/{installment}', 'FeeStructureController@installmentUpdate')->name('fee_structure.installments.update')->middleware('teamSAWrite');
            Route::delete('/{fee_head}/installments/{installment}', 'FeeStructureController@installmentDestroy')->name('fee_structure.installments.destroy')->middleware('teamSAWrite');
            Route::post('/{fee_head}/apply', 'FeeStructureController@apply')->name('fee_structure.apply')->middleware('teamSAWrite');
        });

        /*************** Bulk ID Cards (Wave 2) *****************/
        Route::get('students/id_cards/bulk', 'IdCardController@bulkSelector')->name('students.id_cards.bulk')->middleware('teamSAT');
        Route::get('students/id_cards/bulk/pdf', 'IdCardController@bulk')->name('students.id_cards.bulk.pdf')->middleware('teamSAT');

        /*************** Inventory / Store (Wave 2) *****************/
        Route::group(['prefix' => 'inventory'], function(){
            Route::get('/', 'InventoryController@index')->name('inventory.index')->middleware('teamSA');
            Route::get('create', 'InventoryController@create')->name('inventory.create')->middleware('teamSAWrite');
            Route::post('/', 'InventoryController@store')->name('inventory.store')->middleware('teamSAWrite');
            Route::get('{inventory_item}', 'InventoryController@show')->name('inventory.show')->middleware('teamSA');
            Route::get('{inventory_item}/edit', 'InventoryController@edit')->name('inventory.edit')->middleware('teamSAWrite');
            Route::put('{inventory_item}', 'InventoryController@update')->name('inventory.update')->middleware('teamSAWrite');
            Route::delete('{inventory_item}', 'InventoryController@destroy')->name('inventory.destroy')->middleware('teamSAWrite');
            Route::post('{inventory_item}/movements', 'InventoryController@storeMovement')->name('inventory.movements.store')->middleware('teamSAWrite');
        });

        /*************** Expenses (Wave 2) *****************/
        Route::group(['prefix' => 'expenses'], function(){
            Route::get('/', 'ExpenseController@index')->name('expenses.index');
            Route::get('create', 'ExpenseController@create')->name('expenses.create');
            Route::post('/', 'ExpenseController@store')->name('expenses.store');
            Route::get('report', 'ExpenseController@report')->name('expenses.report');
            Route::get('{expense}/edit', 'ExpenseController@edit')->name('expenses.edit');
            Route::put('{expense}', 'ExpenseController@update')->name('expenses.update');
            Route::delete('{expense}', 'ExpenseController@destroy')->name('expenses.destroy');
        });

        /*************** Backup & Monitoring (Wave 2) *****************/
        Route::group(['prefix' => 'backup', 'middleware' => 'teamSA'], function(){
            Route::get('/', 'BackupController@index')->name('backup.index');
            Route::post('/refresh', 'BackupController@refresh')->name('backup.refresh');
        });

        /*************** School Events *****************/
        Route::resource('events', 'SchoolEventController');
        /* T2 day types (master prompt): per-date kind that drives
           attendance expectations + leave cover. Admin writes via
           the controller's teamSAWrite middleware. */
        Route::post('day-types', 'SchoolEventController@storeDayType')->name('day_types.store');
        Route::delete('day-types/{day_type}', 'SchoolEventController@destroyDayType')->name('day_types.destroy');
        /* T3 rooms & labs: physical spaces the generator assigns. */
        Route::resource('rooms', 'RoomController')->only(['index', 'store', 'update', 'destroy']);

        /*************** Circulars *****************/
        Route::patch('circulars/{circular}/toggle-publish', 'CircularController@togglePublish')->name('circulars.toggle_publish');
        Route::get('circulars/{circular}/print', 'CircularController@print')->name('circulars.print');
        Route::get('circulars/{circular}/pdf', 'CircularController@pdf')->name('circulars.pdf');
        Route::get('circulars/{circular}/official', 'CircularController@official')->name('circulars.official');
        Route::get('circulars/{circular}/attachments/{attachment}', 'CircularController@downloadAttachment')->name('circulars.attachments.download');
        Route::resource('circulars', 'CircularController');

        /*************** LMS — Assignments (Workstream CS1 + CS2 alignment) *****************/
        // Raw numeric ids under NON-{id} parameter names on purpose:
        // the global {id} binder runs through Qs::decodeHash and would
        // turn raw ids into garbage. Shapes follow CS2's blades:
        //   assignments.grade takes the SUBMISSION id (per-submission
        //     grading form on the show page);
        //   assignments.download sits ON the assignment — the plain
        //     call serves the assignment's question file, and
        //     ?submission={id} serves that submission's private file;
        //   internal preview/push hang off the assignment, exam chosen
        //     by request parameter (exam_id).
        Route::group(['prefix' => 'assignments'], function(){
            Route::get('/', 'AssignmentController@index')->name('assignments.index');
            Route::get('create', 'AssignmentController@create')->name('assignments.create');
            Route::post('/', 'AssignmentController@store')->name('assignments.store');
            Route::post('grade/{submission}', 'AssignmentController@grade')->name('assignments.grade');
            Route::get('{assignment}/internal/preview', 'AssignmentController@internalPreview')->name('assignments.internal_preview');
            Route::post('{assignment}/internal/push', 'AssignmentController@pushInternal')->name('assignments.push_internal');
            Route::get('{assignment}/download', 'AssignmentController@download')->name('assignments.download');
            Route::get('{assignment}/share', 'AssignmentController@share')->name('assignments.share');
            Route::post('{assignment}/share', 'AssignmentController@shareStore')->name('assignments.share.store');
            Route::get('{assignment}', 'AssignmentController@show')->name('assignments.show');
            Route::get('{assignment}/edit', 'AssignmentController@edit')->name('assignments.edit');
            Route::put('{assignment}', 'AssignmentController@update')->name('assignments.update');
            Route::delete('{assignment}', 'AssignmentController@destroy')->name('assignments.destroy');
            Route::post('{assignment}/submit', 'AssignmentController@submit')->name('assignments.submit');
        });

        /*************** LMS — Study Materials (Workstream CS1) *****************/
        Route::group(['prefix' => 'materials'], function(){
            Route::get('/', 'StudyMaterialController@index')->name('materials.index');
            Route::get('create', 'StudyMaterialController@create')->name('materials.create');
            Route::post('/', 'StudyMaterialController@store')->name('materials.store');
            Route::get('download/{material}', 'StudyMaterialController@download')->name('materials.download');
            Route::get('{material}/share', 'StudyMaterialController@share')->name('materials.share');
            Route::post('{material}/share', 'StudyMaterialController@shareStore')->name('materials.share.store');
            Route::delete('{material}', 'StudyMaterialController@destroy')->name('materials.destroy');
        });
        /*************** Feedback & Issues (Workstream CW) *****************/
        /* Owner workflow: everyone reports to the school Admin; the
           Admin dedupes (merge) and forwards the compiled items; the
           Super Admin sees ONLY forwarded items and replies. Role gates
           live in FeedbackController (admin / super_admin / owner). */
        Route::get('feedback/create', 'FeedbackController@create')->name('feedback.create');
        Route::post('feedback', 'FeedbackController@store')->name('feedback.store');
        Route::get('feedback/mine', 'FeedbackController@mine')->name('feedback.mine');
        Route::get('feedback/mine/{feedback}', 'FeedbackController@showMine')->name('feedback.mine.show');
        Route::get('feedback/inbox', 'FeedbackController@index')->name('feedback.index');
        Route::post('feedback/forward', 'FeedbackController@forward')->name('feedback.forward');
        Route::get('feedback/school', 'FeedbackController@saIndex')->name('feedback.sa_index');
        Route::post('feedback/school/bulk-close', 'FeedbackController@saBulkClose')->name('feedback.sa_bulk_close');
        Route::get('feedback/school/{feedback}', 'FeedbackController@saShow')->name('feedback.sa_show');
        Route::patch('feedback/school/{feedback}', 'FeedbackController@saUpdate')->name('feedback.sa_update');
        Route::get('feedback/{feedback}', 'FeedbackController@show')->name('feedback.show');
        Route::patch('feedback/{feedback}', 'FeedbackController@update')->name('feedback.update');
        Route::post('feedback/{feedback}/duplicate', 'FeedbackController@markDuplicate')->name('feedback.duplicate');
        Route::post('feedback/{feedback}/reopen', 'FeedbackController@reopen')->name('feedback.reopen');
        /*************** AI Assist (Workstream CX) *****************/
        /* Draft-only endpoints: they return text for the human to
           review in the form; saving happens through the normal
           circular / marks-comment paths. Gates live in the controller
           (teamSA for circulars, teamSAT for report comments). */
        Route::post('ai/circular-draft', 'AiAssistController@circularDraft')->name('ai.circular_draft');
        Route::post('ai/report-comment', 'AiAssistController@reportComment')->name('ai.report_comment');
        /*************** Ask Hackstra — RAG chat (Workstream DH) *****************/
        /* Answers come only from the permission-filtered corpus
           (circulars + Knowledge Base policies); the service enforces
           the audience filter before ranking and refuses honestly
           when nothing permitted covers the question. */
        Route::get('ask', 'AskController@index')->name('ask.index');
        Route::post('ask', 'AskController@ask')->name('ask.ask');

        /*************** Library *****************/
        Route::resource('books', 'BookController')->except(['show']);
        Route::resource('book_requests', 'BookRequestController')->only(['index', 'create', 'store']);
        Route::patch('book_requests/{id}/return', 'BookRequestController@markReturned')->name('book_requests.return');
        Route::get('library/fines', 'LibraryFineController@index')->name('library_fines.index');
        Route::post('library/fines/{id}/waive', 'LibraryFineController@waive')->name('library_fines.waive');

        /*************** Wave 3: parents/students/communication *****************/
        /* Controller-guarded (no team middleware): students and parents
           must reach several of these, so each controller gates its
           own actions. Super Admin reaches none of these (not
           PlatformOnly-listed). Route params are NOT {id}, so no
           hash binding applies. */
        Route::group(['prefix' => 'parent-messages'], function() {
            Route::get('/', 'ParentMessageController@index')->name('parent_messages.index');
            Route::get('create', 'ParentMessageController@create')->name('parent_messages.create');
            Route::post('/', 'ParentMessageController@store')->name('parent_messages.store');
            Route::get('{message}', 'ParentMessageController@show')->name('parent_messages.show');
        });
        Route::group(['prefix' => 'ptm'], function() {
            Route::get('/', 'PtmController@index')->name('ptm.index');
            Route::get('create', 'PtmController@create')->name('ptm.create');
            Route::post('/', 'PtmController@store')->name('ptm.store');
            Route::post('{slot}/book', 'PtmController@book')->name('ptm.book');
            Route::delete('{booking}/cancel', 'PtmController@cancel')->name('ptm.cancel');
            Route::delete('{slot}', 'PtmController@destroy')->name('ptm.destroy');
        });
        Route::group(['prefix' => 'library-reservations'], function() {
            Route::get('/', 'LibraryReservationController@index')->name('library_reservations.index');
            Route::post('/', 'LibraryReservationController@store')->name('library_reservations.store');
            Route::delete('{reservation}/cancel', 'LibraryReservationController@cancel')->name('library_reservations.cancel');
        Route::post('{reservation}/collect', 'LibraryReservationController@collect')->name('library_reservations.collect');
        });
        Route::group(['prefix' => 'health-records'], function() {
            Route::get('/', 'HealthRecordController@index')->name('health_records.index');
            Route::get('create', 'HealthRecordController@create')->name('health_records.create');
            Route::post('/', 'HealthRecordController@store')->name('health_records.store');
            Route::get('{health}', 'HealthRecordController@show')->name('health_records.show');
        });
        Route::group(['prefix' => 'discipline-records'], function() {
            Route::get('/', 'DisciplineRecordController@index')->name('discipline_records.index');
            Route::get('create', 'DisciplineRecordController@create')->name('discipline_records.create');
            Route::post('/', 'DisciplineRecordController@store')->name('discipline_records.store');
            Route::get('{discipline}', 'DisciplineRecordController@show')->name('discipline_records.show');
        });
        Route::group(['prefix' => 'documents'], function() {
            Route::get('/', 'StudentDocumentController@index')->name('documents.index');
            Route::get('create', 'StudentDocumentController@create')->name('documents.create');
            Route::post('/', 'StudentDocumentController@store')->name('documents.store');
            Route::get('{document}/download', 'StudentDocumentController@download')->name('documents.download');
            Route::delete('{document}', 'StudentDocumentController@destroy')->name('documents.destroy');
        });
        Route::group(['prefix' => 'alumni'], function() {
            Route::get('/', 'AlumniController@index')->name('alumni.index');
            Route::get('{alumnus}', 'AlumniController@show')->name('alumni.show');
        });
        Route::group(['prefix' => 'electives'], function() {
            Route::get('/', 'ElectiveChoiceController@index')->name('electives.index');
            Route::post('/', 'ElectiveChoiceController@store')->name('electives.store');
            Route::post('{elective}/approve', 'ElectiveChoiceController@approve')->name('electives.approve');
            Route::post('{elective}/reject', 'ElectiveChoiceController@reject')->name('electives.reject');
        });
        Route::group(['prefix' => 'role-permissions'], function() {
            Route::get('/', 'RolePermissionController@index')->name('role_permissions.index');
            Route::get('create', 'RolePermissionController@create')->name('role_permissions.create');
            Route::post('/', 'RolePermissionController@store')->name('role_permissions.store');
            Route::delete('{grant}', 'RolePermissionController@destroy')->name('role_permissions.destroy');
        });

    });

    /************************ AJAX ****************************/
    Route::group(['prefix' => 'ajax'], function() {
        Route::get('get_lga/{state_id}', 'AjaxController@get_lga')->name('get_lga');
        Route::get('get_class_sections/{class_id}', 'AjaxController@get_class_sections')->name('get_class_sections');
        Route::get('get_class_subjects/{class_id}', 'AjaxController@get_class_subjects')->name('get_class_subjects');
    });

});

/************************ SCHOOL SETTINGS (teamSA: Super Admin + school Admin) ****************************/
/* Workstream AB: school settings (system name, logo, current session, exam
   lock) are day-to-day SCHOOL operations, so the school Admin — who runs the
   school — manages them alongside the Super Admin. Same URLs and route names
   as before; only the gate widened from super_admin to teamSA. */
Route::group(['namespace' => 'SuperAdmin','middleware' => 'teamSA', 'prefix' => 'super_admin'], function(){

    Route::get('/settings', 'SettingController@index')->name('settings');
    Route::put('/settings', 'SettingController@update')->name('settings.update');
    /* Settings lock (owner order 2026-10-29): the Admin locks the
       settings and files the written unlock request from here;
       the two-stage decision lives on the shared requests page. */
    Route::post('/settings/lock', '\App\Http\Controllers\SupportTeam\SettingsLockController@lock')->name('settings.lock');
    Route::post('/settings/unlock-request', '\App\Http\Controllers\SupportTeam\SettingsLockController@requestUnlock')->name('settings.unlock_request');

    /*************** AI Assistant + Knowledge Base (Workstream DH) *****************/
    /* The ai_* keys are platform configuration: AiSettingsController
       restricts writes to the Super Admin (the school Admin sees the
       card read-only). Knowledge Base policies and index rebuilds are
       teamSA, like the rest of this settings surface. */
    Route::put('/settings/ai', 'AiSettingsController@update')->name('settings.ai_update');
    Route::post('/settings/knowledge', 'RagKnowledgeController@store')->name('settings.knowledge.store');
    Route::delete('/settings/knowledge/{document}', 'RagKnowledgeController@destroy')->name('settings.knowledge.destroy');
    Route::post('/settings/knowledge/rebuild', 'RagKnowledgeController@rebuild')->name('settings.knowledge.rebuild');

});

/************************ AUDIT LOG (Workstream CM: teamSA reads) ****************************/
/* The school Admin gains a read of the school's trail (owner ruling):
   the controller scopes Admin to entries NOT acted by a super_admin —
   the company's own platform activity stays Super-Admin-only. */
Route::group(['namespace' => 'SuperAdmin','middleware' => 'teamOrPrincipal:SA', 'prefix' => 'super_admin'], function(){

    Route::get('/audit-logs', 'AuditLogController@index')->name('audit_logs.index');

});

/************************ PLATFORM MAIL (Workstream DF: Super Admin only) ****************************/
/* The platform owner's mail composer + sent-mail log. A platform
   tool — the school's own communication channel stays Circulars. */
Route::group(['namespace' => 'SuperAdmin','middleware' => 'super_admin', 'prefix' => 'super_admin'], function(){

    Route::get('/platform-mail', 'PlatformMailController@create')->name('platform_mail.create');
    Route::post('/platform-mail', 'PlatformMailController@store')->name('platform_mail.send');
    Route::get('/platform-mail/sent', 'PlatformMailController@index')->name('platform_mail.index');

});

/************************ PARENT ****************************/
Route::group(['namespace' => 'MyParent','middleware' => 'my_parent',], function(){

    Route::get('/my_children', 'MyController@children')->name('my_children');

    /* Fees portal (Loop 9, Workstream O) — parameter names avoid {id} on
       purpose: {id} is bound through Qs::decodeHash app-wide, while these
       carry raw ids and are protected by ownership checks + policies. */
    Route::get('/my_fees/receipt/{pr_id}', 'MyController@feesReceipt')->name('my_fees.receipt');
    /* Online payment (Workstream CU) — order + verified callback */
    Route::post('/my_fees/pay/{pr_id}', 'OnlinePaymentController@checkout')->name('my_fees.pay');
    Route::post('/my_fees/pay_callback', 'OnlinePaymentController@callback')->name('my_fees.pay_callback');
    Route::get('/my_fees', 'MyController@fees')->name('my_fees');
    Route::get('/my_fees/{student_id}', 'MyController@feesShow')->name('my_fees.show');

});

/************************ STUDENT ****************************/
/* Student self-service portal (2026-10-23): own fees, own library,
   school events — all read-only, all scoped server-side to the
   signed-in student (PortalController takes no student parameter).
   Paths follow the module/mine convention (tt.mine, id_card.mine);
   /school-events avoids the teamSA events resource's {event} binding. */
Route::group(['namespace' => 'MyStudent', 'middleware' => 'student'], function () {
    Route::get('/fees/mine', 'PortalController@fees')->name('student.fees');
    Route::get('/fees/mine/receipt/{pr_id}', 'PortalController@feesReceipt')->name('student.fees.receipt');
    Route::get('/library/mine', 'PortalController@library')->name('student.library');
    Route::get('/school-events', 'PortalController@events')->name('student.events');
});

/************************ PAYMENT GATEWAY WEBHOOK (Workstream CU) ****************************/
/* Public endpoint called by Razorpay's servers. CSRF-exempt (see
   VerifyCsrfToken) — the webhook signature is the credential. */
Route::post('/webhooks/razorpay', 'GatewayWebhookController@razorpay')->name('webhooks.razorpay');
