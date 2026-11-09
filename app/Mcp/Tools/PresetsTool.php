<?php

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('presets')]
#[Description('Read this installation\'s configured outcome percentages and timing ranges. Accounts may override these values.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class PresetsTool extends SesameTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $this->validate($request, []);

        return Response::json([
            'presets' => config('sesame.presets'),
            'timing_seconds' => config('sesame.timing'),
            'defaults' => config('sesame.defaults'),
        ]);
    }
}
