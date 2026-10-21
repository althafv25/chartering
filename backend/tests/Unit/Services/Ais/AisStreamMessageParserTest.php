<?php

namespace Tests\Unit\Services\Ais;

use App\Services\Ais\AisStreamMessageParser;
use App\Services\Ais\AisStreamProvider;
use App\Services\Ais\VesselIdentifier;
use PHPUnit\Framework\TestCase;

class AisStreamMessageParserTest extends TestCase
{
    private function positionReport(array $overrides = []): array
    {
        return array_replace_recursive([
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 368207620, 'ShipName' => 'EXAMPLE VESSEL', 'Latitude' => 25.7617, 'Longitude' => -80.1918, 'time_utc' => '2026-10-10 06:00:00 +0000 UTC'],
            'Message' => ['PositionReport' => ['MessageID' => 1, 'UserID' => 368207620, 'Sog' => 12.4, 'Cog' => 86.7, 'TrueHeading' => 87, 'Valid' => true]],
        ], $overrides);
    }

    public function test_maps_a_position_report_to_a_dto_for_a_known_mmsi(): void
    {
        $dto = AisStreamMessageParser::toPositionDto($this->positionReport(), ['368207620' => 42]);

        $this->assertNotNull($dto);
        $this->assertSame(42, $dto->vesselId);
        $this->assertSame(AisStreamProvider::NAME, $dto->provider);
        $this->assertSame('25.761700', $dto->latitude);
        $this->assertSame('-80.191800', $dto->longitude);
        $this->assertSame('12.40', $dto->sogKn);
        $this->assertSame('86.70', $dto->cogDeg);
        $this->assertSame(87, $dto->headingDeg);
        $this->assertSame('368207620', $dto->mmsi);
    }

    public function test_ignores_a_position_for_an_mmsi_outside_the_tracked_fleet(): void
    {
        $dto = AisStreamMessageParser::toPositionDto($this->positionReport(), ['999999999' => 1]);

        $this->assertNull($dto);
    }

    public function test_matches_an_mmsi_regardless_of_leading_zero_padding(): void
    {
        $report = $this->positionReport(['MetaData' => ['MMSI' => 7620]]);

        // aisstream may report a short numeric MMSI; our subscription pads to 9 chars with
        // leading zeros, so both sides must normalize the same way to match.
        $dto = AisStreamMessageParser::toPositionDto($report, ['7620' => 7]);

        $this->assertNotNull($dto);
        $this->assertSame(7, $dto->vesselId);
    }

    public function test_ignores_non_position_report_message_types(): void
    {
        $this->assertNull(AisStreamMessageParser::toPositionDto(['MessageType' => 'SubscriptionConfirmation', 'Message' => ['CompressionEnabled' => true]], []));
        $this->assertNull(AisStreamMessageParser::toPositionDto(['MessageType' => 'ShipStaticData'], ['368207620' => 1]));
    }

    public function test_ignores_a_report_missing_coordinates(): void
    {
        $report = $this->positionReport();
        unset($report['MetaData']['Latitude']);

        $this->assertNull(AisStreamMessageParser::toPositionDto($report, ['368207620' => 1]));
    }

    public function test_mmsi_filter_pads_to_nine_characters_deduplicates_and_caps_at_200(): void
    {
        $vessels = [
            new VesselIdentifier(1, '7620', null),
            new VesselIdentifier(2, '368207620', null),
            new VesselIdentifier(3, '368207620', null), // duplicate MMSI, e.g. re-flagged sister vessel entry
            new VesselIdentifier(4, null, '9074729'),    // no MMSI — excluded, not polled by IMO here
        ];

        $filter = AisStreamMessageParser::mmsiFilter($vessels);

        $this->assertSame(['000007620', '368207620'], $filter);
        $this->assertLessThanOrEqual(200, count($filter));
    }

    public function test_bounding_box_parses_the_configured_corners_and_falls_back_to_the_whole_globe(): void
    {
        $this->assertSame([[25.0, 55.0], [26.0, 56.0]], AisStreamMessageParser::boundingBox('25,55,26,56'));
        $this->assertSame([[-90.0, -180.0], [90.0, 180.0]], AisStreamMessageParser::boundingBox('not-a-valid-box'));
    }

    public function test_normalize_mmsi_strips_leading_zeros_only(): void
    {
        $this->assertSame('7620', AisStreamMessageParser::normalizeMmsi('000007620'));
        $this->assertSame('368207620', AisStreamMessageParser::normalizeMmsi('368207620'));
    }
}
