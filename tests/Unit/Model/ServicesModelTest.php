<?php

namespace Tests\Unit\Model;

use InvalidArgumentException;
use ReflectionClass;
use Services_model;
use Tests\TestCase;

class ServicesModelTest extends TestCase
{
    private function services_model(): Services_model
    {
        // EA_Model extends CodeIgniter's CI_Model, which must be defined before EA_Model is parsed.
        require_once BASEPATH . 'core/Model.php';
        require_once APPPATH . 'core/EA_Model.php';
        require_once APPPATH . 'models/Services_model.php';

        // Matches application/config/constants.php -- defined here rather than requiring that
        // file wholesale, which would clash with the DB_SLUG_* stand-ins tests/bootstrap.php
        // already defines for the permission helper tests.
        defined('EVENT_MINIMUM_DURATION') or define('EVENT_MINIMUM_DURATION', 5);

        // The constructor only loads CodeIgniter model/DB dependencies that validate() does not
        // need, as long as the "id"/"id_service_categories" keys (the only $this->db touches) are
        // omitted from the payload.
        return (new ReflectionClass(Services_model::class))->newInstanceWithoutConstructor();
    }

    public function testValidateThrowsWhenNameIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['attendants_number' => 1]);
    }

    public function testValidateThrowsWhenDurationIsBelowTheMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['name' => 'Haircut', 'duration' => 2, 'attendants_number' => 1]);
    }

    public function testValidateThrowsWhenPriceIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['name' => 'Haircut', 'price' => -5, 'attendants_number' => 1]);
    }

    public function testValidateThrowsWhenSlotIntervalIsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['name' => 'Haircut', 'slot_interval' => 0, 'attendants_number' => 1]);
    }

    public function testValidateThrowsWhenAttendantsNumberIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['name' => 'Haircut']);
    }

    public function testValidateThrowsWhenAttendantsNumberIsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate(['name' => 'Haircut', 'attendants_number' => 0]);
    }

    public function testValidateThrowsWhenColorIsNotAHexValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate([
            'name' => 'Haircut',
            'color' => 'red" onmouseover="x',
            'attendants_number' => 1,
        ]);
    }

    public function testValidateThrowsWhenAProviderAttendantsNumberIsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->services_model()->validate([
            'name' => 'Yoga',
            'attendants_number' => 10,
            'provider_attendants_numbers' => [5 => '0'],
        ]);
    }

    public function testValidatePassesForTheGroupBookingOptions(): void
    {
        $this->services_model()->validate([
            'name' => 'Yoga',
            'attendants_number' => 10,
            'provider_attendants_numbers' => [5 => '6', 7 => ''],
        ]);

        $this->assertTrue(true); // No exception thrown.
    }

    public function testValidatePassesForAFullyValidPayload(): void
    {
        $this->services_model()->validate(['name' => 'Haircut', 'attendants_number' => 1]);

        $this->assertTrue(true); // No exception thrown.
    }

    public function testApiEncodeExposesTheProviderAttendantsNumbersAsAnObject(): void
    {
        $service = [
            'id' => 1,
            'name' => 'Yoga',
            'duration' => 60,
            'price' => 0,
            'currency' => 'EUR',
            'description' => null,
            'location' => null,
            'slot_interval' => 15,
            'attendants_number' => 10,
            'is_private' => false,
            'id_service_categories' => null,
            'provider_attendants_numbers' => [2 => 6],
        ];

        $this->services_model()->api_encode($service);

        $this->assertSame('{"2":6}', json_encode($service['providerAttendantsNumbers']));
    }

    public function testApiDecodeReadsTheProviderAttendantsNumbers(): void
    {
        $service = ['providerAttendantsNumbers' => ['2' => 6, '3' => null]];

        $this->services_model()->api_decode($service);

        $this->assertSame([2 => 6, 3 => null], $service['provider_attendants_numbers']);
    }

    public function testNormalizeFreePlacesFieldsStoresTheBookingSettingsChoiceAsNull(): void
    {
        $model = $this->services_model();

        $this->assertNull($model->normalize_free_places_fields(['display_free_places' => ''])['display_free_places']);
        $this->assertNull($model->normalize_free_places_fields(['display_free_places' => null])['display_free_places']);
    }

    public function testNormalizeFreePlacesFieldsStoresOverridesAsOneOrZero(): void
    {
        $model = $this->services_model();

        // Form values arrive as strings, API values as booleans.
        foreach (['1' => 1, '0' => 0, 'true' => 1, 'false' => 0] as $value => $expected) {
            $service = $model->normalize_free_places_fields([
                'display_free_places' => (string) $value,
                'display_max_attendants' => (string) $value,
            ]);

            $this->assertSame($expected, $service['display_free_places']);
            $this->assertSame($expected, $service['display_max_attendants']);
        }

        $this->assertSame(
            0,
            $model->normalize_free_places_fields(['display_free_places' => false])['display_free_places'],
        );
    }

    public function testNormalizeFreePlacesFieldsLeavesMissingFieldsAlone(): void
    {
        $this->assertSame(
            ['name' => 'Yoga'],
            $this->services_model()->normalize_free_places_fields(['name' => 'Yoga']),
        );
    }

    public function testApiEncodeAndDecodeKeepTheFreePlacesFields(): void
    {
        $model = $this->services_model();

        foreach ([null, true, false] as $display_free_places) {
            $service = [
                'id' => 1,
                'name' => 'Yoga',
                'duration' => 60,
                'price' => 0,
                'currency' => 'EUR',
                'description' => null,
                'location' => null,
                'slot_interval' => 15,
                'attendants_number' => 10,
                'display_free_places' => $display_free_places,
                'display_max_attendants' => true,
                'is_private' => false,
                'id_service_categories' => null,
                'provider_attendants_numbers' => [],
            ];

            $model->api_encode($service);

            $this->assertSame($display_free_places, $service['displayFreePlaces']);
            $this->assertTrue($service['displayMaxAttendants']);

            $decoded = $service;
            $model->api_decode($decoded);

            $this->assertSame($display_free_places, $decoded['display_free_places']);
            $this->assertTrue($decoded['display_max_attendants']);
        }
    }
}
