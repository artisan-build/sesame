<?php

namespace App\Mcp\Tools;

use App\Models\SesameRun;
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

#[Name('runs')]
#[Description('List load runs, optionally for one simulated account. Cursor-paginated in descending run-ID order, newest first.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RunsTool extends SesameTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $input = $this->validate($request, [
            'account_id' => ['sometimes', 'uuid'],
            'after' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer:strict', 'between:1,100'],
        ]);
        $limit = $input['limit'] ?? 25;
        $runs = SesameRun::query()
            ->when(isset($input['account_id']), fn ($q) => $q->where('account_id', $input['account_id']))
            ->when(isset($input['after']), fn ($q) => $q->where('id', '<', $input['after']))
            ->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'account_id', 'seed', 'preset', 'label', 'started_at', 'ended_at']);
        $page = $runs->take($limit)->values();

        return Response::json([
            'runs' => $page->toArray(),
            'next' => $runs->count() > $limit ? $page->last()?->id : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Optional simulated account ID.'),
            'after' => $schema->string()->description('Run ID from next on the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}
