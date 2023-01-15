<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Properties;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use NunoMaduro\Larastan\Reflection\ReflectionHelper;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;

/**
 * @internal
 */
final class ModelPropertyExtension implements PropertiesClassReflectionExtension
{
    /** @var array<string, SchemaTable> */
    private array $tables = [];

    public function __construct(
        private TypeStringResolver $stringResolver,
        private MigrationHelper $migrationHelper,
        private SquashedMigrationHelper $squashedMigrationHelper,
        private ModelCastHelper $modelCastHelper,
    ) {
    }

    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (! $classReflection->isSubclassOf(Model::class)) {
            return false;
        }

        if ($classReflection->isAbstract()) {
            return false;
        }

        if ($this->hasAccessor($classReflection, $propertyName)) {
            return false;
        }

        if (ReflectionHelper::hasPropertyTag($classReflection, $propertyName)) {
            return false;
        }

        if (! $this->migrationsLoaded()) {
            $this->loadMigrations();
        }

        try {
            /** @var Model $modelInstance */
            $modelInstance = $classReflection->getNativeReflection()->newInstanceWithoutConstructor();
        } catch (\ReflectionException $e) {
            return false;
        }

        if ($propertyName === $modelInstance->getKeyName()) {
            return true;
        }

        $tableName = $modelInstance->getTable();

        if (! array_key_exists($tableName, $this->tables)) {
            return false;
        }

