{{--
    Payments table (Workstream CK) — shared by the Manage Payments
    landing's tab panes (All Classes + one pane per class) so the
    row markup, figures and Actions menu exist exactly once.

    Expects: $rows (Payment collection), $total_label (string).

    Round 5 P11: rendered through the shared data_table pattern
    (hk-std-paydefs, widths sum to 100); the totals row rides the
    pattern's tfoot slot. Row markup unchanged.
--}}
@component('partials.patterns.data_table', [
    'columns' => [
        ['key' => 'sn', 'label' => '#', 'width' => 5, 'class' => 'hk-nw'],
        ['key' => 'title', 'label' => 'Title', 'width' => 20],
        ['key' => 'amount', 'label' => 'Amount', 'width' => 12, 'class' => 'hk-nw', 'align' => 'right'],
        ['key' => 'ref_no', 'label' => 'Ref_No', 'width' => 13, 'class' => 'hk-nw'],
        ['key' => 'class', 'label' => 'Class', 'width' => 11],
        ['key' => 'method', 'label' => 'Method', 'width' => 10, 'class' => 'hk-nw'],
        ['key' => 'info', 'label' => 'Info', 'width' => 17, 'class' => 'hk-ellip'],
        ['key' => 'action', 'label' => 'Action', 'width' => 12, 'align' => 'center'],
    ],
    'variant' => 'paydefs',
    'min_width' => 880,
])
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
                        <a id="{{ $p->id }}" data-item-name="{{ $p->title }}" onclick="confirmDelete(this.id, this.dataset.itemName)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                        <form method="post" id="item-delete-{{ $p->id }}" action="{{ route('payments.destroy', $p->id) }}" class="hidden">@csrf @method('delete')</form>
                    </div>
                </div>
            </td>
        </tr>
    @endforeach

    @slot('tfoot')
        <tr>
            <th colspan="2" class="text-right">{{ $total_label }}</th>
            <th class="text-right">{{ \App\Helpers\Money::inr($rows->sum('amount')) }}</th>
            <th colspan="5"></th>
        </tr>
    @endslot
@endcomponent
