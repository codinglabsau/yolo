<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/** Attachment and the 0.0.0.0/0 route are separate steps (SyncInternetGatewayAttachmentStep, SyncDefaultRouteStep). */
class InternetGateway implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
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
     * AWS refuses to delete an attached gateway, so detach first; Gateway.NotAttached
     * is tolerated so a half-done teardown still proceeds to the delete.
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
