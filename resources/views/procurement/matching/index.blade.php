<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Supplier invoice matching · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 md:p-7">
<main class="mx-auto max-w-7xl space-y-6">
<header class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Procurement controls</p><h1 class="text-2xl font-bold">Supplier invoice matching</h1><p class="mt-1 text-sm text-slate-500">Match supplier invoices to approved purchase orders and received quantities before posting.</p></div><a href="{{ route('dashboard') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Dashboard</a></header>
@if(session('status'))<p class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('status') }}</p>@endif
@if($errors->any())<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
<section class="overflow-hidden rounded-2xl bg-white shadow-sm">
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">Supplier invoice</th><th class="p-3">Supplier</th><th class="p-3">Amount</th><th class="p-3">Match status</th><th class="p-3">Actions</th></tr></thead><tbody>
@forelse($bills as $bill)
<tr class="border-t align-top"><td class="p-3"><b>{{ $bill->bill_number }}</b><div class="text-xs text-slate-500">{{ $bill->bill_date?->format('Y-m-d') }} · {{ $bill->description }}</div></td><td class="p-3">{{ $bill->supplier?->name }}</td><td class="p-3">K {{ number_format((float)$bill->amount,2) }}</td><td class="p-3"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs capitalize">{{ str_replace('_',' ',$bill->match_status ?? 'not_required') }}</span><div class="mt-1 text-xs text-slate-500">{{ $bill->invoice_type ?? 'general' }}</div></td><td class="min-w-72 p-3">
@if($bill->status==='draft' && in_array($bill->match_status ?? 'not_required',['not_required','unmatched','rejected'],true))
<div class="space-y-3">
@foreach($orders->where('supplier_id',$bill->supplier_id) as $order)
@php($receivedLines=$order->lines->filter(fn($candidate)=>(float)$candidate->quantity_received>0)->values())
@if($receivedLines->isNotEmpty())
<form method="POST" action="{{ route('procurement.matching.match',$bill->id) }}" class="space-y-2 rounded-xl bg-slate-50 p-3">@csrf
<input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
<div class="text-xs font-semibold">{{ $order->order_number }} · {{ $order->warehouse?->name }}</div>
<select name="invoice_type" class="w-full rounded-lg bg-white p-2 text-xs"><option value="goods">Goods invoice</option><option value="landed_cost">Freight / landed-cost invoice</option></select>
<p class="text-xs text-slate-500">Enter billed quantities for each received PO line. Leave unbilled lines at zero.</p>
@foreach($receivedLines as $index=>$line)
<div class="grid grid-cols-1 gap-2 rounded-lg bg-white p-2 md:grid-cols-[2fr_1fr_1fr]">
<div class="text-xs"><b>{{ $line->product?->name }}</b><div class="text-slate-500">Received {{ number_format((float)$line->quantity_received,3) }} · PO rate K{{ number_format((float)$line->unit_cost,2) }}</div><input name="lines[{{ $index }}][purchase_order_line_id]" type="hidden" value="{{ $line->id }}"></div>
<input name="lines[{{ $index }}][quantity_billed]" type="number" min="0" max="{{ (float)$line->quantity_received }}" step="0.001" placeholder="Billed qty (0 if none)" class="rounded-lg bg-slate-100 p-2 text-xs">
<input name="lines[{{ $index }}][unit_price]" type="number" min="0" step="0.01" value="{{ number_format((float)$line->unit_cost,2,'.','') }}" class="rounded-lg bg-slate-100 p-2 text-xs">
</div>
@endforeach
<button class="rounded-full bg-blue-600 px-3 py-2 text-xs font-semibold text-white">Match invoice to PO</button>
</form>

</div>
@elseif($bill->status==='draft' && $bill->match_status==='matched')
<form method="POST" action="{{ route('procurement.matching.approve',$bill->id) }}" class="space-y-2">@csrf@if($bill->invoice_type==='landed_cost')<label class="block text-xs text-slate-600">Allocate landed cost by<select name="allocation_basis" class="mt-1 w-full rounded-lg bg-slate-100 p-2 text-xs"><option value="line_value">Received line value (quantity × PO unit cost)</option><option value="quantity">Received quantity</option><option value="equal">Equal share per received product line</option></select></label>@endif<button class="rounded-full bg-slate-900 px-4 py-2 text-xs text-white">Approve & post invoice</button><p class="mt-1 text-xs text-slate-500">Independent approver required.</p></form>
@else
<div class="text-xs text-slate-500">Invoice status: {{ $bill->status }}@if($bill->purchase_order_id) · PO #{{ $bill->purchase_order_id }}@endif</div>
@endif
</td></tr>
@empty<tr><td colspan="5" class="p-8 text-center text-slate-500">No supplier bills are available.</td></tr>@endforelse
</tbody></table></div><div class="p-4">{{ $bills->links() }}</div>
</section>
<section class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Matching controls</h2><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600"><li>Goods invoices must match approved PO unit prices within K0.01 and cannot exceed received, uninvoiced quantities.</li><li>Goods invoice approval clears goods-received-not-invoiced (GRNI) into accounts payable.</li><li>Freight and other landed costs are allocated by received PO value; the portion still in stock increases inventory valuation, while the consumed portion is charged to cost of goods sold.</li><li>Invoice creator and matcher cannot approve their own invoice.</li></ul><p class="mt-3 text-xs text-slate-500">Landed-cost allocation supports received line value, received quantity or equal share. GST/tax treatment, tariff-based import duty and supplier credit notes remain separate increments.</p></section>
</main></body></html>
