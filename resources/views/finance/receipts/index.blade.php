<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer receipts · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100 md:p-7">
<main class="mx-auto max-w-7xl space-y-6">
<header class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm text-slate-500 dark:text-zinc-400">Accounts receivable · PGK</p><h1 class="text-2xl font-bold">Customer receipts</h1><p class="text-sm text-slate-500 dark:text-zinc-400">Record incoming customer funds, allocate them to posted invoices and post the bank/AR journals</p></div><div class="flex gap-2"><a href="{{ route('finance.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Finance</a><a href="{{ route('dashboard') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Dashboard</a></div></header>
@if(session('status'))<div class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ session('status') }}</div>@endif
@if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-200"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900">
<h2 class="mb-1 font-semibold">Record customer payment</h2><p class="mb-4 text-sm text-slate-500 dark:text-zinc-400">Receipts are saved as drafts. A different finance user must post them.</p>
<form method="POST" action="{{ route('finance.receipts.store') }}" class="space-y-3">@csrf
<div class="grid gap-3 sm:grid-cols-2">
<label class="text-sm">Receipt number<input name="receipt_number" required maxlength="80" placeholder="RCPT-00001" class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"></label>
<label class="text-sm">Receipt date<input name="receipt_date" type="date" required value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"></label>
<label class="text-sm">Customer<select name="customer_id" required class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->code }} · {{ $customer->name }}</option>@endforeach</select></label>
<label class="text-sm">Bank account<select name="bank_account_id" required class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"><option value="">Select deposit account</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}{{ $bank->bank_name ? ' · '.$bank->bank_name : '' }}</option>@endforeach</select></label>
<label class="text-sm">Branch<select name="branch_id" @if($assignedBranchId) disabled @endif class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"><option value="">Use bank account branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($assignedBranchId === $branch->id)>{{ $branch->name }}</option>@endforeach</select>@if($assignedBranchId)<input type="hidden" name="branch_id" value="{{ $assignedBranchId }}">@endif</label>
<label class="text-sm">Amount received (K)<input name="amount" type="number" min="0.01" step="0.01" required placeholder="0.00" class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"></label>
<label class="text-sm">Bank / EFT reference<input name="reference" maxlength="120" placeholder="Transaction reference" class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"></label>
<label class="text-sm">Description<input name="description" maxlength="255" required placeholder="Customer payment" class="mt-1 w-full rounded-xl bg-slate-100 p-3 dark:bg-zinc-800 dark:text-zinc-100"></label>
</div>
<div><h3 class="mb-2 font-medium">Allocate to outstanding invoices</h3><p class="mb-3 text-xs text-slate-500 dark:text-zinc-400">Enter an allocation for each invoice being settled. Leave at K0.00 to leave it untouched. Unallocated funds are posted to customer deposits and advances.</p>
<div class="max-h-72 overflow-auto rounded-xl bg-slate-50 dark:bg-zinc-800">
<table class="w-full text-left text-sm"><thead class="sticky top-0 bg-slate-100 text-xs text-slate-500 dark:text-zinc-400 dark:bg-zinc-800 dark:text-zinc-400"><tr><th class="p-2">Invoice / customer</th><th class="p-2">Due</th><th class="p-2 text-right">Outstanding</th><th class="p-2">Allocate K</th></tr></thead><tbody>
@forelse($invoices as $index => $invoice)
<tr class="border-t border-slate-200 dark:border-zinc-700"><td class="p-2"><b>{{ $invoice->invoice_number }}</b><span class="block text-xs text-slate-500 dark:text-zinc-400">{{ $invoice->customer?->name }}</span><input type="hidden" name="allocations[{{ $index }}][invoice_id]" value="{{ $invoice->id }}"></td><td class="p-2 whitespace-nowrap">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td><td class="p-2 text-right tabular-nums">{{ number_format(max(0,(float)$invoice->amount-(float)$invoice->amount_paid-(float)$invoice->amount_credited),2) }}</td><td class="p-2"><input name="allocations[{{ $index }}][amount]" type="number" min="0" max="{{ max(0,(float)$invoice->amount-(float)$invoice->amount_paid-(float)$invoice->amount_credited) }}" step="0.01" value="0.00" class="w-28 rounded-lg bg-white p-2 text-right"></td></tr>
@empty
<tr><td colspan="4" class="p-4 text-center text-slate-500 dark:text-zinc-400">No outstanding posted invoices in this branch.</td></tr>
@endforelse
</tbody></table>
</div></div>
<button class="rounded-full bg-blue-600 px-5 py-3 text-white shadow-sm">Save receipt draft</button>
</form>
</div>
<div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900">
<h2 class="mb-3 font-semibold">Outstanding customer invoices</h2>
<div class="space-y-2">
@forelse($invoices as $invoice)
<div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 dark:bg-zinc-800 p-3 text-sm"><div><b>{{ $invoice->invoice_number }}</b><p class="text-xs text-slate-500 dark:text-zinc-400">{{ $invoice->customer?->name }} · Due {{ $invoice->due_date?->format('d M Y') ?? 'not set' }}</p></div><div class="text-right"><b class="tabular-nums">K {{ number_format(max(0,(float)$invoice->amount-(float)$invoice->amount_paid-(float)$invoice->amount_credited),2) }}</b><p class="text-xs text-slate-500 dark:text-zinc-400">of K {{ number_format((float)$invoice->amount,2) }}</p></div></div>
@empty
<p class="text-sm text-slate-500 dark:text-zinc-400">No outstanding invoices.</p>
@endforelse
</div>
</div>
</section>

<section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900">
<h2 class="mb-3 font-semibold">Receipt register</h2>
<div class="space-y-2">
@forelse($receipts as $receipt)
<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 dark:bg-zinc-800 p-3">
<div><b>{{ $receipt->receipt_number }}</b><p class="text-sm text-slate-600">{{ $receipt->customer?->name }} · {{ $receipt->receipt_date?->format('d M Y') }} · {{ $receipt->bankAccount?->name }}</p><p class="text-xs text-slate-500 dark:text-zinc-400">{{ $receipt->reference }} · {{ $receipt->description }}</p>
@if($receipt->allocations->isNotEmpty())<p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">Allocations: @foreach($receipt->allocations as $allocation){{ $allocation->invoice?->invoice_number }} K{{ number_format((float)$allocation->amount,2) }}@if(!$loop->last), @endif @endforeach</p>@endif
</div>
<div class="flex items-center gap-3"><div class="text-right"><b class="tabular-nums">K {{ number_format((float)$receipt->amount,2) }}</b><p class="text-xs uppercase text-slate-500 dark:text-zinc-400">{{ $receipt->status }}</p></div>
@if($receipt->status === 'draft')<form method="POST" action="{{ route('finance.receipts.post',$receipt->id) }}">@csrf<button class="rounded-full bg-blue-600 px-4 py-2 text-xs text-white">Post receipt</button></form>@endif
</div>
</div>
@empty
<p class="text-sm text-slate-500 dark:text-zinc-400">No customer receipts recorded yet.</p>
@endforelse
</div>
</section>
</main></body></html>
