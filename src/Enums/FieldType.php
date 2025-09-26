<?php

namespace Ssntpl\DataFields\Enums;

class FieldType
{
    public const BOOLEAN = 'Boolean';
    public const CHECK = 'Check';
    public const TEXT = 'Text';
    public const NUMBER = 'Number';
    public const SINGLE = 'Single';
    public const MULTIPLE = 'Multiple';
    public const DATE = 'Date';
    public const TIME = 'Time';
    public const DATETIME = 'Datetime';
    public const FILE = 'File';
    public const JSON = 'Json';
    public const ARRAY = 'Array';

    public static function getAllTypes()
    {
        return [
            self::BOOLEAN,
            self::CHECK,
            self::TEXT,
            self::NUMBER,
            self::SINGLE,
            self::MULTIPLE,
            self::DATE,
            self::TIME,
            self::DATETIME,
            self::FILE,
            self::JSON,
            self::ARRAY,
        ];
    }
}