        return array_key_exists($propertyName, $this->tables[$tableName]->columns);
    }

    private function hasAccessor(ClassReflection $classReflection, string $propertyName): bool
    {
        $propertyNameStudlyCase = Str::studly($propertyName);

        if ($classReflection->hasNativeMethod(sprintf('get%sAttribute', $propertyNameStudlyCase))) {
            return true;
        }

        $propertyNameCamelCase = Str::camel($propertyName);

        if ($classReflection->hasNativeMethod($propertyNameCamelCase)) {
            $methodReflection = $classReflection->getNativeMethod($propertyNameCamelCase);

            if ($methodReflection->isPublic() || $methodReflection->isPrivate()) {
                return false;
            }

            $returnType = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();

            if (! (new ObjectType(Attribute::class))->isSuperTypeOf($returnType)->yes()) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function migrationsLoaded(): bool
    {
        return ! empty($this->tables);
    }

    private function loadMigrations(): void
    {
        // First try to create tables from squashed migrations, if there are any
        // Then scan the normal migration files for further changes to tables.
        $tables = $this->squashedMigrationHelper->initializeTables();

        $this->tables = $this->migrationHelper->initializeTables($tables);
    }

    public function getProperty(
        ClassReflection $classReflection,
        string $propertyName
    ): PropertyReflection {
        try {
            /** @var Model $modelInstance */
            $modelInstance = $classReflection->getNativeReflection()->newInstanceWithoutConstructor();
        } catch (\ReflectionException $e) {
            throw new ShouldNotHappenException();
        }

        $tableName = $modelInstance->getTable();

        if (
            $propertyName === $modelInstance->getKeyName()
            && (! array_key_exists($tableName, $this->tables) || ! array_key_exists($propertyName, $this->tables[$tableName]->columns))
        ) {
            return new ModelProperty(
                declaringClass: $classReflection,
                readableType: $this->stringResolver->resolve($modelInstance->getKeyType()),
                writableType: $this->stringResolver->resolve($modelInstance->getKeyType()),
            );
        }

        $column = $this->tables[$tableName]->columns[$propertyName];

        if ($this->hasDate($modelInstance, $propertyName)) {
            $readableType = $this->modelCastHelper->getDateType();
            $writeableType = TypeCombinator::union($this->modelCastHelper->getDateType(), new StringType());
        } elseif ($modelInstance->hasCast($propertyName)) {
            $cast = $modelInstance->getCasts()[$propertyName];

            $readableType = $this->modelCastHelper->getReadableType(
                cast: $cast,
                originalType: $this->stringResolver->resolve($column->readableType),
            );
            $writeableType = $this->modelCastHelper->getWriteableType(
                cast: $cast,
                originalType: $this->stringResolver->resolve($column->writeableType),
            );
        } else {
            $readableType = $this->stringResolver->resolve($column->readableType);
            $writeableType = $this->stringResolver->resolve($column->writeableType);
        }

        if ($column->nullable) {
            $readableType = TypeCombinator::addNull($readableType);
            $writeableType = TypeCombinator::addNull($writeableType);
        }

        return new ModelProperty(
            declaringClass: $classReflection,
            readableType: $readableType,
            writableType: $writeableType,
        );
    }

    private function hasDate(Model $modelInstance, string $propertyName): bool
    {
        $dates = $modelInstance->getDates();

        // In order to support SoftDeletes
        if (method_exists($modelInstance, 'getDeletedAtColumn')) {
            $dates[] = $modelInstance->getDeletedAtColumn();
        }

        return in_array($propertyName, $dates);
    }

    /**
     * @param  SchemaColumn  $column
     * @param  Model  $modelInstance
     * @return string[]
     * @phpstan-return array<int, string>
     */
    private function getReadableAndWritableTypes(SchemaColumn $column, Model $modelInstance): array
    {
        $readableType = $column->readableType;
        $writableType = $column->writeableType;

        if (in_array($column->name, $this->getModelDateColumns($modelInstance), true)) {
            return [$this->getDateClass().($column->nullable ? '|null' : ''), $this->getDateClass().'|string'.($column->nullable ? '|null' : '')];
        }

        switch ($column->readableType) {
            case 'string':
            case 'int':
            case 'float':
                $readableType = $writableType = $column->readableType.($column->nullable ? '|null' : '');
                break;

            case 'boolean':
            case 'bool':
                switch ((string) config('database.default')) {
                    case 'sqlite':
                    case 'mysql':
                        $writableType = '0|1|bool';
                        $readableType = 'bool';
                        break;
                    default:
                        $readableType = $writableType = 'bool';
                        break;
                }
                break;
            case 'enum':
            case 'set':
                if (! $column->options) {
                    $readableType = $writableType = 'string';
                } else {
                    $readableType = $writableType = '\''.implode('\'|\'', $column->options).'\'';
                }

                break;

            default:
                break;
        }

        if ($column->nullable) {
            $readableType = TypeCombinator::addNull($readableType);
            $writableType = TypeCombinator::addNull($writableType);
        }

        return new ModelProperty(
            $classReflection,
            $readableType,
            $writableType,
        );
    }

    private function castPropertiesType(Model $modelInstance): void
    {
        $casts = $modelInstance->getCasts();
        foreach ($casts as $name => $type) {
            if (! array_key_exists($name, $this->tables[$modelInstance->getTable()]->columns)) {
                continue;
            }

            // Reduce encrypted castable types
            if (in_array($type, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], true)) {
                $type = Str::after($type, 'encrypted:');
            }

            // Truncate cast parameters
            $type = Str::before($type, ':');

            switch ($type) {
                case 'boolean':
                case 'bool':
                    $realType = 'boolean';
                    break;
                case 'string':
                case 'decimal':
                    $realType = 'string';
                    break;
                case 'array':
                case 'json':
                    $realType = 'array';
                    break;
                case 'object':
                    $realType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $realType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $realType = 'float';
                    break;
                case 'date':
                case 'datetime':
                    $realType = $this->getDateClass();
                    break;
                case 'collection':
                    $realType = '\Illuminate\Support\Collection';
                    break;
                case 'Illuminate\Database\Eloquent\Casts\AsArrayObject':
                    $realType = ArrayObject::class;
                    break;
                case 'Illuminate\Database\Eloquent\Casts\AsCollection':
                    $realType = '\Illuminate\Support\Collection<array-key, mixed>';
                    break;
                default:
                    $realType = class_exists($type) ? ('\\'.$type) : 'mixed';
                    break;
            }

            if ($this->tables[$modelInstance->getTable()]->columns[$name]->nullable) {
                $realType .= '|null';
            }

            $this->tables[$modelInstance->getTable()]->columns[$name]->readableType = $realType;
            $this->tables[$modelInstance->getTable()]->columns[$name]->writeableType = $realType;
        }
    }

    private function hasAttribute(ClassReflection $classReflection, string $propertyName): bool
    {
        if ($classReflection->hasNativeMethod('get'.Str::studly($propertyName).'Attribute')) {
            return true;
        }

        $camelCase = Str::camel($propertyName);

        if ($classReflection->hasNativeMethod($camelCase)) {
            $methodReflection = $classReflection->getNativeMethod($camelCase);

            if ($methodReflection->isPublic() || $methodReflection->isPrivate()) {
                return false;
            }

            $returnType = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();

            if (! (new ObjectType(Attribute::class))->isSuperTypeOf($returnType)->yes()) {
                return false;
            }

            return true;
        }

        return false;
    }
}
