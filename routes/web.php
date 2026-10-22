<?php

Auth::routes();

//Route::get('/test', 'TestController@index')->name('test');
Route::get('/privacy-policy', 'HomeController@privacy_policy')->name('privacy_policy');
Route::get('/terms-of-use', 'HomeController@terms_of_use')->name('terms_of_use');


Route::group(['middleware' => 'auth'], function () {

    Route::get('/', 'HomeController@dashboard')->name('home');
    Route::get('/home', 'HomeController@dashboard');
    Route::get('/dashboard', 'HomeController@dashboard')->name('dashboard');
    Route::get('/dashboard/school', 'HomeController@schoolDashboard')->name('dashboard.school');
    Route::get('/dashboard/search', 'DashboardSearchController')->name('dashboard.search');

    // Workstream CZ (LMS): bell notifications — opening the bell
    // marks the caller's per-user notifications read.
    Route::post('/notifications/read', 'NotificationController@markRead')->name('notifications.read');

    Route::group(['prefix' => 'my_account'], function() {
        Route::get('/', 'MyAccountController@edit_profile')->name('my_account');
        Route::put('/', 'MyAccountController@update_profile')->name('my_account.update');
        Route::put('/change_password', 'MyAccountController@change_pass')->name('my_account.change_pass');
        Route::post('/preferences', 'MyAccountController@update_preferences')->name('my_account.preferences');
        Route::post('/theme', 'MyAccountController@update_theme')->name('my_account.theme');
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
            Route::get('list/{class_id}', 'StudentRecordController@listByClass')->name('students.list')->middleware('teamSAT');
            Route::get('export/{class_id}/{section_id?}', 'StudentRecordController@export')->name('students.export')->middleware('teamSAT');

            /* Bulk operations — CSV import + bulk delete (Workstream AA).
               teamSA gate lives in StudentBulkController's constructor,
               same pattern as PromotionController. No {id} params: the
               delete list travels in the POST body. */
            Route::get('import', 'StudentBulkController@import')->name('students.import');
            Route::get('import/template', 'StudentBulkController@importTemplate')->name('students.import.template');
            Route::post('import', 'StudentBulkController@importStore')->name('students.import.store');
            Route::post('bulk_delete', 'StudentBulkController@bulkDelete')->name('students.bulk_delete');

            /* Promotions */
            Route::post('promote_selector', 'PromotionController@selector')->name('students.promote_selector');
            Route::get('promotion/manage', 'PromotionController@manage')->name('students.promotion_manage');
            Route::delete('promotion/reset/{pid}', 'PromotionController@reset')->name('students.promotion_reset');
            Route::delete('promotion/reset_all', 'PromotionController@reset_all')->name('students.promotion_reset_all');
            Route::get('promotion/{fc?}/{fs?}/{tc?}/{ts?}', 'PromotionController@promotion')->name('students.promotion');
            Route::post('promote/{fc}/{fs}/{tc}/{ts}', 'PromotionController@promote')->name('students.promote');

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
            Route::get('mine', 'TimeTableController@mine')->name('tt.mine');

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
                });

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
            Route::get('reports/defaulters/export', 'FeeReportController@defaultersExport')->name('payments.defaulters.export');
            Route::get('reports/collection', 'FeeReportController@collection')->name('payments.collection');
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
            Route::get('{payroll}', 'PayrollController@myPayslip')->name('my_payslips.payslip');
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
           // tabulation sheet + its print document; the selector POST
           // only redirects into that view.
            Route::group(['middleware' => 'teamSA'], function(){
                Route::get('tabulation/{exam?}/{class?}/{sec_id?}', 'MarkController@tabulation')->name('marks.tabulation');
                Route::post('tabulation', 'MarkController@tabulation_select')->name('marks.tabulation_select');
                Route::get('tabulation/print/{exam}/{class}/{sec_id}', 'MarkController@print_tabulation')->name('marks.print_tabulation');
            });

            // Marks Correction (batch fix) is a WRITE — Admin only.
            Route::group(['middleware' => 'teamSAWrite'], function(){
                Route::get('batch_fix', 'MarkController@batch_fix')->name('marks.batch_fix');
                Route::put('batch_update', 'MarkController@batch_update')->name('marks.batch_update');
            });

            // FOR teamSATWrite (Workstream CM): marks entry is a write
            // surface — Admin + assigned teachers; SA's marks read is
            // show/tabulation below, untouched.
            Route::group(['middleware' => 'teamSATWrite'], function(){
                Route::get('/', 'MarkController@index')->name('marks.index');
                Route::get('manage/{exam}/{class}/{section}/{subject}', 'MarkController@manage')->name('marks.manage');
                Route::put('update/{exam}/{class}/{section}/{subject}', 'MarkController@update')->name('marks.update');
                Route::put('comment_update/{exr_id}', 'MarkController@comment_update')->name('marks.comment_update');
                Route::put('skills_update/{skill}/{exr_id}', 'MarkController@skills_update')->name('marks.skills_update');
                Route::post('selector', 'MarkController@selector')->name('marks.selector');
                Route::get('bulk/{class?}/{section?}', 'MarkController@bulk')->name('marks.bulk');
                Route::post('bulk', 'MarkController@bulk_select')->name('marks.bulk_select');
            });

            Route::get('select_year/{id}', 'MarkController@year_selector')->name('marks.year_selector');
            Route::post('select_year/{id}', 'MarkController@year_selected')->name('marks.year_select');
            Route::get('show/{id}/{year}', 'MarkController@show')->name('marks.show');
            Route::get('print/{id}/{exam_id}/{year}', 'MarkController@print_view')->name('marks.print');
            Route::get('annual/{id}/{year}', 'MarkController@show_annual')->name('marks.annual');
            Route::get('print_annual/{id}/{year}', 'MarkController@print_annual')->name('marks.print_annual');

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

        Route::resource('students', 'StudentRecordController');
        Route::resource('users', 'UserController');
        Route::resource('classes', 'MyClassController');
        Route::resource('sections', 'SectionController');
        Route::resource('subjects', 'SubjectController');
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
        Route::get('attendance/defaulters', 'AttendanceController@defaulters')->name('attendance.defaulters');
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
        Route::get('attendance/periods/{tt_id}/take', 'PeriodAttendanceController@take')->name('attendance.periods.take');
        Route::post('attendance/periods/{tt_id}', 'PeriodAttendanceController@store')->name('attendance.periods.store');

        /*************** School Events *****************/
        Route::resource('events', 'SchoolEventController');

        /*************** Circulars *****************/
        Route::patch('circulars/{circular}/toggle-publish', 'CircularController@togglePublish')->name('circulars.toggle_publish');
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
        Route::get('feedback/school/{feedback}', 'FeedbackController@saShow')->name('feedback.sa_show');
        Route::patch('feedback/school/{feedback}', 'FeedbackController@saUpdate')->name('feedback.sa_update');
        Route::get('feedback/{feedback}', 'FeedbackController@show')->name('feedback.show');
        Route::patch('feedback/{feedback}', 'FeedbackController@update')->name('feedback.update');
        Route::post('feedback/{feedback}/duplicate', 'FeedbackController@markDuplicate')->name('feedback.duplicate');
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
Route::group(['namespace' => 'SuperAdmin','middleware' => 'teamSA', 'prefix' => 'super_admin'], function(){

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

/************************ PAYMENT GATEWAY WEBHOOK (Workstream CU) ****************************/
/* Public endpoint called by Razorpay's servers. CSRF-exempt (see
   VerifyCsrfToken) — the webhook signature is the credential. */
Route::post('/webhooks/razorpay', 'GatewayWebhookController@razorpay')->name('webhooks.razorpay');
