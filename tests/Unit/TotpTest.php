<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function testGeraEValidaCodigoTotp(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();
        $code = $totp->at($secret, (int) floor(time() / 30));

        $this->assertTrue($totp->verify($secret, $code));
    }
}
