<?php

use App\Mcp\Servers\HouseholdServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('household', HouseholdServer::class);
