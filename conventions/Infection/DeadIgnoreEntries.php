<?php

namespace Conventions\Infection;

use PhpParser\Node;
use ReflectionClass;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ColinODell\Json5\Json5Decoder;

/**
 * Finds the infection.json5 ignore entries that name no live code: a missing
 * class or method, or a `Class::method::line` key whose line starts no
 * construct of its mutator's kind - Infection matches a line key against the
 * mutated node's start line and never reports a key that matched nothing.
 */
final class DeadIgnoreEntries
{
    /**
     * The node each line-keyed mutator mutates, as its canMutate() first tests.
     */
    private const array LINE_CONSTRUCTS = [
        'ArrayItemRemoval' => Node\Expr\Array_::class,
        'AssignCoalesce' => Node\Expr\AssignOp\Coalesce::class,
        'CastInt' => Node\Expr\Cast\Int_::class,
        'CastString' => Node\Expr\Cast\String_::class,
        'DecrementInteger' => Node\Scalar\Int_::class,
        'IncrementInteger' => Node\Scalar\Int_::class,
        'MethodCallRemoval' => Node\Stmt\Expression::class,
        'NullSafeMethodCall' => Node\Expr\NullsafeMethodCall::class,
    ];

    /**
     * @return list<string>
     */
    public function in(string $configPath): array
    {
        /** @var array{mutators: array<string, mixed>} $config */
        $config = Json5Decoder::decode((string) file_get_contents($configPath), true);

        $ignoresByMutator = [];

        foreach ($config['mutators'] as $mutator => $settings) {
            if (is_array($settings) && isset($settings['ignore'])) {
                $ignoresByMutator[$mutator] = $settings['ignore'];
            }
        }

        return $this->among($ignoresByMutator);
    }

    /**
     * @param  array<string, list<string>>  $ignoresByMutator
     * @return list<string>
     */
    public function among(array $ignoresByMutator): array
    {
        $dead = [];

        foreach ($ignoresByMutator as $mutator => $entries) {
            foreach ($entries as $entry) {
                $reason = $this->whyDead($mutator, $entry);

                if ($reason !== null) {
                    $dead[] = "{$mutator} {$entry}: {$reason}";
                }
            }
        }

        return $dead;
    }

    protected function whyDead(string $mutator, string $entry): ?string
    {
        $parts = explode('::', $entry);
        $class = $parts[0];
        $method = $parts[1] ?? null;
        $line = $parts[2] ?? null;

        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
            return 'class not found';
        }

        if ($method === null) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($method) || $reflection->getMethod($method)->class !== $class) {
            return 'method not found';
        }

        if ($line === null) {
            return null;
        }

        if (! isset(self::LINE_CONSTRUCTS[$mutator])) {
            return 'no line check for this mutator';
        }

        return $this->constructStartsOn($reflection, $method, self::LINE_CONSTRUCTS[$mutator], (int) $line)
            ? null
            : "no {$mutator} construct starts on that line";
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  class-string<Node>  $construct
     */
    protected function constructStartsOn(ReflectionClass $reflection, string $method, string $construct, int $line): bool
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents((string) $reflection->getFileName())) ?? [];
        $finder = new NodeFinder;

        $methodNode = $finder->findFirst(
            $nodes,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $method,
        );

        return $finder->findFirst(
            $methodNode instanceof Node\Stmt\ClassMethod ? [$methodNode] : [],
            fn (Node $node): bool => $node instanceof $construct
                && $node->getStartLine() === $line
                && ! ($node instanceof Node\Stmt\Expression && ! $this->isCallStatement($node)),
        ) !== null;
    }

    protected function isCallStatement(Node\Stmt\Expression $statement): bool
    {
        return $statement->expr instanceof Node\Expr\MethodCall
            || $statement->expr instanceof Node\Expr\NullsafeMethodCall
            || $statement->expr instanceof Node\Expr\StaticCall;
    }
}
