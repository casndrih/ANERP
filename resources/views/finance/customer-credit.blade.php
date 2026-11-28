<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer credit controls · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 md:p-7">
<main class="mx-auto max-w-6xl space-y-5">
<header class="flex flex-wrap items-center justify-between gap-3">
<div><p class="text-sm text-slate-500">Finance administration · PGK</p><h1 class="text-2xl font-bold">Customer credit controls</h1><p class="text-sm text-slate-500">Set credit limits and place customers on hold. Only company owners and administrators can change these controls.</p></div>
<a href="{{ route('finance.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Back to finance</a>
</header>
@if(session('status'))<div class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('status') }}</div>@endif
@if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
<section class="space-y-3">
@foreach($customers as $customer)
<form method="POST" action="{{ route('finance.customer-credit.update', $customer->id) }}" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm md:grid-cols-[1.2fr_1fr_1.2fr_auto] md:items-end">
@csrf @method('PATCH')
<div><p class="font-semibold">{{ $customer->name }}</p><p class="text-xs text-slate-500">{{ $customer->code }} · {{ $customer->email }}</p></div>
<label class="text-sm">Credit limit (PGK)
<input name="credit_limit" type="number" min="0" step="0.01" value="{{ $customer->credit_limit }}" placeholder="Unlimited" class="mt-1 w-full rounded-xl bg-slate-100 p-3">
</label>
<div class="space-y-2">
<label class="flex items-center gap-2 text-sm"><input type="hidden" name="credit_hold" value="0"><input type="checkbox" name="credit_hold" value="1" @checked($customer->credit_hold)> Place on credit hold</label>
<input name="credit_hold_reason" maxlength="255" value="{{ $customer->credit_hold_reason }}" placeholder="Reason for hold (required when held)" class="w-full rounded-xl bg-slate-100 p-3 text-sm">
</div>
<button class="rounded-full bg-blue-600 px-5 py-3 text-white">Save controls</button>
</form>
@endforeach
</section>
<div class="text-sm text-slate-500">{{ $customers->links() }}</div>
</main></body></html>
