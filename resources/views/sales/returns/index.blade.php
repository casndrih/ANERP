<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sales returns · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100 md:p-7"><main class="mx-auto max-w-7xl space-y-6">
<header class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm text-slate-500">Sales · Inventory and receivables</p><h1 class="text-2xl font-bold">Customer sales returns</h1><p class="text-sm text-slate-500">Item-level returns, condition assessment, warehouse receipt and GST-adjusted credit notes · PGK</p></div><div class="flex gap-2"><a href="{{ route('sales.orders.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Sales orders</a><a href="{{ route('finance.credit-notes.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Credit notes</a><a href="{{ route('dashboard') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Dashboard</a></div></header>
@if(session('status'))<div class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('status') }}</div>@endif
@if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900">
<div class="mb-4"><h2 class="font-semibold">Create sales return draft</h2><p class="text-sm text-slate-500">Only posted sales-order invoices are eligible. Draft returns reserve invoiced quantities and credit value. A different finance user must post the return.</p></div>
<form method="POST" action="{{ route('sales.returns.store') }}" class="space-y-4">@csrf
<div class="grid gap-3 md:grid-cols-3">
<label class="text-sm">Return number<input name="return_number" required maxlength="60" placeholder="SR-2026-0001" class="mt-1 w-full rounded-xl bg-slate-100 p-3"></label>
<label class="text-sm">Return date<input name="return_date" type="date" required value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-xl bg-slate-100 p-3"></label>
<label class="text-sm">Original posted invoice<select name="invoice_id" id="return-invoice" required class="mt-1 w-full rounded-xl bg-slate-100 p-3"><option value="">Select invoice</option>@foreach($invoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->invoice_number }} · {{ $invoice->customer?->name }} · K{{ number_format((float)$invoice->amount,2) }}</option>@endforeach</select></label>
<label class="text-sm">Receiving warehouse<select name="warehouse_id" required class="mt-1 w-full rounded-xl bg-slate-100 p-3"><option value="">Select receiving warehouse</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></label>
<label class="text-sm md:col-span-2">Return reason<input name="reason" required maxlength="255" placeholder="Customer return reason / RMA reference" class="mt-1 w-full rounded-xl bg-slate-100 p-3"></label>
</div>
<div class="flex items-center justify-between"><h3 class="font-semibold">Returned items</h3><button type="button" id="add-return-line" class="rounded-full bg-slate-900 px-4 py-2 text-sm text-white">Add item</button></div>
<div id="return-lines" class="space-y-2">
<div class="return-line grid gap-2 rounded-xl bg-slate-50 p-3 md:grid-cols-[2fr_0.8fr_1fr_1.5fr_auto]">
<select name="lines[0][invoice_line_id]" required class="return-invoice-line rounded-xl bg-white p-3"><option value="">Invoice item</option>@foreach($invoices as $invoice)@foreach($invoice->lines as $line)@if($line->product_id)<option value="{{ $line->id }}" data-invoice-id="{{ $invoice->id }}">{{ $invoice->invoice_number }} · {{ $line->product?->name }} · invoiced {{ number_format((float)$line->quantity,3) }}</option>@endif @endforeach @endforeach</select>
<input name="lines[0][quantity]" type="number" min="0.001" step="0.001" required placeholder="Qty" class="rounded-xl bg-white p-3">
<select name="lines[0][condition]" required class="rounded-xl bg-white p-3"><option value="resellable">Resellable</option><option value="damaged">Damaged</option><option value="defective">Defective</option></select>
<input name="lines[0][condition_notes]" maxlength="1000" placeholder="Condition notes" class="rounded-xl bg-white p-3">
<button type="button" class="remove-return-line rounded-full bg-white px-3 py-2 text-sm text-red-700 shadow-sm" disabled>Remove</button>
</div></div>
<template id="return-line-template"><div class="return-line grid gap-2 rounded-xl bg-slate-50 p-3 md:grid-cols-[2fr_0.8fr_1fr_1.5fr_auto]">
<select name="lines[__INDEX__][invoice_line_id]" required class="return-invoice-line rounded-xl bg-white p-3"><option value="">Invoice item</option>@foreach($invoices as $invoice)@foreach($invoice->lines as $line)@if($line->product_id)<option value="{{ $line->id }}" data-invoice-id="{{ $invoice->id }}">{{ $invoice->invoice_number }} · {{ $line->product?->name }} · invoiced {{ number_format((float)$line->quantity,3) }}</option>@endif @endforeach @endforeach</select>
<input name="lines[__INDEX__][quantity]" type="number" min="0.001" step="0.001" required placeholder="Qty" class="rounded-xl bg-white p-3">
<select name="lines[__INDEX__][condition]" required class="rounded-xl bg-white p-3"><option value="resellable">Resellable</option><option value="damaged">Damaged</option><option value="defective">Defective</option></select>
<input name="lines[__INDEX__][condition_notes]" maxlength="1000" placeholder="Condition notes" class="rounded-xl bg-white p-3">
<button type="button" class="remove-return-line rounded-full bg-white px-3 py-2 text-sm text-red-700 shadow-sm">Remove</button>
</div></template>
<p class="text-xs text-slate-500">Resellable items return to sellable inventory at the original sales issue's recorded unit cost. Damaged/defective items are recorded as physically received but are not added to sellable on-hand stock.</p>
<button class="rounded-full bg-blue-600 px-6 py-3 font-semibold text-white">Save return draft</button>
</form>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const list=document.getElementById('return-lines'),tpl=document.getElementById('return-line-template'),invoice=document.getElementById('return-invoice');let index=1;
 const filter=()=>{list.querySelectorAll('.return-invoice-line').forEach(select=>{select.querySelectorAll('option[data-invoice-id]').forEach(option=>{option.hidden=!!invoice.value&&option.dataset.invoiceId!==invoice.value;});if(select.selectedOptions[0]?.hidden)select.value='';});};
 invoice.addEventListener('change',filter);
 document.getElementById('add-return-line').addEventListener('click',()=>{list.insertAdjacentHTML('beforeend',tpl.innerHTML.replaceAll('__INDEX__',String(index++)));filter();});
 list.addEventListener('click',event=>{const button=event.target.closest('.remove-return-line');if(button&&list.querySelectorAll('.return-line').length>1)button.closest('.return-line').remove();});
 filter();
});
</script>
</section>

