<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use PHPUnit\Framework\TestCase;

final class AgePolicyTest extends TestCase
{
    public function test_a_quarter_weight_in_dated_groups_with_a_switch_per_group(): void
    {
        $age = AgePolicy::fromGroups(['journal', 'news'], ['news']);

        $this->assertSame(0.25, $age->weight('journal'));
        $this->assertSame(1.0, $age->weight('news'), 'Switched off: like any other group.');
        $this->assertSame(1.0, $age->weight('pages'), 'No date field.');
        $this->assertTrue($age->isDated('journal'));
        $this->assertFalse($age->isDated('news'));
    }

    public function test_age_counts_from_six_months_and_fully_at_three_years(): void
    {
        $age = new AgePolicy;
        $now = new DateTimeImmutable('2026-10-04');

        $this->assertSame(0.0, $age->share(new DateTimeImmutable('2026-05-01'), $now));
        $this->assertSame(0.0, $age->share(null, $now));
        $this->assertGreaterThan(0.0, $age->share(new DateTimeImmutable('2026-04-01'), $now));
        $this->assertEqualsWithDelta(0.81, $age->share(new DateTimeImmutable('2024-03-14'), $now), 0.05);
        $this->assertSame(1.0, $age->share(new DateTimeImmutable('2020-01-01'), $now));
        $this->assertSame(30, AgePolicy::months(new DateTimeImmutable('2024-03-14'), $now));
    }
}
