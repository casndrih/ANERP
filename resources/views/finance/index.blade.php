<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finance · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 md:p-7">
<main class="mx-auto max-w-7xl space-y-6">
<header class="flex items-center justify-between"><div><p class="text-sm text-slate-500">General ledger and finance operations · {{ $assignedBranchId ? 'Assigned branch' : 'Company-wide access' }}</p><h1 class="text-2xl font-bold">Finance & accounting</h1></div><a href="{{ route('finance.supplier-credit-notes.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Supplier credit notes</a><a href="{{ route('finance.payments.index') }}" class="rounded-full bg-blue-600 px-4 py-2 text-white shadow-sm">Supplier payments</a><a href="{{ route('dashboard') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Dashboard</a></header>
<div class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('status') }}</div>
<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
@foreach([['Accounts',$accounts->count()],['Draft journals',$journals->where('status','draft')->count()],['Posted journals',$journals->where('status','posted')->count()],['Bank accounts',$bankAccounts->count()]] as $metric)
<div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $metric[0] }}</p><p class="mt-2 text-2xl font-bold">{{ $metric[1] }}</p></div>
@endforeach
</section>

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Chart of accounts</h2><div class="max-h-80 overflow-auto"><table class="w-full text-sm"><thead class="sticky top-0 bg-slate-50 text-left text-slate-500"><tr><th class="p-2">Code</th><th class="p-2">Account</th><th class="p-2">Type</th><th class="p-2">Normal</th></tr></thead><tbody>
@foreach($accounts as $a)
<tr class="border-t"><td class="p-2">{{ $a->account_code }}</td><td class="p-2">{{ $a->name }}</td><td class="p-2 capitalize">{{ $a->account_type }}</td><td class="p-2 capitalize">{{ $a->normal_balance }}</td></tr>
@endforeach
</tbody></table></div></div>
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Create balanced journal</h2><form method="POST" action="{{ route('finance.journals.store') }}" class="space-y-3">@csrf
<input name="journal_date" type="date" required value="{{ now()->toDateString() }}" class="w-full rounded-xl bg-slate-100 p-3">
<select name="branch_id" class="w-full rounded-xl bg-slate-100 p-3"><option value="">Company-wide record</option>
@foreach($branches as $branch)<option value="{{ $branch->id }}" {{ $assignedBranchId === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>@endforeach
</select>
<input name="description" required placeholder="Journal description" class="w-full rounded-xl bg-slate-100 p-3">
<div class="grid grid-cols-2 gap-2">
<select name="lines[0][account_id]" required class="rounded-xl bg-slate-100 p-3">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_code }} · {{ $a->name }}</option>@endforeach</select>
<input name="lines[0][debit]" type="number" min="0" step="0.01" value="0" placeholder="Debit line 1" class="rounded-xl bg-slate-100 p-3">
<input name="lines[0][credit]" type="number" min="0" step="0.01" value="0" placeholder="Credit line 1" class="rounded-xl bg-slate-100 p-3">
<select name="lines[1][account_id]" required class="rounded-xl bg-slate-100 p-3">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_code }} · {{ $a->name }}</option>@endforeach</select>
<input name="lines[1][debit]" type="number" min="0" step="0.01" value="0" placeholder="Debit line 2" class="rounded-xl bg-slate-100 p-3">
<input name="lines[1][credit]" type="number" min="0" step="0.01" value="0" placeholder="Credit line 2" class="rounded-xl bg-slate-100 p-3">
</div><button class="rounded-full bg-blue-600 px-5 py-3 text-white">Save draft journal</button><p class="text-xs text-slate-500">The journal must balance. A different finance user must post it.</p></form></div>
</section>

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Customers</h2><form method="POST" action="{{ route('finance.customers.store') }}" class="grid grid-cols-2 gap-2">@csrf
<input name="code" required placeholder="Customer code" class="rounded-xl bg-slate-100 p-3"><input name="name" required placeholder="Customer name" class="rounded-xl bg-slate-100 p-3"><input name="tin" placeholder="TIN" class="rounded-xl bg-slate-100 p-3"><input name="email" type="email" placeholder="Email" class="rounded-xl bg-slate-100 p-3"><button class="col-span-2 rounded-full bg-slate-900 p-3 text-white">Add customer</button></form><div class="mt-3 text-sm text-slate-500">{{ $customers->count() }} customers</div></div>
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Create customer invoice</h2><form method="POST" action="{{ route('finance.invoices.store') }}" class="grid grid-cols-2 gap-2">@csrf
<select name="branch_id" class="rounded-xl bg-slate-100 p-3"><option value="">Company-wide record</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
<select name="customer_id" required class="rounded-xl bg-slate-100 p-3"><option value="">Customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
<select name="revenue_account_id" required class="rounded-xl bg-slate-100 p-3">@foreach($accounts->where('account_type','income') as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
<input name="invoice_number" required placeholder="Invoice number" class="rounded-xl bg-slate-100 p-3"><input name="amount" required type="number" min="0.01" step="0.01" placeholder="Amount PGK" class="rounded-xl bg-slate-100 p-3"><input name="invoice_date" required type="date" value="{{ now()->toDateString() }}" class="rounded-xl bg-slate-100 p-3"><input name="due_date" type="date" class="rounded-xl bg-slate-100 p-3"><input name="description" required placeholder="Description" class="col-span-2 rounded-xl bg-slate-100 p-3"><button class="col-span-2 rounded-full bg-blue-600 p-3 text-white">Save invoice draft</button></form></div>
</section>

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Create supplier bill</h2><form method="POST" action="{{ route('finance.bills.store') }}" class="grid grid-cols-2 gap-2">@csrf
<select name="branch_id" class="rounded-xl bg-slate-100 p-3"><option value="">Company-wide record</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
<select name="supplier_id" required class="rounded-xl bg-slate-100 p-3"><option value="">Supplier</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
<select name="expense_account_id" required class="rounded-xl bg-slate-100 p-3">@foreach($accounts->where('account_type','expense') as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
<input name="bill_number" required placeholder="Supplier invoice number" class="rounded-xl bg-slate-100 p-3"><input name="amount" required type="number" min="0.01" step="0.01" placeholder="Amount PGK" class="rounded-xl bg-slate-100 p-3"><input name="bill_date" required type="date" value="{{ now()->toDateString() }}" class="rounded-xl bg-slate-100 p-3"><input name="due_date" type="date" class="rounded-xl bg-slate-100 p-3"><input name="description" required placeholder="Description" class="col-span-2 rounded-xl bg-slate-100 p-3"><button class="col-span-2 rounded-full bg-blue-600 p-3 text-white">Save bill draft</button></form></div>
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Bank account</h2><form method="POST" action="{{ route('finance.bank-accounts.store') }}" class="grid grid-cols-2 gap-2">@csrf
<input name="code" required placeholder="Code" class="rounded-xl bg-slate-100 p-3"><input name="name" required placeholder="Account name" class="rounded-xl bg-slate-100 p-3"><select name="branch_id" class="rounded-xl bg-slate-100 p-3"><option value="">Company-wide record</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
<input name="bank_name" placeholder="Bank" class="rounded-xl bg-slate-100 p-3"><input name="account_number" placeholder="Account number" class="rounded-xl bg-slate-100 p-3"><select name="account_id" required class="col-span-2 rounded-xl bg-slate-100 p-3">@foreach($accounts->where('account_type','asset') as $a)<option value="{{ $a->id }}">{{ $a->account_code }} · {{ $a->name }}</option>@endforeach</select><button class="col-span-2 rounded-full bg-slate-900 p-3 text-white">Link bank account</button></form></div>
</section>

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Journal register</h2><div class="space-y-3">
@foreach($journals as $j)
<div class="rounded-xl bg-slate-50 p-3"><div class="flex items-center justify-between gap-2"><b>{{ $j->journal_number }}</b><span class="text-xs capitalize">{{ $j->status }}</span></div><p class="text-xs text-slate-500">{{ $j->journal_date?->format('d M Y') }} · {{ $j->description }}</p><p class="text-xs">Dr K {{ number_format((float)$j->lines->sum('debit'),2) }} / Cr K {{ number_format((float)$j->lines->sum('credit'),2) }}</p>
<?php if ($j->status === 'draft'): ?><form method="POST" action="{{ route('finance.journals.post',$j->id) }}" class="mt-2">@csrf<button class="rounded-full bg-blue-600 px-3 py-1 text-xs text-white">Post journal</button></form><?php endif; ?>
</div>
@endforeach
</div></div>
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Supplier bills & customer invoices</h2><div class="space-y-2">
@foreach($bills as $b)
<div class="flex justify-between gap-2 rounded-xl bg-slate-50 p-3 text-sm"><span>{{ $b->bill_number }} · {{ $b->supplier?->name }}<small class="block text-slate-500">Supplier bill · {{ $b->status }} · K {{ number_format((float)$b->amount,2) }}</small></span>
<?php if ($b->status === 'draft'): ?><form method="POST" action="{{ route('finance.bills.post',$b->id) }}">@csrf<button class="rounded-full bg-blue-600 px-3 py-1 text-xs text-white">Post</button></form><?php endif; ?>
</div>
@endforeach
@foreach($invoices as $i)
<div class="flex justify-between gap-2 rounded-xl bg-slate-50 p-3 text-sm"><span>{{ $i->invoice_number }} · {{ $i->customer?->name }}<small class="block text-slate-500">Customer invoice · {{ $i->status }} · K {{ number_format((float)$i->amount,2) }}</small></span>
<?php if ($i->status === 'draft'): ?><form method="POST" action="{{ route('finance.invoices.post',$i->id) }}">@csrf<button class="rounded-full bg-blue-600 px-3 py-1 text-xs text-white">Post</button></form><?php endif; ?>
</div>
@endforeach
</div></div>
</section>

<section class="grid gap-5 xl:grid-cols-2">
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Record bank transaction</h2><form method="POST" action="{{ route('finance.bank-transactions.store') }}" class="grid grid-cols-2 gap-2">@csrf
<select name="bank_account_id" required class="rounded-xl bg-slate-100 p-3"><option value="">Bank account</option>@foreach($bankAccounts as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select><select name="direction" class="rounded-xl bg-slate-100 p-3"><option value="in">Money in</option><option value="out">Money out</option></select><input name="transaction_date" required type="date" value="{{ now()->toDateString() }}" class="rounded-xl bg-slate-100 p-3"><input name="reference" placeholder="Reference" class="rounded-xl bg-slate-100 p-3"><input name="amount" required type="number" min="0.01" step="0.01" placeholder="Amount PGK" class="rounded-xl bg-slate-100 p-3"><select name="offset_account_id" required class="rounded-xl bg-slate-100 p-3">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_code }} · {{ $a->name }}</option>@endforeach</select><input name="description" required placeholder="Description" class="col-span-2 rounded-xl bg-slate-100 p-3"><button class="col-span-2 rounded-full bg-blue-600 p-3 text-white">Record draft bank transaction</button></form></div>
<div class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Bank reconciliation</h2>
@foreach($bankAccounts as $b)
<form method="POST" action="{{ route('finance.bank-accounts.reconcile',$b->id) }}" class="mb-4 space-y-2 rounded-xl bg-slate-50 p-3">@csrf<b>{{ $b->name }}</b><div class="grid grid-cols-2 gap-2"><input name="statement_start" type="date" required class="rounded-lg bg-white p-2"><input name="statement_end" type="date" required class="rounded-lg bg-white p-2"><input name="opening_balance" type="number" step="0.01" required placeholder="Opening balance" class="rounded-lg bg-white p-2"><input name="closing_balance" type="number" step="0.01" required placeholder="Closing balance" class="rounded-lg bg-white p-2"></div><div class="max-h-36 overflow-auto space-y-1">
@foreach($b->transactions->where('is_reconciled',false) as $tx)
<label class="flex items-center gap-2 text-xs"><input type="checkbox" name="transaction_ids[]" value="{{ $tx->id }}">{{ $tx->transaction_date }} · {{ $tx->direction }} K{{ number_format((float)$tx->amount,2) }} · {{ $tx->description }}</label>
@endforeach
</div><button class="rounded-full bg-slate-900 px-4 py-2 text-xs text-white">Reconcile selected</button></form>
@endforeach
</div>
</section>
</main></body></html>