<section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900"><div class="mb-3"><h2 class="font-semibold">Sales return register</h2><p class="text-sm text-slate-500">Posted returns create a customer credit note and reverse GST. Resellable returns also create a valued stock receipt and reverse cost of sales.</p></div>
<div class="space-y-3">
@forelse($returns as $salesReturn)
<div class="rounded-xl bg-slate-50 p-4 dark:bg-zinc-800">
<div class="flex flex-wrap items-start justify-between gap-3"><div><b>{{ $salesReturn->return_number }}</b><p class="text-sm text-slate-600 dark:text-zinc-300">{{ $salesReturn->customer?->name }} · {{ $salesReturn->invoice?->invoice_number }} · {{ $salesReturn->warehouse?->name }}</p><p class="text-xs text-slate-500">{{ $salesReturn->return_date?->format('d M Y') }} · {{ $salesReturn->reason }}</p></div>
<div class="text-right"><b>K {{ number_format((float)$salesReturn->total_amount,2) }}</b><p class="text-xs uppercase text-slate-500">{{ $salesReturn->status }}</p>@if($salesReturn->creditNote)<p class="text-xs text-slate-500">Credit {{ $salesReturn->creditNote->credit_note_number }}</p>@endif</div></div>
<div class="mt-3 space-y-1">@foreach($salesReturn->lines as $line)<p class="text-xs text-slate-600 dark:text-zinc-300">{{ $line->product?->name }} · {{ number_format((float)$line->quantity,3) }} · {{ ucfirst($line->condition) }} · K{{ number_format((float)$line->total_amount,2) }}</p>@endforeach</div>
@if($salesReturn->status==='draft')<div class="mt-3 flex gap-2"><form method="POST" action="{{ route('sales.returns.post',$salesReturn->id) }}">@csrf<button class="rounded-full bg-blue-600 px-4 py-2 text-xs text-white">Post return & credit</button></form><form method="POST" action="{{ route('sales.returns.cancel',$salesReturn->id) }}">@csrf<button class="rounded-full bg-white px-4 py-2 text-xs text-red-700 shadow-sm">Cancel draft</button></form></div>@endif
</div>
@empty<p class="text-sm text-slate-500">No customer sales returns recorded.</p>@endforelse
</div></section>
</main></body></html>
