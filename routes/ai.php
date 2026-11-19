<?php

use App\Mcp\Servers\MdVaultServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('mdvault', MdVaultServer::class);
