<?php

namespace Scriptle\Laragraph;

use GraphQL\Error\DebugFlag;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use GraphQL\GraphQL as GraphQL_PHP;

class LaragraphController extends Controller
{
    public function ingest(Request $request)
    {
        abort_unless(400, $request->has('query'));
        $schema = preg_replace('/^' . config('laragraph.prefix') . '\//', '', $request->route()->uri);
        $query = $request->get('query');
        $variables = $request->get('variables');
        if (is_null($query)) {
            return [];
        }

        $schema = GraphQL::getSchema($schema);

        return (GraphQL_PHP::executeQuery($schema, $query, variableValues: $variables, fieldResolver: function ($objectValue, $args, $context, ResolveInfo $info) {
            return $objectValue[$info->fieldName] ?? null;
        }))->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS);

    }
}
