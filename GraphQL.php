<?php

namespace Scriptle\Laragraph;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use GraphQL\Type\Definition\Type as GraphQL_PHPType;

class GraphQL {

    public array|null $currentSchema;
    public string|null $currentSchemaName = '';

    public Collection $types;
    public array|null $typeDefs = [];

    public static array|null $builtTypeDefs = [];
    public static array|null $typeDefsToClass = [];
    public static bool $saved = false;
    public static array|null $standardTypes = null;

    public static function getStandardTypes() {
        if (is_null(self::$standardTypes)) {
            return self::$standardTypes = [
                'Int' => GraphQL_PHPType::int(),
                'String' => GraphQL_PHPType::string(),
                'Boolean' => GraphQL_PHPType::boolean(),
                'Float' => GraphQL_PHPType::float(),
                'ID' => GraphQL_PHPType::id(),
            ];
        } else {
            return self::$standardTypes;
        }
    }

    public function scan($dir) {
        $ll = collect(array_slice(scandir($dir), PHP_OS === 'Windows' ? 0 : 2))->filter(fn ($v) => is_dir($dir . DIRECTORY_SEPARATOR . $v) || Str::endsWith($v,'.php'))->map(fn ($v) => preg_replace('/\.php$/', '', $v))->values()->all();
        foreach ($ll as $ind => $item) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . $item)) {
                unset($ll[$ind]);
                $ll[$item] = $this->scan($dir . DIRECTORY_SEPARATOR . $item);
            }
        }
        return $ll;
    }

    public function __construct($schema)
    {
        $filename = 'laragraph-' . Str::slug($this->currentSchemaName) . '-cache.php';
        if (file_exists(storage_path($filename))) {
            $this->typeDefs = json_decode(file_get_contents(storage_path($filename)), true);
            $this->currentSchema = config('laragraph.schemas')[$schema];
            $this->currentSchemaName = $schema;
            return;
        }
        $dir = $this->scan(app_path('GraphQL'));
        $models = $this->scan(app_path('Models'));

        $this->currentSchema = config('laragraph.schemas')[$schema];
        $this->currentSchemaName = $schema;

        $queries = collect($dir['Queries'])->map(fn ($x) => 'App\\GraphQL\\Queries\\' . $x)->values();
        $mutations = collect($dir['Mutations'])->map(fn ($x) => 'App\\GraphQL\\Mutations\\' . $x)->values();
        $customTypes = collect($dir['Types'])->map(fn ($x) => 'App\\GraphQL\\Types\\' . $x)->values();
        $types = collect($models)->map(fn ($x) => 'App\\Models\\' . $x)->values();

        $this->types = $types
            ->merge($queries)
            ->merge($mutations)
            ->merge($customTypes);
    }

    public function parse()
    {
        if (count($this->typeDefs)) {
            // If loaded from cache in constructor
            return;
        }

        foreach ($this->types as $type) {
            if (!class_exists($type)) {
                require_once app_path(preg_replace('/\\\/', '/', preg_replace('/App[\\\]/i', '', $type) . '.php'));
            }
            $rfc = new \ReflectionClass($type);
            $final = [
                'fields' => [],
                'type' => '',
                'description' => '',
            ];
            $methods = collect($rfc->getMethods(\ReflectionMethod::IS_PUBLIC));
            foreach ($rfc->getAttributes() as $attribute) {
                if ($attribute->getName() === Type::class) {
                    if (count($attribute->getArguments()) === 2) {
                        $final['type'] = $attribute->getArguments()[0];
                        $final['description'] = $attribute->getArguments()[1];
                    } else if (count($attribute->getArguments()) === 1) {
                        $final['type'] =  $attribute->getArguments()[0];
                        $final['description'] = null;
                    } else {
                        preg_match('/(?:.+\\\)*(.+)/i', $type, $matches);
                        $final['type'] = $matches[1];
                    }
                }
                if ($attribute->getName() === Field::class) {
                    $name = $attribute->getArguments()[0];

                    // Attempts to match the field to a resolver, if present
                    $resolver = ($methods->filter(function ($x) use ($name) {
                        $name = preg_match('/([^(]+)/', $name, $matches);
                        return $x->name === 'resolve' . Str::studly($matches[1]) . 'Field'
                            || (count($x->getAttributes(ResolvesFor::class)) && $x->getAttributes(ResolvesFor::class)[0]->getArguments()[0] === $matches[1]);
                    }))->first();
                    if (!is_null($resolver)) {
                        $resolver = $resolver->name;;
                    }

                    $final['fields'][$name] = ['type' => $attribute->getArguments()[1], 'description' => $attribute->getArguments()[2], 'resolver' => $resolver];
                }
            }

            static::$typeDefsToClass[$final['type']] = $rfc->getName();

            // Handles directly adding attributes to methods
            $directlyAddedFields = $methods->filter(function ($x) {
                return count($x->getAttributes(Field::class));
            });

            foreach ($directlyAddedFields as $field) {
                $attributes = $field->getAttributes(Field::class);
                foreach ($attributes as $attribute) {
                    $final['fields'][$attribute->getArguments()[0]] = ['type' => $attribute->getArguments()[1], 'description' => $attribute->getArguments()[2], 'resolver' => $field->getName()];
                }
            }


            $this->typeDefs[] = $final;
        }
    }

    public function save()
    {
        file_put_contents(storage_path('laragraph-' . Str::slug($this->currentSchemaName) . '-cache.php'), json_encode($this->typeDefs));
    }

    public function buildAll()
    {
        foreach ($this->typeDefs as $def) {
            if (isset(self::$builtTypeDefs[$def['type']])) continue;

            $this->build($def);
        }
    }

    public function resolveType($in)
    {
        $standardTypes = self::getStandardTypes();

        if (preg_match('/(\[)?(.+?)(!)?(\])?(!)?$/', $in, $matches)) {

            // Wraps into GraphQL-PHP types

            $has = [
                'name' => isset($matches[2]),
                'array' => isset($matches[4]),
                'notNullInternal' => isset($matches[3]),
                'notNullExternal' => isset($matches[5]),
            ];
            $type = isset($standardTypes[$matches[2]]) ? $standardTypes[$matches[2]] : null;
            if (is_null($type)) {

                if (isset(self::$builtTypeDefs[$matches[2]])) {
                    $type = self::$builtTypeDefs[$matches[2]];
                } else {
                    $type = collect($this->typeDefs)->firstWhere('type', $matches[2]);
                    if (is_null($type)) {
                        throw new \Exception('Undefined type: ' . $matches[2]);
                    }
                    $this->build($type);
                    $type = self::$builtTypeDefs[$matches[2]];
                }
            }
            if ($has['notNullInternal']) {
                $type = GraphQL_PHPType::nonNull($type);
            }
            if ($has['array']) {
                $type = GraphQL_PHPType::listOf($type);
            }
            if ($has['notNullExternal']) {
                $type = GraphQL_PHPType::nonNull($type);
            }

            return $type;
        } else {
            throw new \Exception('Invalid GraphQL type format.');
        }
    }

    public function build($def): void
    {
        $config = [
            'name' => $def['type'],
            'fields' => [],
        ];

        foreach ($def['fields'] as $name => $field) {
            $args = [];
            if (preg_match('/(.+)\((.+)\)/', $name, $nameCheck)) {
                $name = $nameCheck[1];

                preg_match_all('/((?:"([^"]+)")?\s*(\w+)\s*:\s*(\w+)(?:\s*=\s*(\w+))?)/', $nameCheck[2], $nameMatches);

                foreach ($nameMatches[3] as $key => $arg) {
                    $ar = [
                        'description' => $nameMatches[2][$key] ?? null,
                        'type' => $this->resolveType($nameMatches[4][$key]),

                    ];
                    if (!empty($nameMatches[5][$key])) {
                        $ar['defaultValue'] = $nameMatches[5][$key];
                    }
                    $args[$arg] = $ar;
                }
            }

            $type = $this->resolveType($field['type']);

            $fieldObjectToAddToConfig = [
                'type' => $type,
                'description' => $field['description'],
                'args' => $args,
            ];

            if ($field['resolver']) {
                $resolver = $field['resolver'];
                $fieldObjectToAddToConfig['resolve'] = function ($objectValue, $args, $context, ResolveInfo $info) use ($resolver) {
                    if ($objectValue === null) {
                        $objectValue = new (static::$typeDefsToClass[$this->currentSchema['schema'][$info->operation->operation]])();
                    }
                    $rfc = new \ReflectionClass($objectValue);
                    $method = $rfc->getMethod($resolver);
                    $params = [];
                    $called = ['objectValue', 'args', 'context', 'info'];
                    foreach ($method->getParameters() as $parameter) {
                        $params = array_merge($params, $parameter->isVariadic() ? array_values(compact($called)) : array_values(compact($parameter->getName())));
                        $called = array_diff($called, [$parameter->getName()]);
                    }
                    return $objectValue->{$resolver}(...$params);
                };
            }
            $config['fields'][$name] = $fieldObjectToAddToConfig;
        }

        $obj = new ObjectType($config);

        self::$builtTypeDefs[$def['type']] = $obj;
    }

    public function replacer($value) {
        if (is_array($value)) {
            return collect($value)->map([$this, 'replacer'])->values()->all();
        }
        return self::$builtTypeDefs[$value];
    }

    public function composeGraphQL(): Schema
    {
        $data = (collect($this->currentSchema['schema'])->map([$this, 'replacer'])->all());
        return new Schema($data);
    }

    public static function getSchema($schema): Schema
    {
        $instance = new self($schema);
        $instance->parse();
        $instance->buildAll();
        return $instance->composeGraphQL();
    }
}
