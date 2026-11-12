<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    // Most tests act as the owner; AuthTest checks logins and roles itself
    protected bool $actAsOwner = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->actAsOwner && in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            Sanctum::actingAs(User::factory()->create(['role' => 'owner']));
        }
    }
}
