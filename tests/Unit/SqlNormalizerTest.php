<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Support\SqlNormalizer;
use PHPUnit\Framework\TestCase;

class SqlNormalizerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Whitespace collapse
    // -------------------------------------------------------------------------

    public function test_collapses_multiple_spaces_and_newlines(): void
    {
        $input  = "select  *\n from\tusers where id = ?";
        $result = SqlNormalizer::normalize($input);

        $this->assertSame('select * from users where id = ?', $result);
    }

    public function test_trims_leading_and_trailing_whitespace(): void
    {
        $result = SqlNormalizer::normalize('   SELECT 1   ');

        $this->assertSame('SELECT 1', $result);
    }

    // -------------------------------------------------------------------------
    // IN (?, ?, ?) → IN (?+)
    // -------------------------------------------------------------------------

    public function test_normalizes_in_list_with_multiple_placeholders(): void
    {
        $result = SqlNormalizer::normalize('select * from users where id in (?, ?, ?)');

        $this->assertSame('select * from users where id in (?+)', $result);
    }

    public function test_normalizes_in_list_with_single_placeholder(): void
    {
        $result = SqlNormalizer::normalize('select * from t where x IN (?)');

        $this->assertSame('select * from t where x IN (?+)', $result);
    }

    public function test_does_not_touch_in_list_with_string_literals(): void
    {
        $sql    = "select * from t where status in ('a','b')";
        $result = SqlNormalizer::normalize($sql);

        $this->assertSame($sql, $result);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_empty_string_returns_empty_string_without_exception(): void
    {
        $result = SqlNormalizer::normalize('');

        $this->assertSame('', $result);
    }

    public function test_idempotence_normalize_twice_equals_once(): void
    {
        $sql    = "select  *\n from\tusers where id in (?, ?, ?)";
        $once   = SqlNormalizer::normalize($sql);
        $twice  = SqlNormalizer::normalize($once);

        $this->assertSame($once, $twice);
    }

    // -------------------------------------------------------------------------
    // Mixed IN list — must stay intact
    // -------------------------------------------------------------------------

    public function test_does_not_touch_mixed_in_list(): void
    {
        $sql    = 'select * from t where x in (?, 1, ?)';
        $result = SqlNormalizer::normalize($sql);

        // Only whitespace normalization applies; IN clause is preserved
        $this->assertSame($sql, $result);
    }
}
