@extends('admin.layouts.app')
@section('title', 'CreatorFeed Orders')
@section('content')

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1 style="margin:0">CreatorFeed Orders</h1>
    <div style="font-size:13px;">
        Mode:
        @if($mode === 'live')<span class="badge" style="background:#46A546;color:#fff">LIVE</span>
        @elseif($mode === 'test')<span class="badge" style="background:#f0ad4e;color:#fff">TEST</span>
        @else<span class="badge" style="background:#888;color:#fff">not configured</span>@endif
        @unless($enabled)<span class="badge" style="background:#d9534f;color:#fff">gateway disabled or missing settings</span>@endunless
    </div>
</div>

<div class="card" style="margin-bottom:15px;">
    <div class="card-body" style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">
        <form method="GET" action="{{ route('creatorfeed.admin.orders') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div><label class="form-label">Invoice ID</label>
                <input type="text" name="invoice" value="{{ request('invoice') }}" class="form-control" style="width:110px;"></div>
            <div><label class="form-label">Status</label>
                <select name="status" class="form-control" style="width:140px;">
                    <option value="">All</option>
                    @foreach(['creating','pending','paid','failed','expired','error'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select></div>
            <div><label class="form-label">Order / CFD-TXN / UTR</label>
                <input type="text" name="q" value="{{ request('q') }}" class="form-control" style="width:200px;"></div>
            <button class="btn btn-primary" type="submit">Filter</button>
        </form>

        <form method="POST" action="{{ route('creatorfeed.admin.lookup') }}" style="display:flex;gap:10px;align-items:flex-end;margin-left:auto;">
            @csrf
            <div><label class="form-label">Check any order on CreatorFeed</label>
                <input type="text" name="reference" placeholder="order_id or CFD-TXN-…" class="form-control" style="width:230px;" required></div>
            <button class="btn btn-default" type="submit">Check status via API</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table table-striped" style="margin:0;font-size:13px;">
            <thead>
                <tr>
                    <th>Created</th><th>Invoice</th><th>Order ID / CreatorFeed ID</th><th>Amount</th>
                    <th>Status</th><th>Payment</th><th>Recorded</th><th>Last check</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($orders as $o)
                @php
                    $color = ['paid'=>'#46A546','pending'=>'#5bc0de','creating'=>'#888','failed'=>'#d9534f','expired'=>'#888','error'=>'#d9534f'][$o->status] ?? '#888';
                @endphp
                <tr>
                    <td style="white-space:nowrap">{{ $o->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if(Route::has('admin.invoices.show'))
                            <a href="{{ route('admin.invoices.show', $o->invoice_id) }}">#{{ $o->invoice?->invoice_num ?: $o->invoice_id }}</a>
                        @else #{{ $o->invoice_id }} @endif
                        <br><small style="color:#777">{{ $o->invoice?->status }}</small>
                    </td>
                    <td><code>{{ $o->order_id }}</code><br><small style="color:#777">{{ $o->cf_id ?: '—' }} · {{ strtoupper((string) $o->mode) }}</small></td>
                    <td style="white-space:nowrap">₹{{ number_format((float) $o->amount, 2) }}
                        @if($o->net !== null)<br><small style="color:#777">net ₹{{ number_format((float) $o->net, 2) }}</small>@endif</td>
                    <td><span class="badge" style="background:{{ $color }};color:#fff">{{ $o->status }}</span>
                        @if($o->failure_reason)<br><small style="color:#d9534f">{{ $o->failure_reason }}</small>@endif
                        @if($o->last_error)<br><small style="color:#d9534f" title="{{ $o->last_error }}">{{ \Illuminate\Support\Str::limit($o->last_error, 60) }}</small>@endif</td>
                    <td>{{ $o->payment_method ? strtoupper($o->payment_method) : '—' }}
                        @if($o->utr)<br><small>UTR {{ $o->utr }}</small>@endif
                        @if($o->paid_at)<br><small style="color:#777">{{ $o->paid_at->format('Y-m-d H:i') }}</small>@endif</td>
                    <td>@if($o->applied_at)<span class="badge" style="background:#46A546;color:#fff">✓</span><br><small style="color:#777">{{ $o->transaction_id }}</small>@else — @endif</td>
                    <td style="white-space:nowrap">@if($o->last_checked_at){{ $o->last_checked_at->diffForHumans() }}<br><small style="color:#777">{{ $o->last_checked_by }}</small>@else — @endif</td>
                    <td>
                        <form method="POST" action="{{ route('creatorfeed.admin.check', $o->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-default" type="submit" style="white-space:nowrap">Check status</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;padding:30px;" style="color:#777">No CreatorFeed orders yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:15px;">{{ $orders->links() }}</div>

<p style="color:#777" style="font-size:12px;margin-top:10px;">
    Webhook URL for the CreatorFeed dashboard: <code>{{ url('/gateway/creatorfeed/callback') }}</code>.
    "Check status" asks the CreatorFeed API and records the payment on the invoice if CreatorFeed reports it paid.
</p>
@endsection
