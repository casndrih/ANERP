<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stock counts · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-5 text-slate-900">
<main class="mx-auto max-w-7xl space-y-6">
<header class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm text-slate-500">Inventory controls</p><h1 class="text-2xl font-bold">Stock counts</h1><p class="text-sm text-slate-500">Capture physical quantities, review variances and submit for independent approval</p></div><div class="flex gap-2"><a href="{{ route('inventory.movements.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Stock control</a><a href="{{ route('dashboard') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Dashboard</a></div></header>
@if(session('status'))<p class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('status') }}</p>@endif
@if($errors->any())<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
@if($canManage)
<section class="rounded-2xl bg-white p-5 shadow-sm space-y-4">
<div><h2 class="font-semibold">Create physical stock count</h2><p class="mt-1 text-sm text-slate-500">System quantities are captured when the draft is created. If stock moves before approval, the count must be repeated.</p></div>
<form method="POST" action="{{ route('inventory.counts.store') }}" class="space-y-4" id="stock-count-form">@csrf
<div class="grid gap-3 sm:grid-cols-2"><select name="warehouse_id" required class="rounded-xl bg-slate-100 p-3"><option value="">Select warehouse</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select><input type="date" name="count_date" required value="{{ now()->toDateString() }}" class="rounded-xl bg-slate-100 p-3"></div>
<div id="count-lines" class="space-y-3"></div>
<div class="flex flex-wrap gap-2"><button type="button" id="add-count-line" class="rounded-full bg-slate-100 px-4 py-2 font-medium">Add product</button><input name="notes" placeholder="Count reference / notes" class="min-w-0 flex-1 rounded-xl bg-slate-100 p-3"></div>
<button class="rounded-full bg-blue-600 px-6 py-3 font-semibold text-white">Save draft count</button>
</form>
<template id="count-line-template"><div class="count-line grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-[2fr_1fr_1fr_auto]"><select required class="product-select min-w-0 rounded-xl bg-white p-3"><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>@endforeach</select><input required type="number" min="0" step="0.001" placeholder="Physical qty" class="counted-quantity rounded-xl bg-white p-3"><input placeholder="Line notes" class="line-notes rounded-xl bg-white p-3"><button type="button" class="remove-line rounded-full bg-white px-3 py-2 text-slate-500">Remove</button></div></template>
</section>
@endif
<section class="space-y-3">
<h2 class="font-semibold">Stock count register</h2>
@forelse($counts as $count)
<article class="rounded-2xl bg-white p-5 shadow-sm space-y-3">
<div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-semibold">{{ $count->count_number }} <span class="ml-2 rounded-full bg-slate-100 px-3 py-1 text-xs">{{ ucfirst($count->status) }}</span></p><p class="mt-1 text-sm text-slate-500">{{ $count->warehouse?->name }} · {{ $count->count_date?->format('Y-m-d') }}</p></div><div class="flex flex-wrap gap-2">@if($canManage && $count->status === 'draft')<form method="POST" action="{{ route('inventory.counts.submit',$count->id) }}">@csrf<button class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Submit for approval</button></form>@endif @if($canApprove && $count->status === 'submitted')<form method="POST" action="{{ route('inventory.counts.approve',$count->id) }}">@csrf<button class="rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Approve & post adjustments</button></form>@endif</div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-2">Product</th><th class="p-2 text-right">System</th><th class="p-2 text-right">Counted</th><th class="p-2 text-right">Variance</th></tr></thead><tbody>@foreach($count->lines as $line)<tr class="border-t"><td class="p-2">{{ $line->product?->sku }} — {{ $line->product?->name }}</td><td class="p-2 text-right">{{ number_format((float)$line->system_quantity,3) }}</td><td class="p-2 text-right">{{ number_format((float)$line->counted_quantity,3) }}</td><td class="p-2 text-right font-semibold {{ (float)$line->variance_quantity < 0 ? 'text-red-600' : ((float)$line->variance_quantity > 0 ? 'text-blue-600' : '') }}">{{ number_format((float)$line->variance_quantity,3) }}</td></tr>@endforeach</tbody></table></div>
@if($count->notes)<p class="text-sm text-slate-500">{{ $count->notes }}</p>@endif
</article>
@empty<p class="rounded-2xl bg-white p-8 text-center text-slate-500 shadow-sm">No stock counts have been created.</p>@endforelse
<div>{{ $counts->links() }}</div>
</section>
</main>
<script>
(() => {
 const list = document.getElementById('count-lines');
 const template = document.getElementById('count-line-template');
 const add = document.getElementById('add-count-line');
 if (!list || !template || !add) return;
 const renumber = () => [...list.children].forEach((row, index) => {
   row.querySelector('.product-select').name = `lines[${index}][product_id]`;
   row.querySelector('.counted-quantity').name = `lines[${index}][counted_quantity]`;
   row.querySelector('.line-notes').name = `lines[${index}][notes]`;
 });
 const append = () => {
   const row = template.content.firstElementChild.cloneNode(true);
   row.querySelector('.remove-line').addEventListener('click', () => { row.remove(); renumber(); });
   list.appendChild(row); renumber();
 };
 add.addEventListener('click', append);
 append();
})();
</script>
</body></html>
