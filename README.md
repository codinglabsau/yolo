# YOLO

YOLO helps you deploy high-availability PHP applications on AWS.

The CLI tool takes care of provisioning and configuring all required resources on AWS, coupled with build and deployment
commands to deploy applications to production from your local machine or CI pipeline.

___

## Disclaimer

YOLO is designed for PHP developers who want to manage AWS using an infrastructure-as-code approach, using plain-old PHP
rather than CloudFormation / Terraform / K8s / Elastic Beanstalk / <some-other-fancy-alternative>.

> [!IMPORTANT]
> While YOLO has been battle-tested on apps serving millions of requests per day, it is not supposed to be a
> set-and-forget solution for busy apps, but rather allows you to proactively manage, grow and adapt your infrastructure
> as requirements
> change over time.

It goes without saying, but use YOLO at your own risk.

## Prerequisites

You'll need access to an AWS account, and some knowledge of AWS services.

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

## Installation

### Install With Composer

```bash
composer require codinglabsau/yolo
```

The entry point to the YOLO CLI is `vendor/bin/yolo` or `yolo` if you have `./vendor/bin` in your path.

Run `yolo` to see the available commands.

### Initialise YOLO

After composer installing, run `yolo init`. The init command does the following:

1. initialises the yolo.yml file in the app with a boilerplate production environment
2. adds some entries to `.gitignore`
3. prompts for a few bits of information to setup the manifest file

After initialising, you can customise the `yolo.yml` manifest file to suit your app's requirements.

## Usage

### Provisioning resources

YOLO is designed to create and manage all AWS resources required to run your application.

After initialising the YOLO manifest, the next step is to start provisioning resources on AWS.

The sync commands are:

- `yolo sync:network <environment>` prepares the VPC, subnets, security groups and SSH keys
- `yolo sync:standalone <environment>` prepares standalone app resources (standalone apps only)
- `yolo sync:landlord <environment>` prepares landlord resources (multitenancy apps only)
- `yolo sync:tenant <environment>` prepares tenant resources (multitenancy apps only)
- `yolo sync:compute <environment>` prepares the compute resources
- `yolo sync:ci <environment>` prepares the continuous integration pipeline

Alternatively, you can run all commands with `yolo sync <environment>`.

> [!TIP]
> All sync commands support a `--dry-run` argument; this is a great starting point to see what resources will be created
> or modified without any actual changes occurring on AWS.

### Managing AMIs

With all the low-level resource provisioned via the `sync` commands, the next step is to create an Amazon Machine
Image (
AMI) with Ubuntu OS as the foundation.

The AMI will be used as the base image for all server instances, and can be rotated
over time to bring in improvements, such as new PHP versions.

#### Create an AMI

Run `yolo ami:create <environment>` to prepare an AMI.
> [!TIP]
> This takes a few minutes to complete

#### Rotating the AMI

To rotate in the new AMI, run `yolo ami:rotate <environment>`.

You will be prompted to select the AMI (the new one should be at the top of the list).

After selecting which AMI to use, new EC2 autoscaling groups will be created, and one instance will be launched in each
group using the new AMI.

The yolo.yml manifest will also be configured with the new autoscaling groups.

> [!NOTE]
> Rotating in a new AMI does not have any impact on existing traffic until the updated manifest is deployed - the
> previous
> deployment will continue serving requests and autoscaling as per normal.

### Managing .env files

You'll need to push the initial .env file for the environment. Environment files are stored
in the S3 artefacts bucket, and retrieved during deployment.

If you have an existing .env file, be sure to copy that over to `.env.<environment>` in the root of the app, otherwise
you can build on the stub provided by the `init` command.

To push the .env file to the artefacts bucket, run `yolo env:push <environment>`.

After the initial push, you can retrieve the .env file with `yolo env:pull <environment>`.

### Building and deploying

Builds can be created with `yolo build <environment>`.

The build command takes care of building a deployment-ready directory in `./yolo`.

Builds can be deployed with `yolo deploy <environment>`.

> [!TIP]
> You can also build and deploy in a single command with `yolo deploy <environment>`.

## Full yolo.yml example

This is a complete yolo.yml file, showing default values where applicable.

Note that some keys are intentionally omitted from the stub generated by `yolo init`.

```yaml
name: codinglabs

environments:
  production:
    aws:
      region: ap-southeast-2
      bucket:
      artefacts-bucket:
      cloudfront:
      alb:
      security-group-id:
      transcoder: false
      autoscaling:
        web:
        queue:
        scheduler:
      ec2:
        instance-type: t3.small
        instance-profile:
        octane: true
        key-pair:
      codedeploy:
        strategy: without-load-balancing|with-load-balancing

    build:
      - composer install --no-cache --no-interaction --optimize-autoloader --no-progress --classmap-authoritative --no-dev
      - npm ci
      - npm run build
      - rm -rf package-lock.json resources/js resources/css node_modules database/seeders database/factories resources/seeding

    domain: codinglabs.com.au
    www: false
    pulse-worker: false
    mysqldump: false

    deploy:
      - php artisan migrate --force

    deploy-all:
      - php artisan optimize
```

## Development

To debug or add features to YOLO, it is recommended to symlink to the local repository.

Add this to composer.json with the path to the local repository:

```json
    // ...

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
