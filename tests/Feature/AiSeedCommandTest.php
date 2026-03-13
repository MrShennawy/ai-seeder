 <?php

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('ai_seeder_test_cmd_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('ai_seeder_test_cmd_users');
});

test('ai:seed command fails for non-existent table', function () {
    $this->artisan('ai:seed this_table_does_not_exist')
        ->assertFailed();
});

test('ai:seed command shows help with correct signature', function () {
    $this->artisan('ai:seed --help')
        ->expectsOutputToContain('table')
        ->expectsOutputToContain('count')
        ->expectsOutputToContain('chunk')
        ->assertSuccessful();
});

test('ai:seed command help includes lang and context options', function () {
    $this->artisan('ai:seed --help')
        ->expectsOutputToContain('lang')
        ->assertSuccessful();
});
