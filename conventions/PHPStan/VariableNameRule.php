<?php

namespace Conventions\PHPStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\RuleErrorBuilder;
use Conventions\PHPStan\VariableNames\MethodParametersCollector;

/**
 * Coding-conventions §2.26: people by role, things by type. Each collector
 * reads one kind of declaration; this rule reports what they found.
 *
 * @implements Rule<CollectedDataNode>
 */
final class VariableNameRule implements Rule
{
    public const array COLLECTORS = [
        MethodParametersCollector::class,
    ];

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach (self::COLLECTORS as $collector) {
            foreach ($node->get($collector) as $file => $declarations) {
                foreach (array_merge(...$declarations) as [$line, $message]) {
                    $errors[] = RuleErrorBuilder::message($message)
                        ->identifier('lesily.variableName')
                        ->file($file)
                        ->line($line)
                        ->build();
                }
            }
        }

        return $errors;
    }
}
