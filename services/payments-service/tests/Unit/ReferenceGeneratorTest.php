<?php

namespace Tests\Unit;

use App\Payments\ReferenceGenerator;
use PHPUnit\Framework\TestCase;

class ReferenceGeneratorTest extends TestCase
{
    public function test_known_luhn_check_digits(): void
    {
        $this->assertSame(2, ReferenceGenerator::checkDigit('482901736154'));
        $this->assertSame(8, ReferenceGenerator::checkDigit('000000000001'));
        // Classic Luhn example: 7992739871 -> 3
        $this->assertSame(3, ReferenceGenerator::checkDigit('7992739871'));
    }

    public function test_generated_reference_has_13_digits_and_valid_check_digit(): void
    {
        $generator = new ReferenceGenerator;
        for ($i = 0; $i < 500; $i++) {
            $ref = $generator->generate();
            $this->assertMatchesRegularExpression('/^\d{13}$/', $ref);
            $this->assertTrue(ReferenceGenerator::isValid($ref), $ref);
        }
    }

    public function test_references_are_random(): void
    {
        $generator = new ReferenceGenerator;
        $refs = array_map(fn () => $generator->generate(), range(1, 1000));
        $this->assertCount(1000, array_unique($refs));
    }

    public function test_single_digit_error_is_detected(): void
    {
        $ref = '4829017361542';
        for ($pos = 0; $pos < 13; $pos++) {
            for ($d = 0; $d <= 9; $d++) {
                if ((int) $ref[$pos] === $d) {
                    continue;
                }
                $typo = substr_replace($ref, (string) $d, $pos, 1);
                $this->assertFalse(ReferenceGenerator::isValid($typo), "Typo not detected: {$typo}");
            }
        }
    }

    public function test_adjacent_transposition_is_detected(): void
    {
        $swapped = '4289017361542'; // 8 and 2 swapped
        $this->assertFalse(ReferenceGenerator::isValid($swapped));
    }

    public function test_invalid_formats_are_rejected(): void
    {
        foreach (['', '123', '48290173615420', 'abcdefghijklm', '482901736154 ', null] as $bad) {
            $this->assertFalse(ReferenceGenerator::isValid($bad));
        }
    }
}
