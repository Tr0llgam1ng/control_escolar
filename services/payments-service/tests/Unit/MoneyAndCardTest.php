<?php

namespace Tests\Unit;

use App\Payments\BankSimulator;
use App\Payments\Money;
use PHPUnit\Framework\TestCase;

class MoneyAndCardTest extends TestCase
{
    public function test_amounts_are_converted_to_integer_cents(): void
    {
        $this->assertSame(15050, Money::toCents('150.5'));
        $this->assertSame(15000, Money::toCents('150'));
        $this->assertSame('0.30', Money::fromCents(Money::toCents('0.1') + Money::toCents('0.2')));
    }

    public function test_card_numbers_are_luhn_checked(): void
    {
        $this->assertTrue(BankSimulator::luhnValid('4111111111111111'));
        $this->assertTrue(BankSimulator::luhnValid('5555555555554444'));
        $this->assertFalse(BankSimulator::luhnValid('4111111111111112'));
        $this->assertFalse(BankSimulator::luhnValid('4111'));
        $this->assertFalse(BankSimulator::luhnValid('41111111111111a1'));
    }
}
