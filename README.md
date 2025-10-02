# YOLO

> [!IMPORTANT]
> This package is in active development - contributions are welcome!

YOLO helps you deploy high-availability PHP applications to AWS.

The CLI tool lives inside your Laravel app in `vendor/bin/yolo`, and takes care of provisioning and configuring all
required resources on
AWS, coupled with build and deployment
commands to deploy applications to production from your local machine or CI pipeline.

YOLO has been battle-tested on apps that serve 2 million requests per day.

## Features

### Autoscaling Worker Groups

YOLO provisions an Application Load Balancer and autoscaling groups (web, queue, scheduler) for each environment.

Each group is self-healing should an instance become unresponsive, and the web group automatically scales up to handle
traffic bursts.

In addition, worker groups can be combined (coming soon) to a single EC2 instance to consolidate small workloads.

### Resource Sharing

YOLO shares various resources between applications to reduce costs.

### Zero-downtime Deployments

YOLO leverages AWS CodeDeploy to perform zero-downtime deployments, which can be triggered from the CLI or via a CI
pipeline.

### Multi-tenancy

Specify tenants in the manifest and YOLO will take care of provisioning resources for each tenant.

### S3

Leverage S3 for storing build artefacts and user data files.

### Octane (experimental)

YOLO supports Laravel Octane for turbocharged PHP applications.

### Video Transcoding

YOLO can be provision resources on AWS to simplify video transcoding on AWS using AWS Elemental MediaConvert.

### And Much More...

- Seperate commands that run on deployment across worker groups
- Scheduled MySQL backups using `mysqldump`
- Control of build and deploy commands
- Re-use existing VPCs, subnets, internet gateways and more

___

## Disclaimer

YOLO is designed for PHP developers who are comfortable managing AWS using an infrastructure-as-code approach.

It is, at it's core, a Symfony CLI app that leverages the AWS SDK, rather than CloudFormation / Terraform / K8s /
Elastic
Beanstalk / <some-other-fancy-alternative>.

While YOLO has underpinned very large, mission-critical production applications, it is not intended to be a set and
forget solution; rather it acts as a control plane that allows you to manage and expand your AWS footprint over time.

It goes without saying, but use YOLO at your own risk.

## Prerequisites

You'll need access to an AWS account, and some knowledge of AWS services.

### Domains on Route53

The domains for your app should be added to Route53 on the same AWS account as where the app is hosted in advance.

### Permissions & Authentication

YOLO uses AWS profiles for authentication.

Profiles are stored in `~/.aws/credentials` for authentication. You'll need to set a
`YOLO_{ENVIRONMENT}_AWS_PROFILE` in the app `.env` file to point to the correct profile; eg.

```bash
YOLO_PRODUCTION_AWS_PROFILE=my-project-profile
```

Once configured, future operations will authenticate using this profile.

You will need wide-ranging AWS credentials to provision everything required by YOLO; administrative permissions are
recommended.

For CI environments like GitHub Actions, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are used instead. Ensure that
any access keys provided in CI are using least-privileged scope.

## Step 1: Installation

### a) Install With Composer

```bash
composer require codinglabsau/yolo
```

The entry point to the YOLO CLI is `vendor/bin/yolo` or `yolo` if you have `./vendor/bin` in your path.

Run `yolo` to see the available commands.

### b) Initialise yolo

Next, run `yolo init`. The init command does the following:

1. initialises the yolo.yml file in the app with a boilerplate production environment
2. adds some entries to `.gitignore`
3. prompts for a few bits of information to setup the manifest file

After initialising, you can customise the `yolo.yml` manifest file to suit your app's requirements.

## Step 2: Provision resources

YOLO is designed to create and manage all AWS resources required to run your application.

Provision all resources by running `yolo sync <environment>`. This command runs all `sync` commands in the correct
order.

The full list of available sync commands are:

- `yolo sync:network <environment>` prepares the VPC, subnets, security groups and SSH keys
- `yolo sync:standalone <environment>` prepares standalone app resources (standalone apps only)
- `yolo sync:landlord <environment>` prepares landlord resources (multitenancy apps only)
- `yolo sync:tenant <environment>` prepares tenant resources (multitenancy apps only)
- `yolo sync:compute <environment>` prepares the compute resources
- `yolo sync:ci <environment>` prepares the continuous integration pipeline
- `yolo sync:iam <environment>` prepares necessary permissions

> [!TIP]
> All sync commands support a `--dry-run` argument; this is a great starting point to see what resources will be created
> or modified without any actual changes occurring on AWS.

## Step 3: Prepare a server image

With all the low-level resource provisioned via the `sync` commands, the next step is to create an Amazon Machine
Image (
AMI) with Ubuntu OS as the foundation.

The image will be used as the initial disk image for all server instances, and can be updated
over time to bring in improvements, such as new PHP versions.

### a) Create an image

