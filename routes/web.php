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

    Route::group(['prefix' => 'my_account'], function() {
        Route::get('/', 'MyAccountController@edit_profile')->name('my_account');
        Route::put('/', 'MyAccountController@update_profile')->name('my_account.update');
        Route::put('/change_password', 'MyAccountController@change_pass')->name('my_account.change_pass');
        Route::post('/preferences', 'MyAccountController@update_preferences')->name('my_account.preferences');
        Route::get('/download', 'MyAccountController@download_data')->name('my_account.download');
    });

    /*************** Support Team *****************/
    Route::group(['namespace' => 'SupportTeam',], function(){

        /*************** Students *****************/
        Route::group(['prefix' => 'students'], function(){
            Route::get('reset_pass/{st_id}', 'StudentRecordController@reset_pass')->name('st.reset_pass');
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
            Route::get('reset_pass/{id}', 'UserController@reset_pass')->name('users.reset_pass');
        });

        /*************** TimeTables *****************/
        Route::group(['prefix' => 'timetables'], function(){
            Route::get('/', 'TimeTableController@index')->name('tt.index');
            Route::get('mine', 'TimeTableController@mine')->name('tt.mine');

            Route::group(['middleware' => 'teamSA'], function() {
                Route::post('/', 'TimeTableController@store')->name('tt.store');
                Route::put('/{tt}', 'TimeTableController@update')->name('tt.update');
                Route::delete('/{tt}', 'TimeTableController@delete')->name('tt.delete');
            });

            /*************** TimeTable Records *****************/
            Route::group(['prefix' => 'records'], function(){

                Route::group(['middleware' => 'teamSA'], function(){
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
            Route::group(['prefix' => 'time_slots', 'middleware' => 'teamSA'], function(){
                Route::post('/', 'TimeTableController@store_time_slot')->name('ts.store');
                Route::post('/use/{ttr}', 'TimeTableController@use_time_slot')->name('ts.use');
                Route::get('edit/{ts}', 'TimeTableController@edit_time_slot')->name('ts.edit');
                Route::delete('/{ts}', 'TimeTableController@delete_time_slot')->name('ts.destroy');
                Route::put('/{ts}', 'TimeTableController@update_time_slot')->name('ts.update');
            });

        });

        /*************** Payments *****************/
        Route::group(['prefix' => 'payments'], function(){

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

        /*************** Pins *****************/
        Route::group(['prefix' => 'pins'], function(){
            Route::get('create', 'PinController@create')->name('pins.create');
            Route::get('/', 'PinController@index')->name('pins.index');
            Route::post('/', 'PinController@store')->name('pins.store');
            Route::get('enter/{id}', 'PinController@enter_pin')->name('pins.enter');
            Route::post('verify/{id}', 'PinController@verify')->name('pins.verify');
            Route::delete('/', 'PinController@destroy')->name('pins.destroy');
        });

        /*************** Marks *****************/
        Route::group(['prefix' => 'marks'], function(){

           // FOR teamSA
            Route::group(['middleware' => 'teamSA'], function(){
                Route::get('batch_fix', 'MarkController@batch_fix')->name('marks.batch_fix');
                Route::put('batch_update', 'MarkController@batch_update')->name('marks.batch_update');
                Route::get('tabulation/{exam?}/{class?}/{sec_id?}', 'MarkController@tabulation')->name('marks.tabulation');
                Route::post('tabulation', 'MarkController@tabulation_select')->name('marks.tabulation_select');
                Route::get('tabulation/print/{exam}/{class}/{sec_id}', 'MarkController@print_tabulation')->name('marks.print_tabulation');
            });

            // FOR teamSAT
            Route::group(['middleware' => 'teamSAT'], function(){
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

        });

        /*************** Admit Cards *****************/
        Route::group(['prefix' => 'exams/admit-cards'], function(){
            Route::get('/', 'AdmitCardController@index')->name('admit_cards.index');
            Route::get('print/{exam}/{class}', 'AdmitCardController@printSheet')->name('admit_cards.print');
        });
        Route::get('my-admit-cards', 'AdmitCardController@myCards')->name('admit_cards.my');
        Route::get('my-admit-cards/{exam}/{student_id}', 'AdmitCardController@myCard')->name('admit_cards.my_card');

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

});

/************************ SUPER ADMIN (platform owner only) ****************************/
Route::group(['namespace' => 'SuperAdmin','middleware' => 'super_admin', 'prefix' => 'super_admin'], function(){

    Route::get('/audit-logs', 'AuditLogController@index')->name('audit_logs.index');

});

/************************ PARENT ****************************/
Route::group(['namespace' => 'MyParent','middleware' => 'my_parent',], function(){

    Route::get('/my_children', 'MyController@children')->name('my_children');

    /* Fees portal (Loop 9, Workstream O) — parameter names avoid {id} on
       purpose: {id} is bound through Qs::decodeHash app-wide, while these
       carry raw ids and are protected by ownership checks + policies. */
    Route::get('/my_fees/receipt/{pr_id}', 'MyController@feesReceipt')->name('my_fees.receipt');
    Route::get('/my_fees', 'MyController@fees')->name('my_fees');
    Route::get('/my_fees/{student_id}', 'MyController@feesShow')->name('my_fees.show');

});
