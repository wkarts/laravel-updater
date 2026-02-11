<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function testValidateKnownCodeAtFixedTime(): void
    {
        $totp = new Totp();
        $secret = 'JBSWY3DPEHPK3PXP';
        $time = 1700000000;

        $knownCode = $totp->code($secret, $time);

        $this->assertTrue($totp->verify($secret, $knownCode, $time, 0));
        $this->assertFalse($totp->verify($secret, '000000', $time, 0));
    }
}
