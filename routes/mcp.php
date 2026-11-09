<?php

use App\Mcp\SesameServer;
use Laravel\Mcp\Facades\Mcp;

// No web/session/CSRF stack: direct credentials and short-lived delegated
// assertions are authenticated by the package, never by browser cookies.
Mcp::web('/mcp', SesameServer::class)->middleware('bfc.mcp:product,read');
