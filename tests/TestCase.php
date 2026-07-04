<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // RefreshDatabase bọc transaction cả connection raw (pgsql → sqlite khi test, xem phpunit.xml)
    protected $connectionsToTransact = [null, 'pgsql'];
}