Run `yolo image:create <environment>` to generate a new AMI.

### b) Prepare the image for traffic

To prepare a new stage, run `yolo stage <environment>`.

This interactive command walks you through updating or replacing the current stage configuration.

New stages have the benefit of allowing testing before migrating production workloads over, however simply updating the
existing stage is recommended for minor changes.

| Situation                   | Recommended strategy |
|-----------------------------|----------------------|
| Update EC2 security group   | update               |
| Update EC2 type             | update               |
| Update EC2 instance profile | update               |
| Update AMI                  | create               |

When creating a new stage, the yolo.yml manifest will also be updated to point to the new autoscaling groups on the next
deployment.

> [!NOTE]
> Rotating in a new image does not have any impact on existing traffic until the updated manifest is deployed - the
> previous deployment will continue serving requests and autoscaling as per normal.

## Step 4. Setup .env file

You'll need to push the initial .env file for the environment. Environment files are stored in the S3 artefacts bucket,
and retrieved during deployment.

If you have an existing .env file, be sure to copy that over to `.env.<environment>` in the root of the app, otherwise
you can build on the stub provided by the `init` command.

To push the .env file to the artefacts bucket, run `yolo env:push <environment>`.

After the initial push, you can retrieve the .env file with `yolo env:pull <environment>`.

## Step 5. Building and deploying

Builds can be created with `yolo build <environment>`.

The build command takes care of building a deployment-ready directory in `./yolo`.

Builds can be deployed with `yolo deploy <environment>`.

> [!TIP]
> You can also build and deploy in a single command with `yolo deploy <environment>`.

## yolo.yml

This is a complete yolo.yml manifest file, showing default values where applicable.

Note that some keys are intentionally omitted from the stub generated by `yolo init`.

```yaml
name: codinglabs

environments:
  production:
    aws:
      account-id:
      region: ap-southeast-2
      vpc:
      internet-gateway:
      public-subnets:
      route-table:
      bucket:
      artefacts-bucket:
      cloudfront:
      alb:
      mediaconvert:
      autoscaling:
        web:
        queue:
        scheduler:
        combine: false
      ec2:
        instance-type: t3.small
        queue-instance-type:
        scheduler-instance-type:
        octane: false
        key-pair:
        security-group:
      rds:
        subnet:
        security-group:
      codedeploy:
        strategy: without-load-balancing|with-load-balancing

    asset-url: # defaults to aws.cloudfront
    pulse-worker: false
    mysqldump: false

    domain: example.com # standalone apps only
    apex: # standalone apps only

    tenants: # multi-tenanted apps only
      boating: # unique key for the tenant
        domain: boating-with-yolo.com

      fishing: # unique key for the tenant
        domain: fishing-with-yolo.com

    build:
      - composer install --no-cache --no-interaction --optimize-autoloader --no-progress --classmap-authoritative --no-dev
      - npm ci
      - npm run build
      - rm -rf package-lock.json resources/js resources/css node_modules database/seeders database/factories resources/seeding

    deploy: #runs on scheduler
      - php artisan migrate --force

    deploy-queue: # runs on queue
      -

    deploy-web: # runs on web
      -

    deploy-all: # runs on all instances
      - php artisan optimize
```

### Domains

Applications hosted with yolo can be served on any domain or subdomain that you own.

The domain should be added to Route53 in advance.

For a standalone application, the domain key can be used:

```yaml
    domain: codinglabs.com.au
```

In this example, the app will be served on `codinglabs.com.au`, and `www.codinglabs.com.au` will redirect to
`codinglabs.com.au`.

If the application is served on any subdomain (including www.) you'll need to specify the apex record as well.

```yaml
    apex: codinglabs.com.au
    domain: www.codinglabs.com.au
```

In this example, the app will be served on `www.codinglabs.com.au`, and `codinglabs.com.au` will redirect to
`www.codinglabs.com.au`.

Multi-tenant applications follow the same logic, except that domains are configured under the `tenants` key.

```yaml
    tenants:
      boating:
        domain: boating.outdoors-with-yolo.com
        apex: outdoors-with-yolo.com

      camping:
        domain: camping.outdoors-with-yolo.com
        apex: outdoors-with-yolo.com

      fishing:
        domain: fishing-with-yolo.com
```

## Development

To debug or add features to YOLO, it is recommended to symlink to the local repository.

Add this to composer.json with the path to the local repository:

```
"repositories": [
    {
    "type": "path",
    "url": "/Users/username/code/yolo"
    }
],
```

To call yolo from the app you are debugging, you'll need to tell yolo the path to the app. Set the `YOLO_BASE_PATH`
environment to the root of the app as follows:

```bash
YOLO_BASE_PATH=$(pwd) yolo
```

## Credits

- [Steve Thomas](https://github.com/stevethomas)
- [All Contributors](https://github.com/codinglabsau/yolo/contributors)

## License

Proprietary.
