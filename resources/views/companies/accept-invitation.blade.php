<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accept invitation · ANERP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 p-5 text-slate-900"><main class="mx-auto mt-12 max-w-lg rounded-3xl bg-white p-7 shadow-sm"><div class="text-sm font-semibold text-blue-600">ANERP</div><h1 class="mt-3 text-2xl font-bold">Join {{ $company->name }}</h1><p class="mt-2 text-sm text-slate-500">Invitation for {{ $invitation->email }} as {{ str($invitation->role_code)->replace('_',' ')->title() }}. This link can only be used once.</p>
@if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route('invitations.accept.store',$token) }}" class="mt-6 space-y-4">@csrf @if(!$existingUser)
<div><label class="mb-1 block text-sm">Full name</label><input name="name" required maxlength="255" class="w-full rounded-xl bg-slate-100 px-4 py-3"></div>
<div><label class="mb-1 block text-sm">Create password (minimum 12 characters)</label><input name="password" type="password" required minlength="12" autocomplete="new-password" class="w-full rounded-xl bg-slate-100 px-4 py-3"></div>
<div><label class="mb-1 block text-sm">Confirm password</label><input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="w-full rounded-xl bg-slate-100 px-4 py-3"></div>
@else<p class="rounded-xl bg-slate-100 p-4 text-sm">You are signed in as the invited user. Accept to add this company.</p>@endif
<button class="w-full rounded-full bg-blue-600 px-5 py-3 font-semibold text-white">Accept invitation</button></form></main></body></html>
