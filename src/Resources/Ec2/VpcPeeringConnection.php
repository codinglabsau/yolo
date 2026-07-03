<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A VPC peering connection from the environment's VPC to one declared peer —
 * the bridge to infrastructure outside the YOLO network (typically a database
 * mid-migration), declared via the env manifest `peering` list. Same-account:
 * YOLO both requests and accepts the connection, then enables DNS resolution
 * on each side so private hostnames (an RDS endpoint) resolve to private IPs
 * across the peer. Configuration sync re-accepts and re-enables on every run,
 * so an interrupted create self-heals. Routing is a separate relationship
 * concern (SyncVpcPeeringRoutesStep).
 */
class VpcPeeringConnection implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function __construct(public readonly string $peerVpcId) {}

    public function name(): string
    {
        return $this->keyedName("peering-{$this->peerVpcId}");
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        return Ec2::livePeeringConnection($this->name()) !== null;
    }

    public function arn(): string
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            throw new ResourceDoesNotExistException("Could not find a live peering connection {$this->name()}");
        }

        return $connection['VpcPeeringConnectionId'];
    }

    public function create(): void
    {
        Aws::ec2()->createVpcPeeringConnection([
            'VpcId' => (new Vpc())->arn(),
            'PeerVpcId' => $this->peerVpcId,
            'TagSpecifications' => [
                ['ResourceType' => 'vpc-peering-connection', ...Aws::tags($this->tags())],
            ],
        ]);

        // Same-account, so accept immediately — the request needs a beat to
        // reach pending-acceptance, and DNS options need active, so both ride
        // the same reconcile the config sync uses on every later run.
        $this->synchroniseConfiguration();
    }

    /**
     * Reconcile the connection to its desired end state: accepted, and DNS
     * resolution enabled on both sides (so the peer's private hostnames — an
     * RDS endpoint — resolve to private IPs from the env VPC and vice versa).
     * Both transitions are eventually consistent, so each is retried briefly;
     * an interrupted create heals on the next sync either way.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            return [];
        }

        $changes = [];
        $connectionId = $connection['VpcPeeringConnectionId'];
        $status = $connection['Status']['Code'] ?? '';

        if ($status !== 'active') {
            $changes[] = Change::make('status', $status, 'active');

            if ($apply) {
                $this->acceptWhenPending($connectionId);
            }
        }

        // Options are only readable (and settable) once active; on the create
        // path the accept above just ran, so re-read for the fresh status.
        $requesterDnsEnabled = (bool) ($connection['RequesterVpcInfo']['PeeringOptions']['AllowDnsResolutionFromRemoteVpc'] ?? false);
        $accepterDnsEnabled = (bool) ($connection['AccepterVpcInfo']['PeeringOptions']['AllowDnsResolutionFromRemoteVpc'] ?? false);

        if (! $requesterDnsEnabled || ! $accepterDnsEnabled) {
            $changes[] = Change::make('DNS resolution over peering', false, true);

            if ($apply) {
                $this->enableDnsResolutionWhenActive($connectionId);
            }
        }

        return $changes;
    }

    /**
     * Delete the peering connection. Routes pointing at it turn blackhole and
     * are pruned by the routes step on the YOLO side (the peer side's return
     * route is inert and left to the peer's owner). A concurrent removal is
     * tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::ec2()->deleteVpcPeeringConnection(['VpcPeeringConnectionId' => $this->arn()]);
        } catch (Ec2Exception $e) {
            if (str_starts_with($e->getAwsErrorCode() ?? '', 'InvalidVpcPeeringConnectionID')) {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Accept the request, retrying while it's still initiating — the create
     * returns before the request reaches pending-acceptance.
     */
    protected function acceptWhenPending(string $connectionId, int $maxAttempts = 6, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ec2()->acceptVpcPeeringConnection(['VpcPeeringConnectionId' => $connectionId]);

                return;
            } catch (Ec2Exception $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'InvalidStateTransition') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /**
     * Enable DNS resolution on both sides, retrying while the just-accepted
     * connection is still provisioning — options are only settable once active.
     */
    protected function enableDnsResolutionWhenActive(string $connectionId, int $maxAttempts = 6, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ec2()->modifyVpcPeeringConnectionOptions([
                    'VpcPeeringConnectionId' => $connectionId,
                    'RequesterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => true],
                    'AccepterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => true],
                ]);

                return;
            } catch (Ec2Exception $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || ! in_array($exception->getAwsErrorCode(), ['InvalidVpcPeeringConnectionState.NotActive', 'IncorrectState'], true)) {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }
}
