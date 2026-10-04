<?php

namespace Tests\Unit\Support;

use App\Domain\Geo\GreatCircle;
use App\Rules\ImoNumber;
use App\Support\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MasterDataHelpersTest extends TestCase
{
    /** @return array<string, array{string,bool}> */
    public static function imoNumbers(): array
    {
        return [
            // 9×7 + 0×6 + 7×5 + 4×4 + 7×3 + 2×2 = 139 → check digit 9
            'valid 9074729' => ['9074729', true],
            'wrong check digit' => ['9074728', false],
            'valid 8814275' => ['8814275', true],
            'too short' => ['907472', false],
            'letters' => ['IMO9074729', false],
        ];
    }

    #[DataProvider('imoNumbers')]
    public function test_imo_check_digit(string $imo, bool $valid): void
    {
        $this->assertSame($valid, ImoNumber::isValid($imo));
    }

    public function test_name_normalizer_strips_punctuation_and_legal_suffixes(): void
    {
        $this->assertSame('gulf marine services', NameNormalizer::normalize('Gulf Marine Services L.L.C.'));
        $this->assertSame('gulf marine services', NameNormalizer::normalize('GULF  MARINE SERVICES Co. LLC'));
        $this->assertSame('smith and sons', NameNormalizer::normalize('Smith & Sons Ltd'));
        $this->assertSame('llc', NameNormalizer::normalize('LLC')); // a bare suffix is kept
    }

    public function test_great_circle_distance(): void
    {
        // One degree of longitude on the equator = 2πR/360 = 60.04 NM (R = 3440.0695 NM)
        $this->assertSame('60.04', GreatCircle::distanceNm(0, 0, 0, 1));
        $this->assertSame('0.00', GreatCircle::distanceNm(25.0, 55.0, 25.0, 55.0));
        // Symmetric
        $this->assertSame(GreatCircle::distanceNm(25.0112, 55.0612, 26.6439, 50.1597), GreatCircle::distanceNm(26.6439, 50.1597, 25.0112, 55.0612));
        // Antipodal points: half the circumference = π·R
        $this->assertSame('10807.30', GreatCircle::distanceNm(0, 0, 0, 180));
    }
}
