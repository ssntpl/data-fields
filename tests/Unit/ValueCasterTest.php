<?php

namespace Ssntpl\DataFields\Tests\Unit;

use Carbon\Carbon;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\ValueCaster;
use Ssntpl\DataFields\Tests\TestCase;

class ValueCasterTest extends TestCase
{
    public function test_bool_write_returns_canonical_string_in_row_mode(): void
    {
        $this->assertSame('1', ValueCaster::castForWrite(DataField::BOOL, true));
        $this->assertSame('1', ValueCaster::castForWrite(DataField::BOOL, 'yes'));
        $this->assertSame('1', ValueCaster::castForWrite(DataField::BOOL, 'YES'));
        $this->assertSame('1', ValueCaster::castForWrite(DataField::BOOL, '1'));
        $this->assertSame('1', ValueCaster::castForWrite(DataField::BOOL, 1));

        $this->assertSame('0', ValueCaster::castForWrite(DataField::BOOL, false));
        $this->assertSame('0', ValueCaster::castForWrite(DataField::BOOL, '0'));
        $this->assertSame('0', ValueCaster::castForWrite(DataField::BOOL, 'no'));
        $this->assertSame('0', ValueCaster::castForWrite(DataField::BOOL, 'False'));
        $this->assertSame('0', ValueCaster::castForWrite(DataField::BOOL, 'arbitrary'));
    }

    public function test_bool_read_handles_postgres_style_text_values_safely(): void
    {
        // '0' and '' should both read as false. Critically, the literal
        // string 'false' (which (bool) treats as true!) must also read false.
        $this->assertFalse(ValueCaster::castForRead(DataField::BOOL, '0'));
        $this->assertFalse(ValueCaster::castForRead(DataField::BOOL, 'false'));
        $this->assertFalse(ValueCaster::castForRead(DataField::BOOL, 'FALSE'));

        $this->assertTrue(ValueCaster::castForRead(DataField::BOOL, '1'));
        $this->assertTrue(ValueCaster::castForRead(DataField::BOOL, 'true'));
        $this->assertTrue(ValueCaster::castForRead(DataField::BOOL, 'yes'));
    }

    public function test_carbon_read_returns_null_for_malformed_value(): void
    {
        $this->assertNull(ValueCaster::castForRead(DataField::DATE, 'not-a-date'));
        $this->assertNull(ValueCaster::castForRead(DataField::TIME, 'not-a-time'));
        $this->assertNull(ValueCaster::castForRead(DataField::DATETIME, 'garbage'));

        $this->assertNull(ValueCaster::castNativeRead(DataField::DATE, 'not-a-date'));
        $this->assertNull(ValueCaster::castNativeRead(DataField::DATETIME, 'garbage'));
    }

    public function test_carbon_read_still_works_for_valid_dates(): void
    {
        $this->assertSame('2026-06-15', ValueCaster::castForRead(DataField::DATE, '2026-06-15'));
        $this->assertInstanceOf(Carbon::class, ValueCaster::castForRead(DataField::DATETIME, '2026-06-15 10:00:00'));
    }
}
