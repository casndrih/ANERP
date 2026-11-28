<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PNG GST summary · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100 md:p-7"><main class="mx-auto max-w-6xl space-y-6">
<header class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm text-slate-500">PNG tax reporting · invoice basis</p><h1 class="text-2xl font-bold">GST summary</h1><p class="text-sm text-slate-500">{{ $company->gst_registered ? 'GST-registered company' : 'Company not marked GST-registered' }} · {{ $assignedBranchId ? 'Assigned branch' : 'Company-wide' }}</p></div><a href="{{ route('finance.index') }}" class="rounded-full bg-white px-4 py-2 shadow-sm">Finance</a></header>
<form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm"><label class="text-sm">From<input name="from" type="date" value="{{ $from }}" class="mt-1 block rounded-xl bg-slate-100 p-3"></label><label class="text-sm">To<input name="to" type="date" value="{{ $to }}" class="mt-1 block rounded-xl bg-slate-100 p-3"></label><button class="rounded-full bg-blue-600 px-5 py-3 text-white">Run summary</button></form>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
@foreach([['Total sales',$totalSales],['Taxable sales',$taxableSales],['Output GST',$outputGst],['Eligible input credits',$inputCredits]] as $metric)<div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $metric[0] }}</p><p class="mt-2 text-2xl font-bold">K {{ number_format($metric[1],2) }}</p></div>@endforeach
</section>
<section class="rounded-2xl bg-white p-5 shadow-sm"><h2 class="mb-4 font-semibold">G1-style working summary</h2><table class="w-full text-sm"><tbody>
<tr class="border-b"><td class="p-3">Total sales (standard, zero-rated and exempt)</td><td class="p-3 text-right">K {{ number_format($totalSales,2) }}</td></tr>
<tr class="border-b"><td class="p-3">Less exempt sales</td><td class="p-3 text-right">K {{ number_format($exemptSales,2) }}</td></tr>
<tr class="border-b"><td class="p-3">Less zero-rated sales</td><td class="p-3 text-right">K {{ number_format($zeroRatedSales,2) }}</td></tr>
<tr class="border-b font-semibold"><td class="p-3">Taxable sales</td><td class="p-3 text-right">K {{ number_format($taxableSales,2) }}</td></tr>
<tr class="border-b"><td class="p-3">Output GST</td><td class="p-3 text-right">K {{ number_format($outputGst,2) }}</td></tr>
<tr class="border-b"><td class="p-3">Eligible domestic input purchases (net)</td><td class="p-3 text-right">K {{ number_format($eligiblePurchases,2) }}</td></tr>
<tr class="border-b"><td class="p-3">Input tax credits recorded</td><td class="p-3 text-right">K {{ number_format($inputCredits,2) }}</td></tr>
<tr class="font-semibold"><td class="p-3">Net GST payable / (refundable)</td><td class="p-3 text-right">K {{ number_format($netGst,2) }}</td></tr>
</tbody></table></section>
<div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900"><b>Not an official GST return.</b> This management summary covers domestic customer invoices and supplier bills recorded in ANERP. It does not yet include import GST/customs assessments, GST withholding under section 65A, private-use adjustments, exempt-sale input-credit apportionment, credit/debit adjustment notes for GST invoices, or tax-agent validation. Confirm the figures against the current IRC G1 return and supporting tax invoices before filing.</div>
</main></body></html>
