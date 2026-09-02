<?php

namespace Codinglabs\Yolo\Resources\Iam;

/**
 * AWS may reorder keys and statements and collapse a single-element Action or
 * Condition list to a scalar, so a string compare reads phantom drift and re-stamps
 * the document every sync. Canonicalisation collapses only those legitimate
 * equivalences, so it never false-matches two distinct documents.
 */
trait CanonicalisesPolicyDocuments
{
    /**
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $desired
     */
    protected function policyDocumentsMatch(array $live, array $desired): bool
    {
        return $this->canonicalisePolicyDocument($live) === $this->canonicalisePolicyDocument($desired);
    }

    protected function canonicalisePolicyDocument(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);

            return array_map($this->canonicalisePolicyDocument(...), $value);
        }

        // Unwrap a single-element list so IAM's "x" and ["x"] compare equal.
        $items = array_map($this->canonicalisePolicyDocument(...), $value);

        usort($items, fn (mixed $a, mixed $b): int => json_encode($a) <=> json_encode($b));

        return count($items) === 1 ? $items[0] : $items;
    }
}
