<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Expect the database to reject the next write with an integrity constraint violation.
     *
     * Matches SQLSTATE class 23 so it holds on every driver the suite runs on: SQLite
     * reports 23000 for any constraint, Postgres 23503 for a foreign key.
     */
    protected function expectConstraintViolation(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/^SQLSTATE\[23\d{3}\]/');
    }
}
