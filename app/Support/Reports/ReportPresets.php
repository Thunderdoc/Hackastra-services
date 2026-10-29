<?php

namespace App\Support\Reports;

/**
 * Role-specific template libraries for the Universal Report Builder.
 *
 * A preset is a PLAIN CONFIG ARRAY rendered by ReportEngine — never a
 * generator, never a blade of its own. Changing a preset therefore
 * cannot change any other report. Role filtering uses the same
 * ReportSources gates, so a preset can never smuggle a forbidden
 * source past the engine (the engine re-checks anyway).
 *
 * Honest gaps, by design: there is no Expense model, so no expense
 * preset exists; BookRequest has only issued/returned statuses, so
 * the Lost/Damaged register is a blank manual sheet, not data.
 */
class ReportPresets
{
    /** All presets; forRole() filters by the 'roles' key. */
    public static function all(): array
    {
        $box = function ($key, $label) {
            return ['key' => $key, 'label' => $label, 'type' => 'box', 'width' => 7];
        };
        $std = ['orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
            'show_header' => true, 'show_meta' => true, 'show_footer' => true,
            'show_signatures' => false, 'blank_rows' => 0, 'totals' => [], 'sort' => null];

        return [
            // ---------------- Teacher ----------------
            [
                'key' => 'teacher_roster', 'label' => 'Class Roster', 'source' => 'students',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Class Roster', 'source' => 'students', 'filters' => [],
                    'columns' => [
                        ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 10],
                        $box('custom_1', 'P'), $box('custom_2', 'A'), $box('custom_3', 'L'),
                    ],
                    'options' => array_merge($std, ['show_signatures' => true]),
                    'instructions' => 'Blank P / A / L boxes are for manual marking.',
                ],
            ],
            [
                'key' => 'teacher_attendance_sheet', 'label' => 'Attendance Sheet', 'source' => 'students',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Attendance Sheet', 'source' => 'students', 'filters' => [],
                    'columns' => [
                        ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 10],
                        $box('custom_1', 'P'), $box('custom_2', 'A'), $box('custom_3', 'L'),
                        ['key' => 'custom_4', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 20],
                    ],
                    'options' => array_merge($std, ['show_signatures' => true]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'teacher_marks_sheet', 'label' => 'Marks and Assessment Sheet', 'source' => 'marks',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Marks and Assessment Sheet', 'source' => 'marks', 'filters' => [],
                    'columns' => [
                        ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 28],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 16],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 10],
                        ['key' => 'total', 'label' => 'Total', 'type' => 'number', 'width' => 10],
                        ['key' => 'custom_1', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 22],
                    ],
                    'options' => array_merge($std, ['totals' => ['total' => 'avg'], 'show_signatures' => true]),
                    'instructions' => 'Component columns (Theory, Internal, Viva, …) are added automatically for configured marking schemes.',
                ],
            ],
            [
                'key' => 'teacher_homework_tracker', 'label' => 'Homework/Assignment Tracker', 'source' => 'blank',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Homework/Assignment Tracker', 'source' => 'blank', 'filters' => [],
                    'columns' => [
                        ['key' => 'custom_1', 'label' => 'Assignment', 'type' => 'text', 'width' => 30],
                        ['key' => 'custom_2', 'label' => 'Subject', 'type' => 'text', 'width' => 18],
                        ['key' => 'custom_3', 'label' => 'Due Date', 'type' => 'date', 'width' => 14],
                        ['key' => 'custom_4', 'label' => 'Submitted', 'type' => 'checkbox', 'width' => 10],
                        ['key' => 'custom_5', 'label' => 'Marks', 'type' => 'number', 'width' => 10],
                        ['key' => 'custom_6', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 18],
                    ],
                    'options' => array_merge($std, ['blank_rows' => 20]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'teacher_contact_list', 'label' => 'Student Contact List', 'source' => 'students',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Student Contact List', 'source' => 'students', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 26],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 16],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 8],
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'width' => 16],
                        ['key' => 'parent', 'label' => 'Parent/Guardian', 'type' => 'text', 'width' => 22],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'teacher_seating_plan', 'label' => 'Seating Plan', 'source' => 'students',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Seating Plan', 'source' => 'students', 'filters' => [],
                    'columns' => [
                        ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 32],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 10],
                        ['key' => 'custom_1', 'label' => 'Seat No.', 'type' => 'text', 'width' => 14],
                        ['key' => 'custom_2', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 30],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'teacher_lab_record', 'label' => 'Practical/Lab Record', 'source' => 'students',
                'roles' => ['teacher', 'admin'],
                'config' => [
                    'title' => 'Practical/Lab Record', 'source' => 'students', 'filters' => [],
                    'columns' => [
                        ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 28],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 10],
                        ['key' => 'custom_1', 'label' => 'Experiment', 'type' => 'text', 'width' => 26],
                        ['key' => 'custom_2', 'label' => 'Marks', 'type' => 'number', 'width' => 10],
                        ['key' => 'custom_3', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 18],
                    ],
                    'options' => array_merge($std, ['show_signatures' => true]),
                    'instructions' => '',
                ],
            ],
            // ---------------- Accountant ----------------
            [
                'key' => 'acct_fee_register', 'label' => 'Fee Collection Register', 'source' => 'fees',
                'roles' => ['accountant', 'admin'],
                'config' => [
                    'title' => 'Fee Collection Register', 'source' => 'fees', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 14],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 11],
                        ['key' => 'fee_title', 'label' => 'Fee Title', 'type' => 'text', 'width' => 20],
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'width' => 12],
                        ['key' => 'amt_paid', 'label' => 'Paid', 'type' => 'currency', 'width' => 12],
                        ['key' => 'balance', 'label' => 'Balance', 'type' => 'currency', 'width' => 12],
                        ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'width' => 12],
                    ],
                    'options' => array_merge($std, ['orientation' => 'landscape', 'totals' => ['amount' => 'sum', 'amt_paid' => 'sum', 'balance' => 'sum'], 'show_signatures' => true]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'acct_daily_cash', 'label' => 'Daily Cash Collection', 'source' => 'fees',
                'roles' => ['accountant', 'admin'],
                'config' => [
                    'title' => 'Daily Cash Collection', 'source' => 'fees', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 24],
                        ['key' => 'fee_title', 'label' => 'Fee Title', 'type' => 'text', 'width' => 24],
                        ['key' => 'amt_paid', 'label' => 'Paid', 'type' => 'currency', 'width' => 14],
                        ['key' => 'ref_no', 'label' => 'Receipt Ref', 'type' => 'text', 'width' => 16],
                        ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'width' => 12],
                    ],
                    'options' => array_merge($std, ['totals' => ['amt_paid' => 'sum'], 'show_signatures' => true]),
                    'instructions' => 'Filter by a single date to get one day’s collection.',
                ],
            ],
            [
                'key' => 'acct_pending_fees', 'label' => 'Pending Fees', 'source' => 'defaulters',
                'roles' => ['accountant', 'admin'],
                'config' => [
                    'title' => 'Pending Fees', 'source' => 'defaulters', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 24],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 15],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 11],
                        ['key' => 'total_due', 'label' => 'Amount Due', 'type' => 'currency', 'width' => 14],
                        ['key' => 'total_paid', 'label' => 'Paid', 'type' => 'currency', 'width' => 13],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 10],
                    ],
                    'options' => array_merge($std, ['totals' => ['total_due' => 'sum', 'total_paid' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'acct_defaulters', 'label' => 'Fee Defaulters', 'source' => 'defaulters',
                'roles' => ['accountant', 'admin'],
                'config' => [
                    'title' => 'Fee Defaulters', 'source' => 'defaulters', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 14],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 11],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 8],
                        ['key' => 'total_due', 'label' => 'Amount Due', 'type' => 'currency', 'width' => 13],
                        ['key' => 'items', 'label' => 'Unpaid Items', 'type' => 'number', 'width' => 10],
                        ['key' => 'oldest_title', 'label' => 'Oldest Fee', 'type' => 'text', 'width' => 20],
                    ],
                    'options' => array_merge($std, ['totals' => ['total_due' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'acct_fine_register', 'label' => 'Fine Collection Register', 'source' => 'fines',
                'roles' => ['accountant', 'admin'],
                'config' => [
                    'title' => 'Fine Collection Register', 'source' => 'fines', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                        ['key' => 'book', 'label' => 'Book', 'type' => 'text', 'width' => 24],
                        ['key' => 'amount', 'label' => 'Fine', 'type' => 'currency', 'width' => 12],
                        ['key' => 'days_overdue', 'label' => 'Days Overdue', 'type' => 'number', 'width' => 10],
                        ['key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'width' => 22],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 10],
                    ],
                    'options' => array_merge($std, ['totals' => ['amount' => 'sum'], 'show_signatures' => true]),
                    'instructions' => '',
                ],
            ],
            // ---------------- Librarian ----------------
            [
                'key' => 'lib_inventory', 'label' => 'Book Inventory', 'source' => 'books',
                'roles' => ['librarian', 'admin'],
                'config' => [
                    'title' => 'Book Inventory', 'source' => 'books', 'filters' => [],
                    'columns' => [
                        ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 11],
                        ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'width' => 20],
                        ['key' => 'book_type', 'label' => 'Type', 'type' => 'text', 'width' => 12],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'width' => 14],
                        ['key' => 'total_copies', 'label' => 'Total', 'type' => 'number', 'width' => 8],
                        ['key' => 'issued_copies', 'label' => 'Issued', 'type' => 'number', 'width' => 8],
                        ['key' => 'available', 'label' => 'Available', 'type' => 'number', 'width' => 9],
                    ],
                    'options' => array_merge($std, ['orientation' => 'landscape', 'totals' => ['total_copies' => 'sum', 'issued_copies' => 'sum', 'available' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'lib_issue_register', 'label' => 'Issue/Return Register', 'source' => 'issues',
                'roles' => ['librarian', 'admin'],
                'config' => [
                    'title' => 'Issue/Return Register', 'source' => 'issues', 'filters' => [],
                    'columns' => [
                        ['key' => 'book', 'label' => 'Book', 'type' => 'text', 'width' => 26],
                        ['key' => 'borrower', 'label' => 'Borrower', 'type' => 'text', 'width' => 22],
                        ['key' => 'start_date', 'label' => 'Issued On', 'type' => 'date', 'width' => 12],
                        ['key' => 'end_date', 'label' => 'Due On', 'type' => 'date', 'width' => 12],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 11],
                        ['key' => 'returned', 'label' => 'Returned', 'type' => 'text', 'width' => 10],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'lib_overdue', 'label' => 'Overdue Books', 'source' => 'issues',
                'roles' => ['librarian', 'admin'],
                'config' => [
                    'title' => 'Overdue Books', 'source' => 'issues', 'filters' => ['status' => 'overdue'],
                    'columns' => [
                        ['key' => 'book', 'label' => 'Book', 'type' => 'text', 'width' => 28],
                        ['key' => 'borrower', 'label' => 'Borrower', 'type' => 'text', 'width' => 24],
                        ['key' => 'end_date', 'label' => 'Due On', 'type' => 'date', 'width' => 12],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 11],
                    ],
                    'options' => $std,
                    'instructions' => 'Overdue means issued, not returned, and past its due date.',
                ],
            ],
            [
                'key' => 'lib_fine_register', 'label' => 'Library Fine Register', 'source' => 'fines',
                'roles' => ['librarian', 'admin'],
                'config' => [
                    'title' => 'Library Fine Register', 'source' => 'fines', 'filters' => [],
                    'columns' => [
                        ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                        ['key' => 'book', 'label' => 'Book', 'type' => 'text', 'width' => 24],
                        ['key' => 'amount', 'label' => 'Fine', 'type' => 'currency', 'width' => 12],
                        ['key' => 'days_overdue', 'label' => 'Days Overdue', 'type' => 'number', 'width' => 10],
                        ['key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'width' => 22],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 10],
                    ],
                    'options' => array_merge($std, ['totals' => ['amount' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'lib_lost_damaged', 'label' => 'Lost/Damaged Register', 'source' => 'blank',
                'roles' => ['librarian', 'admin'],
                'config' => [
                    'title' => 'Lost/Damaged Register', 'source' => 'blank', 'filters' => [],
                    'columns' => [
                        ['key' => 'custom_1', 'label' => 'Book', 'type' => 'text', 'width' => 28],
                        ['key' => 'custom_2', 'label' => 'Borrower', 'type' => 'text', 'width' => 22],
                        ['key' => 'custom_3', 'label' => 'Condition', 'type' => 'dropdown', 'width' => 16],
                        ['key' => 'custom_4', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 24],
                        ['key' => 'custom_5', 'label' => 'Signature', 'type' => 'signature', 'width' => 14],
                    ],
                    'options' => array_merge($std, ['blank_rows' => 20]),
                    'instructions' => 'Manual register: the system tracks only issued/returned loans, so lost/damaged entries are written by hand.',
                ],
            ],
            // ---------------- Admin-only ----------------
            [
                'key' => 'admin_staff', 'label' => 'Staff Directory', 'source' => 'staff',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Staff Directory', 'source' => 'staff', 'filters' => [],
                    'columns' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
                        ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'width' => 24],
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'width' => 14],
                        ['key' => 'user_type', 'label' => 'Role', 'type' => 'text', 'width' => 12],
                        ['key' => 'code', 'label' => 'Staff Code', 'type' => 'text', 'width' => 12],
                        ['key' => 'emp_date', 'label' => 'Joined', 'type' => 'date', 'width' => 12],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_admissions', 'label' => 'Admission Enquiries', 'source' => 'admissions',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Admission Enquiries', 'source' => 'admissions', 'filters' => [],
                    'columns' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'width' => 14],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 13],
                        ['key' => 'session', 'label' => 'Session', 'type' => 'text', 'width' => 12],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_certificates', 'label' => 'Certificates Issued', 'source' => 'certificates',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Certificates Issued', 'source' => 'certificates', 'filters' => [],
                    'columns' => [
                        ['key' => 'student_name', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                        ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 14],
                        ['key' => 'class_name', 'label' => 'Class', 'type' => 'text', 'width' => 11],
                        ['key' => 'section_name', 'label' => 'Section', 'type' => 'text', 'width' => 8],
                        ['key' => 'type', 'label' => 'Type', 'type' => 'text', 'width' => 12],
                        ['key' => 'serial', 'label' => 'Serial', 'type' => 'text', 'width' => 16],
                        ['key' => 'session', 'label' => 'Session', 'type' => 'text', 'width' => 11],
                        ['key' => 'issued_at', 'label' => 'Issued On', 'type' => 'date', 'width' => 12],
                    ],
                    'options' => array_merge($std, ['orientation' => 'landscape']),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_classes', 'label' => 'Class List', 'source' => 'classes',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Class List', 'source' => 'classes', 'filters' => [],
                    'columns' => [
                        ['key' => 'name', 'label' => 'Class', 'type' => 'text', 'width' => 40],
                        ['key' => 'sections', 'label' => 'Sections', 'type' => 'number', 'width' => 20],
                        ['key' => 'students', 'label' => 'Students', 'type' => 'number', 'width' => 20],
                    ],
                    'options' => array_merge($std, ['totals' => ['students' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_circulars', 'label' => 'Circulars', 'source' => 'circulars',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Circulars', 'source' => 'circulars', 'filters' => [],
                    'columns' => [
                        ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'width' => 30],
                        ['key' => 'audience', 'label' => 'Audience', 'type' => 'text', 'width' => 14],
                        ['key' => 'priority', 'label' => 'Priority', 'type' => 'text', 'width' => 10],
                        ['key' => 'published', 'label' => 'Published', 'type' => 'text', 'width' => 10],
                        ['key' => 'starts_at', 'label' => 'Starts', 'type' => 'date', 'width' => 12],
                        ['key' => 'expires_at', 'label' => 'Expires', 'type' => 'date', 'width' => 12],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_timetables', 'label' => 'Timetables', 'source' => 'timetables',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Timetables', 'source' => 'timetables', 'filters' => [],
                    'columns' => [
                        ['key' => 'name', 'label' => 'Timetable', 'type' => 'text', 'width' => 26],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 8],
                        ['key' => 'exam', 'label' => 'Exam', 'type' => 'text', 'width' => 20],
                        ['key' => 'year', 'label' => 'Session', 'type' => 'text', 'width' => 12],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_fee_summary', 'label' => 'Fee Summary', 'source' => 'fee_summary',
                'roles' => ['admin', 'accountant'],
                'config' => [
                    'title' => 'Fee Summary', 'source' => 'fee_summary', 'filters' => [],
                    'columns' => [
                        ['key' => 'fee_title', 'label' => 'Fee Title', 'type' => 'text', 'width' => 24],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'records', 'label' => 'Records', 'type' => 'number', 'width' => 10],
                        ['key' => 'invoiced', 'label' => 'Invoiced', 'type' => 'currency', 'width' => 14],
                        ['key' => 'collected', 'label' => 'Collected', 'type' => 'currency', 'width' => 14],
                        ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'currency', 'width' => 14],
                    ],
                    'options' => array_merge($std, ['orientation' => 'landscape', 'totals' => ['invoiced' => 'sum', 'collected' => 'sum', 'outstanding' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_attendance_summary', 'label' => 'Attendance Summary', 'source' => 'attendance_summary',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Attendance Summary', 'source' => 'attendance_summary', 'filters' => [],
                    'columns' => [
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'width' => 20],
                        ['key' => 'sessions', 'label' => 'Sessions', 'type' => 'number', 'width' => 10],
                        ['key' => 'present', 'label' => 'Present', 'type' => 'number', 'width' => 10],
                        ['key' => 'absent', 'label' => 'Absent', 'type' => 'number', 'width' => 10],
                        ['key' => 'late', 'label' => 'Late', 'type' => 'number', 'width' => 10],
                        ['key' => 'percentage', 'label' => 'Attendance %', 'type' => 'number', 'width' => 12],
                    ],
                    'options' => $std,
                    'instructions' => 'Percentage = (Present + Late) / Marked x 100, the same rule as the attendance pages.',
                ],
            ],
            [
                'key' => 'admin_results_summary', 'label' => 'Results Summary', 'source' => 'results_summary',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Results Summary', 'source' => 'results_summary', 'filters' => [],
                    'columns' => [
                        ['key' => 'exam', 'label' => 'Exam', 'type' => 'text', 'width' => 24],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'students', 'label' => 'Students', 'type' => 'number', 'width' => 10],
                        ['key' => 'average_total', 'label' => 'Average Total', 'type' => 'number', 'width' => 14],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_library_summary', 'label' => 'Library Summary', 'source' => 'library_summary',
                'roles' => ['admin', 'librarian'],
                'config' => [
                    'title' => 'Library Summary', 'source' => 'library_summary', 'filters' => [],
                    'columns' => [
                        ['key' => 'book_type', 'label' => 'Type', 'type' => 'text', 'width' => 20],
                        ['key' => 'titles', 'label' => 'Titles', 'type' => 'number', 'width' => 10],
                        ['key' => 'copies', 'label' => 'Copies', 'type' => 'number', 'width' => 10],
                        ['key' => 'issued', 'label' => 'Issued', 'type' => 'number', 'width' => 10],
                        ['key' => 'available', 'label' => 'Available', 'type' => 'number', 'width' => 10],
                    ],
                    'options' => array_merge($std, ['totals' => ['titles' => 'sum', 'copies' => 'sum', 'issued' => 'sum', 'available' => 'sum']]),
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_assignments', 'label' => 'Assignment Register', 'source' => 'assignments',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Assignment Register', 'source' => 'assignments', 'filters' => [],
                    'columns' => [
                        ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'width' => 26],
                        ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 12],
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 8],
                        ['key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'width' => 18],
                        ['key' => 'teacher', 'label' => 'Teacher', 'type' => 'text', 'width' => 20],
                        ['key' => 'due_at', 'label' => 'Due On', 'type' => 'date', 'width' => 12],
                        ['key' => 'max_marks', 'label' => 'Max Marks', 'type' => 'number', 'width' => 10],
                        ['key' => 'submissions', 'label' => 'Submissions', 'type' => 'number', 'width' => 10],
                    ],
                    'options' => $std,
                    'instructions' => '',
                ],
            ],
            [
                'key' => 'admin_blank_sheet', 'label' => 'Custom Blank Sheet', 'source' => 'blank',
                'roles' => ['admin'],
                'config' => [
                    'title' => 'Custom Blank Sheet', 'source' => 'blank', 'filters' => [],
                    'columns' => [
                        ['key' => 'custom_1', 'label' => 'Field 1', 'type' => 'text', 'width' => 25],
                        ['key' => 'custom_2', 'label' => 'Field 2', 'type' => 'text', 'width' => 25],
                        ['key' => 'custom_3', 'label' => 'Field 3', 'type' => 'text', 'width' => 25],
                        ['key' => 'custom_4', 'label' => 'Remarks', 'type' => 'remarks', 'width' => 25],
                    ],
                    'options' => array_merge($std, ['blank_rows' => 25]),
                    'instructions' => '',
                ],
            ],
            // ---------------- Super Admin ----------------
            [
                'key' => 'sa_platform', 'label' => 'Platform Metrics', 'source' => 'platform',
                'roles' => ['super_admin'],
                'config' => [
                    'title' => 'Platform Metrics', 'source' => 'platform', 'filters' => [],
                    'columns' => [
                        ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                        ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
                    ],
                    'options' => $std,
                    'instructions' => 'Platform numbers only — no school records.',
                ],
            ],
        ];
    }

    /** Presets visible to one user type, each with its full config. */
    public static function forRole(string $userType): array
    {
        // Limited principal role (owner order 2026-10-28): the
        // principal sees the Admin's school presets (the platform
        // source stays super_admin-only in ReportSources).
        $isAdmin = $userType === 'admin';
        if ($userType === 'principal') {
            $userType = 'admin';
        }
        $out = [];
        foreach (self::all() as $preset) {
            if (in_array($userType, $preset['roles'], true)) {
                $out[] = $preset;
            }
        }
        // Owner ruling (library out of Admin completely): the
        // Admin's preset list drops the library-source presets,
        // mirroring ReportSources::allowedFor. Principal keeps
        // the full admin-mapped list.
        if ($isAdmin) {
            $out = array_values(array_filter($out, function ($preset) {
                return !ReportSources::isLibrarySource($preset['source']);
            }));
        }
        return $out;
    }

    /** One preset by key, or null. */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }
        return null;
    }
}
