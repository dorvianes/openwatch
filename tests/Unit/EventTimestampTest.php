<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Support\EventTimestamp;
use PHPUnit\Framework\TestCase;

class EventTimestampTest extends TestCase
{
    public function test_format_null_returns_non_empty_iso8601_string_with_timezone_offset(): void
    {
        $formatted = EventTimestamp::format(null);

        $this->assertIsString($formatted);
        $this->assertNotSame('', $formatted);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $formatted,
            'EventTimestamp::format(null) must return ISO-8601 with timezone offset',
        );
    }
}
