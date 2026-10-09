<?php

use App\Models\ShoppingList;
use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use Tests\Fakes\FakeMcpClient;
use Tests\Fakes\FakeSkylight;

it('syncs and prints a summary', function () {
    $skylight = (new FakeSkylight)->addList('9002', 'Groceries');
    $skylight->addItem('9002', 'Milk');
    $skylight->addItem('9002', 'Eggs');
    app()->instance(SkylightClient::class, new SkylightClient($skylight));

    $this->artisan('skylight:sync')
        ->expectsOutputToContain('2 changes from Skylight.')
        ->assertSuccessful();

    expect(ShoppingList::sole()->items()->count())->toBe(2);
});

it('fails with the reason when skylight cannot be reached', function () {
    app()->instance(SkylightClient::class, new SkylightClient(new FakeMcpClient([
        'skylight_list_lists' => fn () => throw new McpException('The MCP server exited during [initialize].'),
    ])));

    $this->artisan('skylight:sync')
        ->expectsOutputToContain('Skylight sync failed: The MCP server exited during [initialize].')
        ->assertFailed();
});
