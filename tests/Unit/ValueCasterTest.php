<?php

namespace Ssntpl\DataFields\Tests\Unit;

use Carbon\Carbon;
use Ssntpl\DataFields\Support\FieldType;
use Ssntpl\DataFields\Support\ValueCaster;
use Ssntpl\DataFields\Tests\TestCase;

class ValueCasterTest extends TestCase
{
    public function test_bool_write_returns_canonical_string_in_row_mode(): void
    {
        $this->assertSame('1', ValueCaster::castForWrite(FieldType::Bool, true));
        $this->assertSame('1', ValueCaster::castForWrite(FieldType::Bool, 'yes'));
        $this->assertSame('1', ValueCaster::castForWrite(FieldType::Bool, 'YES'));
        $this->assertSame('1', ValueCaster::castForWrite(FieldType::Bool, '1'));
        $this->assertSame('1', ValueCaster::castForWrite(FieldType::Bool, 1));

        $this->assertSame('0', ValueCaster::castForWrite(FieldType::Bool, false));
        $this->assertSame('0', ValueCaster::castForWrite(FieldType::Bool, '0'));
        $this->assertSame('0', ValueCaster::castForWrite(FieldType::Bool, 'no'));
        $this->assertSame('0', ValueCaster::castForWrite(FieldType::Bool, 'False'));
        $this->assertSame('0', ValueCaster::castForWrite(FieldType::Bool, 'arbitrary'));
    }

    public function test_bool_read_handles_postgres_style_text_values_safely(): void
    {
        // '0' and '' should both read as false. Critically, the literal
        // string 'false' (which (bool) treats as true!) must also read false.
        $this->assertFalse(ValueCaster::castForRead(FieldType::Bool, '0'));
        $this->assertFalse(ValueCaster::castForRead(FieldType::Bool, 'false'));
        $this->assertFalse(ValueCaster::castForRead(FieldType::Bool, 'FALSE'));

        $this->assertTrue(ValueCaster::castForRead(FieldType::Bool, '1'));
        $this->assertTrue(ValueCaster::castForRead(FieldType::Bool, 'true'));
        $this->assertTrue(ValueCaster::castForRead(FieldType::Bool, 'yes'));
    }

    public function test_carbon_read_returns_null_for_malformed_value(): void
    {
        $this->assertNull(ValueCaster::castForRead(FieldType::Date, 'not-a-date'));
        $this->assertNull(ValueCaster::castForRead(FieldType::Time, 'not-a-time'));
        $this->assertNull(ValueCaster::castForRead(FieldType::DateTime, 'garbage'));

        $this->assertNull(ValueCaster::castNativeRead(FieldType::Date, 'not-a-date'));
        $this->assertNull(ValueCaster::castNativeRead(FieldType::DateTime, 'garbage'));
    }

    public function test_carbon_read_still_works_for_valid_dates(): void
    {
        $this->assertSame('2026-06-15', ValueCaster::castForRead(FieldType::Date, '2026-06-15'));
        $this->assertInstanceOf(Carbon::class, ValueCaster::castForRead(FieldType::DateTime, '2026-06-15 10:00:00'));
    }

    public function test_accepts_raw_string_in_addition_to_enum(): void
    {
        // Both forms work — the caller can pass either.
        $this->assertSame(3.5, ValueCaster::castForRead(FieldType::Number, '3.5'));
        $this->assertSame(3.5, ValueCaster::castForRead('number', '3.5'));
    }
}
