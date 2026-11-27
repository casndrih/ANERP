<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Security settings · ANERP</title><script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={darkMode:'class'}</script></head>
<body class="min-h-screen bg-slate-100 p-5 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100"><main class="mx-auto max-w-2xl space-y-5">
<header class="rounded-3xl bg-white p-6 shadow-sm dark:bg-zinc-900"><a href="{{ route('dashboard') }}" class="text-sm text-blue-600">← Back to ANERP</a><h1 class="mt-3 text-2xl font-bold">Account security</h1><p class="mt-1 text-sm text-slate-500">Authenticator-based multi-factor authentication</p></header>
@if(session('status'))<div class="rounded-2xl bg-blue-50 p-4 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ session('status') }}</div>@endif
@if($enabled)
<section class="rounded-3xl bg-white p-6 shadow-sm dark:bg-zinc-900"><div class="font-semibold">MFA is enabled</div><p class="mt-2 text-sm text-slate-500">Sign-ins require an authenticator code or an unused recovery code.</p>
@if(count($recoveryCodes))<div class="mt-5 rounded-2xl bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100"><div class="font-semibold">Save these recovery codes now</div><p class="mt-1 text-xs">Each code can be used once. They will not be shown again.</p><div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">@foreach($recoveryCodes as $code)<div class="rounded-lg bg-white/70 p-2 dark:bg-black/20">{{ $code }}</div>@endforeach</div></div>@endif
<form method="POST" action="{{ route('mfa.disable') }}" class="mt-6 space-y-3">@csrf<div><label class="mb-1 block text-sm" for="password">Password confirmation</label><input id="password" name="password" type="password" required class="w-full rounded-xl bg-slate-100 px-4 py-3 dark:bg-zinc-800"></div><div><label class="mb-1 block text-sm" for="code">Current authenticator code</label><input id="code" name="code" inputmode="numeric" maxlength="6" required class="w-full rounded-xl bg-slate-100 px-4 py-3 dark:bg-zinc-800"></div><button class="rounded-full bg-slate-200 px-5 py-3 text-sm font-semibold dark:bg-zinc-800">Disable MFA</button></form></section>
@else
<section class="rounded-3xl bg-white p-6 shadow-sm dark:bg-zinc-900"><div class="font-semibold">Set up an authenticator</div><p class="mt-2 text-sm text-slate-500">Use an authenticator app that supports standard TOTP (30-second, six-digit codes).</p>
@if(!$setupSecret)<form method="POST" action="{{ route('mfa.setup') }}" class="mt-5">@csrf<button class="rounded-full bg-blue-600 px-5 py-3 font-semibold text-white">Generate setup key</button></form>
@else
<div class="mt-5 rounded-2xl bg-slate-100 p-4 dark:bg-zinc-800"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Manual setup key</div><div class="mt-2 break-all font-mono text-lg">{{ $setupSecret }}</div><div class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Authenticator URI</div><div class="mt-1 break-all text-xs">{{ $otpAuthUri }}</div></div>
<form method="POST" action="{{ route('mfa.confirm') }}" class="mt-5 space-y-3">@csrf<label for="code" class="block text-sm font-medium">Enter the current six-digit code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="w-full rounded-xl bg-slate-100 px-4 py-3 text-lg tracking-widest dark:bg-zinc-800">@if($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif<button class="rounded-full bg-blue-600 px-5 py-3 font-semibold text-white">Confirm and enable MFA</button></form>
@endif
</section>
@endif
</main></body></html>
