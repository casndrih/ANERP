<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify sign-in · ANERP</title><script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={darkMode:'class'}</script></head>
<body class="grid min-h-screen place-items-center bg-slate-100 p-5 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100"><main class="w-full max-w-md rounded-3xl bg-white p-8 shadow-sm dark:bg-zinc-900">
<div class="mb-7 text-3xl font-bold">ANERP</div><h1 class="text-xl font-semibold">Two-step verification</h1><p class="mt-2 text-sm text-slate-500">Enter the six-digit code from your authenticator app, or use one unused recovery code.</p>
<form method="POST" action="{{ route('mfa.challenge.verify') }}" class="mt-6 space-y-4">@csrf
<div><label for="code" class="mb-1 block text-sm font-medium">Authenticator code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="w-full rounded-xl bg-slate-100 px-4 py-3 text-lg tracking-widest outline-none focus:ring-2 focus:ring-blue-600 dark:bg-zinc-800"></div>
<div class="text-center text-xs text-slate-500">Or</div>
<div><label for="recovery_code" class="mb-1 block text-sm font-medium">Recovery code</label><input id="recovery_code" name="recovery_code" autocomplete="off" class="w-full rounded-xl bg-slate-100 px-4 py-3 outline-none focus:ring-2 focus:ring-blue-600 dark:bg-zinc-800"></div>
@if($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
<button class="w-full rounded-full bg-blue-600 px-5 py-3 font-semibold text-white hover:bg-blue-700">Verify and continue</button></form>
<form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="w-full rounded-full bg-slate-100 px-5 py-3 text-sm font-medium dark:bg-zinc-800">Sign out</button></form></main></body></html>
