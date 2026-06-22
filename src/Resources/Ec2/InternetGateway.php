<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared internet gateway for the environment. Attaching it to the VPC and
 * routing 0.0.0.0/0 through it are separate relationship actions
 * (SyncInternetGatewayAttachmentStep, SyncDefaultRouteStep).
 */
class InternetGateway implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('internet-gateway', $this->keyedName());
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ec2::internetGateway($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::internetGateway($this->name())['InternetGatewayId'];
    }

    public function create(): void
    {
        Aws::ec2()->createInternetGateway([
            'TagSpecifications' => [
                ['ResourceType' => 'internet-gateway', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Detach the gateway from the VPC, then delete it — AWS refuses to delete an
     * internet gateway that is still attached. The detach is the inverse of
     * SyncInternetGatewayAttachmentStep's attach. A detach against an
     * already-detached gateway (Gateway.NotAttached) is tolerated so we still
     * proceed to delete; a concurrent removal (InvalidInternetGatewayID.NotFound)
     * on either call is tolerated as already-gone.
     */
    public function delete(): void
    {
        $internetGatewayId = $this->arn();

        try {
            Aws::ec2()->detachInternetGateway([
                'InternetGatewayId' => $internetGatewayId,
                'VpcId' => (new Vpc())->arn(),
            ]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidInternetGatewayID.NotFound') {
                return;
            }

            if ($e->getAwsErrorCode() !== 'Gateway.NotAttached') {
                throw $e;
            }
        }

        try {
            Aws::ec2()->deleteInternetGateway(['InternetGatewayId' => $internetGatewayId]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidInternetGatewayID.NotFound') {
                return;
            }

            throw $e;
        }
    }
}
