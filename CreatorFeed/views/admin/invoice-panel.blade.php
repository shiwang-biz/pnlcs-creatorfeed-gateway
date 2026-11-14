{{-- Injected on admin invoice pages via AdminAreaFooterOutput. --}}
<div id="cf-invoice-panel" style="position:fixed;right:18px;bottom:18px;z-index:1050;width:340px;background:#fff;border:1px solid #d0d7de;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.15);font-size:13px;">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid #eee;">
        <strong>CreatorFeed</strong>
        <button type="button" onclick="document.getElementById('cf-invoice-panel').style.display='none'" style="border:0;background:none;font-size:18px;line-height:1;cursor:pointer;" aria-label="Close">&times;</button>
    </div>
    <div style="padding:10px 12px;max-height:220px;overflow:auto;">
        @foreach($orders as $o)
            @php $color = ['paid'=>'#1a7f37','pending'=>'#0969da','failed'=>'#cf222e','error'=>'#cf222e'][$o->status] ?? '#57606a'; @endphp
            <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px dashed #eee;">
                <span><code style="font-size:11px">{{ $o->order_id }}</code><br>
                    <small style="color:#57606a">₹{{ number_format((float) $o->amount, 2) }} · {{ strtoupper((string) $o->mode) }}{{ $o->utr ? ' · UTR '.$o->utr : '' }}</small></span>
                <span style="color:{{ $color }};font-weight:600;white-space:nowrap">{{ $o->status }}{{ $o->applied_at ? ' ✓' : '' }}</span>
            </div>
        @endforeach
    </div>
    <div style="display:flex;gap:8px;padding:10px 12px;border-top:1px solid #eee;">
        <form method="POST" action="{{ route('creatorfeed.admin.check-invoice', $invoiceId) }}" style="flex:1;margin:0;">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary" style="width:100%;">Check status via API</button>
        </form>
        <a href="{{ route('creatorfeed.admin.orders', ['invoice' => $invoiceId]) }}" class="btn btn-sm btn-default">All orders</a>
    </div>
</div>
