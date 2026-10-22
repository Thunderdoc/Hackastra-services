{{--
    Payments table (Workstream CK) — shared by the Manage Payments
    landing's tab panes (All Classes + one pane per class) so the
    row markup, figures and Actions menu exist exactly once.

    Expects: $rows (Payment collection), $total_label (string).
--}}
<div class="table-responsive">
    <table class="table">
        <thead>
        <tr>
            <th>#</th>
            <th>Title</th>
            <th class="text-right">Amount</th>
            <th>Ref_No</th>
            <th>Class</th>
            <th>Method</th>
            <th>Info</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        @foreach($rows as $p)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $p->title }}</td>
                <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($p->amount) }}</td>
                <td>{{ $p->ref_no }}</td>
                <td>{{ $p->my_class_id ? $p->my_class->name : '' }}</td>
                <td>{{ ucwords($p->method) }}</td>
                <td>{{ $p->description }}</td>
                <td class="text-center">
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>

                        <div class="dropdown-menu dropdown-menu-right">
                            {{--Edit--}}
                            <a href="{{ route('payments.edit', $p->id) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                            {{--Delete--}}
                            <a id="{{ $p->id }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                            <form method="post" id="item-delete-{{ $p->id }}" action="{{ route('payments.destroy', $p->id) }}" class="hidden">@csrf @method('delete')</form>
                        </div>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <th colspan="2" class="text-right">{{ $total_label }}</th>
            <th class="text-right">{{ \App\Helpers\Money::inr($rows->sum('amount')) }}</th>
            <th colspan="5"></th>
        </tr>
        </tfoot>
    </table>
</div>
