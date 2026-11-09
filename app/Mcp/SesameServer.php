<?php

namespace App\Mcp;

use App\Mcp\Tools\AccountsTool;
use App\Mcp\Tools\PresetsTool;
use App\Mcp\Tools\RunsTool;
use App\Mcp\Tools\RunSummaryTool;
use App\Mcp\Tools\RunTruthTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Sesame')]
#[Version('1.0.0')]
#[Instructions('Read-only inspection of simulated SES accounts, presets, runs and reconciliation ground truth in this installation. All authorized installation members share its simulator data. Never use Sesame against real recipients or real SES. Account creation and run changes remain operator-only Artisan commands.')]
final class SesameServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        AccountsTool::class,
        PresetsTool::class,
        RunsTool::class,
        RunSummaryTool::class,
        RunTruthTool::class,
    ];
}
