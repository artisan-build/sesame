<?php

namespace App\Mcp\Tools;

use App\Models\SesameRun;
use App\Sesame\RunReport;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('run_summary')]
#[Description('Read live simulator-side send counts, outcomes, refusals, event states and webhook latency for one run. Raw data is retained for seven days; saved summaries are separate snapshots.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RunSummaryTool extends SesameTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $input = $this->validate($request, ['run_id' => ['required', 'uuid']]);
        $run = SesameRun::find($input['run_id']);

        return $run === null ? Response::error('Run not found.') : Response::json([
            'summary' => RunReport::summary($run),
            'saved_summary' => $run->summary,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['run_id' => $schema->string()->description('Run ID returned by runs.')->required()];
    }
}
