<?php

namespace Codinglabs\Yolo\Resources\Iam;

/**
 * Order- and shape-independent comparison of two IAM policy documents. AWS may
 * reorder keys, reorder statements, and collapse a single-element Action or
 * Condition list to a bare scalar — a naive json_encode/string compare reads all
 * of that as phantom drift and re-stamps the document (a new managed-policy
 * version, or a role-trust rewrite) on every sync. Canonicalise both sides (sort
 * keys, sort lists, unwrap single-element lists) before comparing.
 *
 * Shared by SynchronisesPolicyDocument (customer-managed policy versions) and
 * SynchronisesAssumeRolePolicy (role trust documents) so the two compare
 * identically — canonicalisation is strictly more lenient than a string compare,
 * collapsing only AWS's legitimate equivalences, so it never false-matches two
 * semantically-distinct documents.
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

        // Associative (string-keyed) → sort keys for order-independence, recurse.
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);

            return array_map($this->canonicalisePolicyDocument(...), $value);
        }

        // Sequential list → canonicalise each element and sort, then unwrap a
        // single-element list to its scalar so IAM's "x" and ["x"] forms compare
        // equal (Action, single-value Conditions).
        $items = array_map($this->canonicalisePolicyDocument(...), $value);

        usort($items, fn (mixed $a, mixed $b): int => json_encode($a) <=> json_encode($b));

        return count($items) === 1 ? $items[0] : $items;
    }
}
