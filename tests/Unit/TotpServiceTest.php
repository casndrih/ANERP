<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function test_rfc_totp_sha1_vector_is_generated_correctly(): void
    {
        $service = new TotpService();

        self::assertSame('287082', $service->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
    }

    public function test_totp_verification_accepts_current_window_and_rejects_invalid_codes(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $code = $service->codeAt($secret, 1_800_000_000);

        self::assertTrue($service->verify($secret, $code, 1_800_000_000));
        self::assertFalse($service->verify($secret, 'abc123', 1_800_000_000));
        self::assertFalse($service->verify($secret, '000000', 1_800_000_000));
    }
}
