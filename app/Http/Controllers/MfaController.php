<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function settings(Request $request)
    {
        $user = $request->user();
        $secret = $request->session()->get('mfa_setup_secret');
        $issuer = 'ANERP';
        $account = $user->email;
        $uri = $secret
            ? 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
                . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
                . '&algorithm=SHA1&digits=6&period=30'
            : null;

        return view('mfa.settings', [
            'enabled' => (bool) $user->mfa_enabled_at,
            'setupSecret' => $secret,
            'otpAuthUri' => $uri,
            'recoveryCodes' => $request->session()->get('mfa_recovery_codes_once', []),
        ]);
    }

    public function setup(Request $request, TotpService $totp)
    {
        abort_if($request->user()->mfa_enabled_at, 409, 'MFA is already enabled.');
        $request->session()->put('mfa_setup_secret', $totp->generateSecret());

        return redirect()->route('mfa.settings')
            ->with('status', 'Add the displayed ANERP key to your authenticator app, then verify a current code.');
    }

    public function confirm(Request $request, TotpService $totp)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = $request->session()->get('mfa_setup_secret');

        if (!$secret || !$totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or the setup session has expired.']);
        }

        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(Str::random(4) . '-' . Str::random(4));
            $codes[] = $code;
            $hashes[] = Hash::make($code);
        }

        $user = $request->user();
        $user->forceFill([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => $hashes,
        ])->save();

        $request->session()->forget('mfa_setup_secret');
        $request->session()->put('mfa_verified_user_id', $user->id);
        $request->session()->put('mfa_recovery_codes_once', $codes);

        return redirect()->route('mfa.settings')
            ->with('status', 'Multi-factor authentication is enabled. Save your recovery codes somewhere secure.');
    }

    public function challenge()
    {
        return view('mfa.challenge');
    }

    public function verifyChallenge(Request $request, TotpService $totp)
    {
        $data = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'digits:6'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:20'],
        ]);

        $user = $request->user();
        abort_unless($user->mfa_enabled_at && $user->mfa_secret, 403, 'MFA is not configured for this account.');

        $valid = false;
        if (!empty($data['code'])) {
            $valid = $totp->verify(Crypt::decryptString($user->mfa_secret), $data['code']);
        } elseif (!empty($data['recovery_code'])) {
            $codes = $user->mfa_recovery_codes ?? [];
            foreach ($codes as $index => $hash) {
                if (Hash::check(strtoupper(trim($data['recovery_code'])), $hash)) {
                    unset($codes[$index]);
                    $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            throw ValidationException::withMessages(['code' => 'The authenticator or recovery code is invalid.']);
        }

        $request->session()->put('mfa_verified_user_id', $user->id);
        return redirect()->intended(route('dashboard'));
    }

    public function disable(Request $request, TotpService $totp)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $company = $request->attributes->get('activeCompany');
        abort_if(in_array($company?->pivot?->role_code, [
            'owner', 'admin', 'inventory_manager', 'procurement_manager',
            'procurement_approver', 'finance_manager', 'accountant',
            'payroll_manager', 'hr_manager',
        ], true), 403, 'Privileged company roles must keep MFA enabled.');

        abort_unless(Hash::check($data['password'], $user->password), 403, 'Password confirmation failed.');
        abort_unless($user->mfa_secret && $totp->verify(Crypt::decryptString($user->mfa_secret), $data['code']), 403, 'Authenticator code is invalid.');

        $user->forceFill([
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $request->session()->forget(['mfa_verified_user_id', 'mfa_recovery_codes_once']);
        return redirect()->route('mfa.settings')->with('status', 'MFA disabled for this account.');
    }
}
